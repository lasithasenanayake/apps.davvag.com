<?php
define("PLUGIN_PATH_LOCAL", dirname(__DIR__, 3) . "/plugins");

class SOSSData {
    public static function Query($namespace, $query) {
        return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0);
    }
}

require_once dirname(__DIR__) . "/services/api/service.php";

function expectDashboard($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$reflection = new ReflectionClass("youtube_growth_agent\\ApiService");
$service = $reflection->newInstanceWithoutConstructor();
$dateMethod = $reflection->getMethod("optionalDate");
$dateMethod->setAccessible(true);

expectDashboard($dateMethod->invoke($service, null) === null, "a missing freshness timestamp should stay null");
expectDashboard($dateMethod->invoke($service, "1970-01-01 00:00:00") === null, "the datastore epoch default should render as not yet");
expectDashboard($dateMethod->invoke($service, "2026-08-26 14:33:59") === "2026-08-26 14:33:59", "a real freshness timestamp should be retained");

$partial = file_get_contents(dirname(__DIR__) . "/components/command-centre/partial.html");
$script = file_get_contents(dirname(__DIR__) . "/components/command-centre/script.js");
expectDashboard(strpos($partial, "dashboard.sync&&dashboard.sync.error") !== false, "the last sync error should remain visible on the dashboard");
expectDashboard(strpos($script, "formatAnalyticsNumber") !== false, "missing Analytics rows should use explicit unavailable formatting");
expectDashboard(strpos($script, "load(true)") !== false, "sync results should remain visible while dashboard data reloads");

echo "DASHBOARD_STATE_OK" . PHP_EOL;
?>
