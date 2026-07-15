<?php

use ICal\ICal;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/lib.php";

header("Access-Control-Allow-Origin: *");

define("CACHE_DIR", getenv("ICAL2JSON_CACHE_DIR") ?: "/tmp/app_data/cache");
define("CACHE_TTL", 60 * 60);
define("MAX_FILE_SIZE", 1024 * 1024 * 10);

// Server-side only test/deploy seam: when explicitly enabled via environment
// (never via request input) private/reserved target hosts are permitted. This
// keeps the SSRF guard on by default in production while allowing the endpoint
// to be exercised against a local fixture server in tests.
define("ALLOW_PRIVATE_HOSTS", (bool) getenv("ICAL2JSON_ALLOW_PRIVATE_HOSTS"));

// Diagnostic X-Debug-* headers are opt-in and off by default so internal
// details (target url, cache/timing info) are not leaked in production.
define("ICAL2JSON_DEBUG", (bool) getenv("ICAL2JSON_DEBUG"));

if (!isset($_REQUEST["url"])) {
    header("HTTP/1.1 400 Bad Request");
    echo "No url given";
    exit();
}
$url = $_REQUEST["url"];

$validation = validateTargetUrl($url, ALLOW_PRIVATE_HOSTS);
if (!$validation["ok"]) {
    header("HTTP/1.1 " . $validation["code"] . " Bad Request");
    echo $validation["message"];
    exit();
}

$start = isset($_REQUEST["start"]) ? $_REQUEST["start"] : "today";
$end = isset($_REQUEST["end"]) ? $_REQUEST["end"] : "tomorrow";
$maxItems = isset($_REQUEST["maxitems"]) ? intval($_REQUEST["maxitems"]) : 10;
// Never return more than 100, never fewer than 1 item.
$maxItems = max(1, $maxItems);

$completeCacheFile =
    CACHE_DIR .
    "/complete_" .
    md5(json_encode([$url, $start, $end, $maxItems,filemtime(__FILE__)]));

$start = strtotime($start);
$end = strtotime($end);

if (false === $start) {
    header("HTTP/1.1 400 Bad Request");
    echo "Start value is not a valid strtotime() parameter";
    exit();
}
if (false === $end) {
    header("HTTP/1.1 400 Bad Request");
    echo "End value is not a valid strtotime() parameter";
    exit();
}
if ($start > $end) {
    header("HTTP/1.1 400 Bad Request");
    echo "Start value is after end value";
    exit();
}

if ($end - $start > 60 * 60 * 24 * 90) {
    header("HTTP/1.1 400 Bad Request");
    echo "Maximum range is 90 days";
    exit();
}
if ($maxItems > 100) {
    header("HTTP/1.1 400 Bad Request");
    echo "Maximum number of items is 100";
    exit();
}

debugHeader("used-url", $url);
debugHeader("start", $start . " (" . date("Y-m-d", $start) . ")");
debugHeader("end", $end . " (" . date("Y-m-d", $end) . ")");
debugHeader("max-items", (string) $maxItems);

if (!is_dir(CACHE_DIR)) {
    if (!@mkdir(CACHE_DIR, 0700, true) && !is_dir(CACHE_DIR)) {
        header("HTTP/1.1 500 Internal Server Error");
        echo "Could not create cache directory";
        exit();
    }
}

$urlCacheFile = CACHE_DIR . "/url_" . md5($url);
debugHeader(
    "Cache-File-data",
    json_encode([$url, $start, $end, $maxItems], JSON_UNESCAPED_SLASHES)
);
if (
    file_exists($completeCacheFile) &&
    filemtime($completeCacheFile) > time() - CACHE_TTL
) {
    header("Content-Type: application/json");
    debugHeader("Cache-Hit", "complete");
    readfile($completeCacheFile);

    // Flush the response first, then opportunistically clean the cache within
    // a small time budget without holding the client connection open.
    if (function_exists("fastcgi_finish_request")) {
        fastcgi_finish_request();
    }
    cleanCacheDir(CACHE_DIR, CACHE_TTL, 0.5);

    exit();
}

if (
    !file_exists($urlCacheFile) ||
    filemtime($urlCacheFile) < time() - CACHE_TTL
) {
    $dlStart = microtime(true);
    $size = 0;

    // Do not follow redirects: a redirect could point at an internal host and
    // bypass the SSRF validation performed above. Also bound the request time.
    $streamContext = stream_context_create([
        "http" => [
            "follow_location" => 0,
            "max_redirects" => 0,
            "timeout" => 20,
            "ignore_errors" => false,
        ],
        "https" => [
            "follow_location" => 0,
            "max_redirects" => 0,
            "timeout" => 20,
        ],
    ]);

    $fp_in = @fopen($url, "r", false, $streamContext);
    if ($fp_in === false) {
        header("HTTP/1.1 502 Bad Gateway");
        echo "Could not fetch the given url";
        exit();
    }

    $fp_out = @fopen($urlCacheFile, "w");
    if ($fp_out === false) {
        fclose($fp_in);
        header("HTTP/1.1 500 Internal Server Error");
        echo "Could not write cache file";
        exit();
    }

    while (!feof($fp_in)) {
        $chunk = fread($fp_in, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        $size += fwrite($fp_out, $chunk);
        if ($size > MAX_FILE_SIZE) {
            header("HTTP/1.1 400 Bad Request");
            echo "File is too big";
            fclose($fp_in);
            fclose($fp_out);
            unlink($urlCacheFile);
            exit();
        }
    }
    fclose($fp_in);
    fclose($fp_out);

    // A completely empty download is almost certainly an upstream error; do not
    // cache it as if it were a valid (empty) calendar.
    if ($size === 0) {
        unlink($urlCacheFile);
        header("HTTP/1.1 502 Bad Gateway");
        echo "The given url returned no data";
        exit();
    }

    $dlEnd = microtime(true);
    $dlTime = round($dlEnd - $dlStart, 3);
    debugHeader("Download-Time", "$dlTime s");
    $prefixes = ["B", "KB", "MB", "GB", "TB", "PB"];
    $prefix = array_shift($prefixes);
    while ($size > 1500) {
        $prefix = array_shift($prefixes);
        $size = $size / 1024;
    }
    $hsize = round($size, 3) . " " . $prefix;
    debugHeader("Download-Size", $hsize);
} else {
    debugHeader("Cache-Hit", "url");
}

try {
    $parseStart = microtime(true);
    $ical = new ICal($urlCacheFile, [
        "defaultTimeZone" => "UTC",
    ]);
    $events = $ical->eventsFromRange(
        date("Y-m-d H:i:s", $start),
        date("Y-m-d H:i:s", $end)
    );
    $parseEnd = microtime(true);
    $parseTime = round($parseEnd - $parseStart, 3);
    debugHeader("Parse-Time", "$parseTime s");

    $events = transformEvents($events, $maxItems);
} catch (\Throwable $e) {
    error_log("ical2json: failed to parse calendar: " . $e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo "Error parsing ical file";
    exit();
}

$data = json_encode(
    $events,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
file_put_contents($completeCacheFile, $data);
header("Content-Type: application/json");
echo $data;

// Flush the response before cleaning up so the client is not kept waiting.
if (function_exists("fastcgi_finish_request")) {
    fastcgi_finish_request();
}
cleanCacheDir(CACHE_DIR, CACHE_TTL);

