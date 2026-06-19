<?php
namespace ai_agent_creator;

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
        $out->config = $created->config;
        $out->json = json_encode($created->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $out->yaml = $this->toYaml($created->config);
        return $out;
    }

    public function postSaveAgent($req, $res) {
        $body = $this->body($req);
        $agentCodeForExisting = $this->normalizeAgentCode($this->stringValue($body, "agentCode", ""));
        $agents = $this->loadAgents();

        if ($agentCodeForExisting !== "" && isset($agents[$agentCodeForExisting]) && isset($agents[$agentCodeForExisting]["configuration"])) {
            $incomingKey = $this->stringValue($body, "apiKey", "");
            if ($incomingKey === "" || $incomingKey === "********") {
                $existingKey = $this->apiKeyFromConfig($agents[$agentCodeForExisting]["configuration"]);
                if ($existingKey !== "") {
                    $body->apiKey = $existingKey;
                }
            }
        }

        $created = $this->buildConfigFromBody($body, true);
        if (!$created->success) {
            return $created;
        }

        $agentCode = $created->agentCode;
        $existing = isset($agents[$agentCode]) && is_array($agents[$agentCode]) ? $agents[$agentCode] : array();
        $now = gmdate("c");

        $agent = array(
            "agentCode" => $agentCode,
            "name" => $created->agentName,
            "description" => $created->description,
            "capabilities" => $created->capabilities,
            "skills" => $created->skills,
            "configuration" => $created->config,
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
        if ($message === "") {
            return $this->fail("Test message is required.");
        }

        return $this->runAgent(array(
            "agentCode" => $agentCode,
            "message" => $message,
            "profile" => array(
                "profileId" => $profileId === "" ? "creator-console" : $profileId,
                "externalId" => "creator-console",
                "connectorCode" => "creator-console"
            ),
            "sessionId" => "creator-console-" . ($profileId === "" ? "default" : $profileId),
            "flow" => array("flowCode" => "creator-console", "name" => "Creator Console"),
            "connector" => array("code" => "creator-console", "label" => "Creator Console"),
            "payload" => array("source" => "creator-console")
        ));
    }

    public function postRunAgent($req, $res) {
        return $this->runAgent($this->body($req));
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

    public function runAgent($input) {
        $body = $this->objectToArray($input);
        $agentCode = $this->normalizeAgentCode($this->arrayString($body, "agentCode", ""));
        $message = $this->arrayString($body, "message", "");

        if ($agentCode === "") {
            return $this->fail("Agent code is required.");
        }
        if ($message === "") {
            return $this->fail("Message is required.");
        }

        $agents = $this->loadAgents();
        if (!isset($agents[$agentCode])) {
            return $this->fail("Saved agent was not found.");
        }

        if (!function_exists("curl_init")) {
            return $this->fail("PHP cURL is not enabled. Enable the curl extension before running agents.");
        }

        $agent = $agents[$agentCode];
        $config = $agent["configuration"];
        $profile = isset($body["profile"]) ? $this->objectToArray($body["profile"]) : array();
        $profileId = $this->normalizeProfileId($this->arrayString($profile, "profileId", ""));
        if ($profileId === "") {
            $profileId = $this->normalizeProfileId($this->arrayString($profile, "externalId", ""));
        }
        if ($profileId === "") {
            $profileId = "anonymous-" . substr(hash("sha256", $agentCode . "|" . $message), 0, 12);
            $profile["profileId"] = $profileId;
        }

        $sessionId = $this->normalizeSessionId($this->arrayString($body, "sessionId", ""));
        if ($sessionId === "") {
            $sessionId = $this->defaultSessionId($agentCode, $profileId);
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

        $result = $this->callProvider($config, $message, $runtimeContext, isset($session["history"]) && is_array($session["history"]) ? $session["history"] : array());
        if (!$result->success) {
            return $result;
        }

        $session = $this->appendSessionTurn($session, $message, $result->reply, $skillResults, $runtimeContext);
        $sessions[$sessionKey] = $session;
        if (!$this->saveSessions($sessions)) {
            return $this->fail("Agent replied, but the session context could not be saved.");
        }

        $out = $this->ok();
        $out->agentCode = $agentCode;
        $out->reply = $result->reply;
        $out->provider = $config["provider"]["type"];
        $out->model = $config["provider"]["model"];
        $out->profile = $profile;
        $out->session = $this->safeSessionForClient($session);
        $out->skillResults = $skillResults;
        $out->raw = isset($result->raw) ? $result->raw : null;
        return $out;
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
        if ($systemPrompt === "") {
            return $this->fail("Startup/system prompt is required.");
        }

        $temperature = $this->numberValue($body, "temperature", 0.7, 0, 2);
        $maxTokens = $this->integerValue($body, "maxTokens", 2048, 1, 200000);
        $streaming = $this->boolValue($body, "streaming", true);

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
        return array(
            "openai" => array(
                "type" => "openai",
                "label" => "OpenAI API",
                "connectionMethod" => "REST API (chat/completions)",
                "defaultEndpoint" => "https://api.openai.com/v1/chat/completions"
            ),
            "ollama" => array(
                "type" => "ollama",
                "label" => "Local Ollama",
                "connectionMethod" => "Local runtime (CLI/HTTP)",
                "defaultEndpoint" => "http://localhost:11434/api/chat"
            ),
            "lmstudio" => array(
                "type" => "lmstudio",
                "label" => "LM Studio",
                "connectionMethod" => "Local inference server",
                "defaultEndpoint" => "http://localhost:1234/v1/chat/completions"
            ),
            "google" => array(
                "type" => "google",
                "label" => "Google AI API",
                "connectionMethod" => "Generative Language API",
                "defaultEndpoint" => "https://generativelanguage.googleapis.com/v1beta"
            ),
            "other" => array(
                "type" => "other",
                "label" => "Other 3rd-party API",
                "connectionMethod" => "Custom API schema",
                "defaultEndpoint" => ""
            )
        );
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
            if ($endpoint === "" && $cliCommand === "") {
                return "Ollama requires a local HTTP endpoint or CLI command.";
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

    private function buildConfig($meta, $provider, $model, $apiKey, $endpoint, $cliCommand, $customMethod, $authHeader, $systemPrompt, $temperature, $maxTokens, $streaming, $skills, $agentMeta) {
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
            $connection["path"] = "/v1/chat/completions";
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
                "model" => $model
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
            "parameters" => array(
                "temperature" => $temperature,
                "maxTokens" => $maxTokens,
                "streaming" => $streaming
            ),
            "createdAt" => gmdate("c")
        );
    }

    private function callProvider($config, $message, $runtimeContext = array(), $history = array()) {
        $provider = $config["provider"]["type"];
        $payload = $this->providerPayload($config, $message, $runtimeContext, $history);
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

    private function providerPayload($config, $message, $runtimeContext, $history) {
        $provider = $config["provider"]["type"];
        $model = $config["provider"]["model"];
        $messages = $this->conversationMessages($config, $message, $runtimeContext, $history);
        $systemPrompt = $messages[0]["content"];
        $temperature = $config["parameters"]["temperature"];
        $maxTokens = $config["parameters"]["maxTokens"];

        if ($provider === "ollama") {
            return array(
                "model" => $model,
                "messages" => $messages,
                "stream" => false,
                "options" => array("temperature" => $temperature, "num_predict" => $maxTokens)
            );
        }

        if ($provider === "google") {
            $contents = array();
            foreach (array_slice($messages, 1) as $item) {
                $contents[] = array(
                    "role" => $item["role"] === "assistant" ? "model" : "user",
                    "parts" => array(array("text" => $item["content"]))
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

        return array(
            "model" => $model,
            "messages" => $messages,
            "temperature" => $temperature,
            "max_tokens" => $maxTokens,
            "stream" => false
        );
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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return $this->fail("Agent request failed: " . $curlError);
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
            return $this->fail($message);
        }

        $reply = $this->extractReply($provider, $response);
        if ($reply === "") {
            return $this->fail("Agent provider returned JSON, but no text response was found.");
        }

        $out = $this->ok();
        $out->reply = $reply;
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

        return file_put_contents($this->sessionsFile(), json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
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

    private function appendSessionTurn($session, $message, $reply, $skillResults, $runtimeContext) {
        $now = gmdate("c");
        if (!isset($session["history"]) || !is_array($session["history"])) {
            $session["history"] = array();
        }
        if (!isset($session["context"]) || !is_array($session["context"])) {
            $session["context"] = array();
        }

        $session["history"][] = array("role" => "user", "content" => $message, "at" => $now);
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

        return file_put_contents($this->agentsFile(), json_encode($agents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
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
                if (in_array($keyName, array("apikey", "api_key", "key", "token", "secret", "password", "authorization", "authheader", "clientsecret", "accesstoken"))
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
