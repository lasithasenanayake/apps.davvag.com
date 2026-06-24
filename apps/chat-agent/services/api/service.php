<?php
namespace chat_agent;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) {
        require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    }
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) {
        require_once(PLUGIN_PATH . "/auth/auth.php");
    }
    if (defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
        require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
    }
}

class ApiService {
    private $sessionNamespace = "chat_agent_session";
    private $messageNamespace = "chat_agent_message";
    private $sessionCookie = "chat_agent_session";
    private $visitorCookie = "chat_agent_visitor";
    private $defaultAgentCode = "chat-agent";

    public function postBootstrap($req, $res) {
        if (!$this->ensureStore($res)) {
            return null;
        }

        $body = $this->body($req);
        $identity = $this->currentIdentity($this->boolValue($body, "newSession", false));
        $session = $this->findOrCreateSession($identity, $this->stringValue($body, "agentCode", $this->defaultAgentCode), $body, $res);
        if ($session === null) {
            return null;
        }

        $session = $this->clearVisitorUnread($session);
        $out = $this->ok();
        $out->identity = $identity;
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        $out->defaultAgentCode = $this->defaultAgentCode;
        return $out;
    }

    public function postPollSession($req, $res) {
        if (!$this->ensureStore($res)) {
            return null;
        }

        $body = $this->body($req);
        $identity = $this->currentIdentity(false);
        $session = $this->findOrCreateSession($identity, $this->stringValue($body, "agentCode", $this->defaultAgentCode), $body, $res);
        if ($session === null) {
            return null;
        }

        $session = $this->clearVisitorUnread($session);
        $out = $this->ok();
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        return $out;
    }

    public function postSendMessage($req, $res) {
        if (!$this->ensureStore($res)) {
            return null;
        }

        $body = $this->body($req);
        $text = $this->stringValue($body, "message", "");
        if ($text === "") {
            $res->SetError("Message is required.");
            return null;
        }

        $identity = $this->currentIdentity(false);
        $session = $this->findOrCreateSession($identity, $this->stringValue($body, "agentCode", $this->defaultAgentCode), $body, $res);
        if ($session === null) {
            return null;
        }

        $now = $this->now();
        $visitorName = $this->stringValue($body, "visitorName", "");
        $visitorEmail = $this->stringValue($body, "visitorEmail", "");
        if ($visitorName !== "") {
            $session->visitorName = $visitorName;
        }
        if ($visitorEmail !== "") {
            $session->visitorEmail = $visitorEmail;
        }

        $visitorMessage = $this->insertMessage($session->sessionKey, "visitor", $session->visitorKey, $session->visitorName, $text, "inbound", "sent", $session->agentCode, null, $res);
        if ($visitorMessage === null) {
            return null;
        }

        $session->humanUnreadCount = intval($this->value($session, "humanUnreadCount", 0)) + 1;
        $session->needsHumanReview = "true";
        $session->lastSender = "visitor";
        $session->lastMessagePreview = $this->preview($text);
        $session->lastMessageAt = $now;
        $session->status = $session->agentCode === "" ? "waiting_human" : "open";
        $session->updatedAt = $now;
        $session = $this->saveSession($session, $res);
        if ($session === null) {
            return null;
        }

        $agentRun = null;
        if ($session->agentCode !== "") {
            $agentRun = $this->askAgent($session, $text, $body);
            if ($agentRun->success) {
                $reply = isset($agentRun->response) ? $agentRun->response : (isset($agentRun->reply) ? $agentRun->reply : "");
                if ($reply !== "") {
                    $aiMessage = $this->insertMessage($session->sessionKey, "ai_agent", $session->agentCode, "AI Agent", $reply, "outbound", "sent", $session->agentCode, $agentRun, $res);
                    if ($aiMessage === null) {
                        return null;
                    }
                    $session->visitorUnreadCount = intval($this->value($session, "visitorUnreadCount", 0)) + 1;
                    $session->lastSender = "ai_agent";
                    $session->lastMessagePreview = $this->preview($reply);
                    $session->lastMessageAt = $this->now();
                    $session->status = "ai_answered";
                    $session->updatedAt = $this->now();
                    $session = $this->saveSession($session, $res);
                    if ($session === null) {
                        return null;
                    }
                }
            } else {
                $session->status = "waiting_human";
                $session->updatedAt = $this->now();
                $session = $this->saveSession($session, $res);
                if ($session === null) {
                    return null;
                }
            }
        }

        $out = $this->ok();
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        $out->agent = $agentRun;
        return $out;
    }

    public function postListSessions($req, $res) {
        if (!$this->ensureStore($res) || !$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $status = strtolower($this->stringValue($body, "status", ""));
        $search = strtolower($this->stringValue($body, "search", ""));
        $rows = $this->rows($this->sessionNamespace, "", "desc", 500, 0, false);
        $sessions = array();

        foreach ($rows as $session) {
            if ($status !== "" && $status !== "all" && strtolower($this->value($session, "status", "")) !== $status) {
                continue;
            }
            if ($search !== "" && strpos(strtolower($this->sessionSearchText($session)), $search) === false) {
                continue;
            }
            $session->highlight = intval($this->value($session, "humanUnreadCount", 0)) > 0 || $this->value($session, "needsHumanReview", "false") === "true";
            $sessions[] = $session;
        }

        usort($sessions, function($a, $b) {
            return strcmp((string)$this->value($b, "lastMessageAt", ""), (string)$this->value($a, "lastMessageAt", ""));
        });

        $out = $this->ok();
        $out->sessions = $sessions;
        return $out;
    }

    public function postListMessages($req, $res) {
        if (!$this->ensureStore($res) || !$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $sessionKey = $this->normalizeSessionKey($this->stringValue($body, "sessionKey", ""));
        if ($sessionKey === "") {
            $res->SetError("Session key is required.");
            return null;
        }

        $session = $this->sessionByKey($sessionKey, false);
        if ($session === null) {
            $res->SetError("Chat session was not found.");
            return null;
        }

        $out = $this->ok();
        $out->session = $session;
        $out->messages = $this->messagesForSession($sessionKey, 500, false);
        return $out;
    }

    public function postMarkSessionRead($req, $res) {
        if (!$this->ensureStore($res) || !$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $session = $this->adminSessionFromBody($body, $res);
        if ($session === null) {
            return null;
        }

        $session->humanUnreadCount = 0;
        if ($this->boolValue($body, "clearReview", false)) {
            $session->needsHumanReview = "false";
        }
        $session->updatedAt = $this->now();
        $session = $this->saveSession($session, $res);
        if ($session === null) {
            return null;
        }

        $out = $this->ok();
        $out->session = $session;
        return $out;
    }

    public function postHumanReply($req, $res) {
        if (!$this->ensureStore($res) || !$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $message = $this->stringValue($body, "message", "");
        if ($message === "") {
            $res->SetError("Reply message is required.");
            return null;
        }

        $session = $this->adminSessionFromBody($body, $res);
        if ($session === null) {
            return null;
        }

        $human = $this->currentHumanAgent();
        $inserted = $this->insertMessage($session->sessionKey, "human", $human->id, $human->name, $message, "outbound", "sent", $this->value($session, "agentCode", ""), null, $res);
        if ($inserted === null) {
            return null;
        }

        $session->assignedAgentId = $human->id;
        $session->assignedAgentName = $human->name;
        $session->humanUnreadCount = 0;
        $session->visitorUnreadCount = intval($this->value($session, "visitorUnreadCount", 0)) + 1;
        $session->needsHumanReview = "false";
        $session->lastSender = "human";
        $session->lastMessagePreview = $this->preview($message);
        $session->lastMessageAt = $this->now();
        $session->status = "human_replied";
        $session->updatedAt = $this->now();
        $session = $this->saveSession($session, $res);
        if ($session === null) {
            return null;
        }

        $out = $this->ok();
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 500, false);
        return $out;
    }

    public function postUpdateSession($req, $res) {
        if (!$this->ensureStore($res) || !$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $session = $this->adminSessionFromBody($body, $res);
        if ($session === null) {
            return null;
        }

        $status = strtolower($this->stringValue($body, "status", ""));
        if ($status !== "" && in_array($status, array("open", "waiting_human", "ai_answered", "human_replied", "closed", "spam"))) {
            $session->status = $status;
        }

        $agentCode = $this->normalizeCode($this->stringValue($body, "agentCode", ""));
        if ($agentCode !== "") {
            $session->agentCode = $agentCode;
        }

        if ($this->boolValue($body, "assignToMe", false)) {
            $human = $this->currentHumanAgent();
            $session->assignedAgentId = $human->id;
            $session->assignedAgentName = $human->name;
        }

        if ($this->boolValue($body, "clearReview", false)) {
            $session->needsHumanReview = "false";
            $session->humanUnreadCount = 0;
        }

        $session->updatedAt = $this->now();
        $session = $this->saveSession($session, $res);
        if ($session === null) {
            return null;
        }

        $out = $this->ok();
        $out->session = $session;
        return $out;
    }

    private function findOrCreateSession($identity, $agentCode, $body, $res) {
        $session = $this->sessionByKey($identity->sessionKey, true);
        $agentCode = $this->normalizeCode($agentCode);
        $visitorName = $this->stringValue($body, "visitorName", "");
        $visitorEmail = $this->stringValue($body, "visitorEmail", "");

        if ($session !== null) {
            $changed = false;
            if ($agentCode !== "" && $this->value($session, "agentCode", "") !== $agentCode) {
                $session->agentCode = $agentCode;
                $changed = true;
            }
            if ($visitorName !== "" && $this->value($session, "visitorName", "") !== $visitorName) {
                $session->visitorName = $visitorName;
                $changed = true;
            }
            if ($visitorEmail !== "" && $this->value($session, "visitorEmail", "") !== $visitorEmail) {
                $session->visitorEmail = $visitorEmail;
                $changed = true;
            }
            if ($changed) {
                $session->updatedAt = $this->now();
                $session = $this->saveSession($session, $res);
            }
            return $session;
        }

        $now = $this->now();
        $session = new \stdClass();
        $session->sessionKey = $identity->sessionKey;
        $session->visitorKey = $identity->visitorKey;
        $session->visitorType = $identity->type;
        $session->visitorId = $identity->profileId;
        $session->visitorName = $visitorName !== "" ? $visitorName : $identity->name;
        $session->visitorEmail = $visitorEmail !== "" ? $visitorEmail : $identity->email;
        $session->agentCode = $agentCode;
        $session->status = "open";
        $session->needsHumanReview = "false";
        $session->humanUnreadCount = 0;
        $session->visitorUnreadCount = 0;
        $session->lastSender = "";
        $session->lastMessagePreview = "";
        $session->lastMessageAt = $now;
        $session->assignedAgentId = "";
        $session->assignedAgentName = "";
        $session->createdAt = $now;
        $session->updatedAt = $now;
        return $this->saveSession($session, $res);
    }

    private function sessionByKey($sessionKey, $viewObject) {
        $sessionKey = $this->normalizeSessionKey($sessionKey);
        if ($sessionKey === "") {
            return null;
        }
        $result = \SOSSData::Query($this->sessionNamespace, "sessionKey:" . $sessionKey, null, "desc", 1, 0, null, $viewObject);
        if ($result->success && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function adminSessionFromBody($body, $res) {
        $sessionKey = $this->normalizeSessionKey($this->stringValue($body, "sessionKey", ""));
        if ($sessionKey === "") {
            $res->SetError("Session key is required.");
            return null;
        }
        $session = $this->sessionByKey($sessionKey, false);
        if ($session === null) {
            $res->SetError("Chat session was not found.");
            return null;
        }
        return $session;
    }

    private function saveSession($session, $res) {
        $isUpdate = isset($session->id) && intval($session->id) > 0;
        $result = $isUpdate ? \SOSSData::Update($this->sessionNamespace, $session) : \SOSSData::Insert($this->sessionNamespace, $session);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Chat session save failed.");
            return null;
        }
        if (!$isUpdate && isset($result->result->generatedId)) {
            $session->id = $result->result->generatedId;
        }
        return $session;
    }

    private function clearVisitorUnread($session) {
        if (intval($this->value($session, "visitorUnreadCount", 0)) <= 0) {
            return $session;
        }
        $session->visitorUnreadCount = 0;
        $session->updatedAt = $this->now();
        $result = \SOSSData::Update($this->sessionNamespace, $session);
        return $result->success ? $session : $session;
    }

    private function insertMessage($sessionKey, $senderType, $senderId, $senderName, $body, $direction, $status, $agentCode, $raw, $res) {
        $message = new \stdClass();
        $message->messageId = $this->newKey("msg");
        $message->sessionKey = $sessionKey;
        $message->senderType = $senderType;
        $message->senderId = (string)$senderId;
        $message->senderName = $senderName;
        $message->body = $body;
        $message->direction = $direction;
        $message->status = $status;
        $message->agentCode = $agentCode;
        $message->raw = $raw;
        $message->createdAt = $this->now();

        $result = \SOSSData::Insert($this->messageNamespace, $message);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Chat message save failed.");
            return null;
        }
        if (isset($result->result->generatedId)) {
            $message->id = $result->result->generatedId;
        }
        return $message;
    }

    private function messagesForSession($sessionKey, $limit, $viewObject) {
        $rows = $this->rows($this->messageNamespace, "sessionKey:" . $sessionKey, "asc", $limit, 0, $viewObject);
        usort($rows, function($a, $b) {
            return strcmp((string)$this->value($a, "createdAt", ""), (string)$this->value($b, "createdAt", ""));
        });
        return $rows;
    }

    private function askAgent($session, $message, $body) {
        $creator = $this->creatorService();
        if (!$creator->success) {
            return $creator;
        }

        $context = isset($body->context) ? $this->objectToArray($body->context) : array();
        $payload = isset($body->payload) ? $this->objectToArray($body->payload) : array();
        $profileId = $this->value($session, "visitorId", "");
        if ($profileId === "" || $profileId === "0") {
            $profileId = $this->value($session, "visitorKey", "");
        }

        return $creator->service->interactWithAgent(array(
            "agentCode" => $this->value($session, "agentCode", ""),
            "message" => $message,
            "appCode" => "chat-agent",
            "appName" => "Chat Agent",
            "profile" => array(
                "profileId" => $profileId,
                "name" => $this->value($session, "visitorName", ""),
                "email" => $this->value($session, "visitorEmail", "")
            ),
            "conversationKey" => $session->sessionKey,
            "context" => array(
                "chatSession" => array(
                    "sessionKey" => $session->sessionKey,
                    "status" => $this->value($session, "status", ""),
                    "needsHumanReview" => $this->value($session, "needsHumanReview", "false")
                ),
                "appContext" => $context
            ),
            "payload" => $payload
        ));
    }

    private function creatorService() {
        $file = dirname(dirname(dirname(__DIR__))) . "/ai-agent-creator/services/creator-api/service.php";
        if (!file_exists($file)) {
            return $this->fail("ai-agent-creator service file was not found.");
        }

        require_once($file);
        if (!class_exists("\\ai_agent_creator\\CreatorService")) {
            return $this->fail("ai-agent-creator service class was not loaded.");
        }

        $out = $this->ok();
        $out->service = new \ai_agent_creator\CreatorService();
        return $out;
    }

    private function currentIdentity($newSession) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $user = null;
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
        }

        $profile = $this->currentProfile();
        if (isset($user->userid)) {
            $token = isset($_COOKIE["securityToken"]) ? $_COOKIE["securityToken"] : (isset($user->token) ? $user->token : $user->userid);
            $key = "auth-" . substr(hash("sha256", (string)$token), 0, 32);
            $identity = new \stdClass();
            $identity->type = "authenticated";
            $identity->visitorKey = $key;
            $identity->sessionKey = $key;
            $identity->profileId = isset($profile->id) ? (string)$profile->id : (string)$user->userid;
            $identity->name = isset($profile->name) && $profile->name !== "Unknown" ? $profile->name : (isset($user->email) ? $user->email : "Signed-in user");
            $identity->email = isset($user->email) ? $user->email : "";
            return $identity;
        }

        $visitorKey = isset($_COOKIE[$this->visitorCookie]) ? $this->normalizeSessionKey($_COOKIE[$this->visitorCookie]) : "";
        if ($visitorKey === "") {
            $visitorKey = $this->newKey("visitor");
            $this->setCookieValue($this->visitorCookie, $visitorKey, 365);
        }

        $sessionKey = isset($_COOKIE[$this->sessionCookie]) ? $this->normalizeSessionKey($_COOKIE[$this->sessionCookie]) : "";
        if ($newSession || $sessionKey === "") {
            $sessionKey = $this->newKey("chat");
            $this->setCookieValue($this->sessionCookie, $sessionKey, 30);
        }

        $identity = new \stdClass();
        $identity->type = "anonymous";
        $identity->visitorKey = $visitorKey;
        $identity->sessionKey = $sessionKey;
        $identity->profileId = "";
        $identity->name = "Visitor";
        $identity->email = "";
        return $identity;
    }

    private function currentHumanAgent() {
        $profile = $this->currentProfile();
        $out = new \stdClass();
        $out->id = isset($profile->id) && intval($profile->id) > 0 ? (string)$profile->id : (defined("GROUPID") ? GROUPID : "human-agent");
        $out->name = isset($profile->name) && $profile->name !== "Unknown" ? $profile->name : (defined("GROUPID") ? GROUPID : "Human Agent");
        return $out;
    }

    private function currentProfile() {
        $out = new \stdClass();
        $out->id = 0;
        $out->name = "Unknown";
        if (class_exists("\\Profile")) {
            $profile = \Profile::getUserProfile();
            if (isset($profile->profile) && isset($profile->profile->id)) {
                $out->id = $profile->profile->id;
                $out->name = isset($profile->profile->name) ? $profile->profile->name : "Unknown";
                return $out;
            }
        }
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
            if (isset($user->userid) && class_exists("\\SOSSData")) {
                $profileResult = \SOSSData::Query("profile", "linkeduserid:" . $user->userid);
                if ($profileResult->success && count($profileResult->result) > 0) {
                    $out->id = $profileResult->result[0]->id;
                    $out->name = isset($profileResult->result[0]->name) ? $profileResult->result[0]->name : "Unknown";
                    return $out;
                }
                $out->name = isset($user->email) ? $user->email : "Unknown";
            }
        }
        return $out;
    }

    private function requireHumanAgent($res) {
        $group = defined("GROUPID") ? strtolower(GROUPID) : "";
        if (in_array($group, array("sysadmin", "admin", "staff", "human_agent", "agent"))) {
            return true;
        }
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
            if (isset($user->group) && in_array(strtolower($user->group), array("sysadmin", "admin", "staff", "human_agent", "agent"))) {
                return true;
            }
        }
        $res->SetError("Human agent access is required.");
        return false;
    }

    private function rows($namespace, $query, $sorting, $pageSize, $fromPage, $viewObject) {
        $result = \SOSSData::Query($namespace, $query, null, $sorting, $pageSize, $fromPage, null, $viewObject);
        return $result->success ? $result->result : array();
    }

    private function ensureStore($res) {
        if (!class_exists("\\SOSSData")) {
            $res->SetError("SOSSData is not available for Chat Agent.");
            return false;
        }
        return true;
    }

    private function sessionSearchText($session) {
        return implode(" ", array(
            $this->value($session, "sessionKey", ""),
            $this->value($session, "visitorName", ""),
            $this->value($session, "visitorEmail", ""),
            $this->value($session, "agentCode", ""),
            $this->value($session, "lastMessagePreview", ""),
            $this->value($session, "status", "")
        ));
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function objectToArray($value) {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $decoded = json_decode(json_encode($value), true);
            return is_array($decoded) ? $decoded : array();
        }
        return array();
    }

    private function stringValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return trim(substr((string)$body->$key, 0, 50000));
    }

    private function boolValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        if (is_bool($body->$key)) {
            return $body->$key;
        }
        return filter_var($body->$key, FILTER_VALIDATE_BOOLEAN);
    }

    private function normalizeSessionKey($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9@._:-]+/", "-", $value);
        $value = trim($value, "-_");
        return substr($value, 0, 160);
    }

    private function normalizeCode($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "") {
            return "";
        }
        return substr($value, 0, 80);
    }

    private function preview($value) {
        $value = trim(preg_replace("/\s+/", " ", (string)$value));
        return substr($value, 0, 220);
    }

    private function value($object, $key, $default) {
        if (is_object($object) && isset($object->$key)) {
            return $object->$key;
        }
        if (is_array($object) && isset($object[$key])) {
            return $object[$key];
        }
        return $default;
    }

    private function newKey($prefix) {
        if (function_exists("random_bytes")) {
            return $prefix . "-" . bin2hex(random_bytes(16));
        }
        return $prefix . "-" . md5(uniqid("", true));
    }

    private function setCookieValue($name, $value, $days) {
        $_COOKIE[$name] = $value;
        if (!headers_sent()) {
            setcookie($name, $value, time() + (86400 * $days), "/");
        }
    }

    private function now() {
        return gmdate("Y-m-d H:i:s");
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
