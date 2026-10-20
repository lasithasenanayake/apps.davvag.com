<?php
$tenantRoot = dirname(__DIR__, 3);
if (!is_file($tenantRoot . "/plugins/youtube-growth/youtube-growth.php")) { $tenantRoot = "C:/xampp/htdocs/davvag-core/davvag-core/localhost"; }
define("PLUGIN_PATH_LOCAL", $tenantRoot . "/plugins");

class SOSSData {
    public static function Query($namespace, $query) { return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0); }
    public static function Insert($namespace, $value) { return (object)array("success" => true, "id" => 1); }
    public static function Update($namespace, $value) { return (object)array("success" => true); }
    public static function Delete($namespace, $value) { return (object)array("success" => true); }
}

require_once dirname(__DIR__) . "/services/growth-workbench/service.php";
require_once dirname(__DIR__) . "/services/growth-ai/service.php";

function expectResearch($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . PHP_EOL); exit(1); }
}

$workbenchReflection = new ReflectionClass("youtube_growth_agent\\GrowthWorkbenchService");
$workbench = $workbenchReflection->newInstanceWithoutConstructor();
$parse = $workbenchReflection->getMethod("parseCompetitorVideoReference"); $parse->setAccessible(true);
foreach (array(
    "https://www.youtube.com/watch?v=19qCPrr5Xhg",
    "https://youtu.be/19qCPrr5Xhg?t=10",
    "https://youtube.com/shorts/19qCPrr5Xhg",
    "https://youtube.com/embed/19qCPrr5Xhg"
) as $url) {
    $parsed = $parse->invoke($workbench, $url);
    expectResearch($parsed !== null && $parsed->videoId === "19qCPrr5Xhg", "supported YouTube video URL should normalize to its video ID: " . $url);
}
expectResearch($parse->invoke($workbench, "https://example.com/watch?v=19qCPrr5Xhg") === null, "non-YouTube URLs must be rejected");
expectResearch($parse->invoke($workbench, "https://youtube.com/watch?v=too-short") === null, "invalid video IDs must be rejected");

$aiReflection = new ReflectionClass("youtube_growth_agent\\GrowthAiService");
$ai = $aiReflection->newInstanceWithoutConstructor();
$validate = $aiReflection->getMethod("validateCompetitorVideoResearch"); $validate->setAccessible(true);
$language = (object)array("name" => "English", "code" => "en", "confidence" => "high", "source" => "owned_video_transcript");
$allowed = array("19qCPrr5Xhg" => true);
$sources = array((object)array("videoId" => "19qCPrr5Xhg", "transcriptStatus" => "unavailable"));
$pair = function($suffix) { return (object)array("title" => "Accurate title " . $suffix, "angle" => "Distinct angle " . $suffix, "imagePrompt" => "One clear subject, authentic emotion, cinematic lighting, high contrast, safe negative space, 16:9, no logos, no watermark, no misleading imagery, no unreadable text.", "onImageText" => "Clear idea", "rationale" => "Supported by the public metadata pattern.", "evidenceVideoIds" => array("19qCPrr5Xhg")); };
$payload = (object)array(
    "keywords" => array((object)array("text" => "topic keyword", "rationale" => "Present in public metadata.", "evidenceVideoIds" => array("19qCPrr5Xhg"), "evidenceType" => "metadata")),
    "hashtags" => array((object)array("text" => "#topic", "rationale" => "Present in public metadata.", "evidenceVideoIds" => array("19qCPrr5Xhg"), "evidenceType" => "metadata")),
    "description" => "Accurate recommended description.", "tags" => array("topic keyword"),
    "titlePromptPairs" => array($pair("A"), $pair("B"), $pair("C")), "limitations" => array("No guaranteed outcome.")
);
$validated = $validate->invoke($ai, $payload, $allowed, $language, $sources);
expectResearch($validated !== null && count($validated->titlePromptPairs) === 3, "exactly three valid title/prompt pairs should be accepted");
$payload->titlePromptPairs = array($pair("A"), $pair("B"));
expectResearch($validate->invoke($ai, $payload, $allowed, $language, $sources) === null, "a response with fewer than three title/prompt pairs must be rejected");

$appRoot = dirname(__DIR__);
$workbenchDescriptor = json_decode(file_get_contents($appRoot . "/services/growth-workbench/component.json"), true);
foreach (array("GetCompetitorVideoWorkspace", "AddCompetitorVideos", "ImportCompetitorTranscript", "RemoveCompetitorVideo") as $method) { expectResearch(isset($workbenchDescriptor["serviceHandler"]["methods"][$method]), "workbench descriptor should register " . $method); }
$aiDescriptor = json_decode(file_get_contents($appRoot . "/services/growth-ai/component.json"), true);
expectResearch(isset($aiDescriptor["serviceHandler"]["methods"]["GenerateCompetitorVideoResearch"]), "AI descriptor should register competitor video research");
$app = json_decode(file_get_contents($appRoot . "/app.json"), true);
foreach (array("ytg_competitor_transcripts", "ytg_competitor_research") as $schema) { expectResearch(in_array($schema, $app["dependencies"]["schemas"], true), "app should depend on " . $schema); }
$partial = file_get_contents($appRoot . "/components/video-inspector/partial.html");
expectResearch(strpos($partial, "Competitor video research") !== false && strpos($partial, "Research and generate") !== false, "video inspector should expose the competitor research workflow");

echo "COMPETITOR_VIDEO_RESEARCH_OK" . PHP_EOL;
?>
