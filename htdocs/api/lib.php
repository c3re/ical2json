<?php

/**
 * Pure, testable helper functions for the ical2json API.
 *
 * This file intentionally contains no side effects (no headers, no output,
 * no reading of superglobals) so the logic can be unit tested in isolation.
 */

/**
 * Returns true when the given IP address is a routable, public address
 * (i.e. not private and not reserved/loopback/link-local).
 */
function isPublicIp($ip)
{
    return (bool) filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

/**
 * Resolves a host to every IPv4 (A) and IPv6 (AAAA) address it points at.
 *
 * IP literals (v4 or v6, optionally bracketed) are returned as-is. This is
 * used by the SSRF guard so that a host cannot smuggle a private address in
 * via a record type (e.g. AAAA) that a single gethostbyname() would miss.
 *
 * @param  string $host
 * @return array<int, string>
 */
function resolveHostIps($host)
{
    $literal = trim($host, "[]");
    if (filter_var($literal, FILTER_VALIDATE_IP)) {
        return [$literal];
    }

    $ips = [];

    $a = @dns_get_record($host, DNS_A);
    if (is_array($a)) {
        foreach ($a as $record) {
            if (!empty($record["ip"])) {
                $ips[] = $record["ip"];
            }
        }
    }

    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $record) {
            if (!empty($record["ipv6"])) {
                $ips[] = $record["ipv6"];
            }
        }
    }

    // Fallback for environments where dns_get_record is unavailable.
    if ($ips === []) {
        $resolved = gethostbyname($host);
        if ($resolved !== $host) {
            $ips[] = $resolved;
        }
    }

    return array_values(array_unique($ips));
}

/**
 * Validates a user-supplied target URL before it is fetched.
 *
 * Enforces an http/https scheme and, unless private hosts are explicitly
 * allowed, requires that every address the host resolves to is public. This
 * mitigates SSRF against internal/link-local/loopback ranges.
 *
 * @param  string $url
 * @param  bool   $allowPrivate
 * @return array{ok:bool, code:int, message:string}
 */
function validateTargetUrl($url, $allowPrivate = false)
{
    $parsed = parse_url((string) $url);
    if ($parsed === false || empty($parsed["host"])) {
        return ["ok" => false, "code" => 400, "message" => "Invalid URL"];
    }

    $scheme = strtolower($parsed["scheme"] ?? "");
    if ($scheme !== "http" && $scheme !== "https") {
        return [
            "ok" => false,
            "code" => 400,
            "message" => "Only http and https URLs are allowed",
        ];
    }

    if ($allowPrivate) {
        return ["ok" => true, "code" => 200, "message" => ""];
    }

    $ips = resolveHostIps($parsed["host"]);
    if ($ips === []) {
        return [
            "ok" => false,
            "code" => 400,
            "message" => "Could not resolve host",
        ];
    }

    foreach ($ips as $ip) {
        if (!isPublicIp($ip)) {
            return [
                "ok" => false,
                "code" => 400,
                "message" => "Target host is not allowed",
            ];
        }
    }

    return ["ok" => true, "code" => 200, "message" => ""];
}

/**
 * Converts an ics-parser date array (e.g. $event->dtstart_array) into a
 * Unix timestamp that always represents an absolute point in time in UTC.
 *
 * The date array layout produced by the parser is:
 *   [0] => array of parameters, may contain 'TZID' (e.g. 'Europe/Berlin')
 *   [1] => the raw iCal date/time value (e.g. '20240115T140000' or '...Z')
 *
 * The wall-clock value in [1] is interpreted in the timezone given by
 * [0]['TZID']. A trailing 'Z' means the value is already UTC. When no
 * timezone information is present the value is treated as UTC.
 *
 * @param  mixed $dateArray
 * @return int|null Unix timestamp (UTC) or null when it cannot be parsed
 */
function toUtcTimestamp($dateArray)
{
    if (!is_array($dateArray) || !isset($dateArray[1])) {
        return null;
    }

    $value = (string) $dateArray[1];

    // A trailing 'Z' denotes UTC; otherwise honour the event's TZID.
    if (substr($value, -1) === "Z") {
        $tzid = "UTC";
    } elseif (isset($dateArray[0]["TZID"]) && $dateArray[0]["TZID"] !== "") {
        $tzid = (string) $dateArray[0]["TZID"];
    } else {
        $tzid = "UTC";
    }

    try {
        $timeZone = new DateTimeZone($tzid);
    } catch (\Exception $e) {
        $timeZone = new DateTimeZone("UTC");
    }

    $clean = rtrim($value, "Z");

    // Interpret the wall-clock value in its timezone, then read the
    // absolute (UTC) Unix timestamp via getTimestamp().
    $dt = DateTime::createFromFormat("Ymd\\THis", $clean, $timeZone);
    if ($dt === false) {
        // Date-only value (e.g. all-day events): 'YYYYMMDD'.
        $dt = DateTime::createFromFormat("!Ymd", $clean, $timeZone);
    }

    if ($dt === false) {
        return null;
    }

    return $dt->getTimestamp();
}

/**
 * Maps a single ics-parser Event into the flat array structure returned by
 * the API.
 *
 * @param  \ICal\Event $event
 * @return array<string, mixed>
 */
function mapEvent(\ICal\Event $event)
{
    return [
        "summary" => $event->summary ?? null,
        "description" => $event->description ?? null,
        "location" => $event->location ?? null,
        "start" => toUtcTimestamp($event->dtstart_array),
        "end" => toUtcTimestamp($event->dtend_array),
        "duration" => normalizeDuration($event->duration ?? null),
        "url" => $event->url ?? null,
        "status" => $event->status ?? null,
    ];
}

/**
 * Normalizes an event duration into a JSON-friendly scalar.
 *
 * The parser usually exposes the raw iCal duration string (e.g. "PT1H"), but
 * defensively convert a \DateInterval into its ISO-8601 representation so the
 * API never emits an opaque object.
 *
 * @param  mixed $duration
 * @return string|null
 */
function normalizeDuration($duration)
{
    if ($duration === null || $duration === "") {
        return null;
    }

    if ($duration instanceof \DateInterval) {
        return (new \DateTime("@0"))
            ->add($duration)
            ->format("\P\TG\Hi\Ms\S");
    }

    return (string) $duration;
}

/**
 * Safely removes cache files older than $ttl seconds.
 *
 * Tolerates files that vanish concurrently (another request cleaning up) and
 * optionally stops early once $timeBudgetSeconds has elapsed so it can run
 * opportunistically after a response has been flushed.
 *
 * @param  string     $dir
 * @param  int        $ttl
 * @param  float|null $timeBudgetSeconds
 * @return void
 */
function cleanCacheDir($dir, $ttl, $timeBudgetSeconds = null)
{
    $files = glob(rtrim($dir, "/") . "/*");
    if (!is_array($files)) {
        return;
    }

    $threshold = time() - $ttl;
    $deadline =
        $timeBudgetSeconds !== null
            ? microtime(true) + $timeBudgetSeconds
            : null;

    foreach ($files as $file) {
        if (is_file($file)) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }

        if ($deadline !== null && microtime(true) > $deadline) {
            break;
        }
    }
}

/**
 * Emits an X-Debug-* response header, but only when debug output is enabled
 * via the ICAL2JSON_DEBUG constant. Keeps internal details out of production
 * responses by default.
 *
 * @param  string $name
 * @param  string $value
 * @return void
 */
function debugHeader($name, $value)
{
    if (defined("ICAL2JSON_DEBUG") && ICAL2JSON_DEBUG) {
        header("X-Debug-{$name}: {$value}");
    }
}


/**
 * Transforms a list of parsed ICal events into the API output structure.
 *
 * Events are mapped, sorted ascending by their UTC start timestamp and only
 * then truncated to $maxItems. Sorting before truncating guarantees the
 * chronologically earliest events are the ones returned.
 *
 * @param  array<int, \ICal\Event> $events
 * @param  int                     $maxItems
 * @return array<int, array<string, mixed>>
 */
function transformEvents(array $events, $maxItems)
{
    $events = array_map("mapEvent", $events);

    usort($events, function ($a, $b) {
        return ($a["start"] ?? 0) <=> ($b["start"] ?? 0);
    });

    if ($maxItems >= 0 && count($events) > $maxItems) {
        $events = array_slice($events, 0, $maxItems);
    }

    return $events;
}


