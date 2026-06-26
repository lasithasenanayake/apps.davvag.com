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
        $out = $this->ok();
        $out->identity = $identity;
        if (isset($identity->profile) && isset($identity->profile->id) && intval($identity->profile->id) > 0) {
            $out->profile = $identity->profile;
        }
        $out->defaultAgentCode = $this->configuredAgentCode();
        if (!$this->shouldBootstrapSession($identity, $body)) {
            $out->session = null;
            $out->messages = array();
            return $out;
        }

        $session = $this->findOrCreateSession($identity, $this->agentCodeFromBody($body), $body, $res);
        if ($session === null) {
            return null;
        }

        $session = $this->clearVisitorUnread($session);
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        return $out;
    }

    public function postPollSession($req, $res) {
        if (!$this->ensureStore($res)) {
            return null;
        }

        $body = $this->body($req);
        $identity = $this->currentIdentity(false);
        if (!$this->shouldBootstrapSession($identity, $body)) {
            $out = $this->ok();
            $out->identity = $identity;
            if (isset($identity->profile) && isset($identity->profile->id) && intval($identity->profile->id) > 0) {
                $out->profile = $identity->profile;
            }
            $out->session = null;
            $out->messages = array();
            return $out;
        }

        $session = $this->findOrCreateSession($identity, $this->agentCodeFromBody($body), $body, $res);
        if ($session === null) {
            return null;
        }

        $session = $this->clearVisitorUnread($session);
        $out = $this->ok();
        $out->identity = $identity;
        if (isset($identity->profile) && isset($identity->profile->id) && intval($identity->profile->id) > 0) {
            $out->profile = $identity->profile;
        }
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        return $out;
    }

    public function postResolveProfile($req, $res) {
        if (!$this->ensureStore($res)) {
            return null;
        }

        $body = $this->body($req);
        $profileResult = $this->resolveProfileForBody($body, $res);
        if ($profileResult === null) {
            return null;
        }

        $body->profileId = (string)$profileResult->profile->id;
        $identity = $this->currentIdentity(false);
        $session = $this->findOrCreateSession($identity, $this->agentCodeFromBody($body), $body, $res);
        if ($session === null) {
            return null;
        }

        $out = $this->ok();
        $out->profile = $profileResult->profile;
        $out->createdProfile = $profileResult->created;
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        $out->defaultAgentCode = $this->configuredAgentCode();
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
        $profileResult = $this->resolveProfileForBody($body, $res);
        if ($profileResult === null) {
            return null;
        }

        $body->profileId = (string)$profileResult->profile->id;
        $session = $this->findOrCreateSession($identity, $this->agentCodeFromBody($body), $body, $res);
        if ($session === null) {
            return null;
        }

        $now = $this->now();
        $visitorName = $this->stringValue($body, "visitorName", "");
        $visitorEmail = $this->stringValue($body, "visitorEmail", "");
        $visitorPhone = $this->stringValue($body, "visitorPhone", "");
        $visitorDetails = $this->stringValue($body, "visitorDetails", "");
        if ($visitorName !== "") {
            $session->visitorName = $visitorName;
        }
        if ($visitorEmail !== "") {
            $session->visitorEmail = $visitorEmail;
        }
        if ($visitorPhone !== "") {
            $session->visitorPhone = $visitorPhone;
        }
        if ($visitorDetails !== "") {
            $session->visitorDetails = $visitorDetails;
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
                    $agentIdentity = $this->agentIdentityForCode($session->agentCode);
                    $aiMessage = $this->insertMessage($session->sessionKey, "ai_agent", $agentIdentity->senderId, $agentIdentity->name, $reply, "outbound", "sent", $session->agentCode, $agentRun, $res);
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
        $out->profile = $profileResult->profile;
        $out->session = $session;
        $out->messages = $this->messagesForSession($session->sessionKey, 200, true);
        $out->agent = $agentRun;
        $out->defaultAgentCode = $this->configuredAgentCode();
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

    public function postSettings($req, $res) {
        if (!$this->requireHumanAgent($res)) {
            return null;
        }

        $creator = $this->savedAgentsForSettings();
        $out = $this->ok();
        $out->settings = $this->chatSettings();
        $out->defaultAgentCode = $this->configuredAgentCode();
        $out->agents = $creator->agents;
        if (!$creator->success) {
            $out->agentLoadMessage = $creator->message;
        }
        return $out;
    }

    public function postSaveSettings($req, $res) {
        if (!$this->requireHumanAgent($res)) {
            return null;
        }

        $body = $this->body($req);
        $agentCode = $this->normalizeCode($this->stringValue($body, "defaultAgentCode", ""));
        if ($agentCode === "") {
            $agentCode = $this->normalizeCode($this->stringValue($body, "agentCode", ""));
        }
        if ($agentCode === "") {
            $res->SetError("Select an AI agent before saving settings.");
            return null;
        }

        $creator = $this->savedAgentsForSettings();
        if (!$this->agentExists($creator->agents, $agentCode)) {
            $res->SetError("Selected AI agent was not found. Save the agent in AI Agent Creator first.");
            return null;
        }

        $settings = $this->chatSettings();
        $settings->defaultAgentCode = $agentCode;
        $settings->updatedAt = $this->now();
        $human = $this->currentHumanAgent();
        $settings->updatedBy = $human->name;

        if (!$this->saveChatSettings($settings)) {
            $res->SetError("Unable to save Chat Agent settings.");
            return null;
        }

        $out = $this->ok();
        $out->settings = $settings;
        $out->defaultAgentCode = $agentCode;
        $out->agents = $creator->agents;
        return $out;
    }

    private function shouldBootstrapSession($identity, $body) {
        if (isset($identity->type) && $identity->type === "authenticated") {
            return true;
        }

        $profileId = $this->normalizeProfileId($this->stringValue($body, "profileId", ""));
        if ($profileId !== "") {
            return true;
        }
        if ($this->boolValue($body, "forceSession", false)) {
            return true;
        }
        return false;
    }

    private function findOrCreateSession($identity, $agentCode, $body, $res) {
        $session = $this->sessionByKey($identity->sessionKey, true);
        $agentCode = $this->normalizeCode($agentCode);
        $profileId = $this->normalizeProfileId($this->stringValue($body, "profileId", ""));
        $visitorName = $this->stringValue($body, "visitorName", "");
        $visitorEmail = $this->stringValue($body, "visitorEmail", "");
        $visitorPhone = $this->stringValue($body, "visitorPhone", "");
        $visitorDetails = $this->stringValue($body, "visitorDetails", "");
        if (isset($identity->type) && $identity->type === "authenticated") {
            $profileId = isset($identity->profileId) ? $this->normalizeProfileId($identity->profileId) : "";
            $visitorName = isset($identity->name) ? $identity->name : $visitorName;
            $visitorEmail = isset($identity->email) ? $identity->email : $visitorEmail;
            $visitorPhone = isset($identity->phone) ? $identity->phone : $visitorPhone;
        } elseif ($profileId === "" && isset($identity->profileId)) {
            $profileId = $this->normalizeProfileId($identity->profileId);
        }
        if ($visitorName === "" && isset($identity->name)) {
            $visitorName = $identity->name;
        }
        if ($visitorEmail === "" && isset($identity->email)) {
            $visitorEmail = $identity->email;
        }
        if ($visitorPhone === "" && isset($identity->phone)) {
            $visitorPhone = $identity->phone;
        }

        if ($session !== null) {
            $changed = false;
            if ($agentCode !== "" && $this->value($session, "agentCode", "") !== $agentCode) {
                $session->agentCode = $agentCode;
                $changed = true;
            }
            if ($profileId !== "" && $this->value($session, "visitorId", "") !== $profileId) {
                $session->visitorId = $profileId;
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
            if ($visitorPhone !== "" && $this->value($session, "visitorPhone", "") !== $visitorPhone) {
                $session->visitorPhone = $visitorPhone;
                $changed = true;
            }
            if ($visitorDetails !== "" && $this->value($session, "visitorDetails", "") !== $visitorDetails) {
                $session->visitorDetails = $visitorDetails;
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
        $session->visitorId = $profileId !== "" ? $profileId : $identity->profileId;
        $session->visitorName = $visitorName !== "" ? $visitorName : $identity->name;
        $session->visitorEmail = $visitorEmail !== "" ? $visitorEmail : $identity->email;
        $session->visitorPhone = $visitorPhone;
        $session->visitorDetails = $visitorDetails;
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
        $message->body = $this->messageText($body);
        $message->direction = $direction;
        $message->status = $status;
        $message->agentCode = $agentCode;
        $message->raw = $this->messageRaw($raw);
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

    private function messageText($input) {
        $input = trim((string)$input);
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
        return substr($input, 0, 5000);
    }

    private function messageRaw($raw) {
        if ($raw === null) {
            return null;
        }

        $out = new \stdClass();
        $out->success = $this->value($raw, "success", null);
        $out->message = $this->value($raw, "message", "");
        $out->agentCode = $this->value($raw, "agentCode", "");
        $out->provider = $this->value($raw, "provider", "");
        $out->model = $this->value($raw, "model", "");
        $reply = $this->value($raw, "response", $this->value($raw, "reply", ""));
        $out->replyLength = strlen((string)$reply);
        $out->interaction = $this->value($raw, "interaction", null);
        $out->skillResults = $this->value($raw, "skillResults", null);
        return $out;
    }

    private function messagesForSession($sessionKey, $limit, $viewObject) {
        $rows = $this->rows($this->messageNamespace, "sessionKey:" . $sessionKey, "asc", $limit, 0, $viewObject);
        usort($rows, function($a, $b) {
            return strcmp((string)$this->value($a, "createdAt", ""), (string)$this->value($b, "createdAt", ""));
        });
        return $this->enrichMessageSenders($rows);
    }

    private function enrichMessageSenders($messages) {
        $agentIdentities = array();
        foreach ($messages as $message) {
            if (!is_object($message)) {
                continue;
            }

            $senderType = $this->value($message, "senderType", "");
            if ($senderType === "ai_agent") {
                $agentCode = $this->value($message, "agentCode", "");
                if (!isset($agentIdentities[$agentCode])) {
                    $agentIdentities[$agentCode] = $this->agentIdentityForCode($agentCode);
                }
                $identity = $agentIdentities[$agentCode];
                $message->senderName = $identity->name;
                $message->senderProfileId = $identity->profileId;
                $message->senderImage = $identity->image;
                if ($identity->senderId !== "") {
                    $message->senderId = $identity->senderId;
                }
            } elseif ($senderType === "visitor" || $senderType === "human") {
                $profileId = $this->numericProfileId($this->value($message, "senderId", ""));
                if ($profileId > 0) {
                    $message->senderProfileId = $profileId;
                    $message->senderImage = $this->profileImageUrl($profileId);
                }
            }
        }
        return $messages;
    }

    private function resolveProfileForBody($body, $res) {
        $identity = $this->currentIdentity(false);
        if (isset($identity->type) && $identity->type === "authenticated") {
            if (isset($identity->profile) && isset($identity->profile->id) && intval($identity->profile->id) > 0) {
                $out = $this->ok();
                $out->profile = $identity->profile;
                $out->created = false;
                return $out;
            }

            $res->SetError("Your signed-in user does not have a registered profile.");
            return null;
        }

        $profileId = $this->normalizeProfileId($this->stringValue($body, "profileId", ""));
        $name = $this->limit($this->stringValue($body, "visitorName", ""), 200);
        $email = strtolower($this->limit($this->stringValue($body, "visitorEmail", ""), 200));
        $phone = $this->limit($this->stringValue($body, "visitorPhone", ""), 20);
        $details = $this->limit($this->stringValue($body, "visitorDetails", ""), 1200);

        $created = false;
        $profile = null;

        if ($email !== "" && $this->isSafeProfileEmail($email)) {
            $profile = $this->profileByEmail($email);
        }

        if ($profile === null) {
            $profile = $this->profileById($profileId);
        }

        if ($profile === null) {
            if ($name === "") {
                $res->SetError("Name is required before starting chat.");
                return null;
            }
            if (!$this->isSafeProfileEmail($email)) {
                $res->SetError("A valid email is required before starting chat.");
                return null;
            }
            if ($phone === "") {
                $res->SetError("Phone is required before starting chat.");
                return null;
            }

            $profile = $this->profileByEmail($email);
            if ($profile === null) {
                $profile = $this->createProfile($name, $email, $phone, $res);
                if ($profile === null) {
                    return null;
                }
                $created = true;
            }
        }

        $profile = $this->saveProfileFormFields($profile, $name, $email, $phone, $res);
        if ($profile === null) {
            return null;
        }

        if ($details !== "" && isset($profile->id)) {
            $this->saveProfileDetails($profile->id, $details);
            $profile->details = $details;
        }

        $out = $this->ok();
        $out->profile = $profile;
        $out->created = $created;
        return $out;
    }

    private function saveProfileFormFields($profile, $name, $email, $phone, $res) {
        if ($profile === null || !isset($profile->id)) {
            return $profile;
        }

        $changed = false;
        if ($name !== "" && $this->value($profile, "name", "") !== $name) {
            $profile->name = $name;
            $changed = true;
        }
        if ($email !== "" && $this->isSafeProfileEmail($email) && strtolower($this->value($profile, "email", "")) !== $email) {
            $profile->email = $email;
            $changed = true;
        }
        if ($phone !== "" && $this->value($profile, "contactno", "") !== $phone) {
            $profile->contactno = $phone;
            $changed = true;
        }

        if (!$changed) {
            return $profile;
        }

        $result = \SOSSData::Update("profile", $profile, null);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Profile update failed.");
            return null;
        }
        return $profile;
    }

    private function profileById($profileId) {
        if ($profileId === "" || intval($profileId) <= 0) {
            return null;
        }
        $result = \SOSSData::Query("profile", urlencode("id:" . intval($profileId)), null, "desc", 1, 0, null, false);
        if ($result->success && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function profileByEmail($email) {
        $email = strtolower(trim((string)$email));
        if ($email === "") {
            return null;
        }
        $result = \SOSSData::Query("profile", urlencode("email:" . $email), null, "desc", 1, 0, null, false);
        if ($result->success && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function createProfile($name, $email, $phone, $res) {
        $profile = new \stdClass();
        $profile->name = $name;
        $profile->email = $email;
        $profile->contactno = $phone;
        $profile->catogory = "Customer";
        $profile->country = "Sri Lanka";
        $profile->createdate = date_format(new \DateTime(), "m-d-Y H:i:s");
        $profile->Status = "inactive";

        $user = $this->currentUser();
        if (isset($user->userid)) {
            $profile->userid = $user->userid;
        }

        $result = \SOSSData::Insert("profile", $profile, null);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Profile save failed.");
            return null;
        }
        if (isset($result->result->generatedId)) {
            $profile->id = $result->result->generatedId;
        }
        return $profile;
    }

    private function saveProfileDetails($profileId, $details) {
        $profileId = intval($profileId);
        $details = $this->limit($details, 600);
        if ($profileId <= 0 || $details === "") {
            return;
        }

        $result = \SOSSData::Query("profile_attributes", urlencode("id:" . $profileId), null, "desc", 1, 0, null, false);
        if ($result->success && count($result->result) > 0) {
            $attributes = $result->result[0];
            if (!isset($attributes->notes) || trim((string)$attributes->notes) === "") {
                $attributes->notes = $details;
                \SOSSData::Update("profile_attributes", $attributes, null);
            }
            return;
        }

        $attributes = new \stdClass();
        $attributes->id = $profileId;
        $attributes->notes = $details;
        \SOSSData::Insert("profile_attributes", $attributes, null);
    }

    private function isSafeProfileEmail($email) {
        return $email !== ""
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && preg_match("/^[A-Za-z0-9._%+@-]+$/", $email) === 1;
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
                "email" => $this->value($session, "visitorEmail", ""),
                "phone" => $this->value($session, "visitorPhone", ""),
                "details" => $this->value($session, "visitorDetails", "")
            ),
            "conversationKey" => $session->sessionKey,
            "context" => array(
                "chatSession" => array(
                    "sessionKey" => $session->sessionKey,
                    "status" => $this->value($session, "status", ""),
                    "needsHumanReview" => $this->value($session, "needsHumanReview", "false"),
                    "visitorPhone" => $this->value($session, "visitorPhone", ""),
                    "visitorDetails" => $this->value($session, "visitorDetails", "")
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

        $user = $this->currentUser();
        $profile = $this->currentProfile();
        if (isset($user->userid)) {
            $token = isset($_COOKIE["securityToken"]) ? $_COOKIE["securityToken"] : (isset($user->token) ? $user->token : $user->userid);
            $key = "auth-" . substr(hash("sha256", (string)$token), 0, 32);
            $identity = new \stdClass();
            $identity->type = "authenticated";
            $identity->visitorKey = $key;
            $identity->sessionKey = $key;
            $identity->profileId = isset($profile->id) && intval($profile->id) > 0 ? (string)$profile->id : "";
            $identity->name = isset($profile->name) && $profile->name !== "Unknown" ? $profile->name : (isset($user->email) ? $user->email : "Signed-in user");
            $identity->email = isset($profile->email) && $profile->email !== "" ? $profile->email : (isset($user->email) ? $user->email : "");
            $identity->phone = isset($profile->contactno) ? $profile->contactno : "";
            if (isset($profile->id) && intval($profile->id) > 0) {
                $identity->profile = $profile;
            }
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
        $identity->phone = "";
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
            try {
                $profile = \Profile::getUserProfile();
                if (isset($profile->profile) && isset($profile->profile->id)) {
                    return $this->normalizeProfileObject($profile->profile);
                }
            } catch (\Throwable $th) {
            }
        }

        $user = $this->currentUser();
        if (isset($user->userid) && class_exists("\\SOSSData")) {
            $profileResult = \SOSSData::Query("profile", "linkeduserid:" . $user->userid);
            if ($profileResult->success && count($profileResult->result) > 0) {
                return $this->normalizeProfileObject($profileResult->result[0]);
            }
            if (isset($user->email)) {
                $profileResult = \SOSSData::Query("profile", urlencode("email:" . strtolower((string)$user->email)), null, "desc", 1, 0, null, false);
                if ($profileResult->success && count($profileResult->result) > 0) {
                    return $this->normalizeProfileObject($profileResult->result[0]);
                }
            }
            $out->name = isset($user->email) ? $user->email : "Unknown";
            $out->email = isset($user->email) ? $user->email : "";
        }
        return $out;
    }

    private function normalizeProfileObject($profile) {
        $out = new \stdClass();
        $out->id = isset($profile->id) ? $profile->id : 0;
        $out->name = isset($profile->name) ? $profile->name : "Unknown";
        $out->email = isset($profile->email) ? $profile->email : "";
        $out->contactno = isset($profile->contactno) ? $profile->contactno : "";
        $out->phone = $out->contactno;
        $out->catogory = isset($profile->catogory) ? $profile->catogory : "";
        $out->linkeduserid = isset($profile->linkeduserid) ? $profile->linkeduserid : "";
        $out->userid = isset($profile->userid) ? $profile->userid : "";
        return $out;
    }

    private function currentUser() {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (isset($_SESSION["authData"]) && is_object($_SESSION["authData"])) {
            return $_SESSION["authData"];
        }

        if (isset($_COOKIE["authData"])) {
            $user = json_decode($_COOKIE["authData"]);
            if (is_object($user)) {
                return $user;
            }
        }

        if (!class_exists("\\Auth")) {
            return null;
        }

        try {
            $method = new \ReflectionMethod("\\Auth", "Autendicate");
            if ($method->getNumberOfRequiredParameters() === 0) {
                $user = \Auth::Autendicate();
                if (is_object($user)) {
                    return $user;
                }
            }
        } catch (\Throwable $th) {
        }

        if (isset($_COOKIE["securityToken"]) && method_exists("\\Auth", "GetSession")) {
            try {
                $user = \Auth::GetSession($_COOKIE["securityToken"]);
                if (is_object($user)) {
                    return $user;
                }
            } catch (\Throwable $th) {
            }
        }

        return null;
    }

    private function requireHumanAgent($res) {
        $group = defined("GROUPID") ? strtolower(GROUPID) : "";
        if (in_array($group, array("sysadmin", "admin", "staff", "human_agent", "agent"))) {
            return true;
        }
        $user = $this->currentUser();
        if (isset($user->group) && in_array(strtolower($user->group), array("sysadmin", "admin", "staff", "human_agent", "agent"))) {
            return true;
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
            $this->value($session, "visitorPhone", ""),
            $this->value($session, "visitorDetails", ""),
            $this->value($session, "agentCode", ""),
            $this->value($session, "lastMessagePreview", ""),
            $this->value($session, "status", "")
        ));
    }

    private function agentCodeFromBody($body) {
        return $this->configuredAgentCode();
    }

    private function configuredAgentCode() {
        $settings = $this->chatSettings();
        $agentCode = isset($settings->defaultAgentCode) ? (string)$settings->defaultAgentCode : "";
        $agentCode = $this->normalizeCode($agentCode);
        return $agentCode !== "" ? $agentCode : $this->appConfiguredAgentCode();
    }

    private function appConfiguredAgentCode() {
        $config = $this->appConfig();
        $agentCode = $this->defaultAgentCode;
        if (isset($config->configuration) && isset($config->configuration->chatAgent) && isset($config->configuration->chatAgent->defaultAgentCode)) {
            $agentCode = (string)$config->configuration->chatAgent->defaultAgentCode;
        }

        $agentCode = $this->normalizeCode($agentCode);
        return $agentCode !== "" ? $agentCode : $this->defaultAgentCode;
    }

    private function chatSettings() {
        $settings = new \stdClass();
        $settings->defaultAgentCode = $this->appConfiguredAgentCode();
        $settings->updatedAt = "";
        $settings->updatedBy = "";

        $file = $this->settingsFile();
        if (file_exists($file)) {
            $stored = json_decode(file_get_contents($file));
            if (is_object($stored)) {
                foreach ($stored as $key => $value) {
                    $settings->$key = $value;
                }
            }
        }

        $settings->defaultAgentCode = $this->normalizeCode(isset($settings->defaultAgentCode) ? $settings->defaultAgentCode : "");
        if ($settings->defaultAgentCode === "") {
            $settings->defaultAgentCode = $this->appConfiguredAgentCode();
        }
        return $settings;
    }

    private function saveChatSettings($settings) {
        $dir = dirname($this->settingsFile());
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }
        return file_put_contents($this->settingsFile(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function settingsFile() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/data/chat-agent/settings.json";
        }
        return dirname(dirname(__DIR__)) . "/data/settings.json";
    }

    private function savedAgentsForSettings() {
        $out = $this->ok();
        $out->agents = array();

        $creator = $this->creatorService();
        if (!$creator->success) {
            $out->success = false;
            $out->message = $creator->message;
            return $out;
        }

        $list = $creator->service->getListAgents(null, null);
        if (!isset($list->success) || !$list->success) {
            $out->success = false;
            $out->message = isset($list->message) ? $list->message : "Unable to load saved AI agents.";
            return $out;
        }

        $out->agents = isset($list->agents) && is_array($list->agents) ? $list->agents : array();
        return $out;
    }

    private function agentIdentityForCode($agentCode) {
        $identity = new \stdClass();
        $identity->senderId = $agentCode;
        $identity->profileId = "";
        $identity->name = "AI Agent";
        $identity->image = "";

        $agentCode = $this->normalizeCode($agentCode);
        if ($agentCode === "") {
            return $identity;
        }

        $agents = $this->savedAgentsForSettings();
        if (!isset($agents->agents) || !is_array($agents->agents)) {
            return $identity;
        }

        foreach ($agents->agents as $agent) {
            $agentData = $this->objectToArray($agent);
            if ($this->value($agentData, "agentCode", "") !== $agentCode) {
                continue;
            }

            $identity->name = $this->value($agentData, "name", "AI Agent");
            $config = isset($agentData["configuration"]) && is_array($agentData["configuration"]) ? $agentData["configuration"] : array();
            $agentConfig = isset($config["agent"]) && is_array($config["agent"]) ? $config["agent"] : array();
            $profile = isset($agentConfig["profile"]) && is_array($agentConfig["profile"]) ? $agentConfig["profile"] : array();

            $profileName = $this->value($profile, "name", "");
            if ($profileName !== "") {
                $identity->name = $profileName;
            }

            $profileId = $this->numericProfileId($this->value($profile, "profileId", $this->value($agentConfig, "profileId", "")));
            if ($profileId > 0) {
                $identity->profileId = (string)$profileId;
                $identity->senderId = (string)$profileId;
                $identity->image = $this->value($profile, "image", $this->value($agentConfig, "profileImage", ""));
                if ($identity->image === "") {
                    $identity->image = $this->profileImageUrl($profileId);
                }
            }

            return $identity;
        }

        return $identity;
    }

    private function agentExists($agents, $agentCode) {
        foreach ($agents as $agent) {
            if (is_array($agent) && isset($agent["agentCode"]) && $agent["agentCode"] === $agentCode) {
                return true;
            }
            if (is_object($agent) && isset($agent->agentCode) && $agent->agentCode === $agentCode) {
                return true;
            }
        }
        return false;
    }

    private function appConfig() {
        $file = dirname(dirname(__DIR__)) . "/app.json";
        if (!file_exists($file)) {
            return new \stdClass();
        }

        $config = json_decode(file_get_contents($file));
        return is_object($config) ? $config : new \stdClass();
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

    private function normalizeProfileId($value) {
        $value = trim((string)$value);
        $value = preg_replace("/[^A-Za-z0-9@._:-]+/", "-", $value);
        $value = trim($value, "-_");
        return substr($value, 0, 120);
    }

    private function numericProfileId($value) {
        $value = trim((string)$value);
        return ctype_digit($value) ? intval($value) : 0;
    }

    private function profileImageUrl($profileId) {
        return "components/dock/soss-uploader/service/get/profile/" . intval($profileId);
    }

    private function preview($value) {
        $value = trim(preg_replace("/\s+/", " ", (string)$value));
        return substr($value, 0, 220);
    }

    private function limit($value, $length) {
        return substr(trim((string)$value), 0, $length);
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
