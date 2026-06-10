<?php
namespace ai_chatgpt_agent;

class AgentService {
    private $defaultModel = "gpt-4.1-mini";
    private $defaultInstructions = "You are a helpful AI assistant inside the DAVVAG Framework. Be concise, practical, and safe.";

    public function getConfig($req, $res) {
        $config = $this->loadConfig();
        $out = $this->ok();
        $out->apiKeyConfigured = !empty($config->apiKey);
        $out->model = $config->model;
        $out->temperature = $config->temperature;
        $out->maxOutputTokens = $config->maxOutputTokens;
        $out->instructions = $config->instructions;
        return $out;
    }

    public function postSaveConfig($req, $res) {
        $body = $this->body($req);
        $config = $this->loadConfig();

        $apiKey = $this->stringValue($body, "apiKey", "");
        if ($apiKey !== "") {
            if (!$this->looksLikeOpenAiKey($apiKey)) {
                return $this->fail("The API key format does not look valid. It should usually start with sk-.");
            }
            $config->apiKey = $apiKey;
        }

        $model = $this->stringValue($body, "model", $this->defaultModel);
        if (!$this->isValidModelName($model)) {
            return $this->fail("The model name contains unsupported characters.");
        }
        $config->model = $model === "" ? $this->defaultModel : $model;

        $config->instructions = substr($this->stringValue($body, "instructions", $this->defaultInstructions), 0, 8000);
        $config->temperature = $this->numberValue($body, "temperature", 1, 0, 2);
        $config->maxOutputTokens = $this->integerValue($body, "maxOutputTokens", 1000, 1, 8192);

        if (!$this->saveConfig($config)) {
            return $this->fail("Unable to save the AI agent configuration on the server.");
        }

        $out = $this->ok();
        $out->apiKeyConfigured = !empty($config->apiKey);
        return $out;
    }

    public function postClearConfig($req, $res) {
        $config = $this->loadConfig();
        $config->apiKey = "";

        if (!$this->saveConfig($config)) {
            return $this->fail("Unable to clear the saved API key.");
        }

        $out = $this->ok();
        $out->apiKeyConfigured = false;
        return $out;
    }

    public function postChat($req, $res) {
        $body = $this->body($req);
        $config = $this->loadConfig();

        if (empty($config->apiKey)) {
            return $this->fail("No OpenAI API key is configured for this tenant.");
        }

        $message = trim($this->stringValue($body, "message", ""));
        if ($message === "") {
            return $this->fail("Message is required.");
        }

        if (!function_exists("curl_init")) {
            return $this->fail("PHP cURL is not enabled. Enable the curl extension before using the AI agent.");
        }

        $payload = array(
            "model" => $config->model ? $config->model : $this->defaultModel,
            "instructions" => $config->instructions ? $config->instructions : $this->defaultInstructions,
            "input" => $this->buildInput($body, $message)
        );

        if (isset($config->temperature) && $config->temperature !== "") {
            $payload["temperature"] = (float)$config->temperature;
        }

        if (isset($config->maxOutputTokens) && (int)$config->maxOutputTokens > 0) {
            $payload["max_output_tokens"] = (int)$config->maxOutputTokens;
        }

        $apiResult = $this->callResponsesApi($config->apiKey, $payload);
        if (!$apiResult->success) {
            return $apiResult;
        }

        $reply = $this->extractReply($apiResult->response);
        if ($reply === "") {
            return $this->fail("OpenAI returned a response, but no text output was found.");
        }

        $out = $this->ok();
        $out->reply = $reply;
        $out->model = isset($apiResult->response->model) ? $apiResult->response->model : $payload["model"];
        $out->responseId = isset($apiResult->response->id) ? $apiResult->response->id : "";
        $out->usage = isset($apiResult->response->usage) ? $apiResult->response->usage : null;
        return $out;
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function loadConfig() {
        $config = $this->defaultConfig();
        $file = $this->configFile();

        if (file_exists($file)) {
            $json = json_decode(file_get_contents($file));
            if (is_object($json)) {
                foreach ($json as $key => $value) {
                    $config->$key = $value;
                }
            }
        }

        if (empty($config->model)) {
            $config->model = $this->defaultModel;
        }
        if (empty($config->instructions)) {
            $config->instructions = $this->defaultInstructions;
        }
        if (!isset($config->maxOutputTokens) || (int)$config->maxOutputTokens <= 0) {
            $config->maxOutputTokens = 1000;
        }
        if (!isset($config->temperature) || $config->temperature === "") {
            $config->temperature = 1;
        }

        return $config;
    }

    private function defaultConfig() {
        $config = new \stdClass();
        $config->apiKey = "";
        $config->model = $this->defaultModel;
        $config->instructions = $this->defaultInstructions;
        $config->temperature = 1;
        $config->maxOutputTokens = 1000;
        return $config;
    }

    private function saveConfig($config) {
        $dir = $this->configDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return file_put_contents($this->configFile(), json_encode($config, JSON_PRETTY_PRINT)) !== false;
    }

    private function configDir() {
        if (defined("MEDIA_FOLDER")) {
            return rtrim(MEDIA_FOLDER, "\\/") . "/davvag-ai-chatgpt-agent/" . DATASTORE_DOMAIN;
        }

        return TENANT_RESOURCE_LOCATION . "/data/ai-chatgpt-agent";
    }

    private function configFile() {
        return $this->configDir() . "/config.json";
    }

    private function buildInput($body, $message) {
        $input = array();
        $history = isset($body->history) && is_array($body->history) ? $body->history : array();
        $history = array_slice($history, -20);

        foreach ($history as $item) {
            if (!is_object($item)) {
                continue;
            }

            $role = isset($item->role) && $item->role === "assistant" ? "assistant" : "user";
            $content = substr(trim(isset($item->content) ? (string)$item->content : ""), 0, 8000);
            if ($content !== "") {
                $input[] = array(
                    "role" => $role,
                    "content" => $content
                );
            }
        }

        $input[] = array(
            "role" => "user",
            "content" => substr($message, 0, 8000)
        );

        return $input;
    }

    private function callResponsesApi($apiKey, $payload) {
        $ch = curl_init("https://api.openai.com/v1/responses");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return $this->fail("OpenAI request failed: " . $curlError);
        }

        $response = json_decode($raw);
        if (!is_object($response)) {
            return $this->fail("OpenAI returned a non-JSON response.");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = "OpenAI request failed with HTTP " . $httpCode . ".";
            if (isset($response->error) && isset($response->error->message)) {
                $message = $response->error->message;
            }
            return $this->fail($message);
        }

        $out = $this->ok();
        $out->response = $response;
        return $out;
    }

    private function extractReply($response) {
        if (isset($response->output_text) && is_string($response->output_text)) {
            return trim($response->output_text);
        }

        $chunks = array();
        if (isset($response->output) && is_array($response->output)) {
            foreach ($response->output as $item) {
                if (!isset($item->content) || !is_array($item->content)) {
                    continue;
                }

                foreach ($item->content as $content) {
                    if (isset($content->type) && $content->type === "output_text" && isset($content->text)) {
                        $chunks[] = $content->text;
                    }
                    if (isset($content->type) && $content->type === "refusal" && isset($content->refusal)) {
                        $chunks[] = $content->refusal;
                    }
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function stringValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return trim((string)$body->$key);
    }

    private function numberValue($body, $key, $default, $min, $max) {
        if (!isset($body->$key) || $body->$key === "") {
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
        $value = (int)$this->numberValue($body, $key, $default, $min, $max);
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function looksLikeOpenAiKey($apiKey) {
        return strpos($apiKey, "sk-") === 0 && strlen($apiKey) >= 20;
    }

    private function isValidModelName($model) {
        return $model === "" || preg_match("/^[A-Za-z0-9._:-]+$/", $model) === 1;
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
