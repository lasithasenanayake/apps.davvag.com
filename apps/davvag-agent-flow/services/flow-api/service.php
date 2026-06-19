<?php
namespace davvag_agent_flow;

class FlowService {
    public function getBootstrap($req, $res) {
        $out = $this->ok();
        $out->connectors = $this->connectorDefinitions();
        $out->agents = array_values($this->safeAgentsForClient($this->loadAgents()));
        $out->flows = array_values($this->safeFlowsForClient($this->loadFlows()));
        $out->profileCount = count($this->loadProfiles());
        $out->conversationCount = count($this->loadConversations());
        $out->defaultFlow = $this->safeFlowForClient($this->defaultFlow());
        return $out;
    }

    public function getListAgents($req, $res) {
        $out = $this->ok();
        $out->agents = array_values($this->safeAgentsForClient($this->loadAgents()));
        return $out;
    }

    public function getListFlows($req, $res) {
        $out = $this->ok();
        $out->flows = array_values($this->safeFlowsForClient($this->loadFlows()));
        return $out;
    }

    public function postSaveFlow($req, $res) {
        $body = $this->objectToArray($this->body($req));
        $flows = $this->loadFlows();
        $normalized = $this->normalizeFlow($body, $flows, true);
        if (!$normalized->success) {
            return $normalized;
        }

        $flow = $normalized->flow;
        $flows[$flow["flowCode"]] = $flow;

        if (!$this->saveFlows($flows)) {
            return $this->fail("Unable to save the flow document.");
        }
        if (!$this->savePolicyPages($flow)) {
            return $this->fail("Flow saved, but policy pages could not be generated.");
        }

        $out = $this->ok();
        $out->flow = $this->safeFlowForClient($flow);
        $out->flows = array_values($this->safeFlowsForClient($flows));
        return $out;
    }

    public function postDeleteFlow($req, $res) {
        $body = $this->body($req);
        $flowCode = $this->normalizeCode($this->stringValue($body, "flowCode", ""));
        if ($flowCode === "") {
            return $this->fail("Flow code is required.");
        }

        $flows = $this->loadFlows();
        if (isset($flows[$flowCode])) {
            unset($flows[$flowCode]);
        }

        if (!$this->saveFlows($flows)) {
            return $this->fail("Unable to delete the flow document.");
        }

        $out = $this->ok();
        $out->flows = array_values($this->safeFlowsForClient($flows));
        return $out;
    }

    public function postSimulate($req, $res) {
        $body = $this->body($req);
        $input = isset($body->flow) ? $this->objectToArray($body->flow) : array();
        $flows = $this->loadFlows();

        if (!count($input)) {
            $flowCode = $this->normalizeCode($this->stringValue($body, "flowCode", ""));
            if ($flowCode !== "" && isset($flows[$flowCode])) {
                $input = $flows[$flowCode];
            }
        }

        $normalized = $this->normalizeFlow($input, $flows, false);
        if (!$normalized->success) {
            return $normalized;
        }

        $flow = $normalized->flow;
        $connectorCode = $this->normalizeConnectorCode($this->stringValue($body, "connectorCode", ""));
        $message = $this->stringValue($body, "message", "");
        $sender = $this->stringValue($body, "sender", "customer-001");

        if ($connectorCode === "") {
            return $this->fail("Inbound connector is required.");
        }
        if ($message === "") {
            return $this->fail("Message is required.");
        }
        if ($flow["agentCode"] === "") {
            return $this->fail("Select a saved ai-agent-creator agent before running this flow.");
        }

        $connectors = $this->connectorDefinitionsByCode();
        if (!isset($connectors[$connectorCode])) {
            return $this->fail("Unknown connector.");
        }

        $assignment = $this->connectorAssignment($flow, $connectorCode);
        if (!isset($assignment) || empty($assignment["enabled"])) {
            return $this->fail("Selected connector is disabled for this flow.");
        }

        $agents = $this->loadAgents();
        if (!isset($agents[$flow["agentCode"]])) {
            return $this->fail("The selected agent was not found in ai-agent-creator.");
        }

        $agent = $agents[$flow["agentCode"]];
        $connector = $connectors[$connectorCode];

        $run = array(
            "mode" => "dry_run",
            "flowCode" => $flow["flowCode"],
            "flowName" => $flow["name"],
            "connector" => array(
                "code" => $connector["code"],
                "label" => $connector["label"],
                "status" => $assignment["status"]
            ),
            "agent" => array(
                "agentCode" => $agent["agentCode"],
                "name" => $agent["name"],
                "workflow" => isset($agent["workflow"]) ? $agent["workflow"] : null
            ),
            "input" => $this->connectorPayload($connectorCode, $message, $sender),
            "steps" => array(
                array("node" => "connector.inbound", "status" => "received", "channel" => $connector["label"]),
                array("node" => "flow.router", "status" => "matched", "triggers" => $flow["triggers"]),
                array("node" => "ai-agent-creator." . $agent["agentCode"], "status" => "ready", "method" => "creator-api/TestAgent"),
                array("node" => "connector.outbound", "status" => "prepared", "channel" => $connector["label"])
            ),
            "delivery" => array(
                "channel" => $connector["code"],
                "deliveryMode" => $connector["deliveryMode"],
                "replyTarget" => $sender,
                "message" => "Dry run only. Production delivery should call the platform API with the saved connector settings."
            )
        );

        $out = $this->ok();
        $out->run = $run;
        return $out;
    }

    public function postConnectorPayload($req, $res) {
        $body = $this->body($req);
        $connectorCode = $this->normalizeConnectorCode($this->stringValue($body, "connectorCode", ""));
        $message = $this->stringValue($body, "message", "");
        $sender = $this->stringValue($body, "sender", "customer-001");

        if ($connectorCode === "") {
            return $this->fail("Connector code is required.");
        }
        if ($message === "") {
            return $this->fail("Message is required.");
        }

        $connectors = $this->connectorDefinitionsByCode();
        if (!isset($connectors[$connectorCode])) {
            return $this->fail("Unknown connector.");
        }

        $out = $this->ok();
        $out->payload = $this->connectorPayload($connectorCode, $message, $sender);
        return $out;
    }

    public function getWebhook($req, $res) {
        $target = $this->webhookTarget($req);
        if (!$target->success) {
            return $target;
        }

        $verifyToken = $this->queryValue("hub_verify_token", $this->queryValue("verify_token", ""));
        $challenge = $this->queryValue("hub_challenge", $this->queryValue("challenge", ""));
        if ($challenge === "" && isset($_GET["hub.challenge"])) {
            $challenge = (string)$_GET["hub.challenge"];
        }

        $assignment = $this->connectorAssignment($target->flow, $target->connectorCode);
        $savedVerifyToken = isset($assignment["settings"]["verifyToken"]) ? (string)$assignment["settings"]["verifyToken"] : "";
        $savedVerifyToken = $savedVerifyToken === "" && isset($assignment["settings"]["webhookSecret"]) ? (string)$assignment["settings"]["webhookSecret"] : $savedVerifyToken;

        if ($challenge !== "") {
            if ($savedVerifyToken !== "" && $verifyToken !== "" && hash_equals($savedVerifyToken, $verifyToken)) {
                header("Content-Type: text/plain");
                echo $challenge;
                exit();
            }
            if ($savedVerifyToken === "" && $verifyToken === "") {
                header("Content-Type: text/plain");
                echo $challenge;
                exit();
            }
            return $this->fail("Webhook verification token does not match this connector.");
        }

        $out = $this->ok();
        $out->flowCode = $target->flow["flowCode"];
        $out->connectorCode = $target->connectorCode;
        $out->webhookUrl = $this->webhookUrl($target->flow["flowCode"], $target->connectorCode);
        $out->message = "Webhook is ready.";
        return $out;
    }

    public function postWebhook($req, $res) {
        $target = $this->webhookTarget($req);
        if (!$target->success) {
            return $target;
        }

        $body = $this->body($req);
        $payload = $this->objectToArray($body);
        $message = $this->messageFromWebhookPayload($target->connectorCode, $payload);
        $sender = $this->senderFromWebhookPayload($target->connectorCode, $payload);
        if ($sender === "") {
            $sender = "unknown-" . substr(hash("sha256", json_encode($payload)), 0, 12);
        }

        $profileCreated = $this->findOrCreateProfile($target->flow, $target->connectorCode, $sender, $payload);
        if (!$profileCreated->success) {
            return $profileCreated;
        }

        $profile = $profileCreated->profile;
        $sessionId = $this->agentSessionId($target->flow["flowCode"], $target->connectorCode, $profile["profileId"]);
        $this->recordConversationEvent($target->flow["flowCode"], $profile["profileId"], array(
            "direction" => "inbound",
            "connectorCode" => $target->connectorCode,
            "sender" => $sender,
            "message" => $message,
            "payload" => $payload,
            "at" => gmdate("c")
        ));

        $agentRun = null;
        $agentStatus = $target->flow["agentCode"] === "" ? "unassigned" : "ready";
        if ($target->flow["agentCode"] !== "" && $message !== "") {
            $agentRun = $this->runAgentForProfile($target->flow, $target->connectorCode, $profile, $sessionId, $message, $payload);
            $agentStatus = $agentRun->success ? "answered" : "failed";
            $this->recordConversationEvent($target->flow["flowCode"], $profile["profileId"], array(
                "direction" => "outbound",
                "connectorCode" => $target->connectorCode,
                "sender" => "ai-agent-creator",
                "message" => $agentRun->success && isset($agentRun->reply) ? $agentRun->reply : (isset($agentRun->message) ? $agentRun->message : ""),
                "sessionId" => $sessionId,
                "agentCode" => $target->flow["agentCode"],
                "skillResults" => $agentRun->success && isset($agentRun->skillResults) ? $agentRun->skillResults : array(),
                "status" => $agentStatus,
                "at" => gmdate("c")
            ));
        }

        $out = $this->ok();
        $out->received = true;
        $out->flowCode = $target->flow["flowCode"];
        $out->connectorCode = $target->connectorCode;
        $out->agentCode = $target->flow["agentCode"];
        $out->profile = $profile;
        $out->profileCreated = $profileCreated->created;
        $out->sessionId = $sessionId;
        $out->agent = $agentRun;
        $out->route = array(
            "input" => $payload,
            "normalized" => array(
                "sender" => $sender,
                "message" => $message,
                "profileId" => $profile["profileId"],
                "sessionId" => $sessionId
            ),
            "steps" => array(
                array("node" => "connector.webhook", "status" => "received", "connector" => $target->connectorCode),
                array("node" => "customer.profile", "status" => $profileCreated->created ? "created" : "found", "profileId" => $profile["profileId"]),
                array("node" => "flow.router", "status" => "matched", "flowCode" => $target->flow["flowCode"]),
                array("node" => "ai-agent-creator", "status" => $agentStatus, "agentCode" => $target->flow["agentCode"])
            )
        );
        return $out;
    }

    private function normalizeFlow($input, $existingFlows, $requireName) {
        if (!is_array($input)) {
            $input = array();
        }

        $name = $this->arrayString($input, "name", "");
        $flowCode = $this->normalizeCode($this->arrayString($input, "flowCode", ""));
        if ($flowCode === "" && $name !== "") {
            $flowCode = $this->normalizeCode($name);
        }

        if ($flowCode === "") {
            return $this->fail("Flow code is required. Use lowercase letters, numbers, hyphens, or underscores.");
        }
        if ($requireName && $name === "") {
            return $this->fail("Flow name is required.");
        }

        $status = strtolower($this->arrayString($input, "status", "draft"));
        if (!in_array($status, array("draft", "active", "paused"))) {
            $status = "draft";
        }

        $agentCode = $this->normalizeCode($this->arrayString($input, "agentCode", ""));
        if ($agentCode !== "") {
            $agents = $this->loadAgents();
            if (!isset($agents[$agentCode])) {
                return $this->fail("Selected agent must exist in ai-agent-creator.");
            }
        }

        $existing = isset($existingFlows[$flowCode]) && is_array($existingFlows[$flowCode]) ? $existingFlows[$flowCode] : array();
        $now = gmdate("c");

        $flow = array(
            "flowCode" => $flowCode,
            "name" => $name === "" ? $flowCode : $name,
            "agentCode" => $agentCode,
            "status" => $status,
            "triggers" => $this->arrayStringList($input, "triggers"),
            "escalationTarget" => $this->arrayString($input, "escalationTarget", ""),
            "notes" => $this->arrayString($input, "notes", ""),
            "policy" => $this->normalizePolicy(isset($input["policy"]) ? $input["policy"] : array(), isset($existing["policy"]) ? $existing["policy"] : array(), $name === "" ? $flowCode : $name),
            "connectors" => $this->normalizeConnectors(isset($input["connectors"]) ? $input["connectors"] : array(), $existing),
            "createdAt" => isset($existing["createdAt"]) ? $existing["createdAt"] : $now,
            "updatedAt" => $now
        );

        $out = $this->ok();
        $out->flow = $flow;
        return $out;
    }

    private function normalizePolicy($incoming, $existing, $flowName) {
        $incoming = is_array($incoming) ? $incoming : $this->objectToArray($incoming);
        $existing = is_array($existing) ? $existing : $this->objectToArray($existing);
        $today = gmdate("Y-m-d");

        return array(
            "organizationName" => $this->arrayStringOrExisting($incoming, $existing, "organizationName", $flowName),
            "contactEmail" => $this->arrayStringOrExisting($incoming, $existing, "contactEmail", ""),
            "effectiveDate" => $this->arrayStringOrExisting($incoming, $existing, "effectiveDate", $today),
            "privacyPolicy" => $this->arrayStringOrExisting($incoming, $existing, "privacyPolicy", ""),
            "termsAndConditions" => $this->arrayStringOrExisting($incoming, $existing, "termsAndConditions", "")
        );
    }

    private function webhookTarget($req) {
        $route = "";
        if (isset($req) && isset($req->Params()->route)) {
            $route = (string)$req->Params()->route;
        }
        $parts = array_values(array_filter(explode("/", trim($route, "/")), "strlen"));

        $flowCode = isset($parts[0]) ? $this->normalizeCode($parts[0]) : "";
        $connectorCode = isset($parts[1]) ? $this->normalizeConnectorCode($parts[1]) : "";

        if ($flowCode === "" || $connectorCode === "") {
            return $this->fail("Webhook route must include flow code and connector code.");
        }

        $flows = $this->loadFlows();
        if (!isset($flows[$flowCode])) {
            return $this->fail("Webhook flow was not found.");
        }

        $connectors = $this->connectorDefinitionsByCode();
        if (!isset($connectors[$connectorCode])) {
            return $this->fail("Webhook connector was not found.");
        }

        $assignment = $this->connectorAssignment($flows[$flowCode], $connectorCode);
        if (!isset($assignment) || empty($assignment["enabled"])) {
            return $this->fail("Webhook connector is disabled for this flow.");
        }

        $out = $this->ok();
        $out->flow = $flows[$flowCode];
        $out->connectorCode = $connectorCode;
        return $out;
    }

    private function normalizeConnectors($incoming, $existingFlow) {
        $incomingByCode = array();
        if (is_array($incoming)) {
            foreach ($incoming as $item) {
                $row = is_array($item) ? $item : $this->objectToArray($item);
                $code = $this->normalizeConnectorCode(isset($row["code"]) ? $row["code"] : "");
                if ($code !== "") {
                    $incomingByCode[$code] = $row;
                }
            }
        }

        $existingByCode = array();
        if (isset($existingFlow["connectors"]) && is_array($existingFlow["connectors"])) {
            foreach ($existingFlow["connectors"] as $item) {
                $row = is_array($item) ? $item : $this->objectToArray($item);
                $code = $this->normalizeConnectorCode(isset($row["code"]) ? $row["code"] : "");
                if ($code !== "") {
                    $existingByCode[$code] = $row;
                }
            }
        }

        $out = array();
        foreach ($this->connectorDefinitions() as $definition) {
            $code = $definition["code"];
            $row = isset($incomingByCode[$code]) ? $incomingByCode[$code] : array();
            $existing = isset($existingByCode[$code]) ? $existingByCode[$code] : array();
            $settings = array();
            $sourceSettings = isset($row["settings"]) && is_array($row["settings"]) ? $row["settings"] : array();
            $existingSettings = isset($existing["settings"]) && is_array($existing["settings"]) ? $existing["settings"] : array();

            foreach ($definition["fields"] as $field) {
                $key = $field["key"];
                $value = isset($sourceSettings[$key]) ? trim(substr((string)$sourceSettings[$key], 0, 4000)) : "";
                if (!empty($field["secret"]) && ($value === "" || $value === "********") && isset($existingSettings[$key])) {
                    $value = $existingSettings[$key];
                }
                $settings[$key] = $value;
            }

            $status = isset($row["status"]) ? strtolower((string)$row["status"]) : (isset($existing["status"]) ? strtolower((string)$existing["status"]) : "draft");
            if (!in_array($status, array("draft", "ready", "paused"))) {
                $status = "draft";
            }

            $out[] = array(
                "code" => $code,
                "enabled" => $this->boolFromArray($row, "enabled", isset($existing["enabled"]) ? $existing["enabled"] : true),
                "status" => $status,
                "settings" => $settings
            );
        }

        return $out;
    }

    private function defaultFlow() {
        $connectors = array();
        foreach ($this->connectorDefinitions() as $definition) {
            $settings = array();
            foreach ($definition["fields"] as $field) {
                $settings[$field["key"]] = "";
            }
            $connectors[] = array(
                "code" => $definition["code"],
                "enabled" => true,
                "status" => "draft",
                "settings" => $settings
            );
        }

        return array(
            "flowCode" => "",
            "name" => "",
            "agentCode" => "",
            "status" => "draft",
            "triggers" => array("new message", "support request"),
            "escalationTarget" => "",
            "notes" => "",
            "policy" => array(
                "organizationName" => "",
                "contactEmail" => "",
                "effectiveDate" => gmdate("Y-m-d"),
                "privacyPolicy" => "",
                "termsAndConditions" => ""
            ),
            "connectors" => $connectors,
            "createdAt" => null,
            "updatedAt" => null
        );
    }

    private function connectorDefinitionsByCode() {
        $out = array();
        foreach ($this->connectorDefinitions() as $connector) {
            $out[$connector["code"]] = $connector;
        }
        return $out;
    }

    private function connectorDefinitions() {
        return array(
            array(
                "code" => "whatsapp",
                "label" => "WhatsApp",
                "category" => "Messaging",
                "deliveryMode" => "WhatsApp Cloud API",
                "aliases" => array("whatsapp", "whats app"),
                "events" => array("messages", "statuses"),
                "fields" => array(
                    array("key" => "phoneNumberId", "label" => "Phone number ID", "type" => "text", "placeholder" => "1234567890"),
                    array("key" => "businessAccountId", "label" => "Business account ID", "type" => "text", "placeholder" => "WABA ID"),
                    array("key" => "accessToken", "label" => "Access token", "type" => "text", "secret" => true, "placeholder" => "Permanent token"),
                    array("key" => "verifyToken", "label" => "Webhook verify token", "type" => "text", "secret" => true, "placeholder" => "Verify token")
                )
            ),
            array(
                "code" => "email",
                "label" => "Email",
                "category" => "Inbox",
                "deliveryMode" => "IMAP/SMTP",
                "aliases" => array("email", "mail"),
                "events" => array("new_email", "reply"),
                "fields" => array(
                    array("key" => "fromAddress", "label" => "From address", "type" => "email", "placeholder" => "support@example.com"),
                    array("key" => "inboundMailbox", "label" => "Inbound mailbox", "type" => "email", "placeholder" => "inbox@example.com"),
                    array("key" => "smtpHost", "label" => "SMTP host", "type" => "text", "placeholder" => "smtp.example.com"),
                    array("key" => "smtpUser", "label" => "SMTP user", "type" => "text", "placeholder" => "smtp user"),
                    array("key" => "smtpPassword", "label" => "SMTP password", "type" => "text", "secret" => true, "placeholder" => "SMTP password")
                )
            ),
            array(
                "code" => "facebook-messenger",
                "label" => "Facebook Messenger",
                "category" => "Messaging",
                "deliveryMode" => "Meta Messenger Platform",
                "aliases" => array("facebook messager", "facebook messenger", "messenger"),
                "events" => array("messages", "postbacks"),
                "fields" => array(
                    array("key" => "pageId", "label" => "Page ID", "type" => "text", "placeholder" => "Page ID"),
                    array("key" => "pageAccessToken", "label" => "Page access token", "type" => "text", "secret" => true, "placeholder" => "Page token"),
                    array("key" => "appSecret", "label" => "App secret", "type" => "text", "secret" => true, "placeholder" => "App secret"),
                    array("key" => "verifyToken", "label" => "Webhook verify token", "type" => "text", "secret" => true, "placeholder" => "Verify token")
                )
            ),
            array(
                "code" => "instagram",
                "label" => "Instagram",
                "category" => "Social",
                "deliveryMode" => "Instagram Messaging API",
                "aliases" => array("instagram", "ig"),
                "events" => array("messages", "comments"),
                "fields" => array(
                    array("key" => "instagramBusinessId", "label" => "Instagram business ID", "type" => "text", "placeholder" => "IG business ID"),
                    array("key" => "accessToken", "label" => "Access token", "type" => "text", "secret" => true, "placeholder" => "Token"),
                    array("key" => "webhookSecret", "label" => "Webhook secret", "type" => "text", "secret" => true, "placeholder" => "Webhook secret"),
                    array("key" => "defaultRecipient", "label" => "Default recipient", "type" => "text", "placeholder" => "Fallback user ID")
                )
            ),
            array(
                "code" => "tiktok",
                "label" => "TikTok",
                "category" => "Social",
                "deliveryMode" => "TikTok event gateway",
                "aliases" => array("tiktok", "tik tok"),
                "events" => array("comment", "lead", "message"),
                "fields" => array(
                    array("key" => "businessAccountId", "label" => "Business account ID", "type" => "text", "placeholder" => "Business account"),
                    array("key" => "clientKey", "label" => "Client key", "type" => "text", "placeholder" => "Client key"),
                    array("key" => "clientSecret", "label" => "Client secret", "type" => "text", "secret" => true, "placeholder" => "Client secret"),
                    array("key" => "accessToken", "label" => "Access token", "type" => "text", "secret" => true, "placeholder" => "Access token"),
                    array("key" => "webhookSecret", "label" => "Webhook secret", "type" => "text", "secret" => true, "placeholder" => "Webhook secret")
                )
            )
        );
    }

    private function connectorAssignment($flow, $connectorCode) {
        if (!isset($flow["connectors"]) || !is_array($flow["connectors"])) {
            return null;
        }
        foreach ($flow["connectors"] as $connector) {
            if (isset($connector["code"]) && $connector["code"] === $connectorCode) {
                return $connector;
            }
        }
        return null;
    }

    private function connectorPayload($connectorCode, $message, $sender) {
        $timestamp = gmdate("c");
        switch ($connectorCode) {
            case "whatsapp":
                return array(
                    "object" => "whatsapp_business_account",
                    "entry" => array(array(
                        "changes" => array(array(
                            "field" => "messages",
                            "value" => array(
                                "messages" => array(array("from" => $sender, "type" => "text", "text" => array("body" => $message))),
                                "metadata" => array("display_phone_number" => "", "phone_number_id" => "")
                            )
                        ))
                    )),
                    "receivedAt" => $timestamp
                );
            case "email":
                return array(
                    "messageId" => "dry-run-" . md5($sender . $message),
                    "from" => $sender,
                    "to" => "",
                    "subject" => "Agent Flow dry run",
                    "text" => $message,
                    "receivedAt" => $timestamp
                );
            case "facebook-messenger":
                return array(
                    "object" => "page",
                    "entry" => array(array(
                        "messaging" => array(array(
                            "sender" => array("id" => $sender),
                            "message" => array("text" => $message)
                        ))
                    )),
                    "receivedAt" => $timestamp
                );
            case "instagram":
                return array(
                    "object" => "instagram",
                    "entry" => array(array(
                        "changes" => array(array(
                            "field" => "messages",
                            "value" => array("from" => $sender, "message" => $message)
                        ))
                    )),
                    "receivedAt" => $timestamp
                );
            case "tiktok":
                return array(
                    "event" => "message.receive",
                    "sender" => $sender,
                    "message" => $message,
                    "receivedAt" => $timestamp
                );
        }

        return array("sender" => $sender, "message" => $message, "receivedAt" => $timestamp);
    }

    private function findOrCreateProfile($flow, $connectorCode, $sender, $payload) {
        $profiles = $this->loadProfiles();
        $key = $this->profileKey($flow["flowCode"], $connectorCode, $sender);
        $now = gmdate("c");
        $created = false;

        if (isset($profiles[$key]) && is_array($profiles[$key])) {
            $profile = $profiles[$key];
        } else {
            $created = true;
            $profile = array(
                "profileId" => "profile-" . substr(hash("sha256", $key), 0, 16),
                "flowCode" => $flow["flowCode"],
                "connectorCode" => $connectorCode,
                "externalId" => $sender,
                "displayName" => $this->profileDisplayName($connectorCode, $payload, $sender),
                "source" => "davvag-agent-flow",
                "messageCount" => 0,
                "channelIdentities" => array(),
                "createdAt" => $now,
                "updatedAt" => $now,
                "lastSeenAt" => $now
            );
        }

        $profile["flowCode"] = $flow["flowCode"];
        $profile["connectorCode"] = $connectorCode;
        $profile["externalId"] = $sender;
        $profile["displayName"] = isset($profile["displayName"]) && $profile["displayName"] !== "" ? $profile["displayName"] : $this->profileDisplayName($connectorCode, $payload, $sender);
        $profile["messageCount"] = isset($profile["messageCount"]) ? ((int)$profile["messageCount"] + 1) : 1;
        $profile["updatedAt"] = $now;
        $profile["lastSeenAt"] = $now;
        $profile["channelIdentities"] = $this->mergeChannelIdentity(isset($profile["channelIdentities"]) ? $profile["channelIdentities"] : array(), $connectorCode, $sender);

        $profiles[$key] = $profile;
        if (!$this->saveProfiles($profiles)) {
            return $this->fail("Unable to save the customer profile.");
        }

        $out = $this->ok();
        $out->profile = $profile;
        $out->created = $created;
        return $out;
    }

    private function runAgentForProfile($flow, $connectorCode, $profile, $sessionId, $message, $payload) {
        $creator = $this->creatorService();
        if (!$creator->success) {
            return $creator;
        }

        $connectors = $this->connectorDefinitionsByCode();
        $assignment = $this->connectorAssignment($flow, $connectorCode);
        $connector = isset($connectors[$connectorCode]) ? $connectors[$connectorCode] : array("code" => $connectorCode);
        $connector["assignmentStatus"] = isset($assignment["status"]) ? $assignment["status"] : "";

        return $creator->service->runAgent(array(
            "agentCode" => $flow["agentCode"],
            "message" => $message,
            "profile" => $profile,
            "sessionId" => $sessionId,
            "flow" => array(
                "flowCode" => $flow["flowCode"],
                "name" => $flow["name"],
                "status" => $flow["status"],
                "triggers" => $flow["triggers"]
            ),
            "connector" => $connector,
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

    private function recordConversationEvent($flowCode, $profileId, $event) {
        $conversations = $this->loadConversations();
        $key = $flowCode . "|" . $profileId;
        $now = gmdate("c");

        if (!isset($conversations[$key]) || !is_array($conversations[$key])) {
            $conversations[$key] = array(
                "flowCode" => $flowCode,
                "profileId" => $profileId,
                "events" => array(),
                "createdAt" => $now,
                "updatedAt" => $now
            );
        }

        $conversations[$key]["events"][] = $event;
        $conversations[$key]["events"] = array_slice($conversations[$key]["events"], -120);
        $conversations[$key]["updatedAt"] = $now;
        return $this->saveConversations($conversations);
    }

    private function profileDisplayName($connectorCode, $payload, $sender) {
        if ($connectorCode === "email" && isset($payload["fromName"])) {
            return trim((string)$payload["fromName"]);
        }
        if (isset($payload["profile"]["name"])) {
            return trim((string)$payload["profile"]["name"]);
        }
        if (isset($payload["sender"]["name"])) {
            return trim((string)$payload["sender"]["name"]);
        }
        return $sender;
    }

    private function mergeChannelIdentity($identities, $connectorCode, $sender) {
        $identities = is_array($identities) ? $identities : array();
        foreach ($identities as $identity) {
            if (isset($identity["connectorCode"], $identity["externalId"]) && $identity["connectorCode"] === $connectorCode && $identity["externalId"] === $sender) {
                return $identities;
            }
        }
        $identities[] = array(
            "connectorCode" => $connectorCode,
            "externalId" => $sender,
            "linkedAt" => gmdate("c")
        );
        return $identities;
    }

    private function profileKey($flowCode, $connectorCode, $sender) {
        return hash("sha256", strtolower($flowCode . "|" . $connectorCode . "|" . $sender));
    }

    private function agentSessionId($flowCode, $connectorCode, $profileId) {
        return $this->normalizeCode($flowCode) . "-" . $this->normalizeConnectorCode($connectorCode) . "-" . substr(hash("sha256", $profileId), 0, 16);
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

    private function loadFlows() {
        $file = $this->flowsFile();
        if (!file_exists($file)) {
            return array();
        }

        $json = json_decode(file_get_contents($file), true);
        if (!is_array($json)) {
            return array();
        }

        return $json;
    }

    private function loadProfiles() {
        $file = $this->profilesFile();
        if (!file_exists($file)) {
            return array();
        }

        $json = json_decode(file_get_contents($file), true);
        return is_array($json) ? $json : array();
    }

    private function loadConversations() {
        $file = $this->conversationsFile();
        if (!file_exists($file)) {
            return array();
        }

        $json = json_decode(file_get_contents($file), true);
        return is_array($json) ? $json : array();
    }

    private function saveFlows($flows) {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return file_put_contents($this->flowsFile(), json_encode($flows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function saveProfiles($profiles) {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return file_put_contents($this->profilesFile(), json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function saveConversations($conversations) {
        $dir = $this->storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        return file_put_contents($this->conversationsFile(), json_encode($conversations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function storageDir() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/data/davvag-agent-flow";
        }
        return dirname(dirname(__DIR__)) . "/data";
    }

    private function agentsFile() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/data/ai-agent-creator/agents.json";
        }
        return dirname(dirname(dirname(__DIR__))) . "/data/ai-agent-creator/agents.json";
    }

    private function flowsFile() {
        return $this->storageDir() . "/flows.json";
    }

    private function profilesFile() {
        return $this->storageDir() . "/profiles.json";
    }

    private function conversationsFile() {
        return $this->storageDir() . "/conversations.json";
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
            $copy["skills"] = $this->maskGenericSecrets($copy["skills"]);
        }
        if (isset($copy["configuration"])) {
            $copy["configuration"] = $this->maskGenericSecrets($copy["configuration"]);
        }
        return $copy;
    }

    private function safeFlowsForClient($flows) {
        $safe = array();
        foreach ($flows as $flow) {
            $safe[] = $this->safeFlowForClient($flow);
        }
        usort($safe, function($a, $b) {
            return strcmp(strtolower($a["name"]), strtolower($b["name"]));
        });
        return $safe;
    }

    private function safeFlowForClient($flow) {
        $copy = $flow;
        $defs = $this->connectorDefinitionsByCode();
        $copy["webhookUrls"] = array();
        $copy["policyUrls"] = array(
            "privacy" => $this->policyUrl(isset($copy["flowCode"]) ? $copy["flowCode"] : "", "privacy"),
            "terms" => $this->policyUrl(isset($copy["flowCode"]) ? $copy["flowCode"] : "", "terms")
        );
        if (isset($copy["connectors"]) && is_array($copy["connectors"])) {
            foreach ($copy["connectors"] as $index => $connector) {
                $code = isset($connector["code"]) ? $connector["code"] : "";
                if (!isset($defs[$code])) {
                    continue;
                }
                if (!isset($copy["connectors"][$index]["settings"]) || !is_array($copy["connectors"][$index]["settings"])) {
                    $copy["connectors"][$index]["settings"] = array();
                }
                $copy["connectors"][$index]["webhookUrl"] = $this->webhookUrl(isset($copy["flowCode"]) ? $copy["flowCode"] : "", $code);
                $copy["webhookUrls"][$code] = $copy["connectors"][$index]["webhookUrl"];
                foreach ($defs[$code]["fields"] as $field) {
                    $key = $field["key"];
                    if (!empty($field["secret"]) && isset($copy["connectors"][$index]["settings"][$key]) && $copy["connectors"][$index]["settings"][$key] !== "") {
                        $copy["connectors"][$index]["settings"][$key] = "********";
                    }
                }
            }
        }
        return $copy;
    }

    private function savePolicyPages($flow) {
        if (!isset($flow["flowCode"]) || $flow["flowCode"] === "") {
            return true;
        }

        $dir = $this->policyDir($flow["flowCode"]);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }

        $privacy = $this->policyHtml($flow, "privacy");
        $terms = $this->policyHtml($flow, "terms");

        return file_put_contents($dir . "/privacy.html", $privacy) !== false
            && file_put_contents($dir . "/terms.html", $terms) !== false;
    }

    private function policyHtml($flow, $type) {
        $policy = isset($flow["policy"]) && is_array($flow["policy"]) ? $flow["policy"] : array();
        $title = $type === "terms" ? "Terms and Conditions" : "Privacy Policy";
        $contentKey = $type === "terms" ? "termsAndConditions" : "privacyPolicy";
        $flowName = isset($flow["name"]) ? $flow["name"] : $flow["flowCode"];
        $organization = isset($policy["organizationName"]) && $policy["organizationName"] !== "" ? $policy["organizationName"] : $flowName;
        $effectiveDate = isset($policy["effectiveDate"]) && $policy["effectiveDate"] !== "" ? $policy["effectiveDate"] : gmdate("Y-m-d");
        $contactEmail = isset($policy["contactEmail"]) ? $policy["contactEmail"] : "";
        $content = isset($policy[$contentKey]) ? $policy[$contentKey] : "";

        if (trim($content) === "") {
            $content = "This " . strtolower($title) . " has not been completed yet. Please contact the service owner for more information.";
        }

        $contact = "";
        if ($contactEmail !== "") {
            $safeEmail = $this->escapeHtml($contactEmail);
            $contact = '<p class="policy-page__contact">Contact: <a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a></p>';
        }

        return '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n"
            . '<title>' . $this->escapeHtml($title . " - " . $organization) . '</title>' . "\n"
            . '<style>'
            . 'body{margin:0;background:#f4f6f8;color:#17212b;font-family:Arial,sans-serif;line-height:1.6;}'
            . '.policy-page{max-width:880px;margin:0 auto;padding:42px 22px 64px;}'
            . '.policy-page__paper{background:#fff;border:1px solid #d9e1e8;border-radius:8px;padding:30px;box-shadow:0 12px 28px rgba(30,42,54,.08);}'
            . 'h1{margin:0 0 8px;font-size:30px;line-height:1.15;}'
            . 'p{margin:0 0 16px;}'
            . '.policy-page__meta{color:#5f6f82;font-size:14px;margin-bottom:28px;}'
            . '.policy-page__content{white-space:normal;}'
            . '.policy-page__content p{margin-bottom:16px;}'
            . '.policy-page__contact{margin-top:28px;padding-top:18px;border-top:1px solid #d9e1e8;}'
            . 'a{color:#153f3f;}'
            . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body><main class="policy-page"><article class="policy-page__paper">'
            . '<h1>' . $this->escapeHtml($title) . '</h1>'
            . '<p class="policy-page__meta">' . $this->escapeHtml($organization) . ' / Effective ' . $this->escapeHtml($effectiveDate) . '</p>'
            . '<div class="policy-page__content">' . $this->textToParagraphs($content) . '</div>'
            . $contact
            . '</article></main></body></html>';
    }

    private function policyDir($flowCode) {
        return $this->appAssetsDir() . "/policies/" . $this->normalizeCode($flowCode);
    }

    private function appAssetsDir() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/") . "/apps/davvag-agent-flow/assets";
        }
        return dirname(dirname(__DIR__)) . "/assets";
    }

    private function policyUrl($flowCode, $type) {
        $flowCode = $this->normalizeCode($flowCode);
        if ($flowCode === "") {
            return "";
        }
        $file = $type === "terms" ? "terms.html" : "privacy.html";
        return rtrim($this->publicBaseUrl(), "/") . "/assets/davvag-agent-flow/policies/" . rawurlencode($flowCode) . "/" . $file;
    }

    private function webhookUrl($flowCode, $connectorCode) {
        $flowCode = $this->normalizeCode($flowCode);
        $connectorCode = $this->normalizeConnectorCode($connectorCode);
        if ($flowCode === "" || $connectorCode === "") {
            return "";
        }
        return rtrim($this->publicBaseUrl(), "/") . "/components/davvag-agent-flow/flow-api/service/Webhook/" . rawurlencode($flowCode) . "/" . rawurlencode($connectorCode);
    }

    private function publicBaseUrl() {
        $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "";
        $scheme = "http";
        if ((isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "" && strtolower($_SERVER["HTTPS"]) !== "off") || (isset($_SERVER["SERVER_PORT"]) && (string)$_SERVER["SERVER_PORT"] === "443")) {
            $scheme = "https";
        }

        $script = isset($_SERVER["SCRIPT_NAME"]) ? str_replace("\\", "/", $_SERVER["SCRIPT_NAME"]) : "";
        $basePath = "";
        $marker = "/components/";
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            $basePath = substr($script, 0, $pos);
        } elseif ($script !== "") {
            $basePath = rtrim(dirname($script), "/");
        }

        if ($host === "") {
            return $basePath === "" ? "" : $basePath;
        }
        return $scheme . "://" . $host . $basePath;
    }

    private function messageFromWebhookPayload($connectorCode, $payload) {
        if ($connectorCode === "whatsapp" && isset($payload["entry"][0]["changes"][0]["value"]["messages"][0]["text"]["body"])) {
            return (string)$payload["entry"][0]["changes"][0]["value"]["messages"][0]["text"]["body"];
        }
        if ($connectorCode === "facebook-messenger" && isset($payload["entry"][0]["messaging"][0]["message"]["text"])) {
            return (string)$payload["entry"][0]["messaging"][0]["message"]["text"];
        }
        if ($connectorCode === "instagram" && isset($payload["entry"][0]["changes"][0]["value"]["message"])) {
            return (string)$payload["entry"][0]["changes"][0]["value"]["message"];
        }
        if ($connectorCode === "tiktok" && isset($payload["message"])) {
            return (string)$payload["message"];
        }
        if ($connectorCode === "email" && isset($payload["text"])) {
            return (string)$payload["text"];
        }
        if (isset($payload["message"])) {
            return is_string($payload["message"]) ? $payload["message"] : json_encode($payload["message"]);
        }
        if (isset($payload["text"])) {
            return (string)$payload["text"];
        }
        return "";
    }

    private function senderFromWebhookPayload($connectorCode, $payload) {
        if ($connectorCode === "whatsapp" && isset($payload["entry"][0]["changes"][0]["value"]["messages"][0]["from"])) {
            return (string)$payload["entry"][0]["changes"][0]["value"]["messages"][0]["from"];
        }
        if ($connectorCode === "facebook-messenger" && isset($payload["entry"][0]["messaging"][0]["sender"]["id"])) {
            return (string)$payload["entry"][0]["messaging"][0]["sender"]["id"];
        }
        if ($connectorCode === "instagram" && isset($payload["entry"][0]["changes"][0]["value"]["from"])) {
            return (string)$payload["entry"][0]["changes"][0]["value"]["from"];
        }
        if ($connectorCode === "tiktok" && isset($payload["sender"])) {
            return (string)$payload["sender"];
        }
        if ($connectorCode === "email" && isset($payload["from"])) {
            return (string)$payload["from"];
        }
        if (isset($payload["sender"])) {
            return is_string($payload["sender"]) ? $payload["sender"] : json_encode($payload["sender"]);
        }
        if (isset($payload["from"])) {
            return (string)$payload["from"];
        }
        return "";
    }

    private function maskGenericSecrets($value) {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $keyName = strtolower((string)$key);
                if (in_array($keyName, array("apikey", "api_key", "key", "token", "secret", "password", "accesstoken", "authorization", "authheader", "clientsecret"))
                    || strpos($keyName, "token") !== false
                    || strpos($keyName, "secret") !== false
                    || strpos($keyName, "password") !== false
                    || strpos($keyName, "authorization") !== false
                    || strpos($keyName, "api-key") !== false) {
                    $out[$key] = $item === "" ? "" : "********";
                } else {
                    $out[$key] = $this->maskGenericSecrets($item);
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

    private function stringValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return trim(substr((string)$body->$key, 0, 20000));
    }

    private function queryValue($key, $default) {
        if (isset($_GET[$key])) {
            return trim(substr((string)$_GET[$key], 0, 20000));
        }
        return $default;
    }

    private function arrayStringOrExisting($input, $existing, $key, $default) {
        if (isset($input[$key])) {
            return trim(substr((string)$input[$key], 0, 50000));
        }
        if (isset($existing[$key])) {
            return trim(substr((string)$existing[$key], 0, 50000));
        }
        return $default;
    }

    private function arrayString($input, $key, $default) {
        if (!isset($input[$key])) {
            return $default;
        }
        return trim(substr((string)$input[$key], 0, 20000));
    }

    private function arrayStringList($input, $key) {
        if (!isset($input[$key])) {
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

    private function boolFromArray($input, $key, $default) {
        if (!isset($input[$key])) {
            return (bool)$default;
        }
        if (is_bool($input[$key])) {
            return $input[$key];
        }
        return filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function normalizeCode($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        if ($value === "" || preg_match("/^[a-z][a-z0-9_-]{1,80}$/", $value) !== 1) {
            return "";
        }
        return $value;
    }

    private function normalizeConnectorCode($value) {
        $value = strtolower(trim((string)$value));
        $value = str_replace("_", "-", $value);
        return preg_replace("/[^a-z0-9-]+/", "-", $value);
    }

    private function escapeHtml($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
    }

    private function textToParagraphs($value) {
        $blocks = preg_split("/\r?\n\r?\n+/", trim((string)$value));
        $html = array();
        foreach ($blocks as $block) {
            $text = trim($block);
            if ($text !== "") {
                $html[] = "<p>" . nl2br($this->escapeHtml($text)) . "</p>";
            }
        }
        return implode("", $html);
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
