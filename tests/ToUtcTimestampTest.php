<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization + unit tests for toUtcTimestamp().
 *
 * These lock in the timezone-handling behaviour so later refactors cannot
 * silently change how start/end timestamps are computed.
 */
final class ToUtcTimestampTest extends TestCase
{
    public function testBerlinSummerTimeIsConvertedToUtc(): void
    {
        // 2018-09-28 is CEST (UTC+2) in Europe/Berlin.
        $arr = [["TZID" => "Europe/Berlin"], "20180928T080000"];

        $this->assertSame(
            strtotime("2018-09-28 06:00:00 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testBerlinWinterTimeIsConvertedToUtc(): void
    {
        // 2018-12-20 is CET (UTC+1) in Europe/Berlin.
        $arr = [["TZID" => "Europe/Berlin"], "20181220T080000"];

        $this->assertSame(
            strtotime("2018-12-20 07:00:00 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testTrailingZIsTreatedAsUtc(): void
    {
        $arr = [[], "20180602T122342Z"];

        $this->assertSame(
            strtotime("2018-06-02 12:23:42 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testTrailingZOverridesTzid(): void
    {
        // Even with a TZID present, a trailing Z means the value is UTC.
        $arr = [["TZID" => "Europe/Berlin"], "20180602T122342Z"];

        $this->assertSame(
            strtotime("2018-06-02 12:23:42 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testDateOnlyValueIsMidnightUtc(): void
    {
        $arr = [["VALUE" => "DATE"], "20190323"];

        $this->assertSame(
            strtotime("2019-03-23 00:00:00 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testMissingTimezoneDefaultsToUtc(): void
    {
        $arr = [[], "20200101T000000"];

        $this->assertSame(
            strtotime("2020-01-01 00:00:00 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testInvalidTimezoneFallsBackToUtc(): void
    {
        $arr = [["TZID" => "Not/ARealZone"], "20200101T000000"];

        $this->assertSame(
            strtotime("2020-01-01 00:00:00 UTC"),
            toUtcTimestamp($arr)
        );
    }

    public function testNullInputReturnsNull(): void
    {
        $this->assertNull(toUtcTimestamp(null));
    }

    public function testEmptyArrayReturnsNull(): void
    {
        $this->assertNull(toUtcTimestamp([]));
    }

    public function testUnparseableValueReturnsNull(): void
    {
        $this->assertNull(toUtcTimestamp([[], "not-a-date"]));
    }
}

