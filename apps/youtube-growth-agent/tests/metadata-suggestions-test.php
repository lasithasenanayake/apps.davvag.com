<?php
$tenantRoot = dirname(__DIR__, 3);
if (!is_file($tenantRoot . "/plugins/youtube-growth/youtube-growth.php")) { $tenantRoot = "C:/xampp/htdocs/davvag-core/davvag-core/localhost"; }
define("PLUGIN_PATH_LOCAL", $tenantRoot . "/plugins");

class SOSSData {
    public static function Query($namespace, $query) { return (object)array("success" => true, "result" => array(), "numberOfRecords" => 0); }
    public static function Insert($namespace, $value) { return (object)array("success" => true, "result" => (object)array("generatedId" => 1)); }
    public static function Update($namespace, $value) { return (object)array("success" => true); }
    public static function Delete($namespace, $value) { return (object)array("success" => true); }
}

require_once dirname(__DIR__) . "/services/growth-ai/service.php";
require_once dirname(__DIR__) . "/services/metadata-workflow/service.php";

function expectMetadata($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: " . $message . PHP_EOL); exit(1); } }
function privateMethod($reflection, $name) { $method = $reflection->getMethod($name); $method->setAccessible(true); return $method; }

$aiReflection = new ReflectionClass("youtube_growth_agent\\GrowthAiService");
$ai = $aiReflection->newInstanceWithoutConstructor();
expectMetadata($aiReflection->getConstant("METADATA_SUMMARY_THRESHOLD") === 5000, "the dedicated summary threshold must be exactly 5,000 characters");

$chunks = privateMethod($aiReflection, "metadataTranscriptChunks")->invoke($ai, str_repeat("fallback ", 4000), array(
    (object)array("startMs" => 0, "text" => str_repeat("opening ", 1000)),
    (object)array("startMs" => 15000, "text" => str_repeat("middle ", 1000)),
    (object)array("startMs" => 30000, "text" => str_repeat("ending ", 1000))
), 12000);
expectMetadata(count($chunks) >= 2, "transcripts beyond a model-sized chunk should be split");
expectMetadata(strpos(implode("\n", $chunks), "[startMs=15000]") !== false, "chunking must preserve source timestamp markers");

$summaryValidator = privateMethod($aiReflection, "validateMetadataSummary");
$summaryData = (object)array("mainSubject" => "Subject", "importantFacts" => array("Fact"), "keyTopics" => array("Topic"), "terminology" => array("Term"), "timestampedSubtopics" => array((object)array("startMs" => 15000, "topic" => "Topic", "summary" => "Summary")), "untimedTopicOutline" => array(), "limitations" => array());
$validSummary = $summaryValidator->invoke($ai, $summaryData, array("15000" => true), true);
expectMetadata($validSummary !== null && $validSummary->timestampedSubtopics[0]->startMs === 15000, "valid source timestamps should survive summary validation unchanged");
$summaryData->timestampedSubtopics[0]->startMs = 14999;
expectMetadata($summaryValidator->invoke($ai, $summaryData, array("15000" => true), true) === null, "invented or rounded summary timestamps must be rejected");

$competitorContext = privateMethod($aiReflection, "metadataCompetitorContext")->invoke($ai, (object)array("channel" => (object)array("channelId" => "ytg_abcdefghijkl"), "video" => (object)array("youtubeVideoId" => "video123")));
expectMetadata(count($competitorContext->videos) === 0 && strpos($competitorContext->limitations[0], "No competitor videos") !== false, "missing competitor data should be explicit and should not block generation");

$suggestionData = (object)array(
    "titlePromptPairs" => array(
        (object)array("title" => "Accurate title one", "thumbnailPrompt" => str_repeat("Detailed visual composition and subject. ", 4), "optionalThumbnailText" => "Clear idea"),
        (object)array("title" => "Accurate title two", "thumbnailPrompt" => str_repeat("Detailed visual composition and subject. ", 4), "optionalThumbnailText" => "Second idea"),
        (object)array("title" => "Accurate title three", "thumbnailPrompt" => str_repeat("Detailed visual composition and subject. ", 4), "optionalThumbnailText" => "Third idea")
    ),
    "description" => "A strong opening followed by a concise and accurate overview.",
    "chapters" => array((object)array("startMs" => 15000, "title" => "Main topic")), "untimedTopicOutline" => array(),
    "keywords" => array("main topic"), "hashtags" => array("#maintopic"),
    "competitorFindings" => (object)array("searchTerms" => array(), "topicOverlap" => array(), "titlePatterns" => array(), "unavailableInformation" => array()), "limitations" => array()
);
$errors = array(); $args = array($suggestionData, array("15000" => true), true, &$errors);
$validatedSuggestion = privateMethod($aiReflection, "validateMetadataSuggestions")->invokeArgs($ai, $args);
expectMetadata($validatedSuggestion !== null && $validatedSuggestion->chapters[0]->startMs === 15000, "suggestion chapters should retain validated transcript timestamps");
$suggestionData->chapters[0]->startMs = 14999; $errors = array(); $args = array($suggestionData, array("15000" => true), true, &$errors);
expectMetadata(privateMethod($aiReflection, "validateMetadataSuggestions")->invokeArgs($ai, $args) === null, "suggestions with invented chapter timestamps must be rejected");

$workflowReflection = new ReflectionClass("youtube_growth_agent\\MetadataWorkflowService");
$workflow = $workflowReflection->newInstanceWithoutConstructor();
$finalValues = privateMethod($workflowReflection, "validateFinalValues")->invoke($workflow, (object)array("title" => "Edited title", "thumbnailPrompt" => "Edited detailed prompt", "description" => "Edited description", "chapters" => "0:00 Opening", "keywords" => array("edited tag"), "hashtags" => array("#edited")));
expectMetadata($finalValues !== null && $finalValues->title === "Edited title" && $finalValues->chapters === "0:00 Opening", "final edited values should be retained for immutable persistence");

$aggregate = privateMethod($workflowReflection, "aggregateMetricRows")->invoke($workflow,
    array((object)array("date" => "2026-10-01", "views" => 100, "watchMinutes" => 300, "avgViewDuration" => 180)),
    array((object)array("date" => "2026-10-01", "impressions" => 1000, "impressionsCtr" => 5.0)), "2026-10-01", "2026-10-01");
expectMetadata($aggregate->metrics->views === 100 && $aggregate->metrics->impressions === 1000, "performance periods should aggregate available analytics and reach metrics");
$after = clone $aggregate; $after->metrics = clone $aggregate->metrics; $after->metrics->views = 150; $after->calendarDays = 1;
$comparison = privateMethod($workflowReflection, "comparePeriods")->invoke($workflow, $aggregate, $after, 1, null);
expectMetadata($comparison->metrics->views->absoluteChange === 50.0 && $comparison->metrics->views->percentageChange === 50.0, "performance comparison should expose absolute and percentage changes");
$after->metrics->views = 10; $zero = clone $aggregate; $zero->metrics = clone $aggregate->metrics; $zero->metrics->views = 0;
$zeroComparison = privateMethod($workflowReflection, "comparePeriods")->invoke($workflow, $zero, $after, 1, (object)array("applicationId" => 2, "appliedAt" => "2026-10-03 10:00:00"));
expectMetadata($zeroComparison->metrics->views->percentageChange === null && $zeroComparison->metrics->views->status === "ZERO_BASELINE", "zero baselines should not fabricate percentages");
expectMetadata($zeroComparison->subsequentApplication->applicationId === 2, "subsequent applications should be visible in comparisons");

$appRoot = dirname(__DIR__);
$app = json_decode(file_get_contents($appRoot . "/app.json"), true);
foreach (array("ytg_video_summaries", "ytg_metadata_suggestions", "ytg_metadata_applications") as $schema) { expectMetadata(in_array($schema, $app["dependencies"]["schemas"], true), "app should depend on " . $schema); }
expectMetadata(in_array("metadata-workflow", $app["configuration"]["webdock"]["onLoad"], true), "metadata workflow service should load with the app");
$workflowDescriptor = json_decode(file_get_contents($appRoot . "/services/metadata-workflow/component.json"), true);
foreach (array("GetMetadataWorkspace", "MarkMetadataApplied") as $method) { expectMetadata(isset($workflowDescriptor["serviceHandler"]["methods"][$method]), "metadata workflow descriptor should register " . $method); }
$aiDescriptor = json_decode(file_get_contents($appRoot . "/services/growth-ai/component.json"), true);
expectMetadata(isset($aiDescriptor["serviceHandler"]["methods"]["GenerateMetadataSuggestions"]), "AI descriptor should register metadata suggestion generation");
$partial = file_get_contents($appRoot . "/components/video-inspector/partial.html");
expectMetadata(strpos($partial, "Mark edited values as applied") !== false && strpos($partial, "Application history and observed performance") !== false, "the inspector should expose editing, application, and history UI");
$workflowSource = file_get_contents($appRoot . "/services/metadata-workflow/service.php");
expectMetadata(strpos($workflowSource, "MARKED_AS_APPLIED") !== false && strpos($workflowSource, "YouTube was not updated") !== false, "read-only application records must not imply a YouTube update");

echo "METADATA_SUGGESTIONS_OK" . PHP_EOL;
?>
