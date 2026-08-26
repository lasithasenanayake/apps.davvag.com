<?php

if (!class_exists("SOSSData") && defined("PLUGIN_PATH")) {
    require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
}
if (!class_exists("Auth") && defined("PLUGIN_PATH")) {
    require_once(PLUGIN_PATH . "/auth/auth.php");
}
if (!class_exists("Profile") && defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
    require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
}

class YtgConfig {
    private static $saved = null;

    public static function value($name, $default = "") {
        $saved = self::saved();
        if (array_key_exists($name, $saved) && trim((string)$saved[$name]) !== "") {
            return is_string($saved[$name]) ? trim($saved[$name]) : $saved[$name];
        }
        $value = getenv($name);
        if ($value !== false && trim((string)$value) !== "") {
            return trim((string)$value);
        }
        if (defined($name)) {
            $value = constant($name);
            return is_string($value) ? trim($value) : $value;
        }
        return $default;
    }

    public static function boolean($name, $default = false) {
        $value = self::value($name, $default ? "true" : "false");
        return in_array(strtolower((string)$value), array("1", "true", "yes", "on"), true);
    }

    public static function integer($name, $default, $min, $max) {
        $value = self::value($name, (string)$default);
        if (!preg_match('/^-?\d+$/', (string)$value)) {
            return $default;
        }
        return max($min, min($max, intval($value)));
    }

    public static function clientId() { return self::value("YTG_GOOGLE_CLIENT_ID"); }
    public static function clientSecret() { return self::value("YTG_GOOGLE_CLIENT_SECRET"); }
    public static function encryptionKey() { return self::value("YTG_ENCRYPTION_KEY"); }
    public static function redirectUri() { return self::value("YTG_OAUTH_REDIRECT_URI"); }
    public static function privacyUrl() { return self::value("YTG_PRIVACY_POLICY_URL"); }
    public static function termsUrl() { return self::value("YTG_TERMS_URL"); }
    public static function agentCode() { return self::value("YTG_AI_AGENT_CODE", "youtube-growth-strategist"); }
    public static function derivedMetricsEnabled() { return self::boolean("YTG_DERIVED_METRICS_ENABLED", false); }
    public static function dailyQuotaLimit() { return self::integer("YTG_DAILY_QUOTA_LIMIT", 9500, 100, 10000); }

    public static function cronToken() {
        $key = self::encryptionKey();
        return strlen($key) >= 32 ? hash_hmac("sha256", "youtube-growth-agent:daily-cron:v1", $key) : "";
    }

    public static function dailyCronUrl() {
        $token = self::cronToken();
        if ($token === "") {
            return "";
        }
        $serviceUrl = self::currentServiceRedirectUri();
        $marker = "/components/";
        $position = strpos($serviceUrl, $marker);
        if ($position === false) {
            return "";
        }
        return rtrim(substr($serviceUrl, 0, $position), "/") . "/youtube-growth-agent-cron.php?token=" . rawurlencode($token);
    }

    public static function storageDirectory() {
        $root = defined("TENANT_RESOURCE_LOCATION") ? TENANT_RESOURCE_LOCATION : dirname(__DIR__, 2);
        return rtrim($root, "\\/") . "/data/youtube-growth-agent";
    }

    public static function configurationFile() {
        return self::storageDirectory() . "/configuration.php";
    }

    public static function currentServiceRedirectUri() {
        $https = isset($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off" && (string)$_SERVER["HTTPS"] !== "";
        if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"])) {
            $forwardedProtocol = strtolower(trim(explode(",", (string)$_SERVER["HTTP_X_FORWARDED_PROTO"])[0]));
            if (in_array($forwardedProtocol, array("http", "https"), true)) {
                $https = $forwardedProtocol === "https";
            }
        }
        $scheme = $https ? "https" : "http";
        $host = isset($_SERVER["HTTP_HOST"]) ? trim((string)$_SERVER["HTTP_HOST"]) : "";
        if (!preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::\d{1,5})?$/i', $host)) {
            $host = isset($_SERVER["SERVER_NAME"]) ? trim((string)$_SERVER["SERVER_NAME"]) : "localhost";
            if (!preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)$/i', $host)) {
                $host = "localhost";
            }
            $port = isset($_SERVER["SERVER_PORT"]) ? intval($_SERVER["SERVER_PORT"]) : 0;
            if ($port > 0 && (($https && $port !== 443) || (!$https && $port !== 80))) {
                $host .= ":" . $port;
            }
        }

        $requestPath = isset($_SERVER["REQUEST_URI"]) ? parse_url((string)$_SERVER["REQUEST_URI"], PHP_URL_PATH) : "";
        $marker = "/components/youtube-growth-agent/";
        $position = is_string($requestPath) ? strpos($requestPath, $marker) : false;
        if ($position !== false) {
            $basePath = substr($requestPath, 0, $position);
        } else {
            $script = isset($_SERVER["SCRIPT_NAME"]) ? str_replace("\\", "/", (string)$_SERVER["SCRIPT_NAME"]) : "";
            $basePath = rtrim(str_replace("/index.php", "", $script), "/");
        }
        return $scheme . "://" . $host . $basePath . $marker . "youtube-auth/service/OAuthCallback";
    }

    public static function save($values) {
        if (!is_array($values)) {
            throw new InvalidArgumentException("Configuration values must be an array.");
        }
        $allowed = array(
            "YTG_GOOGLE_CLIENT_ID",
            "YTG_GOOGLE_CLIENT_SECRET",
            "YTG_OAUTH_REDIRECT_URI",
            "YTG_ENCRYPTION_KEY",
            "YTG_PRIVACY_POLICY_URL",
            "YTG_TERMS_URL"
        );
        $stored = array();
        foreach ($allowed as $name) {
            $stored[$name] = isset($values[$name]) ? trim((string)$values[$name]) : "";
        }

        $directory = self::storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create the YouTube Growth Agent data directory.");
        }
        self::writeProtectionFiles($directory);

        $content = "<?php\n// Generated by YouTube Growth Agent Settings. Do not expose this file publicly.\nreturn "
            . var_export($stored, true) . ";\n";
        if (file_put_contents(self::configurationFile(), $content, LOCK_EX) === false) {
            throw new RuntimeException("Unable to save the YouTube Growth Agent configuration.");
        }
        @chmod(self::configurationFile(), 0600);
        self::$saved = $stored;
        return true;
    }

    private static function saved() {
        if (self::$saved !== null) {
            return self::$saved;
        }
        self::$saved = array();
        $file = self::configurationFile();
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                self::$saved = $loaded;
            }
        }
        return self::$saved;
    }

    private static function writeProtectionFiles($directory) {
        $protection = array(
            ".htaccess" => "Require all denied\n",
            "web.config" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
            "index.php" => "<?php http_response_code(404); exit;\n"
        );
        foreach ($protection as $name => $content) {
            $path = $directory . "/" . $name;
            if (!is_file($path)) {
                @file_put_contents($path, $content, LOCK_EX);
            }
        }
    }

    public static function scopes() {
        return array(
            "https://www.googleapis.com/auth/youtube.readonly",
            "https://www.googleapis.com/auth/yt-analytics.readonly"
        );
    }

    public static function status() {
        $redirectUri = self::redirectUri();
        if ($redirectUri === "") {
            $redirectUri = self::currentServiceRedirectUri();
        }
        $checks = array(
            "googleClientId" => self::clientId() !== "",
            "googleClientSecret" => self::clientSecret() !== "",
            "oauthRedirectUri" => $redirectUri !== "",
            "encryptionKey" => strlen(self::encryptionKey()) >= 32,
            "privacyPolicyUrl" => self::privacyUrl() !== "",
            "termsUrl" => self::termsUrl() !== "",
            "curl" => function_exists("curl_init"),
            "openssl" => function_exists("openssl_encrypt") && function_exists("openssl_decrypt")
        );
        $ready = true;
        foreach ($checks as $check) {
            if (!$check) {
                $ready = false;
            }
        }
        $canManageConfiguration = defined("GROUPID") && strtolower((string)GROUPID) === "sysadmin";
        return (object)array(
            "ready" => $ready,
            "checks" => (object)$checks,
            "scopes" => self::scopes(),
            "dailyQuotaLimit" => self::dailyQuotaLimit(),
            "derivedMetricsEnabled" => self::derivedMetricsEnabled(),
            "privacyPolicyUrl" => self::privacyUrl(),
            "termsUrl" => self::termsUrl(),
            "canManageConfiguration" => $canManageConfiguration,
            "cronUrl" => $canManageConfiguration ? self::dailyCronUrl() : "",
            "configurationLocation" => "data/youtube-growth-agent/configuration.php",
            "values" => (object)array(
                "googleClientId" => self::clientId(),
                "googleClientSecret" => "",
                "googleClientSecretSaved" => self::clientSecret() !== "",
                "oauthRedirectUri" => $redirectUri,
                "encryptionKey" => "",
                "encryptionKeySaved" => strlen(self::encryptionKey()) >= 32,
                "privacyPolicyUrl" => self::privacyUrl(),
                "termsUrl" => self::termsUrl()
            ),
            "writeBackEnabled" => false
        );
    }
}

class YtgSecretStore {
    private $key;
    private $baseDir;

    public function __construct() {
        $secret = YtgConfig::encryptionKey();
        if (strlen($secret) < 32) {
            throw new RuntimeException("YTG_ENCRYPTION_KEY must contain at least 32 characters.");
        }
        if (!function_exists("openssl_encrypt")) {
            throw new RuntimeException("PHP OpenSSL is required for encrypted YouTube credentials.");
        }
        $this->key = hash("sha256", $secret, true);
        $root = defined("MEDIA_FOLDER") ? MEDIA_FOLDER : (defined("TENANT_RESOURCE_LOCATION") ? TENANT_RESOURCE_LOCATION . "/data" : sys_get_temp_dir());
        $tenant = defined("DATASTORE_DOMAIN") ? DATASTORE_DOMAIN : "default";
        $this->baseDir = rtrim($root, "\\/") . "/youtube-growth-agent/" . preg_replace('/[^A-Za-z0-9._-]/', '_', $tenant);
    }

    public function putState($state, $data) {
        return $this->write("states", $state, $data);
    }

    public function consumeState($state, $maxAgeSeconds = 900) {
        $data = $this->read("states", $state);
        $this->delete("states", $state);
        if (!is_array($data) || !isset($data["createdAt"]) || time() - intval($data["createdAt"]) > $maxAgeSeconds) {
            return null;
        }
        return $data;
    }

    public function putCredential($reference, $data) {
        return $this->write("credentials", $reference, $data);
    }

    public function getCredential($reference) {
        return $this->read("credentials", $reference);
    }

    public function deleteCredential($reference) {
        return $this->delete("credentials", $reference);
    }

    private function write($bucket, $reference, $data) {
        $dir = $this->directory($bucket);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create the protected credential directory.");
        }
        $iv = random_bytes(12);
        $tag = "";
        $plain = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $cipher = openssl_encrypt($plain, "aes-256-gcm", $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException("Credential encryption failed.");
        }
        $envelope = json_encode(array(
            "version" => 1,
            "iv" => base64_encode($iv),
            "tag" => base64_encode($tag),
            "ciphertext" => base64_encode($cipher)
        ), JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->file($bucket, $reference), $envelope, LOCK_EX) === false) {
            throw new RuntimeException("Unable to store encrypted credentials.");
        }
        return true;
    }

    private function read($bucket, $reference) {
        $file = $this->file($bucket, $reference);
        if (!is_file($file)) {
            return null;
        }
        $envelope = json_decode(file_get_contents($file), true);
        if (!is_array($envelope) || !isset($envelope["iv"], $envelope["tag"], $envelope["ciphertext"])) {
            return null;
        }
        $plain = openssl_decrypt(
            base64_decode($envelope["ciphertext"], true),
            "aes-256-gcm",
            $this->key,
            OPENSSL_RAW_DATA,
            base64_decode($envelope["iv"], true),
            base64_decode($envelope["tag"], true)
        );
        return $plain === false ? null : json_decode($plain, true);
    }

    private function delete($bucket, $reference) {
        $file = $this->file($bucket, $reference);
        return !is_file($file) || unlink($file);
    }

    private function directory($bucket) {
        return $this->baseDir . "/" . $bucket;
    }

    private function file($bucket, $reference) {
        return $this->directory($bucket) . "/" . $this->safeReference($reference) . ".enc";
    }

    private function safeReference($reference) {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$reference);
        if ($safe === "" || strlen($safe) > 160) {
            throw new InvalidArgumentException("Invalid credential reference.");
        }
        return $safe;
    }
}

class YtgHttpClient {
    private static $allowedHosts = array(
        "accounts.google.com",
        "oauth2.googleapis.com",
        "www.googleapis.com",
        "youtubeanalytics.googleapis.com",
        "youtubereporting.googleapis.com"
    );

    public static function request($method, $url, $accessToken = "", $body = null, $form = false, $acceptText = false) {
        $out = (object)array("success" => false, "status" => 0, "data" => null, "text" => "", "error" => "");
        if (!function_exists("curl_init")) {
            $out->error = "PHP cURL is not enabled.";
            return $out;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts["scheme"], $parts["host"]) || strtolower($parts["scheme"]) !== "https" || !in_array(strtolower($parts["host"]), self::$allowedHosts, true)) {
            $out->error = "Blocked non-Google API endpoint.";
            return $out;
        }

        $method = strtoupper($method);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $headers = array("Accept: " . ($acceptText ? "text/csv,text/plain,*/*" : "application/json"));
            if ($accessToken !== "") {
                $headers[] = "Authorization: Bearer " . $accessToken;
            }
            $payload = null;
            if ($body !== null) {
                if ($form) {
                    $payload = http_build_query($body, "", "&", PHP_QUERY_RFC3986);
                    $headers[] = "Content-Type: application/x-www-form-urlencoded";
                } else {
                    $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $headers[] = "Content-Type: application/json";
                }
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 45);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERAGENT, "DAVVAG YouTube Growth Agent/0.1");
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $text = curl_exec($ch);
            $error = curl_error($ch);
            $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);

            $out->status = $status;
            $out->text = $text === false ? "" : $text;
            $out->error = $error;
            if ($text !== false && $status >= 200 && $status < 300) {
                $out->success = true;
                if (!$acceptText && trim($text) !== "") {
                    $out->data = json_decode($text, true);
                    if ($out->data === null && strtolower(trim($text)) !== "null") {
                        $out->success = false;
                        $out->error = "Google API returned invalid JSON.";
                    }
                }
                return $out;
            }

            $decoded = is_string($text) ? json_decode($text, true) : null;
            if (is_array($decoded) && isset($decoded["error"])) {
                $googleError = $decoded["error"];
                if (is_array($googleError) && isset($googleError["message"])) {
                    $out->error = (string)$googleError["message"];
                } elseif (is_string($googleError)) {
                    $out->error = $googleError;
                }
            }
            if (!in_array($status, array(429, 500, 502, 503, 504), true) || $attempt === 2) {
                return $out;
            }
            usleep(250000 * (1 << $attempt));
        }
        return $out;
    }
}

class YtgGoogleClient {
    private $store;

    public function __construct() {
        $this->store = new YtgSecretStore();
    }

    public function authorizationUrl($state, $redirectUri) {
        $params = array(
            "client_id" => YtgConfig::clientId(),
            "redirect_uri" => $redirectUri,
            "response_type" => "code",
            "scope" => implode(" ", YtgConfig::scopes()),
            "state" => $state,
            "access_type" => "offline",
            "prompt" => "consent select_account",
            "include_granted_scopes" => "true"
        );
        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986);
    }

    public function exchangeCode($code, $redirectUri) {
        $response = YtgHttpClient::request("POST", "https://oauth2.googleapis.com/token", "", array(
            "client_id" => YtgConfig::clientId(),
            "client_secret" => YtgConfig::clientSecret(),
            "code" => $code,
            "grant_type" => "authorization_code",
            "redirect_uri" => $redirectUri
        ), true);
        if ($response->success && is_array($response->data)) {
            $response->data["expires_at"] = time() + max(60, intval(isset($response->data["expires_in"]) ? $response->data["expires_in"] : 3600));
        }
        return $response;
    }

    public function storeCredential($reference, $token) {
        return $this->store->putCredential($reference, $token);
    }

    public function deleteCredential($reference) {
        return $this->store->deleteCredential($reference);
    }

    public function accessToken($reference) {
        $out = (object)array("success" => false, "accessToken" => "", "error" => "", "token" => null);
        $token = $this->store->getCredential($reference);
        if (!is_array($token) || !isset($token["access_token"])) {
            $out->error = "Stored YouTube credentials are unavailable.";
            return $out;
        }
        if (isset($token["expires_at"]) && intval($token["expires_at"]) <= time() + 90) {
            if (!isset($token["refresh_token"]) || trim((string)$token["refresh_token"]) === "") {
                $out->error = "YouTube authorization expired and requires reconnection.";
                return $out;
            }
            $refresh = YtgHttpClient::request("POST", "https://oauth2.googleapis.com/token", "", array(
                "client_id" => YtgConfig::clientId(),
                "client_secret" => YtgConfig::clientSecret(),
                "refresh_token" => $token["refresh_token"],
                "grant_type" => "refresh_token"
            ), true);
            if (!$refresh->success || !is_array($refresh->data) || !isset($refresh->data["access_token"])) {
                $out->error = $refresh->error !== "" ? $refresh->error : "YouTube token refresh failed.";
                return $out;
            }
            $token = array_merge($token, $refresh->data);
            $token["expires_at"] = time() + max(60, intval(isset($refresh->data["expires_in"]) ? $refresh->data["expires_in"] : 3600));
            $this->store->putCredential($reference, $token);
        }
        $out->success = true;
        $out->accessToken = (string)$token["access_token"];
        $out->token = $token;
        return $out;
    }

    public function revoke($reference) {
        $token = $this->store->getCredential($reference);
        if (!is_array($token)) {
            return (object)array("success" => true, "status" => 0, "error" => "");
        }
        $value = isset($token["refresh_token"]) ? $token["refresh_token"] : (isset($token["access_token"]) ? $token["access_token"] : "");
        if ($value === "") {
            return (object)array("success" => true, "status" => 0, "error" => "");
        }
        return YtgHttpClient::request("POST", "https://oauth2.googleapis.com/revoke", "", array("token" => $value), true);
    }

    public function dataGet($reference, $resource, $params) {
        $allowed = array("channels", "playlistItems", "videos", "commentThreads", "captions", "playlists");
        if (!in_array($resource, $allowed, true)) {
            return (object)array("success" => false, "status" => 0, "data" => null, "text" => "", "error" => "Blocked YouTube Data API resource.");
        }
        return $this->authorized("GET", "https://www.googleapis.com/youtube/v3/" . $resource . "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986), $reference);
    }

    public function analytics($reference, $params) {
        return $this->authorized("GET", "https://youtubeanalytics.googleapis.com/v2/reports?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986), $reference);
    }

    public function reportingGet($reference, $path, $params = array()) {
        if (!preg_match('#^/v1/(jobs|reportTypes)(/[-A-Za-z0-9_]+)*(?:/reports)?$#', $path)) {
            return (object)array("success" => false, "status" => 0, "data" => null, "text" => "", "error" => "Blocked YouTube Reporting API path.");
        }
        $query = count($params) ? "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986) : "";
        return $this->authorized("GET", "https://youtubereporting.googleapis.com" . $path . $query, $reference);
    }

    public function reportingPost($reference, $path, $body) {
        if ($path !== "/v1/jobs") {
            return (object)array("success" => false, "status" => 0, "data" => null, "text" => "", "error" => "Blocked YouTube Reporting API path.");
        }
        return $this->authorized("POST", "https://youtubereporting.googleapis.com" . $path, $reference, $body);
    }

    public function downloadReport($reference, $url) {
        $token = $this->accessToken($reference);
        if (!$token->success) {
            return (object)array("success" => false, "status" => 401, "data" => null, "text" => "", "error" => $token->error);
        }
        return YtgHttpClient::request("GET", $url, $token->accessToken, null, false, true);
    }

    private function authorized($method, $url, $reference, $body = null) {
        $token = $this->accessToken($reference);
        if (!$token->success) {
            return (object)array("success" => false, "status" => 401, "data" => null, "text" => "", "error" => $token->error);
        }
        return YtgHttpClient::request($method, $url, $token->accessToken, $body);
    }
}

abstract class YtgServiceBase {
    protected function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new stdClass();
    }

    protected function fail($res, $message) {
        $res->SetError($message);
        return null;
    }

    protected function now() { return date("Y-m-d H:i:s"); }
    protected function today() { return date("Y-m-d"); }

    protected function currentProfile() {
        $out = (object)array("id" => 0, "name" => "Unknown", "email" => "");
        if (class_exists("Profile")) {
            $stored = Profile::getUserProfile();
            $profile = isset($stored->profile) ? $stored->profile : $stored;
            if (is_object($profile) && isset($profile->id)) {
                $out->id = intval($profile->id);
                $out->name = isset($profile->name) ? (string)$profile->name : "Unknown";
                $out->email = isset($profile->email) ? (string)$profile->email : "";
                return $out;
            }
        }
        if (class_exists("Auth")) {
            $user = Auth::Autendicate();
            if (is_object($user) && isset($user->userid)) {
                $profiles = SOSSData::Query("profile", array(
                    "conditions" => array(array("column" => "linkeduserid", "operator" => "=", "value" => $user->userid)),
                    "pageSize" => 1,
                    "pageFrom" => 0
                ));
                if ($profiles->success && count($profiles->result)) {
                    $out->id = intval($profiles->result[0]->id);
                    $out->name = isset($profiles->result[0]->name) ? (string)$profiles->result[0]->name : "Unknown";
                }
                $out->email = isset($user->email) ? (string)$user->email : "";
            }
        }
        return $out;
    }

    protected function requireProfile($res) {
        $profile = $this->currentProfile();
        if ($profile->id <= 0) {
            $this->fail($res, "An authenticated DAVVAG profile is required.");
            return null;
        }
        return $profile;
    }

    protected function isSysAdmin() {
        return defined("GROUPID") && GROUPID === "sysadmin";
    }

    protected function query($namespace, $conditions = array(), $sorting = array(), $pageSize = 100, $pageFrom = 0) {
        return SOSSData::Query($namespace, array(
            "conditions" => array_values($conditions),
            "sorting" => array_values($sorting),
            "pageSize" => max(1, min(10000, intval($pageSize))),
            "pageFrom" => max(0, intval($pageFrom))
        ));
    }

    protected function first($namespace, $conditions, $sorting = array()) {
        $result = $this->query($namespace, $conditions, $sorting, 1, 0);
        return $result->success && count($result->result) ? $result->result[0] : null;
    }

    protected function upsert($namespace, $uniqueConditions, $row) {
        $existing = $this->first($namespace, $uniqueConditions);
        if ($existing !== null) {
            foreach ((array)$row as $key => $value) {
                $existing->{$key} = $value;
            }
            return SOSSData::Update($namespace, $existing);
        }
        return SOSSData::Insert($namespace, $row);
    }

    protected function generatedId($result, $fallback = 0) {
        if (is_object($result) && isset($result->result)) {
            if (is_object($result->result) && isset($result->result->generatedId)) {
                return intval($result->result->generatedId);
            }
            if (is_array($result->result) && isset($result->result["generatedId"])) {
                return intval($result->result["generatedId"]);
            }
        }
        return intval($fallback);
    }

    protected function channelAccess($channelId, $roles = array()) {
        $channelId = $this->channelId($channelId);
        if ($channelId === "") {
            return null;
        }
        $channel = $this->first("ytg_channels", array(array("column" => "channelId", "operator" => "=", "value" => $channelId)));
        if ($channel === null) {
            return null;
        }
        if ($this->isSysAdmin()) {
            $channel->_accessRole = "Owner";
            return $channel;
        }
        $profile = $this->currentProfile();
        if ($profile->id <= 0) {
            return null;
        }
        $access = $this->first("ytg_channel_access", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "profileId", "operator" => "=", "value" => $profile->id),
            array("column" => "status", "operator" => "=", "value" => "Active")
        ));
        if ($access === null || (count($roles) && !in_array((string)$access->role, $roles, true))) {
            return null;
        }
        $channel->_accessRole = (string)$access->role;
        return $channel;
    }

    protected function requireChannel($res, $channelId, $roles = array()) {
        $channel = $this->channelAccess($channelId, $roles);
        if ($channel === null) {
            $this->fail($res, "Channel was not found or is not available to the current profile.");
        }
        return $channel;
    }

    protected function channelId($value) {
        $value = trim((string)$value);
        return preg_match('/^ytg_[A-Za-z0-9_-]{12,80}$/', $value) ? $value : "";
    }

    protected function youtubeId($value) {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9_-]{6,80}$/', $value) ? $value : "";
    }

    protected function safeChannel($channel) {
        $safe = clone $channel;
        unset($safe->_accessRole);
        return $safe;
    }

    protected function audit($action, $channelId, $target, $beforeData = null, $afterData = null) {
        $profile = $this->currentProfile();
        $row = (object)array(
            "profileId" => $profile->id,
            "channelId" => (string)$channelId,
            "action" => substr((string)$action, 0, 100),
            "target" => substr((string)$target, 0, 255),
            "beforeData" => $this->json($beforeData),
            "afterData" => $this->json($afterData),
            "approvedAt" => $this->now(),
            "createdAt" => $this->now()
        );
        return SOSSData::Insert("ytg_audit_log", $row);
    }

    protected function json($value) {
        if ($value === null) {
            return "";
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function decodeJson($value, $default = array()) {
        if (is_array($value) || is_object($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    protected function deleteByChannel($namespace, $channelId) {
        $rows = $this->query($namespace, array(array("column" => "channelId", "operator" => "=", "value" => $channelId)), array(), 10000, 0);
        $deleted = 0;
        if ($rows->success) {
            foreach ($rows->result as $row) {
                $result = SOSSData::Delete($namespace, $row);
                if ($result->success) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    protected function credentialGrant($channelId) {
        return $this->first("ytg_oauth_grants", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "status", "operator" => "=", "value" => "Connected")
        ), array(array("column" => "grantId", "direction" => "DESC")));
    }

    protected function quotaUsedToday($channelId) {
        $usage = $this->query("ytg_quota_usage", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "usageDate", "operator" => "=", "value" => $this->today())
        ), array(), 10000, 0);
        $total = 0;
        if ($usage->success) {
            foreach ($usage->result as $row) {
                $total += max(0, intval(isset($row->units) ? $row->units : 0));
            }
        }
        return $total;
    }

    protected function consumeQuota($channelId, $operation, $units, $essential = false) {
        $units = max(0, intval($units));
        $used = $this->quotaUsedToday($channelId);
        $limit = $essential ? 10000 : YtgConfig::dailyQuotaLimit();
        if ($used + $units > $limit) {
            return (object)array("success" => false, "used" => $used, "limit" => $limit, "message" => "YouTube API quota safety limit reached.");
        }
        if ($units > 0) {
            SOSSData::Insert("ytg_quota_usage", (object)array(
                "channelId" => $channelId,
                "usageDate" => $this->today(),
                "operation" => substr((string)$operation, 0, 120),
                "units" => $units,
                "createdAt" => $this->now()
            ));
        }
        return (object)array("success" => true, "used" => $used + $units, "limit" => $limit, "message" => "");
    }

    protected function queueSchedule($channelId, $method, $when) {
        $recordId = "ytg-" . strtolower($method) . "-" . $channelId;
        $existing = $this->first("schedule_pending", array(array("column" => "recid", "operator" => "=", "value" => $recordId)));
        $row = (object)array(
            "recid" => $recordId,
            "createdate" => $this->now(),
            "scheduled_date" => $when,
            "status" => "schedule",
            "app" => "youtube-growth-agent",
            "service" => "youtube-sync",
            "method" => $method,
            "postMethod" => "POST",
            "body" => $this->json((object)array("channelId" => $channelId, "scheduled" => true))
        );
        if ($existing !== null) {
            $row->id = $existing->id;
            return SOSSData::Update("schedule_pending", $row);
        }
        return SOSSData::Insert("schedule_pending", $row);
    }
}

?>
