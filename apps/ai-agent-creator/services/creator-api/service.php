<?php
namespace ai_agent_creator;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) {
        require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    }
    if (file_exists(PLUGIN_PATH . "/phpcache/cache.php")) {
        require_once(PLUGIN_PATH . "/phpcache/cache.php");
    }
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) {
        require_once(PLUGIN_PATH . "/auth/auth.php");
    }
}

class CreatorService {
    public function getProviders($req, $res) {
        $out = $this->ok();
        $out->providers = $this->providerMap();
        return $out;
    }

    public function getListAgents($req, $res) {
        $out = $this->ok();
        $out->agents = array_values($this->safeAgentsForClient($this->loadAgents()));
        return $out;
    }

    public function postGenerateConfig($req, $res) {
        $created = $this->buildConfigFromBody($this->body($req), false);
        if (!$created->success) {
            return $created;
        }

        $out = $this->ok();
        // Configuration previews are client-visible and must never disclose credentials.
        $safeConfig = $this->maskSecrets($created->config);
        $out->config = $safeConfig;
        $out->json = json_encode($safeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $out->yaml = $this->toYaml($safeConfig);
        return $out;
    }

    public function postSaveAgent($req, $res) {
        $body = $this->body($req);
        $agentCodeForExisting = $this->normalizeAgentCode($this->stringValue($body, "agentCode", ""));
        $agents = $this->loadAgents();

        if ($agentCodeForExisting !== "" && isset($agents[$agentCodeForExisting]) && isset($agents[$agentCodeForExisting]["configuration"])) {
            $incomingKey = $this->stringValue($body, "apiKey", "");
            $existingProvider = isset($agents[$agentCodeForExisting]["configuration"]["provider"]["type"])
                ? (string)$agents[$agentCodeForExisting]["configuration"]["provider"]["type"] : "";
            $incomingProvider = strtolower($this->stringValue($body, "provider", "openai"));
            if ($existingProvider === $incomingProvider && ($incomingKey === "" || $incomingKey === "********")) {
                $existingKey = $this->apiKeyFromConfig($agents[$agentCodeForExisting]["configuration"]);
                if ($existingKey !== "") {
                    $body->apiKey = $existingKey;
                }
            }
            $incomingHeader = $this->stringValue($body, "authHeader", "");
            if ($existingProvider === $incomingProvider && ($incomingHeader === "" || $incomingHeader === "********") && isset($agents[$agentCodeForExisting]["configuration"]["connection"]["auth"]["header"])) {
                $body->authHeader = $agents[$agentCodeForExisting]["configuration"]["connection"]["auth"]["header"];
            }
        }

        $created = $this->buildConfigFromBody($body, true);
        if (!$created->success) {
            return $created;
        }

        $agentCode = $created->agentCode;
        $existing = isset($agents[$agentCode]) && is_array($agents[$agentCode]) ? $agents[$agentCode] : array();
        $identity = $this->ensureAgentSystemIdentity($body, $created, $existing);
        if (!$identity->success) {
            return $identity;
        }
        $created->config = $this->attachSystemIdentityToConfig($created->config, $identity);

        $now = gmdate("c");

        $agent = array(
            "agentCode" => $agentCode,
            "name" => $created->agentName,
            "description" => $created->description,
            "capabilities" => $created->capabilities,
            "skills" => $created->skills,
            "configuration" => $created->config,
            "profileId" => $identity->profileId,
            "userId" => $identity->userId,
            "workflow" => array(
                "appCode" => "ai-agent-creator",
                "componentCode" => "creator-api",
                "method" => "RunAgent",
                "methodType" => "post",
                "input" => array(
                    "agentCode" => $agentCode,
                    "message" => "scopData.inputData.message",
                    "profile" => "scopData.inputData.profile",
                    "sessionId" => "scopData.inputData.sessionId"
                )
            ),
            "createdAt" => isset($existing["createdAt"]) ? $existing["createdAt"] : $now,
            "updatedAt" => $now
        );

        $agents[$agentCode] = $agent;
        if (!$this->saveAgents($agents)) {
            return $this->fail("Unable to save the agent configuration.");
        }

        $out = $this->ok();
        $out->agent = $this->safeAgentForClient($agent);
        $out->agents = array_values($this->safeAgentsForClient($agents));
        return $out;
    }

    public function postDeleteAgent($req, $res) {
        $body = $this->body($req);
        $agentCode = $this->normalizeAgentCode($this->stringValue($body, "agentCode", ""));
        if ($agentCode === "") {
            return $this->fail("Agent code is required.");
        }

        $agents = $this->loadAgents();
        if (isset($agents[$agentCode])) {
            unset($agents[$agentCode]);
        }

        if (!$this->saveAgents($agents)) {
            return $this->fail("Unable to delete the saved agent.");
        }

        $out = $this->ok();
        $out->agents = array_values($this->safeAgentsForClient($agents));
        return $out;
    }

    public function postTestAgent($req, $res) {
        $body = $this->body($req);
        $agentCode = $this->normalizeAgentCode($this->stringValue($body, "agentCode", ""));
        $message = $this->stringValue($body, "message", "");
        $profileId = $this->normalizeProfileId($this->stringValue($body, "profileId", "creator-console"));

        if ($agentCode === "") {
            return $this->fail("Select a saved agent before testing.");
        }
        $content = isset($body->content) ? $body->content : array();
        $testSessionId = $this->normalizeSessionId($this->stringValue($body, "sessionId", ""));
        if ($testSessionId === "") {
            $testSessionId = "creator-console-" . ($profileId === "" ? "default" : $profileId);
        }
        if ($message === "" && empty($content)) {
            return $this->fail("A test message or attachment is required.");
        }

        return $this->runAgent(array(
            "agentCode" => $agentCode,
            "message" => $message,
            "content" => $content,
            "profile" => array(
                "profileId" => $profileId === "" ? "creator-console" : $profileId,
                "externalId" => "creator-console",
                "connectorCode" => "creator-console"
            ),
            "sessionId" => $testSessionId,
            "flow" => array("flowCode" => "creator-console", "name" => "Creator Console"),
            "connector" => array("code" => "creator-console", "label" => "Creator Console"),
            "payload" => array("source" => "creator-console")
        ));
    }

    public function postRunAgent($req, $res) {
        return $this->runAgent($this->body($req));
    }

    public function postInteractWithAgent($req, $res) {
        return $this->interactWithAgent($this->body($req));
    }

    public function postClearSession($req, $res) {
        $body = $this->objectToArray($this->body($req));
        $agentCode = $this->normalizeAgentCode($this->arrayString($body, "agentCode", ""));
        $profileId = $this->normalizeProfileId($this->arrayString($body, "profileId", ""));
        $sessionId = $this->normalizeSessionId($this->arrayString($body, "sessionId", ""));

        if ($agentCode === "" || $profileId === "") {
            return $this->fail("Agent code and profile ID are required to clear a session.");
        }

        if ($sessionId === "") {
            $sessionId = $this->defaultSessionId($agentCode, $profileId);
        }

        $sessions = $this->loadSessions();
        $key = $this->sessionKey($agentCode, $profileId, $sessionId);
        if (isset($sessions[$key])) {
            unset($sessions[$key]);
        }

        if (!$this->saveSessions($sessions)) {
            return $this->fail("Unable to clear the agent session.");
        }

        $out = $this->ok();
        $out->cleared = true;
        return $out;
    }

    public function interactWithAgent($input) {
        $body = $this->objectToArray($input);
        $agentCode = $this->normalizeAgentCode($this->arrayString($body, "agentCode", ""));
        $message = $this->interactionMessage($body);
        $appCode = $this->normalizeContextCode($this->arrayString($body, "appCode", "davvag-app"), "davvag-app");
        $appName = $this->arrayString($body, "appName", $appCode);
        $conversationKey = $this->normalizeSessionId($this->arrayString($body, "conversationKey", ""));
        if ($conversationKey === "") {
            $conversationKey = $this->normalizeSessionId($this->arrayString($body, "conversationId", ""));
        }

        $profile = isset($body["profile"]) ? $this->objectToArray($body["profile"]) : array();
        $profileId = $this->normalizeProfileId($this->arrayString($profile, "profileId", ""));
        if ($profileId === "") {
            $profileId = $this->normalizeProfileId($this->arrayString($body, "profileId", ""));
        }
        if ($profileId !== "") {
            $profile["profileId"] = $profileId;
        }
        if (!isset($profile["sourceApp"])) {
            $profile["sourceApp"] = $appCode;
        }

        $sessionId = $this->normalizeSessionId($this->arrayString($body, "sessionId", ""));
        if ($sessionId === "" && $conversationKey !== "" && $agentCode !== "") {
            $sessionId = $this->appSessionId($appCode, $agentCode, $profileId, $conversationKey);
        }

        $context = isset($body["context"]) ? $this->objectToArray($body["context"]) : array();
        $payload = isset($body["payload"]) ? $this->objectToArray($body["payload"]) : array();

        $runInput = array(
            "agentCode" => $agentCode,
            "message" => $message,
            "content" => isset($body["content"]) ? $body["content"] : array(),
            "profile" => $profile,
            "sessionId" => $sessionId,
            "flow" => isset($body["flow"]) ? $this->objectToArray($body["flow"]) : array(
                "flowCode" => $appCode,
                "name" => $appName,
                "source" => "app-service"
            ),
            "connector" => isset($body["connector"]) ? $this->objectToArray($body["connector"]) : array(
                "code" => "davvag-app",
                "label" => "DAVVAG App",
                "appCode" => $appCode
            ),
            "payload" => array(
                "source" => "app-service",
                "appCode" => $appCode,
                "appName" => $appName,
                "conversationKey" => $conversationKey,
                "context" => $context,
                "data" => $payload
            )
        );

        $result = $this->runAgent($runInput);
        if (!$result->success) {
            return $result;
        }

        $result->response = isset($result->reply) ? $result->reply : "";
        $result->interaction = array(
            "agentCode" => $agentCode,
            "appCode" => $appCode,
            "appName" => $appName,
            "profileId" => isset($result->profile["profileId"]) ? $result->profile["profileId"] : $profileId,
            "sessionId" => isset($result->session["sessionId"]) ? $result->session["sessionId"] : $sessionId,
            "conversationKey" => $conversationKey,
            "context" => $context
        );

        return $result;
    }

    public function runAgent($input) {
        $startedAt = microtime(true);
        $requestId = $this->newRuntimeId("agent");
        $body = $this->objectToArray($input);
        $appContext = $this->applicationContextFromRunBody($body);
        $agentCode = $this->normalizeAgentCode($this->arrayString($body, "agentCode", ""));
        $message = $this->arrayString($body, "message", "");
        $contentResult = $this->normalizeRuntimeContent(isset($body["content"]) ? $body["content"] : array(), $message);

        if ($agentCode === "") {
            return $this->fail("Agent code is required.");
        }
        if (!$contentResult->success) {
            return $contentResult;
        }
        $content = $contentResult->content;
        if ($message === "" && !count($content)) {
            return $this->fail("Message or content is required.");
        }

        $agents = $this->loadAgents();
        if (!isset($agents[$agentCode])) {
            $messageText = "Saved agent was not found.";
            $this->recordAgentError($requestId, $agentCode, null, "", "", $appContext, "validation", $messageText, $startedAt, array());
            return $this->fail($messageText);
        }

        if (!function_exists("curl_init")) {
            $messageText = "PHP cURL is not enabled. Enable the curl extension before running agents.";
            $this->recordAgentError($requestId, $agentCode, null, "", "", $appContext, "runtime", $messageText, $startedAt, array());
            return $this->fail($messageText);
        }

        $agent = $agents[$agentCode];
        $config = $this->normalizeSavedConfig(isset($agent["configuration"]) ? $agent["configuration"] : array());
        $identityStatus = $this->validateSavedAgentIdentity($config);
        if (!$identityStatus->success) {
            $this->recordAgentError($requestId, $agentCode, $config, "", "", $appContext, "identity", $identityStatus->message, $startedAt, array());
            return $identityStatus;
        }

        $profile = isset($body["profile"]) ? $this->objectToArray($body["profile"]) : array();
        $explicitSessionId = $this->normalizeSessionId($this->arrayString($body, "sessionId", ""));
        $ephemeral = false;
        $profileId = $this->normalizeProfileId($this->arrayString($profile, "profileId", ""));
        if ($profileId === "") {
            $profileId = $this->normalizeProfileId($this->arrayString($profile, "externalId", ""));
        }
        if ($profileId === "" && $explicitSessionId !== "") {
            $profileId = "anonymous-session-" . substr(hash("sha256", $agentCode . "|" . $explicitSessionId), 0, 12);
            $profile["profileId"] = $profileId;
        } elseif ($profileId === "") {
            $ephemeral = true;
            $profileId = "anonymous-ephemeral-" . substr($requestId, -12);
            $profile["profileId"] = $profileId;
        } else {
            $profile["profileId"] = $profileId;
        }

        $sessionId = $explicitSessionId;
        if ($sessionId === "") {
            $sessionId = $ephemeral ? "ephemeral-" . substr($requestId, -16) : $this->defaultSessionId($agentCode, $profileId);
        }

        $sessions = $this->loadSessions();
        $sessionKey = $this->sessionKey($agentCode, $profileId, $sessionId);
        $session = isset($sessions[$sessionKey]) && is_array($sessions[$sessionKey])
            ? $sessions[$sessionKey]
            : $this->defaultSession($agentCode, $profileId, $sessionId);

        $skillResults = $this->executeSkills($agent, $message, $profile, $session, $body);
        $runtimeContext = array(
            "profile" => $profile,
            "session" => $this->sessionForPrompt($session),
            "flow" => isset($body["flow"]) ? $this->objectToArray($body["flow"]) : array(),
            "connector" => isset($body["connector"]) ? $this->objectToArray($body["connector"]) : array(),
            "payload" => isset($body["payload"]) ? $this->objectToArray($body["payload"]) : array(),
            "skillCatalog" => $this->skillCatalogForPrompt($this->agentSkills($agent)),
            "skillResults" => $skillResults
        );

        $compatibility = $this->validateContentForConfig($config, $content);
        if ($compatibility !== true) {
            return $this->fail($compatibility);
        }
        $result = $this->callProvider($config, $message, $runtimeContext, isset($session["history"]) && is_array($session["history"]) ? $session["history"] : array(), $content);
        if (!$result->success) {
            $this->recordAgentError($requestId, $agentCode, $config, $profileId, $sessionId, $appContext, "provider", $result->message, $startedAt, array(
                "skillResults" => $skillResults
            ));
            return $result;
        }

        $session = $this->appendSessionTurn($session, $message, $result->reply, $skillResults, $runtimeContext, $content);
        if (!$ephemeral) {
            $sessions[$sessionKey] = $session;
        }
        if (!$ephemeral && !$this->saveSessions($sessions)) {
            $messageText = "Agent replied, but the session context could not be saved.";
            $this->recordAgentError($requestId, $agentCode, $config, $profileId, $sessionId, $appContext, "session", $messageText, $startedAt, array());
            return $this->fail($messageText);
        }

        $usage = isset($result->usage) && is_array($result->usage) ? $result->usage : $this->emptyTokenUsage();
        $usage["cost"] = $this->calculateUsageCost($config, $usage);
        $billingUsageId = $this->recordBillingUsage($requestId, $agentCode, $config, $profile, $sessionId, $appContext, $message, $result->reply, $usage, $startedAt, array(
            "skillCount" => count($skillResults),
            "requestChars" => isset($result->requestChars) ? (int)$result->requestChars : 0,
            "responseChars" => isset($result->responseChars) ? (int)$result->responseChars : strlen((string)$result->reply)
        ));
        if ($billingUsageId === "") {
            $this->recordAgentError($requestId, $agentCode, $config, $profileId, $sessionId, $appContext, "billing_log", "Agent replied, but token usage could not be written to the billing log.", $startedAt, array());
        }

        $out = $this->ok();
        $out->agentCode = $agentCode;
        $out->reply = $result->reply;
        $out->outputs = isset($result->outputs) && is_array($result->outputs) ? $result->outputs : array();
        $out->provider = $config["provider"]["type"];
        $out->model = $config["provider"]["model"];
        $out->profile = $profile;
        $out->session = $this->safeSessionForClient($session);
        $out->skillResults = $skillResults;
        $out->usage = $usage;
        $out->billingUsageId = $billingUsageId;
        $out->billingLogged = $billingUsageId !== "";
        $out->ephemeralSession = $ephemeral;
        $out->raw = isset($result->raw) ? $result->raw : null;
        return $out;
    }

    private function recordBillingUsage($requestId, $agentCode, $config, $profile, $sessionId, $appContext, $message, $reply, $usage, $startedAt, $meta) {
        if (!class_exists("\\SOSSData")) {
            return "";
        }

        $usageId = $this->newRuntimeId("usage");
        $record = new \stdClass();
        $record->usageId = $usageId;
        $record->requestId = $requestId;
        $record->agentCode = $agentCode;
        $record->agentName = isset($config["agent"]["name"]) ? $this->limitText($config["agent"]["name"], 255) : "";
        $record->provider = isset($config["provider"]["type"]) ? $this->limitText($config["provider"]["type"], 80) : "";
        $record->model = isset($config["provider"]["model"]) ? $this->limitText($config["provider"]["model"], 120) : "";
        $record->profileId = $this->normalizeProfileId($this->arrayString($profile, "profileId", ""));
        $record->profileName = $this->limitText($this->arrayString($profile, "name", ""), 255);
        $record->appCode = $this->limitText($this->arrayString($appContext, "appCode", "ai-agent-creator"), 80);
        $record->appName = $this->limitText($this->arrayString($appContext, "appName", $record->appCode), 255);
        $record->sessionId = $this->limitText($sessionId, 180);
        $record->conversationKey = $this->limitText($this->arrayString($appContext, "conversationKey", ""), 180);
        $record->messageHash = hash("sha256", (string)$message);
        $record->inputTokens = $this->usageInt($usage, "inputTokens");
        $record->outputTokens = $this->usageInt($usage, "outputTokens");
        $record->totalTokens = $this->usageInt($usage, "totalTokens");
        $record->cachedTokens = $this->usageInt($usage, "cachedTokens");
        $record->reasoningTokens = $this->usageInt($usage, "reasoningTokens");
        $record->isEstimated = isset($usage["estimated"]) && $usage["estimated"] === "true" ? "true" : "false";
        $record->usageSource = $this->limitText(isset($usage["source"]) ? $usage["source"] : "", 80);
        $record->requestChars = isset($meta["requestChars"]) ? (int)$meta["requestChars"] : 0;
        $record->responseChars = isset($meta["responseChars"]) ? (int)$meta["responseChars"] : strlen((string)$reply);
        $record->skillCount = isset($meta["skillCount"]) ? (int)$meta["skillCount"] : 0;
        $record->durationMs = $this->durationMs($startedAt);
        $record->status = "success";
        $record->rawUsage = isset($usage["rawUsage"]) ? $usage["rawUsage"] : null;
        $record->createdAt = gmdate("Y-m-d H:i:s");

        $result = \SOSSData::Insert("ai_agent_billing_usage", $record);
        if (isset($result->success) && $result->success) {
            return $usageId;
        }
        return "";
    }

    private function recordAgentError($requestId, $agentCode, $config, $profileId, $sessionId, $appContext, $stage, $message, $startedAt, $context) {
        if (!class_exists("\\SOSSData")) {
            return false;
        }

        $record = new \stdClass();
        $record->errorId = $this->newRuntimeId("err");
        $record->requestId = $requestId;
        $record->agentCode = $this->limitText($agentCode, 80);
        $record->provider = is_array($config) && isset($config["provider"]["type"]) ? $this->limitText($config["provider"]["type"], 80) : "";
        $record->model = is_array($config) && isset($config["provider"]["model"]) ? $this->limitText($config["provider"]["model"], 120) : "";
        $record->profileId = $this->limitText($profileId, 120);
        $record->appCode = $this->limitText($this->arrayString($appContext, "appCode", "ai-agent-creator"), 80);
        $record->appName = $this->limitText($this->arrayString($appContext, "appName", $record->appCode), 255);
        $record->sessionId = $this->limitText($sessionId, 180);
        $record->conversationKey = $this->limitText($this->arrayString($appContext, "conversationKey", ""), 180);
        $record->stage = $this->limitText($stage, 80);
        $record->status = "error";
        $record->message = $this->limitText($message, 2000);
        $record->errorHash = hash("sha256", $stage . "|" . $message);
        $record->durationMs = $this->durationMs($startedAt);
        $record->context = $this->maskSecrets($context);
        $record->createdAt = gmdate("Y-m-d H:i:s");

        $result = \SOSSData::Insert("ai_agent_error_log", $record);
        return isset($result->success) && $result->success;
    }

    private function applicationContextFromRunBody($body) {
        $payload = isset($body["payload"]) ? $this->objectToArray($body["payload"]) : array();
        $flow = isset($body["flow"]) ? $this->objectToArray($body["flow"]) : array();
        $connector = isset($body["connector"]) ? $this->objectToArray($body["connector"]) : array();

        $appCode = $this->arrayString($body, "appCode", "");
        if ($appCode === "") {
            $appCode = $this->arrayString($payload, "appCode", "");
        }
        if ($appCode === "") {
            $appCode = $this->arrayString($connector, "appCode", "");
        }
        if ($appCode === "") {
            $appCode = $this->arrayString($flow, "flowCode", "");
        }
        $appCode = $this->normalizeContextCode($appCode, "ai-agent-creator");

        $appName = $this->arrayString($body, "appName", "");
        if ($appName === "") {
            $appName = $this->arrayString($payload, "appName", "");
        }
        if ($appName === "") {
            $appName = $this->arrayString($flow, "name", "");
        }
        if ($appName === "") {
            $appName = $appCode;
        }

        $conversationKey = $this->arrayString($body, "conversationKey", "");
        if ($conversationKey === "") {
            $conversationKey = $this->arrayString($payload, "conversationKey", "");
        }

        return array(
            "appCode" => $appCode,
            "appName" => $this->limitText($appName, 255),
            "conversationKey" => $this->normalizeSessionId($conversationKey)
        );
    }

    private function usageInt($usage, $key) {
        if (is_array($usage) && isset($usage[$key]) && is_numeric($usage[$key])) {
            return (int)$usage[$key];
        }
        return 0;
    }

    private function durationMs($startedAt) {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    private function newRuntimeId($prefix) {
        if (function_exists("random_bytes")) {
            return $prefix . "-" . bin2hex(random_bytes(12));
        }
        if (function_exists("openssl_random_pseudo_bytes")) {
            return $prefix . "-" . bin2hex(openssl_random_pseudo_bytes(12));
        }
        return $prefix . "-" . substr(hash("sha256", uniqid("", true)), 0, 24);
    }

    private function buildConfigFromBody($body, $requireAgentMeta) {
        $provider = strtolower($this->stringValue($body, "provider", "openai"));
        $providers = $this->providerMap();

        if (!isset($providers[$provider])) {
            return $this->fail("Unsupported provider type.");
        }

        $agentCode = $this->normalizeAgentCode($this->stringValue($body, "agentCode", ""));
        $agentName = $this->stringValue($body, "agentName", "");
        $description = $this->stringValue($body, "description", "");
        $capabilities = $this->capabilitiesValue($body);
        $skillsCreated = $this->skillsValue($body);
        if (!$skillsCreated->success) {
            return $skillsCreated;
        }
        $skills = $skillsCreated->skills;

        if ($requireAgentMeta) {
            if ($agentCode === "") {
                return $this->fail("Agent code is required. Use lowercase letters, numbers, hyphens, or underscores.");
            }
            if ($agentName === "") {
                return $this->fail("Agent name is required.");
            }
            if ($description === "") {
                return $this->fail("Describe what this agent is capable of.");
            }
            if (!count($capabilities)) {
                return $this->fail("Add at least one capability for this agent.");
            }
        }

        $model = $this->stringValue($body, "model", "");
        if ($model === "") {
            return $this->fail("Model name/version is required.");
        }
        if (!$this->isValidModelName($model)) {
            return $this->fail("Model name/version contains unsupported characters.");
        }

        $systemPrompt = $this->stringValue($body, "systemPrompt", "");

        $modelMetaResult = $this->customModelMetadata($body, $provider, $model);
        if (!$modelMetaResult->success) return $modelMetaResult;
        $modelMeta = $modelMetaResult->model;
        $temperature = $this->numberValue($body, "temperature", 0.7, 0, 2);
        $modelOutputLimit = $modelMeta && !empty($modelMeta["maxOutputTokens"])
            ? (int)$modelMeta["maxOutputTokens"] : 200000;
        $maxTokens = $this->integerValue($body, "maxTokens", min(2048, $modelOutputLimit), 1, $modelOutputLimit);
        // This component has no SSE transport. Persisting true would be misleading.
        $streaming = false;
        $modalitiesResult = $this->configuredModalities($body, $modelMeta);
        if (!$modalitiesResult->success) {
            return $modalitiesResult;
        }

        $apiKey = $this->stringValue($body, "apiKey", "");
        $endpoint = $this->stringValue($body, "endpoint", "");
        $cliCommand = $this->stringValue($body, "cliCommand", "");
        $customMethod = strtoupper($this->stringValue($body, "customMethod", "POST"));
        $authHeader = $this->stringValue($body, "authHeader", "");

        $validation = $this->validateProviderDetails($provider, $apiKey, $endpoint, $cliCommand, $customMethod, $authHeader);
        if ($validation !== true) {
            return $this->fail($validation);
        }

        $config = $this->buildConfig(
            $providers[$provider],
            $provider,
            $model,
            $apiKey,
            $endpoint,
            $cliCommand,
            $customMethod,
            $authHeader,
            $systemPrompt,
            $temperature,
            $maxTokens,
            $streaming,
            $skills,
            $modalitiesResult->modalities,
            $modelMeta,
            array(
                "code" => $agentCode,
                "name" => $agentName,
                "description" => $description,
                "capabilities" => $capabilities
            )
        );

        $out = $this->ok();
        $out->agentCode = $agentCode;
        $out->agentName = $agentName;
        $out->description = $description;
        $out->capabilities = $capabilities;
        $out->skills = $skills;
        $out->config = $config;
        return $out;
    }

    private function providerMap() {
        $verified = "2026-08-25";
        $openAiPricing = "https://developers.openai.com/api/docs/models";
        $googlePricing = "https://ai.google.dev/gemini-api/docs/pricing";
        return array(
            "openai" => array(
                "type" => "openai", "code" => "openai", "label" => "OpenAI API",
                "connectionMethod" => "REST API (Responses for curated models; Chat Completions for legacy configs)",
                "defaultEndpoint" => "https://api.openai.com/v1/responses", "credentialsRequired" => true,
                "modelDiscovery" => array("supported" => true, "endpoint" => "https://api.openai.com/v1/models"),
                "pricingSourceUrl" => $openAiPricing, "pricingLastVerified" => $verified,
                "notes" => "Curated current models use the Responses API. Older saved agents retain Chat Completions.",
                "fallbackModels" => array(
                    $this->catalogModel("gpt-5.6-luna", "GPT-5.6 Luna", "Cost-sensitive, high-volume work.", "Fast", "stable", array("text", "image"), array("text"), 1050000, 128000, array("maxTokens"), "responses", "0.20", null, "1.20", $openAiPricing, $verified),
                    $this->catalogModel("gpt-5.6-terra", "GPT-5.6 Terra", "Balanced intelligence and cost.", "Balanced", "stable", array("text", "image"), array("text"), 1050000, 128000, array("maxTokens"), "responses", "2.00", null, "12.00", $openAiPricing, $verified),
                    $this->catalogModel("gpt-5.6-sol", "GPT-5.6 Sol", "Frontier model for complex professional work.", "Best quality", "stable", array("text", "image"), array("text"), 1050000, 128000, array("maxTokens"), "responses", "4.00", null, "20.00", $openAiPricing, $verified)
                )
            ),
            "ollama" => array(
                "type" => "ollama", "code" => "ollama", "label" => "Local Ollama",
                "connectionMethod" => "Local HTTP runtime (CLI command is saved as manual metadata only)",
                "defaultEndpoint" => "http://localhost:11434/api/chat", "credentialsRequired" => false,
                "modelDiscovery" => array("supported" => true, "endpoint" => "/api/tags"),
                "pricingSourceUrl" => "https://ollama.com/", "pricingLastVerified" => $verified,
                "notes" => "No per-token provider API fee. Local hardware, hosting, and electricity costs are not included.",
                "fallbackModels" => array(
                    $this->catalogModel("gemma4", "Gemma 4", "Local vision-capable model; availability depends on the installed runtime.", "Local vision", "local", array("text", "image"), array("text"), null, null, array("temperature", "maxTokens"), "ollama-chat", "0", null, "0", "https://docs.ollama.com/capabilities/vision", $verified),
                    $this->catalogModel("llama3.1", "Llama 3.1", "Common local text model; limits depend on the installed variant.", "Local", "local", array("text"), array("text"), null, null, array("temperature", "maxTokens"), "ollama-chat", "0", null, "0", "https://ollama.com/library", $verified)
                )
            ),
            "lmstudio" => array(
                "type" => "lmstudio", "code" => "lmstudio", "label" => "LM Studio",
                "connectionMethod" => "Local OpenAI-compatible server",
                "defaultEndpoint" => "http://localhost:1234/v1/chat/completions", "credentialsRequired" => false,
                "modelDiscovery" => array("supported" => true, "endpoint" => "http://localhost:1234/api/v1/models"),
                "pricingSourceUrl" => "https://lmstudio.ai/docs/developer/rest/list", "pricingLastVerified" => $verified,
                "notes" => "No per-token provider API fee. Local hardware, hosting, and electricity costs are not included. Capabilities come from the loaded model.",
                "fallbackModels" => array(
                    $this->catalogModel("local-model", "Loaded local model", "Choose a discovered generative model or enter its exact local key.", "Local", "local", array("text"), array("text"), null, null, array("temperature", "maxTokens"), "openai-chat", "0", null, "0", "https://lmstudio.ai/docs/developer", $verified)
                )
            ),
            "google" => array(
                "type" => "google", "code" => "google", "label" => "Google AI API",
                "connectionMethod" => "Gemini generateContent API", "defaultEndpoint" => "https://generativelanguage.googleapis.com/v1beta",
                "credentialsRequired" => true, "modelDiscovery" => array("supported" => true, "endpoint" => "https://generativelanguage.googleapis.com/v1beta/models"),
                "pricingSourceUrl" => $googlePricing, "pricingLastVerified" => $verified,
                "notes" => "Standard paid API token pricing is shown; free-tier availability and long-context tiers can differ.",
                "fallbackModels" => array(
                    $this->catalogModel("gemini-2.5-flash-lite", "Gemini 2.5 Flash-Lite", "Fast, budget-friendly multimodal model.", "Fast", "stable", array("text", "image", "audio", "video", "document"), array("text"), 1000000, 65536, array("temperature", "maxTokens"), "generateContent", "0.10", "0.01", "0.40", $googlePricing, $verified, array("audioInputPerMillionTokens" => "0.30")),
                    $this->catalogModel("gemini-2.5-flash", "Gemini 2.5 Flash", "Price-performance model for multimodal reasoning.", "Balanced", "stable", array("text", "image", "audio", "video", "document"), array("text"), 1000000, 65536, array("temperature", "maxTokens"), "generateContent", "0.30", "0.03", "2.50", $googlePricing, $verified, array("audioInputPerMillionTokens" => "1.00")),
                    $this->catalogModel("gemini-2.5-pro", "Gemini 2.5 Pro", "Advanced multimodal model for complex reasoning and coding.", "Best quality", "stable", array("text", "image", "audio", "video", "document"), array("text"), 1048576, 65536, array("temperature", "maxTokens"), "generateContent", "1.25", "0.125", "10.00", $googlePricing, $verified, array("priceTierNote" => "Rates shown apply to prompts up to 200k tokens."))
                )
            ),
            "other" => array(
                "type" => "other", "code" => "other", "label" => "Other OpenAI-compatible API",
                "connectionMethod" => "Fixed OpenAI-compatible chat contract", "defaultEndpoint" => "", "credentialsRequired" => true,
                "modelDiscovery" => array("supported" => false), "pricingSourceUrl" => "", "pricingLastVerified" => $verified,
                "notes" => "Text-only by default. This adapter sends model/messages/parameters and reads choices[0].message.content, reply, or text; arbitrary schemas are not claimed.",
                "fallbackModels" => array(
                    $this->catalogModel("custom-model", "Custom model ID", "Manually configured text model with unknown limits and pricing.", "Custom", "custom", array("text"), array("text"), null, null, array("temperature", "maxTokens"), "custom-fixed", null, null, null, "", $verified)
                )
            )
        );
    }

    private function catalogModel($id, $name, $description, $recommended, $lifecycle, $input, $output, $context, $maxOutput, $parameters, $apiMode, $inputPrice, $cachedPrice, $outputPrice, $pricingUrl, $verified, $extraPricing = array()) {
        return array(
            "id" => $id, "name" => $name, "description" => $description, "recommendedUse" => $recommended,
            "lifecycle" => $lifecycle, "inputModalities" => $input, "outputModalities" => $output,
            "contextWindow" => $context, "maxOutputTokens" => $maxOutput, "supportedParameters" => $parameters,
            "apiMode" => $apiMode, "pricing" => array_merge(array(
                "status" => $inputPrice === null || $outputPrice === null ? "unknown" : ($lifecycle === "local" ? "local" : "paid_api"),
                "currency" => "USD", "unit" => "per 1M tokens", "inputPerMillionTokens" => $inputPrice,
                "cachedInputPerMillionTokens" => $cachedPrice, "outputPerMillionTokens" => $outputPrice,
                "officialUrl" => $pricingUrl, "lastVerified" => $verified
            ), $extraPricing)
        );
    }

    private function modelMetadata($provider, $model) {
        $providers = $this->providerMap();
        if (isset($providers[$provider]["fallbackModels"])) {
            foreach ($providers[$provider]["fallbackModels"] as $item) {
                if (isset($item["id"]) && $item["id"] === $model) {
                    return $item;
                }
            }
        }
        $local = $provider === "ollama" || $provider === "lmstudio";
        return $this->catalogModel(
            $model, $model, "Custom model ID. Verify capabilities and limits with the provider.", "Custom",
            $local ? "local" : "custom", array("text"), array("text"), null, null,
            array("temperature", "maxTokens"), $provider === "openai" ? "chat-completions" : "default",
            $local ? "0" : null, null, $local ? "0" : null,
            isset($providers[$provider]["pricingSourceUrl"]) ? $providers[$provider]["pricingSourceUrl"] : "", "2026-08-25"
        );
    }

    private function customModelMetadata($body, $provider, $model) {
        $out = $this->ok(); $out->model = $this->modelMetadata($provider, $model);
        if ($provider !== "other" || !isset($body->customModelMetadata) || $body->customModelMetadata === "") return $out;
        $raw = is_string($body->customModelMetadata) ? json_decode($body->customModelMetadata, true) : $this->objectToArray($body->customModelMetadata);
        if (!is_array($raw)) return $this->fail("Custom model metadata must be valid JSON.");
        $input = isset($raw["inputModalities"]) ? $this->normalizeModalityList($raw["inputModalities"]) : array("text");
        $output = isset($raw["outputModalities"]) ? $this->normalizeModalityList($raw["outputModalities"]) : array("text");
        if ($input !== array("text") || $output !== array("text")) return $this->fail("The fixed custom API adapter is text-only. Multimodal custom APIs require a validated request/response mapping that this version does not claim.");
        $out->model["inputModalities"] = $input; $out->model["outputModalities"] = $output;
        if (isset($raw["contextWindow"]) && is_numeric($raw["contextWindow"])) $out->model["contextWindow"] = max(1, min(10000000, (int)$raw["contextWindow"]));
        if (isset($raw["maxOutputTokens"]) && is_numeric($raw["maxOutputTokens"])) $out->model["maxOutputTokens"] = max(1, min(1000000, (int)$raw["maxOutputTokens"]));
        if (isset($raw["pricing"]) && is_array($raw["pricing"])) {
            $pricing = $out->model["pricing"];
            foreach (array("inputPerMillionTokens", "cachedInputPerMillionTokens", "outputPerMillionTokens") as $key) {
                if (array_key_exists($key, $raw["pricing"])) {
                    $value = $raw["pricing"][$key];
                    if ($value !== null && !preg_match('/^\d+(?:\.\d{1,6})?$/', (string)$value)) return $this->fail("Custom pricing values must be non-negative decimal strings.");
                    $pricing[$key] = $value === null ? null : (string)$value;
                }
            }
            $pricing["currency"] = isset($raw["pricing"]["currency"]) && preg_match('/^[A-Z]{3}$/', (string)$raw["pricing"]["currency"]) ? (string)$raw["pricing"]["currency"] : "USD";
            $pricing["status"] = $pricing["inputPerMillionTokens"] !== null && $pricing["outputPerMillionTokens"] !== null ? "manual" : "unknown";
            $pricing["officialUrl"] = ""; $pricing["lastVerified"] = gmdate("Y-m-d"); $out->model["pricing"] = $pricing;
        }
        return $out;
    }

    private function configuredModalities($body, $modelMeta) {
        $supportedInput = $modelMeta && isset($modelMeta["inputModalities"]) ? $modelMeta["inputModalities"] : array("text");
        $supportedOutput = $modelMeta && isset($modelMeta["outputModalities"]) ? $modelMeta["outputModalities"] : array("text");
        $raw = isset($body->modalities) ? $this->objectToArray($body->modalities) : array();
        $input = isset($raw["input"]) ? $this->normalizeModalityList($raw["input"]) : $this->normalizeModalityList(isset($body->inputModalities) ? $body->inputModalities : array("text"));
        $output = isset($raw["output"]) ? $this->normalizeModalityList($raw["output"]) : $this->normalizeModalityList(isset($body->outputModalities) ? $body->outputModalities : array("text"));
        if (!count($input)) {
            $input = array("text");
        }
        if (!count($output)) {
            $output = array("text");
        }
        foreach ($input as $modality) {
            if (!in_array($modality, $supportedInput, true)) {
                return $this->fail("Selected model does not support " . $modality . " input.");
            }
        }
        foreach ($output as $modality) {
            if (!in_array($modality, $supportedOutput, true)) {
                return $this->fail("Selected model does not support " . $modality . " output.");
            }
        }
        $out = $this->ok();
        $out->modalities = array("input" => $input, "output" => $output);
        return $out;
    }

    public function postDiscoverModels($req, $res) {
        $body = $this->body($req);
        $provider = strtolower($this->stringValue($body, "provider", ""));
        $providers = $this->providerMap();
        if (!isset($providers[$provider]) || $provider === "other") return $this->fail("Model discovery is not supported for this provider.");
        $apiKey = $this->stringValue($body, "apiKey", "");
        $endpoint = $this->stringValue($body, "endpoint", $providers[$provider]["defaultEndpoint"]);
        if (($provider === "openai" || $provider === "google") && $apiKey === "") return $this->fail("An API key is required for authenticated model discovery.");
        $url = ""; $headers = array("Accept: application/json");
        if ($provider === "openai") { $url = "https://api.openai.com/v1/models"; $headers[] = "Authorization: Bearer " . $apiKey; }
        elseif ($provider === "google") { $url = "https://generativelanguage.googleapis.com/v1beta/models"; $headers[] = "x-goog-api-key: " . $apiKey; }
        elseif ($provider === "ollama") { $parts = parse_url($endpoint); $url = $parts && isset($parts["scheme"], $parts["host"]) ? $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "") . "/api/tags" : ""; }
        elseif ($provider === "lmstudio") { $parts = parse_url($endpoint); $url = $parts && isset($parts["scheme"], $parts["host"]) ? $parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : "") . "/api/v1/models" : ""; }
        if ($url === "" || !function_exists("curl_init")) return $this->fail("Model discovery endpoint is unavailable.");
        $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $raw = curl_exec($ch); $error = curl_error($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            $out = $this->ok(); $out->models = $providers[$provider]["fallbackModels"]; $out->discoverySuccess = false;
            $out->warning = $this->sanitizeProviderError($raw === false ? $error : "Discovery returned HTTP " . $status . ".") . " Curated fallback models are shown."; return $out;
        }
        $decoded = json_decode($raw, true); $discovered = $this->discoveredModelEntries($provider, $decoded);
        $merged = array(); foreach ($providers[$provider]["fallbackModels"] as $item) $merged[$item["id"]] = $item;
        foreach ($discovered as $item) if (!isset($merged[$item["id"]])) $merged[$item["id"]] = $item;
        $out = $this->ok(); $out->models = array_values($merged); $out->discoverySuccess = true; return $out;
    }

    private function discoveredModelEntries($provider, $decoded) {
        $items = array();
        if ($provider === "google") $source = isset($decoded["models"]) && is_array($decoded["models"]) ? $decoded["models"] : array();
        elseif ($provider === "ollama") $source = isset($decoded["models"]) && is_array($decoded["models"]) ? $decoded["models"] : array();
        elseif ($provider === "lmstudio") $source = isset($decoded["models"]) && is_array($decoded["models"]) ? $decoded["models"] : array();
        else $source = isset($decoded["data"]) && is_array($decoded["data"]) ? $decoded["data"] : array();
        foreach ($source as $entry) {
            if (!is_array($entry)) continue;
            if ($provider === "google" && (!isset($entry["supportedGenerationMethods"]) || !in_array("generateContent", $entry["supportedGenerationMethods"], true))) continue;
            if ($provider === "lmstudio" && isset($entry["type"]) && !in_array($entry["type"], array("llm", "vlm"), true)) continue;
            $id = $provider === "google" ? preg_replace('#^models/#', '', isset($entry["name"]) ? $entry["name"] : "") : (isset($entry["id"]) ? $entry["id"] : (isset($entry["key"]) ? $entry["key"] : (isset($entry["name"]) ? $entry["name"] : "")));
            if ($id === "" || !$this->isValidModelName($id)) continue;
            $vision = $provider === "lmstudio" && !empty($entry["capabilities"]["vision"]);
            $meta = $this->modelMetadata($provider, $id); $meta["name"] = isset($entry["displayName"]) ? $entry["displayName"] : (isset($entry["display_name"]) ? $entry["display_name"] : $id);
            $meta["description"] = "Discovered from the provider; pricing and unspecified limits remain unknown.";
            if ($vision) $meta["inputModalities"] = array("text", "image");
            if (isset($entry["max_context_length"])) $meta["contextWindow"] = (int)$entry["max_context_length"];
            $items[] = $meta;
        }
        return $items;
    }

    private function normalizeModalityList($value) {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', strtolower($value));
        } elseif (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return array();
        }
        $allowed = array("text", "image", "audio", "video", "document");
        $out = array();
        foreach ($value as $item) {
            $item = strtolower(trim((string)$item));
            if (in_array($item, $allowed, true) && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    private function validateProviderDetails($provider, $apiKey, $endpoint, $cliCommand, $customMethod, $authHeader) {
        if ($provider === "openai") {
            if ($apiKey === "") {
                return "OpenAI API key is required.";
            }
            return true;
        }

        if ($provider === "google") {
            if ($apiKey === "") {
                return "Google AI API key is required.";
            }
            return true;
        }

        if ($provider === "ollama") {
            if ($endpoint === "") {
                return "Ollama requires its HTTP API endpoint. The CLI command is metadata only and is never executed.";
            }
            if ($endpoint !== "" && !$this->isHttpUrl($endpoint)) {
                return "Ollama endpoint must be a valid HTTP URL.";
            }
            return true;
        }

        if ($provider === "lmstudio") {
            if ($endpoint === "") {
                return "LM Studio local inference server endpoint is required.";
            }
            if (!$this->isHttpUrl($endpoint)) {
                return "LM Studio endpoint must be a valid HTTP URL.";
            }
            return true;
        }

        if ($provider === "other") {
            if ($endpoint === "") {
                return "Custom API endpoint is required.";
            }
            if (!$this->isHttpUrl($endpoint)) {
                return "Custom API endpoint must be a valid HTTP URL.";
            }
            if (!in_array($customMethod, array("POST", "PUT", "PATCH"))) {
                return "Custom API method must be POST, PUT, or PATCH.";
            }
            if ($apiKey === "" && $authHeader === "") {
                return "Custom APIs require an API key or auth header.";
            }
            return true;
        }

        return "Unsupported provider type.";
    }

    private function buildConfig($meta, $provider, $model, $apiKey, $endpoint, $cliCommand, $customMethod, $authHeader, $systemPrompt, $temperature, $maxTokens, $streaming, $skills, $modalities, $modelMeta, $agentMeta) {
        if ($provider === "openai" || $provider === "google") {
            $endpoint = $meta["defaultEndpoint"];
        } else {
            $endpoint = $endpoint === "" ? $meta["defaultEndpoint"] : $endpoint;
        }

        $connection = array(
            "method" => $meta["connectionMethod"],
            "endpoint" => $endpoint
        );

        if ($provider === "openai") {
            $connection["httpMethod"] = "POST";
            $connection["path"] = $modelMeta && $modelMeta["apiMode"] === "responses" ? "/v1/responses" : "/v1/chat/completions";
            $connection["endpoint"] = $modelMeta && $modelMeta["apiMode"] === "responses" ? "https://api.openai.com/v1/responses" : "https://api.openai.com/v1/chat/completions";
            $connection["auth"] = array("type" => "bearer", "apiKey" => $apiKey);
        } elseif ($provider === "ollama") {
            $connection["httpMethod"] = "POST";
            $connection["runtime"] = array("type" => "local", "cliCommand" => $cliCommand, "httpEndpoint" => $endpoint);
        } elseif ($provider === "lmstudio") {
            $connection["httpMethod"] = "POST";
            $connection["server"] = array("type" => "local_inference_server", "openAiCompatible" => true);
        } elseif ($provider === "google") {
            $connection["httpMethod"] = "POST";
            $connection["baseUrl"] = $endpoint;
            $connection["resource"] = "models/" . $model . ":generateContent";
            $connection["auth"] = array("type" => "api_key", "apiKey" => $apiKey);
        } else {
            $connection["httpMethod"] = $customMethod;
            $connection["auth"] = array("type" => $authHeader !== "" ? "custom_header" : "api_key", "apiKey" => $apiKey, "header" => $authHeader);
            $connection["schema"] = array("requestBody" => "custom", "responseParser" => "custom");
        }

        return array(
            "provider" => array(
                "type" => $provider,
                "name" => $meta["label"],
                "model" => $model,
                "apiMode" => $modelMeta && isset($modelMeta["apiMode"]) ? $modelMeta["apiMode"] : ($provider === "openai" ? "chat-completions" : "default"),
                "modelInfo" => $modelMeta
            ),
            "connection" => $connection,
            "agent" => array(
                "code" => $agentMeta["code"],
                "name" => $agentMeta["name"],
                "description" => $agentMeta["description"],
                "capabilities" => $agentMeta["capabilities"],
                "skills" => $this->skillCatalogForPrompt($skills),
                "initialized" => true,
                "readyForInteraction" => true,
                "startupPrompt" => $systemPrompt,
                "messages" => array(array("role" => "system", "content" => $systemPrompt))
            ),
            "skills" => $skills,
            "modalities" => $modalities,
            "parameters" => array(
                "temperature" => $temperature,
                "maxTokens" => $maxTokens,
                "streaming" => $streaming
            ),
            "createdAt" => gmdate("c")
        );
    }

    private function ensureAgentSystemIdentity($body, $created, $existing) {
        if (!class_exists("\\SOSSData")) {
            return $this->fail("The profile datastore is not available. Install the sossdata plugin before saving an agent.");
        }
        if (!class_exists("\\Auth")) {
            return $this->fail("The auth plugin is not available. Install auth before saving an agent system user.");
        }

        $existingConfig = isset($existing["configuration"]) && is_array($existing["configuration"]) ? $existing["configuration"] : array();
        $existingIdentity = $this->identityFromConfig($existingConfig);
        $profileInput = $this->agentProfileInput($body, $created, $existingIdentity);
        $validation = $this->validateAgentProfileInput($profileInput);
        if (!$validation->success) {
            return $validation;
        }

        $userPassword = $profileInput["userPassword"] !== "" ? $profileInput["userPassword"] : $this->randomPassword();
        $userResult = $this->ensureAgentUser($profileInput, $existingIdentity, $userPassword);
        if (!$userResult->success) {
            return $userResult;
        }

        $profileResult = $this->ensureAgentProfile($profileInput, $existingIdentity, $userResult);
        if (!$profileResult->success) {
            return $profileResult;
        }

        $out = $this->ok();
        $out->profileId = $profileResult->profileId;
        $out->profile = array(
            "profileId" => $profileResult->profileId,
            "name" => $profileInput["name"],
            "email" => $profileInput["email"],
            "phone" => $profileInput["phone"],
            "image" => $this->profileImageUrl($profileResult->profileId),
            "catogory" => "AI Agent"
        );
        $out->userId = $userResult->userId;
        $out->user = array(
            "userid" => $userResult->userId,
            "username" => $profileInput["email"],
            "email" => $profileInput["email"],
            "name" => $profileInput["name"],
            "groupid" => "sysuser",
            "password" => $userPassword
        );
        return $out;
    }

    private function agentProfileInput($body, $created, $existingIdentity) {
        $profile = isset($existingIdentity["profile"]) && is_array($existingIdentity["profile"]) ? $existingIdentity["profile"] : array();
        $user = isset($existingIdentity["user"]) && is_array($existingIdentity["user"]) ? $existingIdentity["user"] : array();

        return array(
            "profileId" => $this->integerString($this->stringValue($body, "profileId", isset($profile["profileId"]) ? $profile["profileId"] : "0")),
            "userId" => $this->stringValue($body, "userId", isset($user["userid"]) ? $user["userid"] : ""),
            "name" => $this->limitText($this->stringValue($body, "agentProfileName", $created->agentName), 200),
            "email" => strtolower($this->limitText($this->stringValue($body, "agentEmail", isset($profile["email"]) ? $profile["email"] : ""), 200)),
            "phone" => $this->limitText($this->stringValue($body, "agentPhone", isset($profile["phone"]) ? $profile["phone"] : ""), 20),
            "userPassword" => isset($user["password"]) && $user["password"] !== "********" ? (string)$user["password"] : ""
        );
    }

    private function validateAgentProfileInput($input) {
        if ($input["name"] === "" || $input["email"] === "" || $input["phone"] === "") {
            return $this->fail("Agent profile name, email, and phone are required.");
        }
        if (!filter_var($input["email"], FILTER_VALIDATE_EMAIL)) {
            return $this->fail("Agent email is not valid.");
        }
        if (preg_match("/^[A-Za-z0-9._%+@-]+$/", $input["email"]) !== 1) {
            return $this->fail("Agent email contains unsupported characters.");
        }
        return $this->ok();
    }

    private function ensureAgentUser($input, $existingIdentity, $password) {
        $existingUserId = $input["userId"] !== "" ? $input["userId"] : $this->pathValue($existingIdentity, "user.userid");
        $user = $existingUserId !== "" ? $this->userById($existingUserId) : null;
        $emailUser = $this->userByEmail($input["email"]);

        if ($emailUser && (!$user || (string)$emailUser->userid !== (string)$user->userid)) {
            return $this->fail("This email is already registered to another user. Use a dedicated email for the AI agent.");
        }

        if (!$user) {
            $created = $this->createAgentUser($input, $password);
            if (!$created->success) {
                return $created;
            }
            $userId = $created->userId;
        } else {
            $userId = isset($user->userid) ? (string)$user->userid : "";
            $this->updateAgentUserRecord($user, $input, $password);
        }

        if ($userId === "") {
            return $this->fail("AI agent user could not be resolved.");
        }

        $join = \Auth::Join($this->authHostName(), $userId, "sysuser");
        if (is_object($join) && isset($join->success) && $join->success === false) {
            return $this->fail("AI agent user was created, but could not be joined to the sysuser group.");
        }
        $this->clearUserCaches();

        $out = $this->ok();
        $out->userId = $userId;
        $out->joinResult = $join;
        return $out;
    }

    private function createAgentUser($input, $password) {
        $user = new \stdClass();
        $user->username = $input["email"];
        $user->email = $input["email"];
        $user->name = $input["name"];
        $user->password = $password;

        $created = \Auth::SaveUser($user);
        if (!is_object($created) || !isset($created->userid)) {
            return $this->fail("AI agent system user could not be created.");
        }

        $out = $this->ok();
        $out->userId = (string)$created->userid;
        return $out;
    }

    private function updateAgentUserRecord($user, $input, $password) {
        if (!class_exists("\\SOSSData")) {
            return;
        }

        $changed = false;
        if (!isset($user->email) || strtolower((string)$user->email) !== $input["email"]) {
            $user->email = $input["email"];
            $changed = true;
        }
        if (!isset($user->username) || strtolower((string)$user->username) !== $input["email"]) {
            $user->username = $input["email"];
            $changed = true;
        }
        if (!isset($user->name) || (string)$user->name !== $input["name"]) {
            $user->name = $input["name"];
            $changed = true;
        }
        if (!isset($user->password) || (string)$user->password === "") {
            $user->password = md5($password);
            $changed = true;
        }

        if ($changed) {
            \SOSSData::Update("users", $user);
        }
    }

    private function ensureAgentProfile($input, $existingIdentity, $userResult) {
        $profile = null;
        $profileId = (int)$input["profileId"];
        if ($profileId > 0) {
            $profile = $this->profileById($profileId);
            if (!$profile) {
                return $this->fail("The selected AI agent profile was not found.");
            }
            if (!$this->isAgentProfile($profile)) {
                return $this->fail("The selected profile is not tagged as an AI Agent.");
            }
        }

        $emailProfile = $this->profileByEmail($input["email"]);
        if ($emailProfile && (!$profile || (int)$emailProfile->id !== (int)$profile->id)) {
            if (!$this->isAgentProfile($emailProfile)) {
                return $this->fail("A non-agent profile already uses this email. Use a dedicated email for the AI agent.");
            }
            $profile = $emailProfile;
        }

        $isNew = $profile === null;
        if ($isNew) {
            $profile = new \stdClass();
            $profile->createdate = date_format(new \DateTime(), "m-d-Y H:i:s");
            $profile->Status = "Active";
        }

        $profile->name = $input["name"];
        $profile->email = $input["email"];
        $profile->contactno = $input["phone"];
        $profile->catogory = "AI Agent";
        $profile->userid = $userResult->userId;
        $profile->linkeduserid = $userResult->userId;

        $result = $isNew ? \SOSSData::Insert("profile", $profile, null) : \SOSSData::Update("profile", $profile, null);
        if (!$result->success) {
            return $this->fail(isset($result->message) ? $result->message : "AI agent profile could not be saved.");
        }

        if ($isNew && isset($result->result) && isset($result->result->generatedId)) {
            $profile->id = $result->result->generatedId;
        }

        $this->clearProfileCache();
        $savedProfileId = isset($profile->id) ? (int)$profile->id : 0;
        if ($savedProfileId <= 0) {
            return $this->fail("AI agent profile was saved, but no profile id was returned.");
        }

        $out = $this->ok();
        $out->profileId = $savedProfileId;
        return $out;
    }

    private function attachSystemIdentityToConfig($config, $identity) {
        $config["agent"]["profile"] = $identity->profile;
        $config["agent"]["profileId"] = $identity->profileId;
        $config["agent"]["profileImage"] = $identity->profile["image"];
        $config["agent"]["user"] = $identity->user;
        $config["agent"]["userId"] = $identity->userId;
        $config["agent"]["userGroup"] = "sysuser";
        $config["systemUser"] = $identity->user;
        return $config;
    }

    private function validateSavedAgentIdentity($config) {
        $identity = $this->identityFromConfig($config);
        if (empty($identity["profile"]["profileId"]) || empty($identity["user"]["userid"])) {
            // Identity metadata was not present in early saved-agent records. It is
            // additive context, not a prerequisite for provider execution.
            return $this->ok();
        }
        if (isset($identity["user"]["groupid"]) && $identity["user"]["groupid"] !== "sysuser") {
            return $this->fail("Saved agent user is not mapped to the sysuser group.");
        }
        return $this->ok();
    }

    private function normalizeSavedConfig($config) {
        $config = is_array($config) ? $config : array();
        if (!isset($config["provider"]) || !is_array($config["provider"])) $config["provider"] = array();
        if (!isset($config["provider"]["type"])) $config["provider"]["type"] = "openai";
        if (!isset($config["provider"]["model"])) $config["provider"]["model"] = "";
        if (!isset($config["provider"]["apiMode"])) $config["provider"]["apiMode"] = "chat-completions";
        if (!isset($config["connection"]) || !is_array($config["connection"])) $config["connection"] = array();
        if (!isset($config["connection"]["httpMethod"])) $config["connection"]["httpMethod"] = "POST";
        if (!isset($config["agent"]) || !is_array($config["agent"])) $config["agent"] = array();
        if (!isset($config["agent"]["startupPrompt"])) $config["agent"]["startupPrompt"] = "You are a helpful assistant.";
        if (!isset($config["agent"]["capabilities"])) $config["agent"]["capabilities"] = array();
        if (!isset($config["parameters"]) || !is_array($config["parameters"])) $config["parameters"] = array();
        if (!isset($config["parameters"]["temperature"])) $config["parameters"]["temperature"] = 0.7;
        if (!isset($config["parameters"]["maxTokens"])) $config["parameters"]["maxTokens"] = 2048;
        $config["parameters"]["streaming"] = false;
        if (!isset($config["modalities"]) || !is_array($config["modalities"])) $config["modalities"] = array("input" => array("text"), "output" => array("text"));
        return $config;
    }

    private function identityFromConfig($config) {
        if (!is_array($config)) {
            return array("profile" => array(), "user" => array());
        }
        $agent = isset($config["agent"]) && is_array($config["agent"]) ? $config["agent"] : array();
        $profile = isset($agent["profile"]) && is_array($agent["profile"]) ? $agent["profile"] : array();
        $user = isset($agent["user"]) && is_array($agent["user"]) ? $agent["user"] : array();
        if (!count($user) && isset($config["systemUser"]) && is_array($config["systemUser"])) {
            $user = $config["systemUser"];
        }
        return array("profile" => $profile, "user" => $user);
    }

    private function userByEmail($email) {
        if (!class_exists("\\SOSSData") || trim((string)$email) === "") {
            return null;
        }
        $result = \SOSSData::Query("users", "email:" . strtolower(trim((string)$email)));
        if ($result->success && isset($result->result) && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function userById($userId) {
        if (!class_exists("\\SOSSData") || trim((string)$userId) === "") {
            return null;
        }
        $result = \SOSSData::Query("users", "userid:" . trim((string)$userId));
        if ($result->success && isset($result->result) && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function profileById($profileId) {
        if (!class_exists("\\SOSSData") || (int)$profileId <= 0) {
            return null;
        }
        $result = \SOSSData::Query("profile", urlencode("id:" . (int)$profileId), null, "desc", 1, 0, null, false);
        if ($result->success && isset($result->result) && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function profileByEmail($email) {
        if (!class_exists("\\SOSSData") || trim((string)$email) === "") {
            return null;
        }
        $result = \SOSSData::Query("profile", urlencode("email:" . strtolower(trim((string)$email))), null, "desc", 1, 0, null, false);
        if ($result->success && isset($result->result) && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function isAgentProfile($profile) {
        return isset($profile->catogory) && strtolower(trim((string)$profile->catogory)) === "ai agent";
    }

    private function randomPassword() {
        if (function_exists("random_bytes")) {
            $bytes = random_bytes(12);
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes(12);
        } else {
            $bytes = hash("sha256", uniqid("agent", true), true);
        }
        $token = rtrim(strtr(base64_encode($bytes), "+/", "AZ"), "=");
        return "Ai" . substr($token, 0, 14) . "7";
    }

    private function authHostName() {
        if (defined("HOST_NAME")) {
            return HOST_NAME;
        }
        if (defined("AUTH_DOMAIN")) {
            return AUTH_DOMAIN;
        }
        return isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "localhost";
    }

    private function profileImageUrl($profileId) {
        return "components/dock/soss-uploader/service/get/profile/" . (int)$profileId;
    }

    private function clearProfileCache() {
        if (class_exists("\\CacheData")) {
            \CacheData::clearObjects("profile");
        }
    }

    private function clearUserCaches() {
        if (class_exists("\\CacheData")) {
            \CacheData::clearObjects("users");
            \CacheData::clearObjects("usergroups");
            \CacheData::clearObjects("sys_access");
            \CacheData::clearObjects("domain_permision_e");
        }
    }

    private function callProvider($config, $message, $runtimeContext = array(), $history = array(), $content = array()) {
        $provider = $config["provider"]["type"];
        $payload = $this->providerPayload($config, $message, $runtimeContext, $history, $content);
        $url = $this->providerUrl($config);
        $headers = array("Content-Type: application/json");

        if ($provider === "openai") {
            $headers[] = "Authorization: Bearer " . $config["connection"]["auth"]["apiKey"];
        } elseif ($provider === "google") {
            $url .= "?key=" . rawurlencode($config["connection"]["auth"]["apiKey"]);
        } elseif ($provider === "other") {
            $auth = $config["connection"]["auth"];
            if (!empty($auth["header"])) {
                $headers[] = $auth["header"];
            } elseif (!empty($auth["apiKey"])) {
                $headers[] = "Authorization: Bearer " . $auth["apiKey"];
            }
        }

        return $this->sendJsonRequest($url, $config["connection"]["httpMethod"], $headers, $payload, $provider);
    }

    private function providerUrl($config) {
        if ($config["provider"]["type"] === "google") {
            return rtrim($config["connection"]["baseUrl"], "/") . "/" . $config["connection"]["resource"];
        }
        return $config["connection"]["endpoint"];
    }

    private function providerPayload($config, $message, $runtimeContext, $history, $content = array()) {
        $provider = $config["provider"]["type"];
        $model = $config["provider"]["model"];
        $messages = $this->conversationMessages($config, $message, $runtimeContext, $history);
        $systemPrompt = $messages[0]["content"];
        $temperature = $config["parameters"]["temperature"];
        $maxTokens = $config["parameters"]["maxTokens"];

        if ($provider === "ollama") {
            if (count($content)) {
                $last = count($messages) - 1;
                $messages[$last] = $this->ollamaMessage($message, $content);
            }
            return array(
                "model" => $model,
                "messages" => $messages,
                "stream" => false,
                "options" => array("temperature" => $temperature, "num_predict" => $maxTokens)
            );
        }

        if ($provider === "google") {
            $contents = array();
            $conversation = array_slice($messages, 1);
            foreach ($conversation as $index => $item) {
                $isLast = $index === count($conversation) - 1;
                $contents[] = array(
                    "role" => $item["role"] === "assistant" ? "model" : "user",
                    "parts" => $isLast && count($content) ? $this->googleParts($content) : array(array("text" => $item["content"]))
                );
            }

            return array(
                "systemInstruction" => array("parts" => array(array("text" => $systemPrompt))),
                "contents" => $contents,
                "generationConfig" => array("temperature" => $temperature, "maxOutputTokens" => $maxTokens)
            );
        }

        if ($provider === "other") {
            return array(
                "model" => $model,
                "messages" => $messages,
                "parameters" => $config["parameters"],
                "agent" => array(
                    "code" => $config["agent"]["code"],
                    "name" => $config["agent"]["name"],
                    "capabilities" => $config["agent"]["capabilities"],
                    "skills" => isset($config["agent"]["skills"]) ? $config["agent"]["skills"] : array()
                )
            );
        }

        $apiMode = isset($config["provider"]["apiMode"]) ? $config["provider"]["apiMode"] : "chat-completions";
        if ($provider === "openai" && $apiMode === "responses") {
            $input = array();
            foreach ($messages as $index => $item) {
                $isLast = $index === count($messages) - 1;
                $input[] = array(
                    "role" => $item["role"],
                    "content" => $isLast && count($content)
                        ? $this->openAiResponseContent($content)
                        : array(array("type" => $item["role"] === "assistant" ? "output_text" : "input_text", "text" => $item["content"]))
                );
            }
            return array("model" => $model, "input" => $input, "max_output_tokens" => $maxTokens, "stream" => false);
        }

        if (count($content) && ($provider === "openai" || $provider === "lmstudio")) {
            $messages[count($messages) - 1]["content"] = $this->openAiChatContent($content);
        }

        return array(
            "model" => $model,
            "messages" => $messages,
            "temperature" => $temperature,
            "max_tokens" => $maxTokens,
            "stream" => false
        );
    }

    private function openAiResponseContent($content) {
        $parts = array();
        foreach ($content as $item) {
            if ($item["type"] === "text") {
                $parts[] = array("type" => "input_text", "text" => $item["text"]);
            } elseif ($item["type"] === "image") {
                $parts[] = array("type" => "input_image", "image_url" => $item["url"]);
            } elseif ($item["type"] === "document") {
                $parts[] = array("type" => "input_file", "file_url" => $item["url"]);
            }
        }
        return $parts;
    }

    private function openAiChatContent($content) {
        $parts = array();
        foreach ($content as $item) {
            if ($item["type"] === "text") {
                $parts[] = array("type" => "text", "text" => $item["text"]);
            } elseif ($item["type"] === "image") {
                $parts[] = array("type" => "image_url", "image_url" => array("url" => $item["url"]));
            }
        }
        return $parts;
    }

    private function googleParts($content) {
        $parts = array();
        foreach ($content as $item) {
            if ($item["type"] === "text") {
                $parts[] = array("text" => $item["text"]);
                continue;
            }
            if (strpos($item["url"], "data:") === 0) {
                $comma = strpos($item["url"], ",");
                $parts[] = array("inlineData" => array("mimeType" => $item["mimeType"], "data" => substr($item["url"], $comma + 1)));
            } else {
                $parts[] = array("fileData" => array("mimeType" => $item["mimeType"], "fileUri" => $item["url"]));
            }
        }
        return $parts;
    }

    private function ollamaMessage($message, $content) {
        $images = array();
        foreach ($content as $item) {
            if ($item["type"] === "image") {
                $comma = strpos($item["url"], ",");
                if (strpos($item["url"], "data:") !== 0 || $comma === false) {
                    continue;
                }
                $images[] = substr($item["url"], $comma + 1);
            }
        }
        $out = array("role" => "user", "content" => $message);
        if (count($images)) {
            $out["images"] = $images;
        }
        return $out;
    }

    private function conversationMessages($config, $message, $runtimeContext, $history) {
        $messages = array(array(
            "role" => "system",
            "content" => $this->enhancedSystemPrompt($config, $runtimeContext)
        ));

        $history = is_array($history) ? array_slice($history, -20) : array();
        foreach ($history as $item) {
            if (!is_array($item) || !isset($item["role"]) || !isset($item["content"])) {
                continue;
            }
            $role = $item["role"] === "assistant" ? "assistant" : "user";
            $content = trim(substr((string)$item["content"], 0, 5000));
            if ($content !== "") {
                $messages[] = array("role" => $role, "content" => $content);
            }
        }

        $messages[] = array("role" => "user", "content" => $message);
        return $messages;
    }

    private function enhancedSystemPrompt($config, $runtimeContext) {
        $prompt = isset($config["agent"]["startupPrompt"]) ? (string)$config["agent"]["startupPrompt"] : "";
        $parts = array($prompt);
        $parts[] = "Runtime rules: use the saved customer profile and session context when responding. Use executed skill results as trusted context. Do not say a service action succeeded unless a service_call skill result reports success.";

        if (!empty($runtimeContext["profile"])) {
            $parts[] = "Customer profile JSON:\n" . $this->jsonForPrompt($runtimeContext["profile"], 3000);
        }
        if (!empty($runtimeContext["session"])) {
            $parts[] = "Session context JSON:\n" . $this->jsonForPrompt($runtimeContext["session"], 5000);
        }
        if (!empty($runtimeContext["flow"])) {
            $parts[] = "Flow JSON:\n" . $this->jsonForPrompt($runtimeContext["flow"], 2000);
        }
        if (!empty($runtimeContext["connector"])) {
            $parts[] = "Connector JSON:\n" . $this->jsonForPrompt($runtimeContext["connector"], 2000);
        }
        if (!empty($runtimeContext["skillCatalog"])) {
            $parts[] = "Available skills JSON:\n" . $this->jsonForPrompt($runtimeContext["skillCatalog"], 5000);
        }
        if (!empty($runtimeContext["skillResults"])) {
            $parts[] = "Executed skill results JSON:\n" . $this->jsonForPrompt($runtimeContext["skillResults"], 7000);
        }

        return implode("\n\n", array_filter($parts, "strlen"));
    }

    private function jsonForPrompt($value, $limit) {
        $json = json_encode($this->maskSecrets($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return "{}";
        }
        if (strlen($json) > $limit) {
            return substr($json, 0, $limit) . "\n...";
        }
        return $json;
    }

    private function sendJsonRequest($url, $method, $headers, $payload, $provider) {
        $ch = curl_init($url);
        $payloadJson = json_encode($payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return $this->fail($this->sanitizeProviderError("Agent request failed: " . $curlError));
        }

        $response = json_decode($raw);
        if (!is_object($response)) {
            return $this->fail("Agent provider returned a non-JSON response.");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = "Agent provider request failed with HTTP " . $httpCode . ".";
            if (isset($response->error->message)) {
                $message = $response->error->message;
            } elseif (isset($response->error) && is_string($response->error)) {
                $message = $response->error;
            }
            return $this->fail($this->sanitizeProviderError($message));
        }

        $reply = $this->extractReply($provider, $response);
        $outputs = $this->extractOutputs($provider, $response);
        if ($reply === "" && !count($outputs)) {
            return $this->fail("Agent provider returned JSON, but no text response was found.");
        }

        $out = $this->ok();
        $out->reply = $reply;
        $out->outputs = $outputs;
        $out->usage = $this->extractTokenUsage($provider, $response, $payload, $reply);
        $out->requestChars = strlen((string)$payloadJson);
        $out->responseChars = strlen((string)$reply);
        $out->httpCode = $httpCode;
        $out->raw = $response;
        return $out;
    }

    private function extractReply($provider, $response) {
        if (($provider === "openai" || $provider === "lmstudio" || $provider === "other") && isset($response->choices[0]->message->content)) {
            return trim((string)$response->choices[0]->message->content);
        }
        if (isset($response->output_text) && is_string($response->output_text)) {
            return trim($response->output_text);
        }
        if (isset($response->output) && is_array($response->output)) {
            $text = array();
            foreach ($response->output as $output) {
                if (!isset($output->content) || !is_array($output->content)) {
                    continue;
                }
                foreach ($output->content as $part) {
                    if (isset($part->text) && is_string($part->text)) {
                        $text[] = $part->text;
                    }
                }
            }
            if (count($text)) {
                return trim(implode("\n", $text));
            }
        }
        if ($provider === "ollama" && isset($response->message->content)) {
            return trim((string)$response->message->content);
        }
        if ($provider === "ollama" && isset($response->response)) {
            return trim((string)$response->response);
        }
        if ($provider === "google" && isset($response->candidates[0]->content->parts) && is_array($response->candidates[0]->content->parts)) {
            $parts = array();
            foreach ($response->candidates[0]->content->parts as $part) {
                if (isset($part->text)) {
                    $parts[] = $part->text;
                }
            }
            return trim(implode("\n", $parts));
        }
        if (isset($response->reply)) {
            return trim((string)$response->reply);
        }
        if (isset($response->text)) {
            return trim((string)$response->text);
        }
        return "";
    }

    private function extractOutputs($provider, $response) {
        $outputs = array();
        if ($provider === "google" && isset($response->candidates[0]->content->parts) && is_array($response->candidates[0]->content->parts)) {
            foreach ($response->candidates[0]->content->parts as $part) {
                if (isset($part->inlineData->data) && isset($part->inlineData->mimeType)) {
                    $mime = (string)$part->inlineData->mimeType;
                    $outputs[] = array("type" => $this->contentTypeFromMime($mime), "url" => "data:" . $mime . ";base64," . (string)$part->inlineData->data, "mimeType" => $mime);
                } elseif (isset($part->fileData->fileUri)) {
                    $mime = isset($part->fileData->mimeType) ? (string)$part->fileData->mimeType : "application/octet-stream";
                    $outputs[] = array("type" => $this->contentTypeFromMime($mime), "url" => (string)$part->fileData->fileUri, "mimeType" => $mime);
                }
            }
        }
        if (isset($response->output) && is_array($response->output)) {
            foreach ($response->output as $output) {
                if (!isset($output->content) || !is_array($output->content)) {
                    continue;
                }
                foreach ($output->content as $part) {
                    $url = isset($part->image_url) ? $part->image_url : (isset($part->url) ? $part->url : "");
                    if ($url !== "") {
                        $outputs[] = array("type" => "image", "url" => (string)$url, "mimeType" => isset($part->mime_type) ? (string)$part->mime_type : "image/png");
                    }
                }
            }
        }
        return $outputs;
    }

    private function sanitizeProviderError($message) {
        $message = substr((string)$message, 0, 1000);
        $message = preg_replace('/(sk-[A-Za-z0-9_-]{8,}|AIza[A-Za-z0-9_-]{8,})/', '********', $message);
        $message = preg_replace('/(authorization|api[-_ ]?key|token)\s*[:=]\s*[^\s,;]+/i', '$1: ********', $message);
        return $message;
    }

    private function extractTokenUsage($provider, $response, $payload, $reply) {
        $inputTokens = $this->firstIntPath($response, array(
            "usage.prompt_tokens",
            "usage.input_tokens",
            "usageMetadata.promptTokenCount",
            "token_usage.prompt_tokens",
            "prompt_eval_count"
        ));
        $outputTokens = $this->firstIntPath($response, array(
            "usage.completion_tokens",
            "usage.output_tokens",
            "usageMetadata.candidatesTokenCount",
            "token_usage.completion_tokens",
            "eval_count"
        ));
        $totalTokens = $this->firstIntPath($response, array(
            "usage.total_tokens",
            "usageMetadata.totalTokenCount",
            "token_usage.total_tokens",
            "total_tokens"
        ));
        $cachedTokens = $this->firstIntPath($response, array(
            "usage.prompt_tokens_details.cached_tokens",
            "usage.input_tokens_details.cached_tokens",
            "usageMetadata.cachedContentTokenCount",
            "cached_tokens"
        ));
        $reasoningTokens = $this->firstIntPath($response, array(
            "usage.completion_tokens_details.reasoning_tokens",
            "usage.output_tokens_details.reasoning_tokens",
            "reasoning_tokens"
        ));

        if ($totalTokens <= 0 && ($inputTokens > 0 || $outputTokens > 0)) {
            $totalTokens = $inputTokens + $outputTokens;
        }
        if ($inputTokens <= 0 && $totalTokens > 0 && $outputTokens > 0) {
            $inputTokens = max(0, $totalTokens - $outputTokens);
        }
        if ($outputTokens <= 0 && $totalTokens > 0 && $inputTokens > 0) {
            $outputTokens = max(0, $totalTokens - $inputTokens);
        }

        $estimated = "false";
        $source = $provider . "_usage";
        if ($totalTokens <= 0) {
            $estimated = "true";
            $source = "estimated_chars";
            $inputTokens = $this->estimateTokensFromText(json_encode($payload));
            $outputTokens = $this->estimateTokensFromText($reply);
            $totalTokens = $inputTokens + $outputTokens;
        }

        return array(
            "inputTokens" => $inputTokens,
            "outputTokens" => $outputTokens,
            "totalTokens" => $totalTokens,
            "cachedTokens" => $cachedTokens,
            "reasoningTokens" => $reasoningTokens,
            "estimated" => $estimated,
            "source" => $source,
            "rawUsage" => $this->rawUsageFromResponse($response)
        );
    }

    private function emptyTokenUsage() {
        return array(
            "inputTokens" => 0,
            "outputTokens" => 0,
            "totalTokens" => 0,
            "cachedTokens" => 0,
            "reasoningTokens" => 0,
            "estimated" => "true",
            "source" => "unavailable",
            "rawUsage" => null
        );
    }

    private function firstIntPath($source, $paths) {
        foreach ($paths as $path) {
            $value = $this->anyPathValue($source, $path);
            if (is_numeric($value)) {
                return max(0, (int)$value);
            }
        }
        return 0;
    }

    private function anyPathValue($source, $path) {
        $current = $source;
        foreach (explode(".", $path) as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif (is_object($current) && isset($current->$part)) {
                $current = $current->$part;
            } elseif (is_array($current) && ctype_digit($part) && array_key_exists((int)$part, $current)) {
                $current = $current[(int)$part];
            } else {
                return null;
            }
        }
        return $current;
    }

    private function rawUsageFromResponse($response) {
        $usage = $this->anyPathValue($response, "usage");
        if ($usage !== null) {
            return $usage;
        }
        $usage = $this->anyPathValue($response, "usageMetadata");
        if ($usage !== null) {
            return $usage;
        }
        $usage = $this->anyPathValue($response, "token_usage");
        if ($usage !== null) {
            return $usage;
        }

        $ollamaPrompt = $this->anyPathValue($response, "prompt_eval_count");
        $ollamaCompletion = $this->anyPathValue($response, "eval_count");
        if ($ollamaPrompt !== null || $ollamaCompletion !== null) {
            return array(
                "prompt_eval_count" => $ollamaPrompt,
                "eval_count" => $ollamaCompletion
            );
        }
        return null;
    }

    private function estimateTokensFromText($value) {
        $text = trim(preg_replace("/\s+/", " ", (string)$value));
        if ($text === "") {
            return 0;
        }
        return max(1, (int)ceil(strlen($text) / 4));
    }

    private function normalizeRuntimeContent($raw, $message) {
        if (is_object($raw)) $raw = (array)$raw;
        if ($raw === null || $raw === "") $raw = array();
        if (!is_array($raw)) return $this->fail("Content must be an array.");
        $allowedMimes = array(
            "image" => array("image/jpeg", "image/png", "image/webp", "image/gif"),
            "audio" => array("audio/mpeg", "audio/wav", "audio/x-wav", "audio/ogg", "audio/mp4", "audio/webm"),
            "video" => array("video/mp4", "video/webm", "video/quicktime"),
            "document" => array("application/pdf", "text/plain", "text/csv", "application/json", "application/vnd.openxmlformats-officedocument.wordprocessingml.document")
        );
        $providedContent = count($raw) > 0;
        $content = array(); $attachments = 0; $totalBytes = 0; $hasText = false;
        foreach ($raw as $index => $item) {
            $item = $this->objectToArray($item);
            $type = strtolower($this->arrayString($item, "type", ""));
            if ($type === "file") $type = "document";
            if ($type === "text") {
                $text = trim($this->arrayString($item, "text", ""));
                if ($text === "") return $this->fail("Content item " . ($index + 1) . " has empty text.");
                $content[] = array("type" => "text", "text" => $this->limitText($text, 50000));
                $hasText = true;
                continue;
            }
            if (!isset($allowedMimes[$type])) return $this->fail("Content item " . ($index + 1) . " has an unsupported type.");
            $mime = strtolower($this->arrayString($item, "mimeType", ""));
            if (!in_array($mime, $allowedMimes[$type], true)) return $this->fail("Content item " . ($index + 1) . " has an unsupported MIME type.");
            $url = trim($this->arrayString($item, "url", ""));
            if (!$this->isSafeContentReference($url, $mime)) return $this->fail("Content item " . ($index + 1) . " has an unsafe or unsupported reference.");
            $bytes = isset($item["size"]) && is_numeric($item["size"]) ? (int)$item["size"] : $this->dataUrlBytes($url);
            if ($bytes < 0 || $bytes > 10485760) return $this->fail("Each attachment must be 10 MB or smaller.");
            $totalBytes += $bytes; $attachments++;
            if ($attachments > 8 || $totalBytes > 20971520) return $this->fail("Use at most 8 attachments and 20 MB total.");
            $content[] = array(
                "type" => $type, "url" => $url, "mimeType" => $mime,
                "name" => $this->limitText(basename(str_replace("\\", "/", $this->arrayString($item, "name", $type))), 180), "size" => $bytes
            );
        }
        if ($providedContent && !$hasText && trim($message) !== "") array_unshift($content, array("type" => "text", "text" => $this->limitText(trim($message), 50000)));
        $out = $this->ok(); $out->content = $content; return $out;
    }

    private function isSafeContentReference($url, $mime) {
        if ($url === "" || preg_match('/^[A-Za-z]:[\\\\\/]/', $url) || strpos($url, "../") !== false || strpos($url, "..\\") !== false) return false;
        if (strpos($url, "data:") === 0) {
            if (strpos($url, "data:" . $mime . ";base64,") !== 0) return false;
            $comma = strpos($url, ",");
            return $comma !== false && base64_decode(substr($url, $comma + 1), true) !== false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts["scheme"]) || !in_array(strtolower($parts["scheme"]), array("https", "gs"), true)) return false;
        if (isset($parts["host"])) {
            $host = strtolower($parts["host"]);
            $ip = filter_var($host, FILTER_VALIDATE_IP);
            if ($host === "localhost" || substr($host, -6) === ".local" || ($ip && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) return false;
        }
        return true;
    }

    private function dataUrlBytes($url) {
        if (strpos($url, "data:") !== 0) return 0;
        $comma = strpos($url, ","); $encoded = $comma === false ? "" : substr($url, $comma + 1);
        return (int)floor(strlen($encoded) * 3 / 4);
    }

    private function contentTypeFromMime($mime) {
        if (strpos($mime, "image/") === 0) return "image";
        if (strpos($mime, "audio/") === 0) return "audio";
        if (strpos($mime, "video/") === 0) return "video";
        return "document";
    }

    private function validateContentForConfig($config, $content) {
        $enabled = isset($config["modalities"]["input"]) && is_array($config["modalities"]["input"]) ? $config["modalities"]["input"] : array("text");
        $provider = isset($config["provider"]["type"]) ? $config["provider"]["type"] : "";
        foreach ($content as $item) {
            if (!in_array($item["type"], $enabled, true)) return "This agent is not configured for " . $item["type"] . " input.";
            if ($provider === "ollama" && $item["type"] === "image" && strpos($item["url"], "data:") !== 0) return "Ollama image input must be uploaded inline; the server does not fetch URLs or local paths.";
        }
        return true;
    }

    private function calculateUsageCost($config, $usage) {
        $model = isset($config["provider"]["model"]) ? $config["provider"]["model"] : "";
        $provider = isset($config["provider"]["type"]) ? $config["provider"]["type"] : "";
        $meta = isset($config["provider"]["modelInfo"]) && is_array($config["provider"]["modelInfo"]) ? $config["provider"]["modelInfo"] : $this->modelMetadata($provider, $model);
        $pricing = isset($meta["pricing"]) && is_array($meta["pricing"]) ? $meta["pricing"] : array();
        if (($provider === "ollama" || $provider === "lmstudio") && isset($pricing["status"]) && $pricing["status"] === "local") return array("status" => "local", "currency" => "USD", "amount" => "0", "estimated" => true, "note" => "No per-token provider API fee; local operating costs are excluded.");
        if (!isset($pricing["inputPerMillionTokens"], $pricing["outputPerMillionTokens"]) || $pricing["inputPerMillionTokens"] === null || $pricing["outputPerMillionTokens"] === null) return array("status" => "unavailable", "currency" => "USD", "amount" => null, "estimated" => true, "note" => "Pricing unavailable.");
        $input = $this->usageInt($usage, "inputTokens"); $output = $this->usageInt($usage, "outputTokens");
        $cached = min($input, $this->usageInt($usage, "cachedTokens")); $uncached = $input - $cached;
        $inputRate = $this->decimalRateToPicoPerToken($pricing["inputPerMillionTokens"]);
        $outputRate = $this->decimalRateToPicoPerToken($pricing["outputPerMillionTokens"]);
        $cachedRate = isset($pricing["cachedInputPerMillionTokens"]) && $pricing["cachedInputPerMillionTokens"] !== null ? $this->decimalRateToPicoPerToken($pricing["cachedInputPerMillionTokens"]) : $inputRate;
        $picoUsd = ($uncached * $inputRate) + ($cached * $cachedRate) + ($output * $outputRate);
        $amount = rtrim(rtrim(number_format($picoUsd / 1000000000000, 12, ".", ""), "0"), "."); if ($amount === "") $amount = "0";
        return array("status" => "estimated", "currency" => isset($pricing["currency"]) ? $pricing["currency"] : "USD", "amount" => $amount, "estimated" => true,
            "source" => isset($pricing["officialUrl"]) ? $pricing["officialUrl"] : "", "lastVerified" => isset($pricing["lastVerified"]) ? $pricing["lastVerified"] : "",
            "breakdown" => array("uncachedInputTokens" => $uncached, "cachedInputTokens" => $cached, "outputTokens" => $output));
    }

    private function decimalRateToPicoPerToken($value) {
        $value = trim((string)$value); if (!preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches)) return 0;
        $fraction = isset($matches[2]) ? substr(str_pad($matches[2], 6, "0"), 0, 6) : "000000";
        return ((int)$matches[1] * 1000000) + (int)$fraction;
    }

    private function skillsValue($body) {
        $out = $this->ok();
        $out->skills = array();

        if (!isset($body->skills)) {
            return $out;
        }

        $source = $body->skills;
        if (is_string($source)) {
            $source = trim($source);
            if ($source === "") {
                return $out;
            }
            $decoded = json_decode($source, true);
            if (!is_array($decoded)) {
                return $this->fail("Skills JSON must be a valid JSON array.");
            }
            $source = $decoded;
        } else {
            $source = $this->objectToArray($source);
        }

        if ($this->isAssoc($source) && isset($source["code"])) {
            $source = array($source);
        }

        if (!is_array($source)) {
            return $this->fail("Skills must be an array.");
        }

        foreach ($source as $item) {
            $normalized = $this->normalizeSkill($item);
            if (!$normalized->success) {
                return $normalized;
            }
            $out->skills[] = $normalized->skill;
        }

        return $out;
    }

    private function normalizeSkill($item) {
        $item = is_array($item) ? $item : $this->objectToArray($item);
        $code = $this->normalizeSkillCode($this->arrayString($item, "code", ""));
        if ($code === "") {
            return $this->fail("Each skill requires a code using lowercase letters, numbers, hyphens, or underscores.");
        }

        $type = strtolower($this->arrayString($item, "type", "data_query"));
        if (!in_array($type, array("data_query", "service_call"))) {
            return $this->fail("Skill " . $code . " has an unsupported type.");
        }

        $runMode = strtolower($this->arrayString($item, "runMode", "triggered"));
        if (!in_array($runMode, array("always", "triggered", "manual"))) {
            $runMode = "triggered";
        }

        $skill = array(
            "code" => $code,
            "name" => $this->arrayString($item, "name", $code),
            "type" => $type,
            "enabled" => $this->boolFromArray($item, "enabled", true),
            "runMode" => $runMode,
            "description" => $this->arrayString($item, "description", ""),
            "triggerKeywords" => $this->arrayList($item, "triggerKeywords")
        );

        if ($type === "data_query") {
            $skill["source"] = $this->arrayString($item, "source", "json_file");
            $skill["dataFile"] = $this->arrayString($item, "dataFile", "");
            $skill["queryFields"] = $this->arrayList($item, "queryFields");
            $skill["limit"] = $this->integerFromArray($item, "limit", 5, 1, 25);
            if (isset($item["data"]) && is_array($item["data"])) {
                $skill["data"] = $item["data"];
            }
        } else {
            $method = strtoupper($this->arrayString($item, "method", "POST"));
            if (!in_array($method, array("GET", "POST", "PUT", "PATCH"))) {
                $method = "POST";
            }
            $skill["method"] = $method;
            $skill["url"] = $this->arrayString($item, "url", "");
            $skill["headers"] = isset($item["headers"]) && is_array($item["headers"]) ? $item["headers"] : array();
            $skill["bodyTemplate"] = isset($item["bodyTemplate"]) ? $item["bodyTemplate"] : array();
            $skill["timeoutSeconds"] = $this->integerFromArray($item, "timeoutSeconds", 20, 2, 60);
        }

        $out = $this->ok();
        $out->skill = $skill;
        return $out;
    }

    private function executeSkills($agent, $message, $profile, $session, $body) {
        $results = array();
        $skills = $this->agentSkills($agent);
        $context = array(
            "message" => $message,
            "profile" => $profile,
            "session" => $this->sessionForPrompt($session),
            "context" => isset($session["context"]) && is_array($session["context"]) ? $session["context"] : array(),
            "flow" => isset($body["flow"]) ? $this->objectToArray($body["flow"]) : array(),
            "connector" => isset($body["connector"]) ? $this->objectToArray($body["connector"]) : array(),
            "payload" => isset($body["payload"]) ? $this->objectToArray($body["payload"]) : array(),
            "now" => gmdate("c")
        );

        foreach ($skills as $skill) {
            if (!$this->shouldRunSkill($skill, $message)) {
                continue;
            }

            if ($skill["type"] === "data_query") {
                $results[] = $this->runDataQuerySkill($skill, $message, $context);
            } elseif ($skill["type"] === "service_call") {
                $results[] = $this->runServiceCallSkill($skill, $context);
            }
        }

        return $results;
    }

    private function shouldRunSkill($skill, $message) {
        if (empty($skill["enabled"])) {
            return false;
        }
        if ($skill["runMode"] === "manual") {
            return false;
        }
        if ($skill["runMode"] === "always") {
            return true;
        }

        $keywords = isset($skill["triggerKeywords"]) && is_array($skill["triggerKeywords"]) ? $skill["triggerKeywords"] : array();
        if (!count($keywords)) {
            return $skill["type"] === "data_query";
        }

        foreach ($keywords as $keyword) {
            if ($keyword !== "" && stripos($message, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private function runDataQuerySkill($skill, $message, $context) {
        $startedAt = gmdate("c");
        $records = array();

        if (isset($skill["data"]) && is_array($skill["data"])) {
            $records = $skill["data"];
        } elseif ($skill["source"] === "json_file") {
            $file = $this->tenantDataFile($skill["dataFile"]);
            if ($file === "" || !file_exists($file)) {
                return $this->skillResult($skill, "failed", "Data file was not found.", array("dataFile" => $skill["dataFile"]), $startedAt);
            }

            $decoded = json_decode(file_get_contents($file), true);
            if (!is_array($decoded)) {
                return $this->skillResult($skill, "failed", "Data file is not valid JSON.", array("dataFile" => $skill["dataFile"]), $startedAt);
            }
            $records = $this->recordListFromJson($decoded);
        }

        $matches = $this->queryRecords($records, $message, $skill["queryFields"], $skill["limit"]);
        return $this->skillResult($skill, "success", count($matches) . " records matched.", array(
            "matches" => $matches,
            "query" => $message
        ), $startedAt);
    }

    private function runServiceCallSkill($skill, $context) {
        $startedAt = gmdate("c");
        if (!function_exists("curl_init")) {
            return $this->skillResult($skill, "failed", "PHP cURL is not enabled.", null, $startedAt);
        }

        $url = $this->renderTemplate($skill["url"], $context);
        if (!$this->isHttpUrl($url)) {
            return $this->skillResult($skill, "failed", "Service URL is not a valid HTTP URL.", array("url" => $url), $startedAt);
        }

        $headers = array();
        foreach ($skill["headers"] as $key => $value) {
            $headers[] = $key . ": " . $this->renderTemplate($value, $context);
        }
        if (!count($headers)) {
            $headers[] = "Content-Type: application/json";
        }

        $method = $skill["method"];
        $body = $this->renderTemplate($skill["bodyTemplate"], $context);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $skill["timeoutSeconds"]);
        if ($method !== "GET") {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return $this->skillResult($skill, "failed", "Service call failed: " . $curlError, null, $startedAt);
        }

        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : array("raw" => substr((string)$raw, 0, 8000));
        $status = $httpCode >= 200 && $httpCode < 300 ? "success" : "failed";
        return $this->skillResult($skill, $status, "Service call returned HTTP " . $httpCode . ".", array(
            "httpCode" => $httpCode,
            "response" => $data
        ), $startedAt);
    }

    private function skillResult($skill, $status, $message, $data, $startedAt) {
        return array(
            "skillCode" => $skill["code"],
            "skillName" => $skill["name"],
            "type" => $skill["type"],
            "status" => $status,
            "message" => $message,
            "data" => $this->maskSecrets($data),
            "startedAt" => $startedAt,
            "finishedAt" => gmdate("c")
        );
    }

    private function queryRecords($records, $message, $queryFields, $limit) {
        $terms = preg_split("/[^a-z0-9@._-]+/i", strtolower($message));
        $terms = array_values(array_filter($terms, function($term) {
            return strlen($term) >= 3;
        }));
        $matches = array();

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $haystack = $this->recordSearchText($record, $queryFields);
            $score = 0;
            foreach ($terms as $term) {
                if ($term !== "" && strpos($haystack, $term) !== false) {
                    $score++;
                }
            }
            if ($score > 0 || !count($terms)) {
                $record["_score"] = $score;
                $matches[] = $record;
            }
        }

        usort($matches, function($a, $b) {
            return (isset($b["_score"]) ? $b["_score"] : 0) - (isset($a["_score"]) ? $a["_score"] : 0);
        });

        return array_slice($matches, 0, $limit);
    }

    private function recordSearchText($record, $queryFields) {
        $values = array();
        if (count($queryFields)) {
            foreach ($queryFields as $field) {
                $value = $this->pathValue($record, $field);
                if (is_scalar($value)) {
                    $values[] = (string)$value;
                }
            }
        } else {
            $values = $this->scalarValues($record);
        }
        return strtolower(implode(" ", $values));
    }

    private function scalarValues($value) {
        $out = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                $out = array_merge($out, $this->scalarValues($item));
            }
        } elseif (is_scalar($value)) {
            $out[] = (string)$value;
        }
        return $out;
    }

    private function recordListFromJson($decoded) {
        if (!$this->isAssoc($decoded)) {
            return $decoded;
        }
        foreach ($decoded as $value) {
            if (is_array($value) && !$this->isAssoc($value)) {
                return $value;
            }
        }
        return array($decoded);
    }

    private function renderTemplate($value, $context) {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $out[$key] = $this->renderTemplate($item, $context);
            }
            return $out;
        }

        if (!is_string($value)) {
            return $value;
        }

        if (preg_match('/^\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}$/', $value, $match) === 1) {
            $resolved = $this->pathValue($context, $match[1]);
            return $resolved === null ? "" : $resolved;
        }

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', function($match) use ($context) {
            $resolved = $this->pathValue($context, $match[1]);
            if (is_array($resolved) || is_object($resolved)) {
                return json_encode($resolved, JSON_UNESCAPED_SLASHES);
            }
            return $resolved === null ? "" : (string)$resolved;
        }, $value);
    }

    private function pathValue($source, $path) {
        $current = $source;
        foreach (explode(".", $path) as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif (is_array($current) && ctype_digit($part) && array_key_exists((int)$part, $current)) {
                $current = $current[(int)$part];
            } else {
                return null;
            }
        }
        return $current;
    }

    private function agentSkills($agent) {
        if (isset($agent["skills"]) && is_array($agent["skills"])) {
            return $agent["skills"];
        }
        if (isset($agent["configuration"]["skills"]) && is_array($agent["configuration"]["skills"])) {
            return $agent["configuration"]["skills"];
        }
        return array();
    }

    private function skillCatalogForPrompt($skills) {
        $catalog = array();
        foreach ($skills as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $catalog[] = array(
                "code" => isset($skill["code"]) ? $skill["code"] : "",
                "name" => isset($skill["name"]) ? $skill["name"] : "",
                "type" => isset($skill["type"]) ? $skill["type"] : "",
                "runMode" => isset($skill["runMode"]) ? $skill["runMode"] : "",
                "description" => isset($skill["description"]) ? $skill["description"] : "",
                "triggerKeywords" => isset($skill["triggerKeywords"]) ? $skill["triggerKeywords"] : array()
            );
        }
        return $catalog;
    }

    private function loadSessions() {
        $file = $this->sessionsFile();
        if (!file_exists($file)) {
            return array();
        }

        $json = json_decode(file_get_contents($file), true);
        return is_array($json) ? $json : array();
    }

    private function saveSessions($sessions) {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return $this->atomicJsonWrite($this->sessionsFile(), $sessions);
    }

    private function defaultSession($agentCode, $profileId, $sessionId) {
        $now = gmdate("c");
        return array(
            "sessionId" => $sessionId,
            "agentCode" => $agentCode,
            "profileId" => $profileId,
            "context" => array(),
            "history" => array(),
            "messageCount" => 0,
            "createdAt" => $now,
            "updatedAt" => $now
        );
    }

    private function appendSessionTurn($session, $message, $reply, $skillResults, $runtimeContext, $content = array()) {
        $now = gmdate("c");
        if (!isset($session["history"]) || !is_array($session["history"])) {
            $session["history"] = array();
        }
        if (!isset($session["context"]) || !is_array($session["context"])) {
            $session["context"] = array();
        }

        $safeContent = array();
        foreach ($content as $item) {
            if (!is_array($item) || !isset($item["type"]) || $item["type"] === "text") continue;
            $reference = isset($item["url"]) && strpos($item["url"], "data:") !== 0 ? $item["url"] : "inline-media-not-persisted";
            $safeContent[] = array("type" => $item["type"], "mimeType" => isset($item["mimeType"]) ? $item["mimeType"] : "", "name" => isset($item["name"]) ? $item["name"] : "", "size" => isset($item["size"]) ? $item["size"] : 0, "reference" => $reference);
        }
        $userTurn = array("role" => "user", "content" => $message, "at" => $now);
        if (count($safeContent)) $userTurn["attachments"] = $safeContent;
        $session["history"][] = $userTurn;
        $session["history"][] = array("role" => "assistant", "content" => $reply, "at" => $now);
        $session["history"] = array_slice($session["history"], -40);
        $session["messageCount"] = isset($session["messageCount"]) ? ((int)$session["messageCount"] + 1) : 1;
        $session["context"]["lastUserMessage"] = $message;
        $session["context"]["lastAssistantReply"] = $reply;
        $session["context"]["lastSkillResults"] = $skillResults;
        $session["context"]["lastFlow"] = isset($runtimeContext["flow"]) ? $runtimeContext["flow"] : array();
        $session["context"]["lastConnector"] = isset($runtimeContext["connector"]) ? $runtimeContext["connector"] : array();
        $session["updatedAt"] = $now;
        return $session;
    }

    private function sessionForPrompt($session) {
        return array(
            "sessionId" => isset($session["sessionId"]) ? $session["sessionId"] : "",
            "profileId" => isset($session["profileId"]) ? $session["profileId"] : "",
            "messageCount" => isset($session["messageCount"]) ? $session["messageCount"] : 0,
            "context" => isset($session["context"]) && is_array($session["context"]) ? $session["context"] : array(),
            "recentHistory" => isset($session["history"]) && is_array($session["history"]) ? array_slice($session["history"], -10) : array()
        );
    }

    private function safeSessionForClient($session) {
        return $this->maskSecrets($this->sessionForPrompt($session));
    }

    private function sessionKey($agentCode, $profileId, $sessionId) {
        return hash("sha256", $agentCode . "|" . $profileId . "|" . $sessionId);
    }

    private function defaultSessionId($agentCode, $profileId) {
        return $agentCode . "-" . substr(hash("sha256", $profileId), 0, 16);
    }

    private function appSessionId($appCode, $agentCode, $profileId, $conversationKey) {
        $profilePart = $profileId === "" ? "anonymous" : $profileId;
        return $appCode . "-" . $agentCode . "-" . substr(hash("sha256", $profilePart . "|" . $conversationKey), 0, 16);
    }

    private function tenantDataFile($dataFile) {
        $dataFile = str_replace("\\", "/", trim((string)$dataFile));
        if ($dataFile === "" || strpos($dataFile, "..") !== false || preg_match('/^[A-Za-z]:/', $dataFile)) {
            return "";
        }
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/data/" . ltrim($dataFile, "/");
        }
        return $this->storageDir() . "/" . ltrim($dataFile, "/");
    }

    private function loadAgents() {
        $file = $this->agentsFile();
        if (!file_exists($file)) {
            return array();
        }

        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) {
            return array();
        }

        return $json;
    }

    private function saveAgents($agents) {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return $this->atomicJsonWrite($this->agentsFile(), $agents);
    }

    private function atomicJsonWrite($file, $value) {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        $lock = @fopen($file . ".lock", "c");
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) fclose($lock);
            return false;
        }
        $tmp = $file . ".tmp." . getmypid() . "." . substr($this->newRuntimeId("write"), -8);
        $written = file_put_contents($tmp, $json, LOCK_EX) !== false;
        if ($written && function_exists("chmod")) @chmod($tmp, 0664);
        $saved = $written && @rename($tmp, $file);
        if (!$saved && file_exists($tmp)) @unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
        return $saved;
    }

    private function storageDir() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/data/ai-agent-creator";
        }
        return dirname(dirname(__DIR__)) . "/data";
    }

    private function agentsFile() {
        return $this->storageDir() . "/agents.json";
    }

    private function sessionsFile() {
        return $this->storageDir() . "/sessions.json";
    }

    private function safeAgentsForClient($agents) {
        $safe = array();
        foreach ($agents as $agent) {
            $safe[] = $this->safeAgentForClient($agent);
        }
        usort($safe, function($a, $b) {
            return strcmp(strtolower($a["name"]), strtolower($b["name"]));
        });
        return $safe;
    }

    private function safeAgentForClient($agent) {
        $copy = $agent;
        if (isset($copy["skills"])) {
            $copy["skills"] = $this->maskSecrets($copy["skills"]);
        }
        if (isset($copy["configuration"])) {
            $copy["configuration"] = $this->maskSecrets($copy["configuration"]);
        }
        return $copy;
    }

    private function apiKeyFromConfig($config) {
        if (isset($config["connection"]["auth"]["apiKey"])) {
            return (string)$config["connection"]["auth"]["apiKey"];
        }
        return "";
    }

    private function maskSecrets($value) {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $keyName = strtolower((string)$key);
                if (in_array($keyName, array("apikey", "api_key", "key", "token", "secret", "password", "authorization", "authheader", "header", "clientsecret", "accesstoken"))
                    || strpos($keyName, "token") !== false
                    || strpos($keyName, "secret") !== false
                    || strpos($keyName, "password") !== false
                    || strpos($keyName, "authorization") !== false
                    || strpos($keyName, "api-key") !== false) {
                    $out[$key] = $item === "" ? "" : "********";
                } else {
                    $out[$key] = $this->maskSecrets($item);
                }
            }
            return $out;
        }
        return $value;
    }

    private function objectToArray($value) {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $data = json_decode(json_encode($value), true);
            return is_array($data) ? $data : array();
        }
        return array();
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function stringValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return trim(substr((string)$body->$key, 0, 20000));
    }

    private function limitText($value, $maxLength) {
        return trim(substr((string)$value, 0, $maxLength));
    }

    private function integerString($value) {
        $value = trim((string)$value);
        return ctype_digit($value) ? $value : "0";
    }

    private function arrayString($input, $key, $default) {
        if (!is_array($input) || !isset($input[$key])) {
            return $default;
        }
        return trim(substr((string)$input[$key], 0, 50000));
    }

    private function arrayList($input, $key) {
        if (!is_array($input) || !isset($input[$key])) {
            return array();
        }

        $source = $input[$key];
        if (!is_array($source)) {
            $source = preg_split("/[\r\n,]+/", (string)$source);
        }

        $out = array();
        foreach ($source as $item) {
            $value = trim(substr((string)$item, 0, 255));
            if ($value !== "") {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    private function capabilitiesValue($body) {
        if (!isset($body->capabilities)) {
            return array();
        }

        if (is_array($body->capabilities)) {
            $items = $body->capabilities;
        } else {
            $items = preg_split("/[\r\n,]+/", (string)$body->capabilities);
        }

        $out = array();
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== "") {
                $out[] = substr($value, 0, 255);
            }
        }
        return array_values(array_unique($out));
    }

    private function normalizeAgentCode($value) {
        $value = strtolower(trim($value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "" || preg_match("/^[a-z][a-z0-9_-]{1,63}$/", $value) !== 1) {
            return "";
        }
        return $value;
    }

    private function normalizeSkillCode($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "" || preg_match("/^[a-z][a-z0-9_-]{1,80}$/", $value) !== 1) {
            return "";
        }
        return $value;
    }

    private function normalizeProfileId($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9@._:-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "") {
            return "";
        }
        return substr($value, 0, 120);
    }

    private function normalizeSessionId($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9@._:-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "") {
            return "";
        }
        return substr($value, 0, 160);
    }

    private function normalizeContextCode($value, $default) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "") {
            return $default;
        }
        return substr($value, 0, 80);
    }

    private function interactionMessage($body) {
        $message = $this->arrayString($body, "message", "");
        if ($message !== "") {
            return $message;
        }

        $message = $this->arrayString($body, "prompt", "");
        if ($message !== "") {
            return $message;
        }

        return $this->arrayString($body, "question", "");
    }

    private function boolFromArray($input, $key, $default) {
        if (!is_array($input) || !isset($input[$key])) {
            return (bool)$default;
        }
        if (is_bool($input[$key])) {
            return $input[$key];
        }
        return filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function integerFromArray($input, $key, $default, $min, $max) {
        if (!is_array($input) || !isset($input[$key]) || !is_numeric($input[$key])) {
            return $default;
        }
        $value = (int)$input[$key];
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function numberValue($body, $key, $default, $min, $max) {
        if (!isset($body->$key) || $body->$key === "" || !is_numeric($body->$key)) {
            return $default;
        }

        $value = (float)$body->$key;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function integerValue($body, $key, $default, $min, $max) {
        return (int)$this->numberValue($body, $key, $default, $min, $max);
    }

    private function boolValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return filter_var($body->$key, FILTER_VALIDATE_BOOLEAN);
    }

    private function isValidModelName($model) {
        return preg_match("/^[A-Za-z0-9._:\/-]+$/", $model) === 1;
    }

    private function isHttpUrl($value) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($value);
        return isset($parts["scheme"]) && in_array(strtolower($parts["scheme"]), array("http", "https"));
    }

    private function toYaml($value, $indent = 0) {
        $space = str_repeat("  ", $indent);

        if (is_object($value)) {
            $value = (array)$value;
        }

        if (is_array($value)) {
            $lines = array();
            $assoc = $this->isAssoc($value);

            foreach ($value as $key => $item) {
                if ($assoc) {
                    $prefix = $space . $key . ":";
                    if (is_array($item) || is_object($item)) {
                        $lines[] = $prefix;
                        $lines[] = $this->toYaml($item, $indent + 1);
                    } else {
                        $lines[] = $prefix . " " . $this->yamlScalar($item);
                    }
                } else {
                    $prefix = $space . "-";
                    if (is_array($item) || is_object($item)) {
                        $lines[] = $prefix;
                        $lines[] = $this->toYaml($item, $indent + 1);
                    } else {
                        $lines[] = $prefix . " " . $this->yamlScalar($item);
                    }
                }
            }

            return implode("\n", $lines);
        }

        return $space . $this->yamlScalar($value);
    }

    private function isAssoc($array) {
        if (array() === $array) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function yamlScalar($value) {
        if (is_bool($value)) {
            return $value ? "true" : "false";
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if ($value === null) {
            return "null";
        }
        return json_encode((string)$value);
    }

    private function ok() {
        $out = new \stdClass();
        $out->success = true;
        return $out;
    }

    private function fail($message) {
        $out = new \stdClass();
        $out->success = false;
        $out->message = $message;
        return $out;
    }
}
?>
