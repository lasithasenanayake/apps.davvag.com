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
$snapshot = $aiReflection->getMethod("agentFailureSnapshot"); $snapshot->setAccessible(true);
$diagnostic = $snapshot->invoke($ai, (object)array("valid" => false, "error" => "Invalid JSON", "rawResponse" => str_repeat("x", 13000)));
expectResearch($diagnostic->validJson === false && $diagnostic->error === "Invalid JSON", "failure diagnostics should expose the AI parsing error");
expectResearch(strlen($diagnostic->rawResponse) === 12000, "failure diagnostics should bound the raw AI response");
$promptMessage = $aiReflection->getMethod("agentPromptMessage"); $promptMessage->setAccessible(true);
$visiblePrompt = $promptMessage->invoke($ai, "Return JSON.", array("selectedOwnedVideoTranscript" => "සිංහල transcript", "competitorVideoIds" => array("19qCPrr5Xhg")));
expectResearch(strpos($visiblePrompt, "AUTHORITATIVE APPLICATION PAYLOAD JSON") !== false, "saved-agent messages should identify the authoritative runtime payload");
expectResearch(strpos($visiblePrompt, "සිංහල transcript") !== false && strpos($visiblePrompt, "19qCPrr5Xhg") !== false, "saved-agent messages should contain the transcript and real competitor IDs visible to the model");
$language = (object)array("name" => "English", "code" => "en", "confidence" => "high", "source" => "owned_video_transcript");
$allowed = array("19qCPrr5Xhg" => true);
$sources = array((object)array("videoId" => "19qCPrr5Xhg", "transcriptStatus" => "unavailable"));
$currentTitle = "Existing uploaded video title";
$ownedTranscript = "The transcript explains topic keyword through a verified public event and describes why the decision matters to viewers. It includes enough original context for evidence-grounded title generation.";
$pair = function($suffix) { return (object)array("title" => "Topic keyword explained for viewers " . $suffix, "angle" => "Distinct angle " . $suffix, "transcriptEvidenceExcerpt" => "The transcript explains topic keyword through a verified public event", "usedKeywords" => array("topic keyword"), "usedHashtags" => array("#topic"), "imagePrompt" => "One clear subject, authentic emotion, cinematic lighting, high contrast, safe negative space, 16:9, no logos, no watermark, no misleading imagery, no unreadable text.", "onImageText" => "Clear idea", "rationale" => "Supported by the transcript and public metadata pattern.", "evidenceVideoIds" => array("19qCPrr5Xhg")); };
$payload = (object)array(
    "keywords" => array((object)array("text" => "topic keyword", "rationale" => "Present in public metadata.", "evidenceVideoIds" => array("19qCPrr5Xhg"), "evidenceType" => "metadata")),
    "hashtags" => array((object)array("text" => "#topic", "rationale" => "Present in public metadata.", "evidenceVideoIds" => array("19qCPrr5Xhg"), "evidenceType" => "metadata")),
    "description" => "Accurate recommended description.", "tags" => array("topic keyword"),
    "titlePromptPairs" => array($pair("A"), $pair("B"), $pair("C")), "limitations" => array("No guaranteed outcome.")
);
$validated = $validate->invoke($ai, $payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript);
expectResearch($validated !== null && count($validated->titlePromptPairs) === 3, "exactly three valid title/prompt pairs should be accepted");
$payloadWithoutTags = clone $payload;
unset($payloadWithoutTags->tags);
$validatedWithoutTags = $validate->invoke($ai, $payloadWithoutTags, $allowed, $language, $sources, $currentTitle, $ownedTranscript);
expectResearch($validatedWithoutTags !== null, "otherwise-valid research should not fail when the AI omits optional tags");
expectResearch(in_array("topic keyword", $validatedWithoutTags->tags, true) && in_array("topic", $validatedWithoutTags->tags, true), "missing tags should be derived from validated ranked keywords and hashtags");
$payload->titlePromptPairs = array($pair("A"), $pair("B"));
$validationErrors = array();
$invalidArgs = array($payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript, &$validationErrors);
expectResearch($validate->invokeArgs($ai, $invalidArgs) === null, "a response with fewer than three title/prompt pairs must be rejected");
expectResearch(in_array("Exactly three titlePromptPairs are required.", $validationErrors, true), "validation should explain the rejected title-pair count");
$payload->titlePromptPairs = array($pair("A"), $pair("B"), $pair("C"));
$payload->titlePromptPairs[0]->title = $currentTitle;
expectResearch($validate->invoke($ai, $payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript) === null, "the current uploaded title must be rejected");
$payload->titlePromptPairs = array($pair("A"), $pair("B"), $pair("C"));
$payload->titlePromptPairs[0]->title = $currentTitle . " - Complete guide";
expectResearch($validate->invoke($ai, $payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript) === null, "a trivial suffix added to the current title must be rejected");
$payload->titlePromptPairs = array($pair("A"), $pair("B"), $pair("C"));
$payload->titlePromptPairs[0]->transcriptEvidenceExcerpt = "This sentence does not exist in the owned transcript";
expectResearch($validate->invoke($ai, $payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript) === null, "uncited transcript grounding must be rejected");
$payload->titlePromptPairs = array($pair("A"), $pair("B"), $pair("C"));
$payload->titlePromptPairs[0]->title = "A compelling title with no ranked term";
expectResearch($validate->invoke($ai, $payload, $allowed, $language, $sources, $currentTitle, $ownedTranscript) === null, "every title must use a ranked keyword naturally");

$appRoot = dirname(__DIR__);
$workbenchDescriptor = json_decode(file_get_contents($appRoot . "/services/growth-workbench/component.json"), true);
foreach (array("GetCompetitorVideoWorkspace", "AddCompetitorVideos", "ImportCompetitorTranscript", "RemoveCompetitorVideo") as $method) { expectResearch(isset($workbenchDescriptor["serviceHandler"]["methods"][$method]), "workbench descriptor should register " . $method); }
$aiDescriptor = json_decode(file_get_contents($appRoot . "/services/growth-ai/component.json"), true);
expectResearch(isset($aiDescriptor["serviceHandler"]["methods"]["GenerateCompetitorVideoResearch"]), "AI descriptor should register competitor video research");
$app = json_decode(file_get_contents($appRoot . "/app.json"), true);
foreach (array("ytg_competitor_transcripts", "ytg_competitor_research") as $schema) { expectResearch(in_array($schema, $app["dependencies"]["schemas"], true), "app should depend on " . $schema); }
$partial = file_get_contents($appRoot . "/components/video-inspector/partial.html");
expectResearch(strpos($partial, "Competitor video research") !== false && strpos($partial, "Research and generate") !== false, "video inspector should expose the competitor research workflow");
$aiSource = file_get_contents($appRoot . "/services/growth-ai/service.php");
expectResearch(strpos($aiSource, "competitor-video-research-v2") !== false, "the transcript-grounded prompt contract should use version 2");
expectResearch(strpos($aiSource, "competitor-video-research-repair-v2") !== false, "invalid primary output should receive one structured repair attempt");
expectResearch(strpos($aiSource, 'agentCode("TITLE", "seo-suggestion-agent")') !== false, "the repair attempt should use the configured title specialist");
expectResearch(strpos($aiSource, '"outputContract" => $this->competitorResearchOutputContract()') !== false, "every research call should include the application-owned output contract");
expectResearch(strpos($aiSource, "deriveResearchTags") !== false, "the application should derive safe tags when the AI omits them");
expectResearch(strpos($aiSource, "Troubleshooting details:") !== false, "failed research should return structured AI response diagnostics");
expectResearch(strpos($aiSource, '"primaryAgent" => $this->agentFailureSnapshot($run)') !== false, "failure diagnostics should include the primary AI response");
expectResearch(strpos($aiSource, '"repairAgent" => $this->agentFailureSnapshot($repairRun)') !== false, "failure diagnostics should include the repair AI response");
expectResearch(strpos($aiSource, '$this->agentPromptMessage($message, $payload)') !== false, "the saved-agent provider message should include the complete application payload");
expectResearch(strpos($aiSource, "No low-quality titles were saved") !== false, "invalid AI title output should fail closed instead of returning generic fallback titles");

echo "COMPETITOR_VIDEO_RESEARCH_OK" . PHP_EOL;
?>
