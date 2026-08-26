<?php
define("PLUGIN_PATH_LOCAL", dirname(__DIR__, 3) . "/plugins");

class SOSSData {
    public static $inserted = array();

    public static function Query($namespace, $query) {
        return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0);
    }

    public static function Insert($namespace, $row) {
        self::$inserted[] = (object)array("namespace" => $namespace, "row" => clone $row);
        return (object)array("success" => true, "result" => (object)array("generatedId" => count(self::$inserted)));
    }

    public static function Update($namespace, $row) {
        self::$inserted[] = (object)array("namespace" => $namespace, "row" => clone $row);
        return (object)array("success" => true);
    }
}

require_once dirname(__DIR__) . "/services/youtube-sync/service.php";

class FakeAnalyticsClient {
    public $requests = array();
    private $singleResponse;

    public function __construct($singleResponse = null) {
        $this->singleResponse = $singleResponse;
    }

    public function analytics($credentialRef, $params) {
        $this->requests[] = $params;
        if ($this->singleResponse !== null) {
            return $this->singleResponse;
        }
        if (isset($params["dimensions"]) && $params["dimensions"] === "day") {
            return $this->response(array("day", "views", "estimatedMinutesWatched", "averageViewDuration", "averageViewPercentage", "subscribersGained", "subscribersLost", "likes", "comments", "shares"), array());
        }
        $startIndex = isset($params["startIndex"]) ? intval($params["startIndex"]) : 1;
        $count = $startIndex === 1 ? 200 : 1;
        $rows = array();
        for ($index = 0; $index < $count; $index++) {
            $number = $startIndex + $index;
            $rows[] = array(sprintf("vid%05d", $number), $number, $number * 1.5, 90.25, 52.5);
        }
        return $this->response(array("video", "views", "estimatedMinutesWatched", "averageViewDuration", "averageViewPercentage"), $rows);
    }

    private function response($headers, $rows) {
        return (object)array(
            "success" => true,
            "error" => "",
            "data" => array(
                "columnHeaders" => array_map(function($name) { return array("name" => $name); }, $headers),
                "rows" => $rows
            )
        );
    }
}

function expectAnalytics($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$reflection = new ReflectionClass("youtube_growth_agent\\YouTubeSyncService");
$service = $reflection->newInstanceWithoutConstructor();

$bulkMethod = $reflection->getMethod("syncAnalytics");
$bulkMethod->setAccessible(true);
$bulkClient = new FakeAnalyticsClient();
$bulk = $bulkMethod->invoke($service, $bulkClient, "credential", "ytg_test_channel_123", 28);

expectAnalytics($bulk->success && $bulk->videoRows === 201, "all paged video rows should be stored");
expectAnalytics($bulk->videoPages === 2, "a full 200-row page should request the next page");
expectAnalytics(count($bulkClient->requests) === 3, "daily plus two video Analytics requests should run");
expectAnalytics($bulkClient->requests[1]["startIndex"] === 1, "video pagination should start at row 1");
expectAnalytics($bulkClient->requests[2]["startIndex"] === 201, "video pagination should continue at row 201");
expectAnalytics($bulkClient->requests[1]["metrics"] === "views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage", "video reports should request the four compatible inspector metrics");

$singleMethod = $reflection->getMethod("syncVideoAnalytics");
$singleMethod->setAccessible(true);
$singleResponse = (object)array(
    "success" => true,
    "error" => "",
    "data" => array(
        "columnHeaders" => array(
            array("name" => "views"),
            array("name" => "estimatedMinutesWatched"),
            array("name" => "averageViewDuration"),
            array("name" => "averageViewPercentage")
        ),
        "rows" => array(array(72, 38.75, 104.5, 41.25))
    )
);
$single = $singleMethod->invoke($service, new FakeAnalyticsClient($singleResponse), "credential", "ytg_test_channel_123", "YxDQpuL3tAM", 28);
$stored = end(SOSSData::$inserted)->row;
expectAnalytics($single->success && $single->stored, "a returned single-video row should be reported as stored");
expectAnalytics($stored->watchMinutes === 38.75, "watch minutes should retain the Analytics value");
expectAnalytics($stored->avgViewDuration === 104.5, "average duration should retain the Analytics value");
expectAnalytics($stored->avgViewPercentage === 41.25, "average percentage should retain the Analytics value");

$failureResponse = (object)array("success" => false, "error" => "Analytics API is not enabled.", "data" => null);
$failure = $singleMethod->invoke($service, new FakeAnalyticsClient($failureResponse), "credential", "ytg_test_channel_123", "YxDQpuL3tAM", 28);
expectAnalytics(!$failure->success && !$failure->stored, "an Analytics API failure must not be reported as a successful refresh");
expectAnalytics($failure->error === "Analytics API is not enabled.", "the actionable API error should be returned to the inspector");

echo "YOUTUBE_ANALYTICS_SYNC_OK" . PHP_EOL;
?>
