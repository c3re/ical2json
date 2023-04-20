<?php
header("Access-Control-Allow-Origin: *");
use ICal\Event;
use ICal\ICal;

define("CACHE_DIR", "/tmp/app_data/cache");
define("CACHE_TTL", 60 * 60);
define("MAX_FILE_SIZE", 1024 * 1024 * 10);

if (!isset($_REQUEST["url"])) {
    header("HTTP/1.1 400 Bad Request");
    echo "No url given";
    exit();
}
$url = $_REQUEST["url"];
$parsedUrl = parse_url($url);
$host = $parsedUrl["host"];
$hostIp = gethostbyname($host);
if (
    !filter_var(
        $hostIp,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    )
) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

if (0 !== strpos($url, "http")) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

$start = isset($_REQUEST["start"]) ? $_REQUEST["start"] : "today";
$end = isset($_REQUEST["end"]) ? $_REQUEST["end"] : "tomorrow";
$maxItems = isset($_REQUEST["maxitems"]) ? intval($_REQUEST["maxitems"]) : 10;

$completeCacheFile =
    CACHE_DIR .
    "/complete_" .
    md5(json_encode([$url, $start, $end, $maxItems]));

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

header("X-Debug-used-url: $url");
header("X-Debug-start: $start (" . date("Y-m-d", $start) . ")");
header("X-Debug-end: $end (" . date("Y-m-d", $end) . ")");
header("X-Debug-max-items: $maxItems");

require_once __DIR__ . "/../vendor/autoload.php";

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}

$urlCacheFile = CACHE_DIR . "/url_" . md5($url);
header(
    "X-Debug-Cache-File-data: " .
        json_encode([$url, $start, $end, $maxItems], JSON_UNESCAPED_SLASHES)
);
if (
    file_exists($completeCacheFile) &&
    filemtime($completeCacheFile) > time() - CACHE_TTL
) {
    header("Content-Type: application/json");
    header("X-Debug-Cache-Hit: complete");
    readfile($completeCacheFile);
    //use fast response time to allow cache cleaning for 500ms
    $cacheCleanEnd = microtime(true) + 0.5;
    $cacheFiles = glob(CACHE_DIR . "/*");
    do {
        $cacheFile = array_shift($cacheFiles);
        if (filemtime($cacheFile) < time() - CACHE_TTL) {
            unlink($cacheFile);
        }
    } while (microtime(true) <= $cacheCleanEnd && count($cacheFiles) > 0);

    exit();
}

if (
    !file_exists($urlCacheFile) ||
    filemtime($urlCacheFile) < time() - CACHE_TTL
) {
    $dlStart = microtime(true);
    $size = 0;
    $fp_in = fopen($url, "r");
    $fp_out = fopen($urlCacheFile, "w");
    while ($fp_in && !feof($fp_in)) {
        $size += fwrite($fp_out, fread($fp_in, 1024 * 1024));
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
    $dlEnd = microtime(true);
    $dlTime = round($dlEnd - $dlStart, 3);
    header("X-Debug-Download-Time: $dlTime s");
    $prefixes = ["B", "KB", "MB", "GB", "TB", "PB"];
    $prefix = array_shift($prefixes);
    while ($size > 1500) {
        $prefix = array_shift($prefixes);
        $size = $size / 1024;
    }
    $hsize = round($size, 3) . " " . $prefix;
    header("X-Debug-Download-Size: $hsize");
} else {
    header("X-Debug-Cache-Hit: url");
}

$errors = false;

try {
    $parseStart = microtime(true);
    $ical = new ICal($urlCacheFile, [
        "defaultTimeZone" => "UTC",
    ]);
    $events = $ical->eventsFromRange(
        date("Y-m-d", strtotime("today")),
        date("Y-m-d", strtotime("today + 90 days"))
    );
    $parseEnd = microtime(true);
    $parseTime = round($parseEnd - $parseStart, 3);
    header("X-Debug-Parse-Time: $parseTime s");

    while (count($events) > $maxItems) {
        array_pop($events);
    }

    $events = array_map(function (Event $event) {
        $r = [];
        @$r["summary"] = $event->summary;
        @$r["description"] = $event->description;
        @$r["location"] = $event->location;
        @$r["start"] = $event->dtstart_array[2];
        @$r["end"] = $event->dtend_array[2];
        @$r["duration"] = $event->duration;
        @$r["url"] = $event->url;
        @$r["status"] = $event->status;
        return $r;
    }, $events);
    usort($events, function ($a, $b) {
        return $a["start"] - $b["start"];
    });
} catch (\Exception $e) {
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

foreach (glob(CACHE_DIR . "/*") as $file) {
    if (filemtime($file) < time() - CACHE_TTL) {
        unlink($file);
    }
}
