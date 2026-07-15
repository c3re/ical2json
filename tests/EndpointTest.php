<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the api/index.php HTTP endpoint.
 *
 * These drive the real script through PHP's built-in web server and assert on
 * its public contract (status codes, headers and JSON body) rather than on the
 * behaviour of the underlying ics-parser library.
 *
 * A second built-in server serves calendar fixtures from a local origin; the
 * endpoint is told (via a server-side environment flag) to allow private hosts
 * so it can fetch from 127.0.0.1 during tests.
 */
final class EndpointTest extends TestCase {
    private static HttpTestServer $app;
    private static HttpTestServer $fixtures;
    private static string $fixtureDir;
    private static string $cacheDir;

    public static function setUpBeforeClass(): void {
        self::$fixtureDir = self::makeTempDir('ical2json_fx_');
        self::$cacheDir = self::makeTempDir('ical2json_cache_');

        self::$fixtures = new HttpTestServer(self::$fixtureDir);

        self::$app = new HttpTestServer(__DIR__ . '/../htdocs', [
            'ICAL2JSON_ALLOW_PRIVATE_HOSTS' => '1',
            'ICAL2JSON_CACHE_DIR' => self::$cacheDir,
            'ICAL2JSON_DEBUG' => '1',
        ]);
    }

    public static function tearDownAfterClass(): void {
        self::$app->stop();
        self::$fixtures->stop();
        self::rrmdir(self::$fixtureDir);
        self::rrmdir(self::$cacheDir);
    }

    protected function setUp(): void {
        // Isolate cache state between tests.
        foreach (glob(self::$cacheDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ---------------------------------------------------------------------
    // Request validation
    // ---------------------------------------------------------------------

    public function testMissingUrlReturns400(): void {
        $res = $this->get([]);

        $this->assertSame(400, $res['status']);
        $this->assertSame('No url given', $res['body']);
    }

    public function testNonHttpSchemeReturns400(): void {
        // Host resolves to a private IP (allowed in test), so the request
        // reaches the scheme check and is rejected there.
        $res = $this->get(['url' => 'ftp://127.0.0.1/cal.ics']);

        $this->assertSame(400, $res['status']);
    }

    public function testInvalidStartReturns400(): void {
        $res = $this->get([
            'url' => self::$fixtures->url('cal.ics'),
            'start' => 'definitely-not-a-date',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('Start value', $res['body']);
    }

    public function testInvalidEndReturns400(): void {
        $res = $this->get([
            'url' => self::$fixtures->url('cal.ics'),
            'end' => 'definitely-not-a-date',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('End value', $res['body']);
    }

    public function testStartAfterEndReturns400(): void {
        $res = $this->get([
            'url' => self::$fixtures->url('cal.ics'),
            'start' => 'tomorrow',
            'end' => 'today',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('after end value', $res['body']);
    }

    public function testRangeTooLargeReturns400(): void {
        $res = $this->get([
            'url' => self::$fixtures->url('cal.ics'),
            'start' => 'today',
            'end' => 'today + 100 days',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString('90 days', $res['body']);
    }

    public function testMaxItemsTooLargeReturns400(): void {
        $res = $this->get([
            'url' => self::$fixtures->url('cal.ics'),
            'maxitems' => '101',
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertStringContainsString(
            'Maximum number of items',
            $res['body'],
        );
    }

    public function testPrivateHostIsBlockedWhenNotAllowed(): void {
        // A fresh app server WITHOUT the allow-private flag must reject a
        // request that targets a private/loopback host (SSRF guard).
        $guardedApp = new HttpTestServer(__DIR__ . '/../htdocs', [
            'ICAL2JSON_CACHE_DIR' => self::$cacheDir,
        ]);

        try {
            $query = http_build_query([
                'url' => self::$fixtures->url('cal.ics'),
            ]);
            $url = $guardedApp->url('api/index.php') . '?' . $query;

            $context = stream_context_create([
                'http' => ['ignore_errors' => true, 'timeout' => 10],
            ]);
            $body = file_get_contents($url, false, $context);
            $this->assertNotFalse($body);
            $status = $this->parseStatus($http_response_header);

            $this->assertSame(400, $status);
        } finally {
            $guardedApp->stop();
        }
    }

    // ---------------------------------------------------------------------
    // Successful responses
    // ---------------------------------------------------------------------

    public function testCorsHeaderIsAlwaysSent(): void {
        $res = $this->get([]);

        $this->assertSame(
            '*',
            $res['headers']['access-control-allow-origin'] ?? null,
        );
    }

    public function testValidRequestReturnsSortedUtcEvents(): void {
        $berlinStart = new DateTime(
            'tomorrow 08:00',
            new DateTimeZone('Europe/Berlin'),
        );
        $berlinEnd = new DateTime(
            'tomorrow 16:00',
            new DateTimeZone('Europe/Berlin'),
        );
        $utcStart = new DateTime('+2 days 10:00', new DateTimeZone('UTC'));
        $utcEnd = new DateTime('+2 days 11:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            $this->vevent(
                'utc-1',
                'UTC Event',
                'DTSTART:' . $utcStart->format('Ymd\\THis') . 'Z',
                'DTEND:' . $utcEnd->format('Ymd\\THis') . 'Z',
            ),
            $this->vevent(
                'berlin-1',
                'Berlin Event',
                'DTSTART;TZID=Europe/Berlin:' .
                    $berlinStart->format('Ymd\\THis'),
                'DTEND;TZID=Europe/Berlin:' . $berlinEnd->format('Ymd\\THis'),
            ),
        ]);

        $name = 'sorted_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);

        $res = $this->get([
            'url' => self::$fixtures->url($name),
            'start' => 'today',
            'end' => 'today + 3 days',
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertSame(
            'application/json',
            $res['headers']['content-type'] ?? null,
        );

        $data = json_decode($res['body'], true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // Sorted ascending by start; Berlin (tomorrow) precedes UTC (+2 days).
        $this->assertSame('Berlin Event', $data[0]['summary']);
        $this->assertSame('UTC Event', $data[1]['summary']);

        // Timestamps are correct absolute UTC values.
        $this->assertSame($berlinStart->getTimestamp(), $data[0]['start']);
        $this->assertSame($berlinEnd->getTimestamp(), $data[0]['end']);
        $this->assertSame($utcStart->getTimestamp(), $data[1]['start']);
        $this->assertSame($utcEnd->getTimestamp(), $data[1]['end']);
    }

    public function testResponseIsCachedOnSecondRequest(): void {
        $start = new DateTime('tomorrow 09:00', new DateTimeZone('UTC'));
        $end = new DateTime('tomorrow 10:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            $this->vevent(
                'cache-1',
                'Cached Event',
                'DTSTART:' . $start->format('Ymd\\THis') . 'Z',
                'DTEND:' . $end->format('Ymd\\THis') . 'Z',
            ),
        ]);

        $name = 'cache_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);
        $url = self::$fixtures->url($name);

        $params = [
            'url' => $url,
            'start' => 'today',
            'end' => 'today + 2 days',
        ];

        $first = $this->get($params);
        $this->assertSame(200, $first['status']);

        $second = $this->get($params);
        $this->assertSame(200, $second['status']);
        $this->assertSame(
            'complete',
            $second['headers']['x-debug-cache-hit'] ?? null,
        );
        $this->assertSame($first['body'], $second['body']);
    }

    public function testRequestedRangeExcludesOutOfRangeEvents(): void {
        $inRange = new DateTime('tomorrow 09:00', new DateTimeZone('UTC'));
        $outOfRange = new DateTime('+30 days 09:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            $this->vevent(
                'in-range',
                'In Range',
                'DTSTART:' . $inRange->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $inRange)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
            $this->vevent(
                'out-of-range',
                'Out Of Range',
                'DTSTART:' . $outOfRange->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $outOfRange)
                        ->modify('+1 hour')
                        ->format('Ymd\\THis') .
                    'Z',
            ),
        ]);

        $name = 'range_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);

        $res = $this->get([
            'url' => self::$fixtures->url($name),
            'start' => 'today',
            'end' => 'today + 3 days',
        ]);

        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertIsArray($data);
        $this->assertSame(['In Range'], array_column($data, 'summary'));
    }

    public function testMaxItemsKeepsEarliestEventsAfterSorting(): void {
        // Three events, listed in the file in reverse chronological order.
        $day1 = new DateTime('tomorrow 10:00', new DateTimeZone('UTC'));
        $day2 = new DateTime('+2 days 10:00', new DateTimeZone('UTC'));
        $day3 = new DateTime('+3 days 10:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            $this->vevent(
                'e3',
                'Day 3',
                'DTSTART:' . $day3->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $day3)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
            $this->vevent(
                'e2',
                'Day 2',
                'DTSTART:' . $day2->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $day2)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
            $this->vevent(
                'e1',
                'Day 1',
                'DTSTART:' . $day1->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $day1)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
        ]);

        $name = 'truncate_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);

        $res = $this->get([
            'url' => self::$fixtures->url($name),
            'start' => 'today',
            'end' => 'today + 5 days',
            'maxitems' => '2',
        ]);

        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertIsArray($data);
        // The two chronologically earliest events, in ascending order.
        $this->assertSame(['Day 1', 'Day 2'], array_column($data, 'summary'));
    }

    public function testMaxItemsBelowOneIsClampedToOne(): void {
        $day1 = new DateTime('tomorrow 10:00', new DateTimeZone('UTC'));
        $day2 = new DateTime('+2 days 10:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            $this->vevent(
                'c1',
                'First',
                'DTSTART:' . $day1->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $day1)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
            $this->vevent(
                'c2',
                'Second',
                'DTSTART:' . $day2->format('Ymd\\THis') . 'Z',
                'DTEND:' .
                    (clone $day2)->modify('+1 hour')->format('Ymd\\THis') .
                    'Z',
            ),
        ]);

        $name = 'clamp_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);

        $res = $this->get([
            'url' => self::$fixtures->url($name),
            'start' => 'today',
            'end' => 'today + 5 days',
            'maxitems' => '0',
        ]);

        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('First', $data[0]['summary']);
    }

    // ---------------------------------------------------------------------
    // Upstream/download failures
    // ---------------------------------------------------------------------

    public function testDurationIsReturnedAsString(): void {
        $start = new DateTime('tomorrow 10:00', new DateTimeZone('UTC'));

        $ics = $this->buildCalendar([
            "BEGIN:VEVENT\r\n" .
            "UID:dur-1\r\n" .
            "DTSTAMP:20200101T000000Z\r\n" .
            "SUMMARY:With Duration\r\n" .
            'DTSTART:' .
            $start->format('Ymd\\THis') .
            "Z\r\n" .
            "DURATION:PT1H30M\r\n" .
            "END:VEVENT\r\n",
        ]);

        $name = 'dur_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, $ics);

        $res = $this->get([
            'url' => self::$fixtures->url($name),
            'start' => 'today',
            'end' => 'today + 3 days',
        ]);

        $this->assertSame(200, $res['status']);
        $data = json_decode($res['body'], true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertIsString($data[0]['duration']);
        $this->assertSame('PT1H30M', $data[0]['duration']);
    }

    public function testDebugHeadersAreOffByDefault(): void {
        // App server with private hosts allowed (so it can reach the fixture)
        // but WITHOUT the debug flag.
        $prodLikeApp = new HttpTestServer(__DIR__ . '/../htdocs', [
            'ICAL2JSON_ALLOW_PRIVATE_HOSTS' => '1',
            'ICAL2JSON_CACHE_DIR' => self::$cacheDir,
        ]);

        try {
            $start = new DateTime('tomorrow 10:00', new DateTimeZone('UTC'));
            $ics = $this->buildCalendar([
                $this->vevent(
                    'nodebug-1',
                    'No Debug',
                    'DTSTART:' . $start->format('Ymd\\THis') . 'Z',
                    'DTEND:' .
                        (clone $start)->modify('+1 hour')->format('Ymd\\THis') .
                        'Z',
                ),
            ]);
            $name = 'nodebug_' . bin2hex(random_bytes(4)) . '.ics';
            $this->writeFixture($name, $ics);

            $res = $this->getFrom($prodLikeApp, [
                'url' => self::$fixtures->url($name),
                'start' => 'today',
                'end' => 'today + 3 days',
            ]);

            $this->assertSame(200, $res['status']);
            foreach (array_keys($res['headers']) as $header) {
                $this->assertStringStartsNotWith(
                    'x-debug',
                    $header,
                    'debug headers must be off by default',
                );
            }
        } finally {
            $prodLikeApp->stop();
        }
    }

    public function testUnreachableUrlReturnsError(): void {
        // The fixture server responds 404 for a non-existent file, which the
        // http stream wrapper surfaces as a failed open.
        $res = $this->get([
            'url' => self::$fixtures->url('does-not-exist.ics'),
        ]);

        $this->assertGreaterThanOrEqual(500, $res['status']);
        $this->assertLessThan(600, $res['status']);
    }

    public function testEmptyDownloadReturnsError(): void {
        $name = 'empty_' . bin2hex(random_bytes(4)) . '.ics';
        $this->writeFixture($name, '');

        $res = $this->get(['url' => self::$fixtures->url($name)]);

        $this->assertGreaterThanOrEqual(500, $res['status']);
        $this->assertLessThan(600, $res['status']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Performs a GET request against the endpoint.
     *
     * @param  array<string,string> $params
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function get(array $params): array {
        return $this->getFrom(self::$app, $params);
    }

    /**
     * Performs a GET request against a specific app server instance.
     *
     * @param  array<string,string> $params
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function getFrom(HttpTestServer $server, array $params): array {
        $query = http_build_query($params);
        $url =
            $server->url('api/index.php') . ($query !== '' ? '?' . $query : '');

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $body = file_get_contents($url, false, $context);
        $this->assertNotFalse($body, 'request to endpoint failed');

        return [
            'status' => $this->parseStatus($http_response_header),
            'headers' => $this->parseHeaders($http_response_header),
            'body' => $body,
        ];
    }

    /** @param array<int,string> $responseHeaders */
    private function parseStatus(array $responseHeaders): int {
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /**
     * @param  array<int,string> $responseHeaders
     * @return array<string,string>
     */
    private function parseHeaders(array $responseHeaders): array {
        $headers = [];
        foreach ($responseHeaders as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = strtolower(trim(substr($line, 0, $pos)));
                $headers[$key] = trim(substr($line, $pos + 1));
            }
        }
        return $headers;
    }

    private function writeFixture(string $name, string $contents): void {
        file_put_contents(self::$fixtureDir . '/' . $name, $contents);
    }

    /** @param array<int,string> $vevents */
    private function buildCalendar(array $vevents): string {
        return "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//ical2json test//EN\r\n" .
            "CALSCALE:GREGORIAN\r\n" .
            implode('', $vevents) .
            "END:VCALENDAR\r\n";
    }

    private function vevent(
        string $uid,
        string $summary,
        string $dtstart,
        string $dtend,
    ): string {
        return "BEGIN:VEVENT\r\n" .
            "UID:{$uid}\r\n" .
            "DTSTAMP:20200101T000000Z\r\n" .
            "SUMMARY:{$summary}\r\n" .
            "{$dtstart}\r\n" .
            "{$dtend}\r\n" .
            "END:VEVENT\r\n";
    }

    private static function makeTempDir(string $prefix): string {
        $dir = tempnam(sys_get_temp_dir(), $prefix);
        unlink($dir);
        mkdir($dir, 0700, true);
        return $dir;
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::rrmdir($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
