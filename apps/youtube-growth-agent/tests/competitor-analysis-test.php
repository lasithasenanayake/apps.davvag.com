<?php
define("YTG_DERIVED_METRICS_ENABLED", true);
$tenantRoot = dirname(__DIR__, 3);
if (!is_file($tenantRoot . "/plugins/youtube-growth/youtube-growth.php")) { $tenantRoot = dirname(__DIR__, 2); }
define("PLUGIN_PATH_LOCAL", $tenantRoot . "/plugins");

class SOSSData {
    public static function Query($namespace, $query) { return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0); }
    public static function Update($namespace, $value) { return (object)array("success" => true); }
}

require_once PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php";
require_once dirname(__DIR__) . "/services/growth-workbench/service.php";

function expectCompetitor($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . PHP_EOL); exit(1); }
}

$reflection = new ReflectionClass("youtube_growth_agent\\GrowthWorkbenchService");
$service = $reflection->newInstanceWithoutConstructor();
$parse = $reflection->getMethod("parseCompetitorReference"); $parse->setAccessible(true);
$hashtags = $reflection->getMethod("extractHashtags"); $hashtags->setAccessible(true);
$duration = $reflection->getMethod("isoDurationSeconds"); $duration->setAccessible(true);
$analysis = $reflection->getMethod("buildCompetitorAnalysis"); $analysis->setAccessible(true);

$agentMappings = YtgConfig::agentMappings();
foreach (array("TRANSCRIPT", "SEO", "KEYWORD", "TITLE", "DESCRIPTION", "HASHTAG", "PACKAGING", "COMPETITOR", "VIDEO_IDEA", "COMMUNITY", "STRATEGIST") as $task) {
    expectCompetitor(isset($agentMappings[$task]) && preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $agentMappings[$task]), "the " . $task . " task should have a valid saved-agent mapping");
}

$channelId = "UC1234567890123456789012";
expectCompetitor($parse->invoke($service, $channelId)->value === $channelId, "a bare UC channel ID should be accepted");
expectCompetitor($parse->invoke($service, "https://www.youtube.com/channel/" . $channelId)->value === $channelId, "a /channel/ URL should resolve without search");
expectCompetitor($parse->invoke($service, "https://youtube.com/@Example.Channel")->type === "handle", "a handle URL should use channels.list forHandle");
expectCompetitor($parse->invoke($service, "https://youtube.com/c/legacy-name") === null, "an undocumented custom URL should be rejected honestly");
expectCompetitor($hashtags->invoke($service, "Topic #Growth #සිංහල #Growth") === array("#Growth", "#සිංහල"), "hashtags should be Unicode-safe and deduplicated");
expectCompetitor($duration->invoke($service, "PT1H2M3S") === 3723, "ISO duration should be converted to seconds");

$competitors = array((object)array("competitorId" => 1, "label" => "Example", "subscriberCount" => 1000, "subscriberCountHidden" => false));
$videos = array(
    (object)array("competitorVideoId" => 1, "competitorId" => 1, "youtubeVideoId" => "video-fast", "title" => "Fast", "description" => "", "publishedAt" => date("Y-m-d H:i:s", time() - 86400), "views" => 1000, "tags" => array(), "hashtags" => array()),
    (object)array("competitorVideoId" => 2, "competitorId" => 1, "youtubeVideoId" => "video-old", "title" => "Old", "description" => "", "publishedAt" => date("Y-m-d H:i:s", time() - 864000), "views" => 2000, "tags" => array(), "hashtags" => array())
);
$ranked = $analysis->invoke($service, $competitors, $videos, false);
expectCompetitor($ranked->derivedMetricsEnabled === true, "derived calculations should honor the policy flag");
expectCompetitor($ranked->shortlist[0]->youtubeVideoId === "video-fast", "recent velocity should rank above a larger but slower raw view count");
expectCompetitor($ranked->shortlist[0]->viewsToSubscriberRatio === 1.0, "views-to-subscriber ratio should use the public subscriber snapshot");
expectCompetitor($ranked->shortlist[0]->calculationSource === "PRODUCT_CALCULATION", "derived values should be labelled as product calculations");

$growthAiDescriptor = json_decode(file_get_contents(dirname(__DIR__) . "/services/growth-ai/component.json"), true);
expectCompetitor(isset($growthAiDescriptor["serviceHandler"]["methods"]["GenerateCompetitorIdeas"]), "the competitor idea endpoint should be registered");
$growthAiSource = file_get_contents(dirname(__DIR__) . "/services/growth-ai/service.php");
foreach (array("COMPETITOR", "KEYWORD", "TITLE", "DESCRIPTION", "HASHTAG", "VIDEO_IDEA") as $task) {
    expectCompetitor(strpos($growthAiSource, '"' . $task . '"') !== false, "the " . $task . " specialist task should be mapped");
}

$studioPartial = file_get_contents(dirname(__DIR__) . "/components/growth-studio/partial.html");
$studioScript = file_get_contents(dirname(__DIR__) . "/components/growth-studio/script.js");
expectCompetitor(strpos($studioPartial, "JSON.stringify") === false, "AI suggestions should not be printed as raw JSON");
expectCompetitor(strpos($studioPartial, "resultBlocks(") !== false, "AI suggestions should render as organized result blocks");
expectCompetitor(strpos($studioPartial, 'v-html="markdownHtml(') !== false, "plain-text agent output should use the Markdown renderer");
expectCompetitor(strpos($studioScript, "function parseJsonValue") !== false, "string responses should be checked for JSON before rendering");
expectCompetitor(strpos($studioScript, "function escapeHtml") !== false, "Markdown rendering should escape raw HTML before formatting");

$videoSchema = json_decode(file_get_contents($tenantRoot . "/schemas/ytg_competitor_videos.json"), true);
$fields = array_map(function($field) { return $field["fieldName"]; }, $videoSchema["fields"]);
foreach (array("tags", "hashtags", "viewsPerDay", "viewsToSubscriberRatio", "performanceRank", "shortlisted") as $field) {
    expectCompetitor(in_array($field, $fields, true), "competitor video schema should contain " . $field);
}

echo "COMPETITOR_ANALYSIS_OK" . PHP_EOL;
?>
