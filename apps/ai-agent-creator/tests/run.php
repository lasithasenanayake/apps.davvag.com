<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ai-agent-creator-tests-" . getmypid();
if (!is_dir($testRoot) && !mkdir($testRoot, 0775, true)) {
    fwrite(STDERR, "Unable to create test directory.\n");
    exit(1);
}
define("TENANT_RESOURCE_LOCATION", $testRoot);
require_once dirname(__DIR__) . "/services/creator-api/service.php";

use ai_agent_creator\CreatorService;

class AacTestRequest {
    private $payload;
    public function __construct($payload) { $this->payload = $payload; }
    public function Body() { return $this->payload; }
}

$service = new CreatorService();
$passed = 0;
$failed = 0;

function checkAac($condition, $label) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}\n";
    }
}

function callPrivate($object, $method, array $args = array()) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function body(array $values) {
    return (object)$values;
}

function baseConfigBody($provider, $model) {
    return body(array(
        "provider" => $provider, "model" => $model, "apiKey" => "test-key-not-real",
        "endpoint" => $provider === "ollama" ? "http://localhost:11434/api/chat" : ($provider === "lmstudio" ? "http://localhost:1234/v1/chat/completions" : ""),
        "systemPrompt" => "Answer clearly.", "temperature" => 0.7, "maxTokens" => 2048,
        "streaming" => true, "skills" => "[]"
    ));
}

$catalog = $service->getProviders(null, null);
checkAac($catalog->success === true && isset($catalog->providers["openai"]["fallbackModels"]), "provider catalog is additive and server-owned");
checkAac($catalog->providers["google"]["pricingLastVerified"] === "2026-08-25", "catalog pricing is visibly date-stamped");
checkAac($catalog->providers["other"]["modelDiscovery"]["supported"] === false, "custom fixed contract does not claim discovery");

$preview = $service->postGenerateConfig(new AacTestRequest(baseConfigBody("openai", "gpt-5.6-luna")), null);
checkAac(strpos($preview->json, "test-key-not-real") === false && strpos($preview->yaml, "test-key-not-real") === false, "JSON and YAML previews mask secrets");
checkAac(strpos($preview->json, "********") !== false, "masked preview retains an explicit secret placeholder");
checkAac($preview->config["parameters"]["streaming"] === false, "streaming is truthfully saved false");

$blankPromptBody = baseConfigBody("openai", "gpt-5.6-luna");
$blankPromptBody->systemPrompt = "";
$blankPromptPreview = $service->postGenerateConfig(new AacTestRequest($blankPromptBody), null);
checkAac($blankPromptPreview->success && $blankPromptPreview->config["agent"]["startupPrompt"] === "", "startup/system prompt may be blank");

$openBody = baseConfigBody("openai", "gpt-5.6-luna");
$openBody->modalities = array("input" => array("text", "image"), "output" => array("text"));
$openCreated = callPrivate($service, "buildConfigFromBody", array($openBody, false));
checkAac($openCreated->success && $openCreated->config["provider"]["apiMode"] === "responses", "new curated OpenAI models use Responses API mode");
$imageContent = array(array("type" => "text", "text" => "Describe it"), array("type" => "image", "url" => "data:image/png;base64," . base64_encode("png"), "mimeType" => "image/png", "name" => "tiny.png", "size" => 3));
$openPayload = callPrivate($service, "providerPayload", array($openCreated->config, "Describe it", array(), array(), $imageContent));
checkAac(isset($openPayload["input"][1]["content"][1]["type"]) && $openPayload["input"][1]["content"][1]["type"] === "input_image", "OpenAI image input uses the native multimodal payload");

$googleBody = baseConfigBody("google", "gemini-2.5-flash");
$googleBody->modalities = array("input" => array("text", "image", "audio", "video", "document"), "output" => array("text"));
$googleCreated = callPrivate($service, "buildConfigFromBody", array($googleBody, false));
$googleContent = array(array("type" => "text", "text" => "Review"));
foreach (array("image" => "image/png", "audio" => "audio/mpeg", "video" => "video/mp4", "document" => "application/pdf") as $type => $mime) {
    $googleContent[] = array("type" => $type, "url" => "data:" . $mime . ";base64," . base64_encode($type), "mimeType" => $mime, "name" => $type, "size" => strlen($type));
}
$googlePayload = callPrivate($service, "providerPayload", array($googleCreated->config, "Review", array(), array(), $googleContent));
checkAac(count($googlePayload["contents"][0]["parts"]) === 5 && isset($googlePayload["contents"][0]["parts"][4]["inlineData"]), "Google maps text, image, audio, video, and document parts natively");

$ollamaBody = baseConfigBody("ollama", "gemma4");
$ollamaBody->modalities = array("input" => array("text", "image"), "output" => array("text"));
$ollamaCreated = callPrivate($service, "buildConfigFromBody", array($ollamaBody, false));
$ollamaPayload = callPrivate($service, "providerPayload", array($ollamaCreated->config, "Describe", array(), array(), $imageContent));
checkAac(isset($ollamaPayload["messages"][1]["images"][0]) && strpos($ollamaPayload["messages"][1]["images"][0], "data:") !== 0, "Ollama vision sends the native base64 images array");
$badOllama = callPrivate($service, "validateContentForConfig", array($ollamaCreated->config, array(array("type" => "image", "url" => "https://example.com/a.png"))));
checkAac(is_string($badOllama), "Ollama rejects URL fetching for vision input");

$normal = callPrivate($service, "normalizeRuntimeContent", array($imageContent, "Describe"));
checkAac($normal->success && count($normal->content) === 2, "attachment validation accepts safe supported inline media");
$textOnly = callPrivate($service, "normalizeRuntimeContent", array(array(), "Existing message"));
checkAac($textOnly->success && count($textOnly->content) === 0, "existing text-only requests stay on their original payload path");
$unsafe = callPrivate($service, "normalizeRuntimeContent", array(array(array("type" => "document", "url" => "C:\\secret.txt", "mimeType" => "text/plain")), ""));
checkAac($unsafe->success === false, "attachment validation rejects server filesystem paths");
$privateUrl = callPrivate($service, "normalizeRuntimeContent", array(array(array("type" => "image", "url" => "https://127.0.0.1/a.png", "mimeType" => "image/png")), ""));
checkAac($privateUrl->success === false, "attachment validation rejects private-network references");

$usage = array("inputTokens" => 1000, "cachedTokens" => 200, "outputTokens" => 500);
$cost = callPrivate($service, "calculateUsageCost", array($googleCreated->config, $usage));
checkAac($cost["amount"] === "0.001496", "decimal-safe cost calculation uses uncached, cached, and output tokens");
$customBody = baseConfigBody("other", "vendor-model"); $customBody->endpoint = "https://api.example.com/v1/chat";
$customCreated = callPrivate($service, "buildConfigFromBody", array($customBody, false));
$unknownCost = callPrivate($service, "calculateUsageCost", array($customCreated->config, $usage));
checkAac($unknownCost["status"] === "unavailable" && $unknownCost["amount"] === null, "unknown pricing remains unavailable without false precision");
$customBody->customModelMetadata = json_encode(array("inputModalities" => array("text"), "outputModalities" => array("text"), "maxOutputTokens" => 4096, "pricing" => array("inputPerMillionTokens" => "0.50", "outputPerMillionTokens" => "1.50", "currency" => "USD")));
$manualCreated = callPrivate($service, "buildConfigFromBody", array($customBody, false));
checkAac($manualCreated->success && $manualCreated->config["provider"]["modelInfo"]["pricing"]["status"] === "manual", "custom text models accept validated manual limits and pricing metadata");
$customBody->customModelMetadata = json_encode(array("inputModalities" => array("text", "image"), "outputModalities" => array("text")));
checkAac(callPrivate($service, "buildConfigFromBody", array($customBody, false))->success === false, "custom adapter rejects unimplemented multimodal mappings honestly");

$limitBody = baseConfigBody("openai", "gpt-5.6-luna"); $limitBody->maxTokens = 999999;
$limited = callPrivate($service, "buildConfigFromBody", array($limitBody, false));
checkAac($limited->config["parameters"]["maxTokens"] === 128000, "model maximum output-token limit is enforced");

$legacy = callPrivate($service, "normalizeSavedConfig", array(array("provider" => array("type" => "openai", "model" => "legacy"), "connection" => array("endpoint" => "https://api.openai.com/v1/chat/completions", "auth" => array("apiKey" => "legacy")))));
checkAac($legacy["modalities"]["input"] === array("text") && $legacy["provider"]["apiMode"] === "chat-completions", "older text-only configurations load defensively");
checkAac(callPrivate($service, "validateSavedAgentIdentity", array($legacy))->success === true, "legacy agents without additive identity metadata remain runnable");

$lmDiscovered = callPrivate($service, "discoveredModelEntries", array("lmstudio", array("models" => array(
    array("type" => "embedding", "key" => "embed-only"),
    array("type" => "llm", "key" => "chat-model", "display_name" => "Chat", "max_context_length" => 8192, "capabilities" => array("vision" => false)),
    array("type" => "llm", "key" => "vision-model", "display_name" => "Vision", "capabilities" => array("vision" => true))
))));
checkAac(count($lmDiscovered) === 2 && $lmDiscovered[1]["inputModalities"] === array("text", "image"), "LM Studio discovery filters embeddings and honors reported vision capability");

$maskedError = callPrivate($service, "sanitizeProviderError", array("Authorization: Bearer sk-supersecret123 API key=AIzaSecret123456"));
checkAac(strpos($maskedError, "supersecret") === false && strpos($maskedError, "AIzaSecret") === false, "provider errors mask common credential formats");

$saved = callPrivate($service, "saveAgents", array(array("compat" => array("agentCode" => "compat"))));
$agentFile = $testRoot . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "ai-agent-creator" . DIRECTORY_SEPARATOR . "agents.json";
checkAac($saved && is_array(json_decode((string)file_get_contents($agentFile), true)), "atomic persistence writes valid existing JSON format");
checkAac(!file_exists($agentFile . ".tmp"), "atomic persistence leaves no fixed temporary file");

$ui = (string)file_get_contents(dirname(__DIR__) . "/components/creator-console/script.js");
checkAac(strpos($ui, "var providers = {}") !== false && strpos($ui, "gemini-1.5-pro") === false, "frontend contains no hard-coded provider/model catalog");
checkAac(strpos($ui, "providerDrafts") !== false && strpos($ui, "streaming: false") !== false, "provider credentials are isolated in memory and streaming stays false");
checkAac(strpos($ui, "localStorage") === false && strpos($ui, "sessionStorage") === false, "credentials and drafts are not stored in browser storage");

foreach (glob($agentFile . ".*") ?: array() as $extra) @unlink($extra);
if (file_exists($agentFile)) @unlink($agentFile);
$dataDir = dirname($agentFile); if (is_dir($dataDir)) @rmdir($dataDir);
$parentDir = dirname($dataDir); if (is_dir($parentDir)) @rmdir($parentDir);
if (is_dir($testRoot)) @rmdir($testRoot);

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
