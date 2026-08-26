<?php
define("TENANT_RESOURCE_LOCATION", __DIR__ . "/fixtures/no-tenant");
define("PLUGIN_PATH_LOCAL", dirname(__DIR__, 3) . "/plugins");
define("GROUPID", "sysadmin");

$_SERVER["HTTPS"] = "on";
$_SERVER["HTTP_HOST"] = "www.example.com";
$_SERVER["REQUEST_URI"] = "/components/youtube-growth-agent/api/service/GetConfiguration";
$_SERVER["SCRIPT_NAME"] = "/components/index.php";
$_SERVER["SERVER_PORT"] = 443;

class SOSSData {
    public static $updates = array();

    public static function Query($namespace, $query) {
        if ($namespace === "ytg_channels") {
            return (object)array("success" => true, "result" => array((object)array(
                "channelId" => "ytg_test_channel_123",
                "status" => "Connected",
                "lastAnalyticsSyncAt" => "2026-08-26 14:33:59"
            )), "numberOfRecords" => 1);
        }
        if ($namespace === "ytg_sync_jobs") {
            return (object)array("success" => true, "result" => array((object)array(
                "jobId" => 42,
                "status" => "Completed"
            )), "numberOfRecords" => 1);
        }
        return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0);
    }

    public static function Insert($namespace, $row) {
        return (object)array("success" => true, "result" => (object)array("generatedId" => 7));
    }

    public static function Update($namespace, $row) {
        self::$updates[] = (object)array("namespace" => $namespace, "row" => clone $row);
        return (object)array("success" => true);
    }
}

require_once dirname(__DIR__) . "/services/youtube-sync/service.php";

function expectCron($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

expectCron(\YtgConfig::dailyCronUrl() === "https://www.example.com/components/youtube-growth-agent/youtube-sync/service/RunDailyCron", "the Settings cron URL should target the application service method");

$service = new \youtube_growth_agent\YouTubeSyncService();
$result = $service->runDailyCron();
$record = end(SOSSData::$updates)->row;
expectCron($result->success && $result->status === "Completed", "an all-skipped invocation should complete successfully");
expectCron($record->totalChannels === 1 && $record->skippedChannels === 1, "today's completed channel should be recorded as skipped");
expectCron($record->completedChannels === 0 && $record->pendingChannels === 0 && $record->failedChannels === 0, "a skipped channel must not run analysis again");

$serviceSource = file_get_contents(dirname(__DIR__) . "/services/youtube-sync/service.php");
$descriptor = json_decode(file_get_contents(dirname(__DIR__) . "/services/youtube-sync/component.json"));
expectCron(isset($descriptor->serviceHandler->methods->RunDailyCron) && $descriptor->serviceHandler->methods->RunDailyCron->method === "GET", "the daily cron must be registered as a GET service method");
expectCron(strpos($serviceSource, "function getRunDailyCron") !== false, "the cron endpoint must be implemented in the youtube-sync service");
expectCron(strpos($serviceSource, "echo \"done\"") !== false, "the only successful service response should be done");
expectCron(strpos($serviceSource, "http_response_code(500)") !== false, "internal failures should return an empty error response");

echo "CRON_SERVICE_OK" . PHP_EOL;
?>
