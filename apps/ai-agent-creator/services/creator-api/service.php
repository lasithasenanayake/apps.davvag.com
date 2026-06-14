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
            "configuration" => $created->config,
            "workflow" => array(
                "appCode" => "ai-agent-creator",
                "componentCode" => "creator-api",
                "method" => "TestAgent",
                "methodType" => "post",
                "input" => array(
                    "agentCode" => $agentCode,
                    "message" => "scopData.inputData.message"
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

        if ($agentCode === "") {
            return $this->fail("Select a saved agent before testing.");
        }
        if ($message === "") {
            return $this->fail("Test message is required.");
        }

        $agents = $this->loadAgents();
        if (!isset($agents[$agentCode])) {
            return $this->fail("Saved agent was not found.");
        }

        if (!function_exists("curl_init")) {
            return $this->fail("PHP cURL is not enabled. Enable the curl extension before testing agents.");
        }

        $config = $agents[$agentCode]["configuration"];
        $result = $this->callProvider($config, $message);
        if (!$result->success) {
            return $result;
        }

        $out = $this->ok();
        $out->agentCode = $agentCode;
        $out->reply = $result->reply;
        $out->provider = $config["provider"]["type"];
        $out->model = $config["provider"]["model"];
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

    private function buildConfig($meta, $provider, $model, $apiKey, $endpoint, $cliCommand, $customMethod, $authHeader, $systemPrompt, $temperature, $maxTokens, $streaming, $agentMeta) {
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
                "initialized" => true,
                "readyForInteraction" => true,
                "startupPrompt" => $systemPrompt,
                "messages" => array(array("role" => "system", "content" => $systemPrompt))
            ),
            "parameters" => array(
                "temperature" => $temperature,
                "maxTokens" => $maxTokens,
                "streaming" => $streaming
            ),
            "createdAt" => gmdate("c")
        );
    }

    private function callProvider($config, $message) {
        $provider = $config["provider"]["type"];
        $payload = $this->providerPayload($config, $message);
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

    private function providerPayload($config, $message) {
        $provider = $config["provider"]["type"];
        $model = $config["provider"]["model"];
        $systemPrompt = $config["agent"]["startupPrompt"];
        $temperature = $config["parameters"]["temperature"];
        $maxTokens = $config["parameters"]["maxTokens"];

        if ($provider === "ollama") {
            return array(
                "model" => $model,
                "messages" => array(
                    array("role" => "system", "content" => $systemPrompt),
                    array("role" => "user", "content" => $message)
                ),
                "stream" => false,
                "options" => array("temperature" => $temperature, "num_predict" => $maxTokens)
            );
        }

        if ($provider === "google") {
            return array(
                "systemInstruction" => array("parts" => array(array("text" => $systemPrompt))),
                "contents" => array(array("role" => "user", "parts" => array(array("text" => $message)))),
                "generationConfig" => array("temperature" => $temperature, "maxOutputTokens" => $maxTokens)
            );
        }

        if ($provider === "other") {
            return array(
                "model" => $model,
                "messages" => array(
                    array("role" => "system", "content" => $systemPrompt),
                    array("role" => "user", "content" => $message)
                ),
                "parameters" => $config["parameters"],
                "agent" => array(
                    "code" => $config["agent"]["code"],
                    "name" => $config["agent"]["name"],
                    "capabilities" => $config["agent"]["capabilities"]
                )
            );
        }

        return array(
            "model" => $model,
            "messages" => array(
                array("role" => "system", "content" => $systemPrompt),
                array("role" => "user", "content" => $message)
            ),
            "temperature" => $temperature,
            "max_tokens" => $maxTokens,
            "stream" => false
        );
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
                if (in_array(strtolower($key), array("apikey", "api_key", "key", "token"))) {
                    $out[$key] = $item === "" ? "" : "********";
                } else {
                    $out[$key] = $this->maskSecrets($item);
                }
            }
            return $out;
        }
        return $value;
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
