<?php
define("TENANT_RESOURCE_LOCATION", __DIR__ . "/fixtures/no-tenant");
define("PLUGIN_PATH_LOCAL", dirname(__DIR__, 3) . "/plugins");
define("YTG_ENCRYPTION_KEY", "test-cron-encryption-key-with-at-least-32-characters");
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

$expectedToken = hash_hmac("sha256", "youtube-growth-agent:daily-cron:v1", YTG_ENCRYPTION_KEY);
expectCron(\YtgConfig::cronToken() === $expectedToken, "the cron token should be a deterministic HMAC of the protected encryption key");
expectCron(\YtgConfig::dailyCronUrl() === "https://www.example.com/youtube-growth-agent-cron.php?token=" . $expectedToken, "the Settings cron URL should target the public root endpoint");

$service = new \youtube_growth_agent\YouTubeSyncService();
$result = $service->runDailyCron();
$record = end(SOSSData::$updates)->row;
expectCron($result->success && $result->status === "Completed", "an all-skipped invocation should complete successfully");
expectCron($record->totalChannels === 1 && $record->skippedChannels === 1, "today's completed channel should be recorded as skipped");
expectCron($record->completedChannels === 0 && $record->pendingChannels === 0 && $record->failedChannels === 0, "a skipped channel must not run analysis again");

$endpoint = file_get_contents(dirname(__DIR__, 4) . "/davvag-core/youtube-growth-agent-cron.php");
expectCron(strpos($endpoint, "hash_equals") !== false, "the public endpoint must compare its token in constant time");
expectCron(strpos($endpoint, "ytgCronFinish(200, \"done\")") !== false, "the only successful public response should be done");
expectCron(strpos($endpoint, "ytgCronFinish(500)") !== false, "internal failures should return an empty error response");

echo "CRON_SERVICE_OK" . PHP_EOL;
?>
