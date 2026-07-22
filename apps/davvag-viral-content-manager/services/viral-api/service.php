<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
if (file_exists(PLUGIN_PATH . "/auth/auth.php")) {
    require_once(PLUGIN_PATH . "/auth/auth.php");
}

class ViralContentManagerService {
    private $socialAccountNamespace = "social_accounts";
    private $contentNamespace = "content_items";
    private $analysisNamespace = "content_analysis";
    private $seoNamespace = "seo_suggestions";
    private $competitorNamespace = "competitor_results";
    private $transcriptNamespace = "transcripts";
    private $clipNamespace = "short_clip_suggestions";
    private $queueNamespace = "publishing_queue";
    private $metricsNamespace = "platform_metrics";
    private $agentLogNamespace = "ai_agent_logs";
    private $agentMappingNamespace = "viral_agent_mappings";

    public function postAgentCatalog($req, $res) {
        return $this->agentCatalogResponse();
    }

    public function postSaveAgentMappings($req, $res) {
        $body = $this->body($req);
        $incoming = isset($body->mappings) ? $body->mappings : new stdClass();
        $tasks = $this->agentTasks();
        $saved = $this->savedAgentsByCode();
        $savedRows = array();

        foreach ($tasks as $task) {
            $taskCode = $task["code"];
            $selected = "";
            if (is_object($incoming) && isset($incoming->{$taskCode})) {
                $selected = $this->limitText($incoming->{$taskCode}, 140);
            } elseif (is_array($incoming) && isset($incoming[$taskCode])) {
                $selected = $this->limitText($incoming[$taskCode], 140);
            }

            if ($selected !== "" && !isset($saved[$selected])) {
                $res->SetError("Selected agent was not found in AI Agent Creator: " . $selected);
                return null;
            }

            $existing = SOSSData::Query($this->agentMappingNamespace, "taskCode:" . $this->querySafe($taskCode), null, "desc", 1, 0);
            $row = new stdClass();
            if ($existing->success && count($existing->result) > 0 && isset($existing->result[0]->mappingId)) {
                $row->mappingId = $existing->result[0]->mappingId;
            } else {
                $row->createdAt = $this->now();
            }
            $row->taskCode = $taskCode;
            $row->taskName = $task["name"];
            $row->expectedAgentCode = $task["expectedAgentCode"];
            $row->selectedAgentCode = $selected;
            $row->suggestedPrompt = $task["suggestedPrompt"];
            $row->status = $selected === "" ? "Not Mapped" : "Mapped";
            $row->updatedAt = $this->now();

            $savedRows[] = isset($row->mappingId)
                ? SOSSData::Update($this->agentMappingNamespace, $row)
                : SOSSData::Insert($this->agentMappingNamespace, $row);
        }

        $out = $this->agentCatalogResponse();
        $out->saved = $savedRows;
        return $out;
    }

    public function postListHistory($req, $res) {
        $body = $this->body($req);
        $size = isset($body->size) ? min(200, max(1, intval($body->size))) : 60;
        $result = SOSSData::Query($this->contentNamespace, "", null, "desc", $size, 0);
        return $result->success ? $result->result : array();
    }

    public function postListAccounts($req, $res) {
        $result = SOSSData::Query($this->socialAccountNamespace, "", null, "asc", 200, 0);
        if (!$result->success) {
            $res->SetError(isset($result->message) && $result->message !== "" ? $result->message : "Account list failed.");
            return array();
        }
        return $this->safeAccountsForClient($result->result);
    }

    public function postFetchUrlDetails($req, $res) {
        $body = $this->body($req);
        $url = $this->normalizeUrl($this->text($body, "url", 1000));
        if (!$this->isSupportedUrl($url)) {
            $res->SetError("A YouTube, TikTok, Facebook, or Instagram URL is required.");
            return null;
        }

        $platform = $this->platformFromUrl($url, $this->text($body, "platform", 80));
        $details = $this->fetchPlatformDetails($platform, $url, $body);
        $details->url = $url;
        $details->platform = $platform;
        $details->language = $details->language !== "" ? $details->language : $this->detectLanguage(trim($details->title . " " . $details->description . " " . $details->transcript));
        return $details;
    }

    public function postSaveAccount($req, $res) {
        $body = $this->body($req);
        $accountName = $this->text($body, "accountName", 160);
        if ($accountName === "") {
            $res->SetError("Account name is required.");
            return null;
        }

        $account = new stdClass();
        $existingRaw = new stdClass();
        if (isset($body->socialAccountId) && intval($body->socialAccountId) > 0) {
            $account->socialAccountId = intval($body->socialAccountId);
            $existing = SOSSData::Query($this->socialAccountNamespace, "socialAccountId:" . $account->socialAccountId, null, "desc", 1, 0);
            if ($existing->success && count($existing->result) > 0 && isset($existing->result[0]->raw)) {
                $existingRaw = $this->normalizeStoredObject($existing->result[0]->raw);
            }
        }
        $account->platform = $this->text($body, "platform", 60, "YouTube");
        $account->accountName = $accountName;
        $account->accountHandle = $this->text($body, "accountHandle", 160);
        $account->accountId = $this->text($body, "accountId", 160);
        $account->connectionType = $this->text($body, "connectionType", 60, "Manual");
        $account->connectionStatus = $this->text($body, "connectionStatus", 60, "Manual");
        if (isset($body->scopesText) && trim((string)$body->scopesText) !== "") {
            $account->scopes = $this->scopeList($body->scopesText);
        } else {
            $account->scopes = isset($body->scopes) ? $body->scopes : array();
        }
        $account->notes = $this->text($body, "notes", 2000);
        $account->raw = $existingRaw;
        if (isset($body->raw)) {
            $incomingRaw = $this->normalizeStoredObject($body->raw);
            foreach ($incomingRaw as $key => $value) {
                $account->raw->{$key} = $value;
            }
        }
        $this->copySecretField($body, $account->raw, "apiKey");
        $this->copySecretField($body, $account->raw, "accessToken");
        $this->copySecretField($body, $account->raw, "pageId");
        $this->copySecretField($body, $account->raw, "clientId");
        $this->copySecretField($body, $account->raw, "clientSecret");
        $this->copySecretField($body, $account->raw, "redirectUri");
        $this->copySecretField($body, $account->raw, "scopesText");
        $this->copySecretField($body, $account->raw, "appId");
        $this->copySecretField($body, $account->raw, "appSecret");
        $account->updatedAt = $this->now();
        if (!isset($account->socialAccountId)) {
            $account->createdAt = $account->updatedAt;
        }

        $result = isset($account->socialAccountId)
            ? SOSSData::Update($this->socialAccountNamespace, $account)
            : SOSSData::Insert($this->socialAccountNamespace, $account);

        if (!$result->success) {
            $res->SetError(isset($result->message) && $result->message !== "" ? $result->message : "Account save failed.");
            return null;
        }

        if (!isset($account->socialAccountId) && isset($result->result->generatedId)) {
            $account->socialAccountId = $result->result->generatedId;
        }
        return $this->safeAccountForClient($account);
    }

    public function postDeleteAccount($req, $res) {
        $body = $this->body($req);
        $socialAccountId = isset($body->socialAccountId) ? intval($body->socialAccountId) : 0;
        if ($socialAccountId < 1) {
            $res->SetError("A valid connected account ID is required.");
            return null;
        }

        $existing = SOSSData::Query(
            $this->socialAccountNamespace,
            "socialAccountId:" . $socialAccountId,
            null,
            "desc",
            1,
            0
        );
        if (!$existing->success) {
            $res->SetError(isset($existing->message) && $existing->message !== "" ? $existing->message : "Account lookup failed.");
            return null;
        }
        if (count($existing->result) < 1) {
            $res->SetError("Connected account was not found.");
            return null;
        }

        $result = SOSSData::Delete($this->socialAccountNamespace, $existing->result[0]);
        if (!$result->success) {
            $res->SetError(isset($result->message) && $result->message !== "" ? $result->message : "Account delete failed.");
            return null;
        }

        $out = new stdClass();
        $out->socialAccountId = $socialAccountId;
        return $out;
    }

    public function postStartOAuth($req, $res) {
        $body = $this->body($req);
        $platform = $this->text($body, "platform", 80, "YouTube");
        $provider = $this->oauthProvider($platform);
        if ($provider === null) {
            $res->SetError("OAuth is not configured for this platform.");
            return null;
        }

        $storedRaw = $this->existingAccountRaw($body);
        $clientId = $this->text($body, "clientId", 400);
        $clientSecret = $this->text($body, "clientSecret", 800);
        if ($clientId === "") {
            $clientId = $this->text($body, "appId", 400);
        }
        if ($clientSecret === "") {
            $clientSecret = $this->text($body, "appSecret", 800);
        }
        if ($clientId === "") {
            $clientId = $this->secretText($storedRaw, "clientId", 400);
        }
        if ($clientId === "") {
            $clientId = $this->secretText($storedRaw, "appId", 400);
        }
        if ($clientSecret === "") {
            $clientSecret = $this->secretText($storedRaw, "clientSecret", 800);
        }
        if ($clientSecret === "") {
            $clientSecret = $this->secretText($storedRaw, "appSecret", 800);
        }
        if ($clientId === "") {
            $res->SetError("Client/App ID is required for OAuth.");
            return null;
        }
        if ($clientSecret === "") {
            $res->SetError("Client Secret is required for server-side OAuth token exchange.");
            return null;
        }

        $redirectUri = $this->text($body, "redirectUri", 1000);
        if ($redirectUri === "") {
            $redirectUri = $this->secretText($storedRaw, "redirectUri", 1000);
        }
        if ($redirectUri === "") {
            $redirectUri = $this->oauthRedirectUri();
        }

        $scopesText = $this->text($body, "scopesText", 1200);
        if ($scopesText === "") {
            $scopesText = $this->text($storedRaw, "scopesText", 1200);
        }
        $scopes = $scopesText !== "" ? $this->scopeList($scopesText) : $provider["scopes"];
        $state = $this->uid("oauth");

        $stateData = array(
            "state" => $state,
            "provider" => $provider["code"],
            "platform" => $platform,
            "clientId" => $clientId,
            "clientSecret" => $clientSecret,
            "redirectUri" => $redirectUri,
            "scopes" => $scopes,
            "accountName" => $this->text($body, "accountName", 180, $platform . " Account"),
            "accountHandle" => $this->text($body, "accountHandle", 180),
            "pageId" => $this->text($body, "pageId", 180, $this->secretText($storedRaw, "pageId", 180)),
            "notes" => $this->text($body, "notes", 2000),
            "createdAt" => time()
        );

        if (!$this->saveOAuthState($state, $stateData)) {
            $res->SetError("Unable to create OAuth state. Check tenant data folder permissions.");
            return null;
        }

        $authUrl = $this->oauthAuthorizeUrl($provider, $stateData);
        $out = new stdClass();
        $out->authUrl = $authUrl;
        $out->state = $state;
        $out->redirectUri = $redirectUri;
        $out->scopes = $scopes;
        return $out;
    }

    public function getOAuthCallback($req, $res) {
        $query = $req->Query();
        $state = isset($query->state) ? trim((string)$query->state) : "";
        $code = isset($query->code) ? trim((string)$query->code) : "";
        $error = isset($query->error) ? trim((string)$query->error) : "";

        if ($error !== "") {
            $this->oauthCallbackHtml(false, "OAuth was cancelled or failed: " . htmlspecialchars($error, ENT_QUOTES, "UTF-8"));
        }
        if ($state === "" || $code === "") {
            $this->oauthCallbackHtml(false, "OAuth callback is missing state or code.");
        }

        $stateData = $this->loadOAuthState($state);
        if (!is_array($stateData)) {
            $this->oauthCallbackHtml(false, "OAuth state expired or was not found.");
        }

        $provider = $this->oauthProviderByCode($stateData["provider"]);
        if ($provider === null) {
            $this->oauthCallbackHtml(false, "OAuth provider is not supported.");
        }

        $token = $this->oauthExchangeCode($provider, $stateData, $code);
        if (!$token->success) {
            $this->oauthCallbackHtml(false, "Token exchange failed: " . htmlspecialchars($token->error, ENT_QUOTES, "UTF-8"));
        }

        $account = $this->accountFromOAuthToken($provider, $stateData, $token->data);
        $result = SOSSData::Insert($this->socialAccountNamespace, $account);
        $this->deleteOAuthState($state);

        if (!$result->success) {
            $this->oauthCallbackHtml(false, "OAuth completed, but the account could not be saved.");
        }

        $this->oauthCallbackHtml(true, "OAuth connected. You can close this tab and refresh Connected Accounts.");
    }

    public function postAnalyzeUrl($req, $res) {
        $body = $this->body($req);
        $url = $this->normalizeUrl($this->text($body, "url", 1000));
        if (!$this->isSupportedUrl($url)) {
            $res->SetError("A YouTube, TikTok, Facebook, or Instagram URL is required.");
            return null;
        }

        $platform = $this->platformFromUrl($url, $this->text($body, "platform", 80));
        $metadata = $this->fetchPublicMetadata($platform, $url);
        $title = $this->text($body, "title", 300, isset($metadata["title"]) ? $metadata["title"] : "");
        if ($title === "") {
            $title = $platform . " content analysis";
        }

        $description = $this->text($body, "description", 8000);
        $transcript = $this->text($body, "transcript", 50000);
        $audience = $this->text($body, "audience", 300, "target creators");
        $language = $this->text($body, "language", 80, "English");
        $combined = trim($title . " " . $description . " " . $transcript);
        $topic = $this->topicFromText($combined, $platform);
        $keywords = $this->keywordsFromText($combined, $topic);
        $hashtags = $this->hashtagsFromKeywords($keywords, $platform);
        $seoBefore = $this->scoreSeo($title, $description, $keywords, $hashtags, $transcript);
        $titleOptions = $this->titleOptions($title, $topic, $keywords);
        $improvedDescription = $this->improvedDescription($titleOptions[0], $topic, $keywords, $hashtags, $audience);
        $thumbnailPrompts = $this->thumbnailPrompts($topic, $platform, $audience);
        $seoAfter = $this->scoreSeo($titleOptions[0], $improvedDescription, $keywords, $hashtags, $transcript) + 8;
        $seoAfter = min(100, max($seoBefore, $seoAfter));
        $contentUid = $this->createContentItem("existing_url", $platform, $url, $title, $description, $topic, $audience, $language, array(
            "metadata" => $metadata,
            "source" => "AnalyzeUrl"
        ));

        if ($transcript !== "") {
            $this->saveTranscript($contentUid, $platform, $language, "manual", $transcript);
        }

        $clips = $this->buildClips($transcript, $topic, $hashtags, $platform);
        $competitors = $this->competitorResearchSeeds($platform, $keywords, $topic, $contentUid);

        $agentNotes = $this->maybeRunAgent(
            $body,
            $this->agentCodeForTask($body, "content-url-analyzer-agent"),
            "Analyze this creator content using the supplied URL, platform, title, description, transcript, keywords, and audience context. Return specific, ready-to-use optimization recommendations: improved title angles, description improvements, keyword and hashtag strategy, thumbnail direction, CTA, pinned comment, audience fit, and the three highest-priority changes. Do not invent facts or claim live access beyond the supplied payload.",
            $contentUid,
            array(
                "url" => $url,
                "platform" => $platform,
                "title" => $title,
                "description" => $description,
                "transcript" => $this->limitText($transcript, 12000),
                "audience" => $audience,
                "language" => $language,
                "keywords" => $keywords,
                "metadata" => $metadata
            )
        );

        $this->saveAnalysis($contentUid, "url_analysis", $platform, $topic, $audience, $seoBefore, $seoAfter, $keywords, $hashtags, array(
            "titleLength" => strlen($title),
            "descriptionLength" => strlen($description),
            "transcriptWords" => $this->wordCount($transcript),
            "metadata" => $metadata
        ), $agentNotes);

        $this->saveSeoSuggestion($contentUid, "existing_content", $titleOptions, $improvedDescription, $keywords, $hashtags, $thumbnailPrompts, $this->cta($platform, $topic), $this->pinnedComment($topic, $hashtags), $seoBefore, $seoAfter);
        $this->saveCompetitors($competitors);
        $this->saveClips($contentUid, $platform, $clips);

        $out = new stdClass();
        $out->contentUid = $contentUid;
        $out->platform = $platform;
        $out->url = $url;
        $out->title = $title;
        $out->topic = $topic;
        $out->audience = $audience;
        $out->keywords = $keywords;
        $out->hashtags = $hashtags;
        $out->titleOptions = $titleOptions;
        $out->improvedDescription = $improvedDescription;
        $out->thumbnailPrompts = $thumbnailPrompts;
        $out->cta = $this->cta($platform, $topic);
        $out->pinnedComment = $this->pinnedComment($topic, $hashtags);
        $out->seoBefore = $seoBefore;
        $out->seoAfter = $seoAfter;
        $out->competitors = $competitors;
        $out->shortClips = $clips;
        $out->agentNotes = $agentNotes;
        return $out;
    }

    public function postGeneratePost($req, $res) {
        $body = $this->body($req);
        $idea = $this->text($body, "idea", 12000);
        if ($idea === "") {
            $res->SetError("Idea is required.");
            return null;
        }

        $platform = $this->text($body, "platform", 80, "YouTube");
        $audience = $this->text($body, "audience", 300, "target viewers");
        $language = $this->text($body, "language", 80, "English");
        $topic = $this->topicFromText($idea, $platform);
        $keywords = $this->keywordsFromText($idea, $topic);
        $hashtags = $this->hashtagsFromKeywords($keywords, $platform);
        $titleOptions = $this->titleOptions($idea, $topic, $keywords);
        $contentUid = $this->createContentItem("new_post_idea", $platform, "", $titleOptions[0], $idea, $topic, $audience, $language, array(
            "source" => "GeneratePost"
        ));

        $agentNotes = $this->maybeRunAgent(
            $body,
            $this->agentCodeForTask($body, "publishing-assistant-agent"),
            "Create platform-ready titles, captions, descriptions, hashtags, hooks, and CTAs for this content idea.",
            $contentUid,
            array(
                "idea" => $idea,
                "platform" => $platform,
                "audience" => $audience,
                "language" => $language,
                "keywords" => $keywords
            )
        );

        $youtubeTitle = $titleOptions[0];
        $description = $this->improvedDescription($youtubeTitle, $topic, $keywords, $hashtags, $audience);
        $thumbnailPrompt = $this->thumbnailPrompts($topic, $platform, $audience);

        $out = new stdClass();
        $out->contentUid = $contentUid;
        $out->platform = $platform;
        $out->idea = $idea;
        $out->topic = $topic;
        $out->youtubeTitle = $youtubeTitle;
        $out->tiktokCaption = $this->captionForPlatform("TikTok", $topic, $hashtags);
        $out->facebookPost = $this->facebookPost($topic, $idea, $hashtags);
        $out->description = $description;
        $out->keywords = $keywords;
        $out->hashtags = $hashtags;
        $out->thumbnailPrompt = $thumbnailPrompt[0];
        $out->hooks = $this->hooks($topic, $idea);
        $out->cta = $this->cta($platform, $topic);
        $out->bestPlatformFormat = $this->bestPlatformFormat($platform, $idea);
        $out->shortFormVersion = $this->shortFormVersion($topic, $idea);
        $out->longFormVersion = $this->longFormVersion($topic, $idea);
        $out->agentNotes = $agentNotes;

        $seoScore = $this->scoreSeo($youtubeTitle, $description, $keywords, $hashtags, $idea);
        $this->saveAnalysis($contentUid, "new_post", $platform, $topic, $audience, 0, $seoScore, $keywords, $hashtags, array(
            "bestPlatformFormat" => $out->bestPlatformFormat
        ), $agentNotes);
        $this->saveSeoSuggestion($contentUid, "new_post", $titleOptions, $description, $keywords, $hashtags, $thumbnailPrompt, $out->cta, $this->pinnedComment($topic, $hashtags), 0, $seoScore);

        return $out;
    }

    public function postFindShorts($req, $res) {
        $body = $this->body($req);
        $url = $this->normalizeUrl($this->text($body, "url", 1000));
        $transcript = $this->text($body, "transcript", 50000);
        if (!$this->isSupportedUrl($url)) {
            $res->SetError("A supported long-form video URL is required.");
            return null;
        }
        if ($transcript === "") {
            $res->SetError("Transcript is required for timestamp suggestions.");
            return null;
        }

        $platform = $this->text($body, "platform", 80, "TikTok");
        $language = $this->text($body, "language", 80, "English");
        $sourcePlatform = $this->platformFromUrl($url, "");
        $topic = $this->topicFromText($transcript, $sourcePlatform);
        $keywords = $this->keywordsFromText($transcript, $topic);
        $hashtags = $this->hashtagsFromKeywords($keywords, $platform);
        $contentUid = $this->createContentItem("shorts_from_long_video", $sourcePlatform, $url, $topic, "", $topic, "", $language, array(
            "targetPlatform" => $platform,
            "source" => "FindShorts"
        ));

        $this->saveTranscript($contentUid, $sourcePlatform, $language, "manual", $transcript);
        $clips = $this->buildClips($transcript, $topic, $hashtags, $platform);
        $this->saveClips($contentUid, $platform, $clips);

        $agentNotes = $this->maybeRunAgent(
            $body,
            $this->agentCodeForTask($body, "short-clip-finder-agent"),
            "Find the strongest short-form moments in this transcript and explain why each clip can work.",
            $contentUid,
            array(
                "url" => $url,
                "sourcePlatform" => $sourcePlatform,
                "targetPlatform" => $platform,
                "transcript" => $this->limitText($transcript, 16000),
                "clips" => $clips
            )
        );

        $this->saveAnalysis($contentUid, "short_clip_finder", $platform, $topic, "", 0, 0, $keywords, $hashtags, array(
            "clipCount" => count($clips),
            "wordCount" => $this->wordCount($transcript)
        ), $agentNotes);

        $out = new stdClass();
        $out->contentUid = $contentUid;
        $out->sourcePlatform = $sourcePlatform;
        $out->platform = $platform;
        $out->topic = $topic;
        $out->keywords = $keywords;
        $out->hashtags = $hashtags;
        $out->clips = $clips;
        $out->agentNotes = $agentNotes;
        return $out;
    }

    public function postQueuePublish($req, $res) {
        $body = $this->body($req);
        $queue = new stdClass();
        $queue->contentUid = $this->text($body, "contentUid", 80);
        $queue->platform = $this->text($body, "platform", 80, "Manual Copy");
        $queue->queueType = $this->text($body, "queueType", 80, "Manual Copy");
        $queue->status = $this->text($body, "status", 60, "Queued");
        $scheduledAt = $this->text($body, "scheduledAt", 80);
        if ($scheduledAt !== "") {
            $queue->scheduledAt = $scheduledAt;
        }
        $queue->payload = isset($body->payload) ? $body->payload : new stdClass();
        $queue->notes = $this->text($body, "notes", 2000);
        $queue->createdAt = $this->now();
        $queue->updatedAt = $queue->createdAt;

        $result = SOSSData::Insert($this->queueNamespace, $queue);
        return $result->success ? $result->result : $queue;
    }

    public function postMetricsOverview($req, $res) {
        $body = $this->body($req);
        $query = "";
        $contentUid = $this->text($body, "contentUid", 80);
        if ($contentUid !== "") {
            $query = "contentUid:" . $this->querySafe($contentUid);
        }

        $result = SOSSData::Query($this->metricsNamespace, $query, null, "desc", 200, 0);
        $rows = $result->success ? $result->result : array();
        $out = new stdClass();
        $out->items = $rows;
        $out->views = 0;
        $out->likes = 0;
        $out->comments = 0;
        $out->shares = 0;
        foreach ($rows as $row) {
            $out->views += isset($row->views) ? intval($row->views) : 0;
            $out->likes += isset($row->likes) ? intval($row->likes) : 0;
            $out->comments += isset($row->comments) ? intval($row->comments) : 0;
            $out->shares += isset($row->shares) ? intval($row->shares) : 0;
        }
        return $out;
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new stdClass();
    }

    private function text($obj, $field, $maxLen, $default = "") {
        if (!is_object($obj) || !isset($obj->{$field})) {
            return $default;
        }
        $value = trim((string)$obj->{$field});
        if ($value === "") {
            return $default;
        }
        return $this->limitText($value, $maxLen);
    }

    private function boolValue($obj, $field) {
        if (!is_object($obj) || !isset($obj->{$field})) {
            return false;
        }
        $value = $obj->{$field};
        return $value === true || $value === 1 || $value === "1" || strtolower((string)$value) === "true";
    }

    private function fetchPlatformDetails($platform, $url, $body) {
        $details = $this->emptyUrlDetails();
        if ($platform === "YouTube") {
            return $this->fetchYouTubeDetails($url, $body);
        }
        if ($platform === "TikTok") {
            return $this->fetchTikTokDetails($url, $body);
        }
        if ($platform === "Facebook" || $platform === "Facebook Pages" || $platform === "Facebook Reels") {
            return $this->fetchFacebookDetails($url, $body);
        }
        if ($platform === "Instagram Reels") {
            $details->messages[] = "Instagram Reels metadata requires a Meta Graph API token and media ID. Paste transcript manually if it is not available from the connected account.";
            return $details;
        }

        $details->messages[] = "No platform fetcher is configured for this URL.";
        return $details;
    }

    private function emptyUrlDetails() {
        $details = new stdClass();
        $details->title = "";
        $details->description = "";
        $details->transcript = "";
        $details->language = "";
        $details->source = "";
        $details->messages = array();
        $details->raw = new stdClass();
        return $details;
    }

    private function fetchYouTubeDetails($url, $body) {
        $details = $this->emptyUrlDetails();
        $details->source = "YouTube";
        $videoId = $this->externalIdFromUrl($url);
        if ($videoId === "") {
            $details->messages[] = "Could not detect the YouTube video ID.";
            return $details;
        }

        $credentials = $this->platformCredentials("YouTube", $body);
        $apiKey = $this->credentialValue($credentials, array("apiKey", "youtubeApiKey"));
        $accessToken = $this->credentialValue($credentials, array("accessToken", "youtubeAccessToken", "oauthToken"));

        if ($apiKey !== "") {
            $apiUrl = "https://www.googleapis.com/youtube/v3/videos?part=snippet&id=" . rawurlencode($videoId) . "&key=" . rawurlencode($apiKey);
            $response = $this->curlJson($apiUrl, array());
            if ($response->success && isset($response->data["items"][0]["snippet"])) {
                $snippet = $response->data["items"][0]["snippet"];
                $details->title = isset($snippet["title"]) ? $snippet["title"] : "";
                $details->description = isset($snippet["description"]) ? $snippet["description"] : "";
                if (isset($snippet["defaultAudioLanguage"])) {
                    $details->language = $this->languageName($snippet["defaultAudioLanguage"]);
                } elseif (isset($snippet["defaultLanguage"])) {
                    $details->language = $this->languageName($snippet["defaultLanguage"]);
                }
                $details->raw->youtubeVideosList = $this->maskFetchedRaw($response->data);
                $details->source = "YouTube Data API";
            } else {
                $details->messages[] = "The saved YouTube API key was found, but YouTube did not return video details. Check that YouTube Data API v3 is enabled and the key restrictions allow this server.";
            }
        } else {
            $oembed = $this->curlJson("https://www.youtube.com/oembed?format=json&url=" . rawurlencode($url), array());
            if ($oembed->success) {
                $details->title = isset($oembed->data["title"]) ? $oembed->data["title"] : "";
                $details->raw->youtubeOembed = $this->maskFetchedRaw($oembed->data);
                $details->source = "YouTube oEmbed";
            }
            $details->messages[] = "Add a YouTube API key in Connected Accounts to fetch the official description and language.";
        }

        if ($accessToken !== "") {
            $caption = $this->fetchYouTubeCaptionWithOAuth($videoId, $accessToken, $details->language);
            if ($caption->transcript !== "") {
                $details->transcript = $caption->transcript;
                $details->language = $details->language !== "" ? $details->language : $caption->language;
                $details->source .= " + YouTube Captions API";
            } elseif ($caption->message !== "") {
                $details->messages[] = $caption->message;
            }
        }

        if ($details->transcript === "") {
            $caption = $this->fetchYouTubePublicCaption($videoId, $details->language);
            if ($caption->transcript !== "") {
                $details->transcript = $caption->transcript;
                $details->language = $details->language !== "" ? $details->language : $caption->language;
                $details->source .= " + YouTube captions";
            } else {
                $details->messages[] = "Transcript was not available from YouTube captions for this video.";
            }
        }

        return $details;
    }

    private function fetchTikTokDetails($url, $body) {
        $details = $this->emptyUrlDetails();
        $details->source = "TikTok oEmbed";
        $response = $this->curlJson("https://www.tiktok.com/oembed?url=" . rawurlencode($url), array());
        if ($response->success) {
            $details->title = isset($response->data["title"]) ? $response->data["title"] : "";
            $details->description = $details->title;
            $details->raw->tiktokOembed = $this->maskFetchedRaw($response->data);
            $details->language = $this->detectLanguage($details->title);
        } else {
            $details->messages[] = "TikTok metadata could not be fetched from oEmbed.";
        }
        $details->messages[] = "TikTok does not expose public transcript text through this app flow. Paste transcript manually if needed.";
        return $details;
    }

    private function fetchFacebookDetails($url, $body) {
        $details = $this->emptyUrlDetails();
        $details->source = "Meta Graph API";
        $credentials = $this->platformCredentials("Facebook", $body);
        $accessToken = $this->credentialValue($credentials, array("accessToken", "pageAccessToken", "facebookAccessToken"));
        $objectId = $this->facebookObjectIdFromUrl($url);

        if ($accessToken !== "" && $objectId !== "") {
            $apiUrl = "https://graph.facebook.com/v19.0/" . rawurlencode($objectId)
                . "?fields=id,title,description,message,created_time,permalink_url,length"
                . "&access_token=" . rawurlencode($accessToken);
            $response = $this->curlJson($apiUrl, array());
            if ($response->success) {
                $details->title = isset($response->data["title"]) ? $response->data["title"] : "";
                $details->description = isset($response->data["description"]) ? $response->data["description"] : (isset($response->data["message"]) ? $response->data["message"] : "");
                $details->language = $this->detectLanguage($details->title . " " . $details->description);
                $details->raw->facebookGraph = $this->maskFetchedRaw($response->data);
            } else {
                $details->messages[] = "Meta Graph API did not return details for this object ID.";
            }
        } elseif ($accessToken !== "") {
            $apiUrl = "https://graph.facebook.com/v19.0/oembed_video?url=" . rawurlencode($url) . "&access_token=" . rawurlencode($accessToken);
            $response = $this->curlJson($apiUrl, array());
            if ($response->success) {
                $details->title = isset($response->data["title"]) ? $response->data["title"] : "";
                $details->description = $details->title;
                $details->raw->facebookOembed = $this->maskFetchedRaw($response->data);
            } else {
                $details->messages[] = "Meta oEmbed did not return details for this URL.";
            }
        } else {
            $details->messages[] = "Add a Facebook Page or Graph API access token in Connected Accounts to fetch metadata.";
        }

        if ($details->transcript === "") {
            $details->messages[] = "Facebook/Reels transcripts require captions or media data exposed by the connected Meta account. Paste transcript manually if unavailable.";
        }
        return $details;
    }

    private function fetchYouTubeCaptionWithOAuth($videoId, $accessToken, $preferredLanguage) {
        $out = (object)array("transcript" => "", "language" => "", "message" => "");
        $listUrl = "https://www.googleapis.com/youtube/v3/captions?part=snippet&videoId=" . rawurlencode($videoId);
        $list = $this->curlJson($listUrl, array("Authorization: Bearer " . $accessToken));
        if (!$list->success || !isset($list->data["items"]) || !is_array($list->data["items"]) || !count($list->data["items"])) {
            $out->message = "YouTube Captions API did not return captions for this OAuth token.";
            return $out;
        }

        $captionId = "";
        $captionLanguage = "";
        $preferredCode = $this->languageCode($preferredLanguage);
        foreach ($list->data["items"] as $item) {
            $lang = isset($item["snippet"]["language"]) ? $item["snippet"]["language"] : "";
            if ($captionId === "" || ($preferredCode !== "" && strtolower($lang) === strtolower($preferredCode))) {
                $captionId = isset($item["id"]) ? $item["id"] : "";
                $captionLanguage = $lang;
            }
        }

        if ($captionId === "") {
            $out->message = "YouTube Captions API returned captions without downloadable IDs.";
            return $out;
        }

        $download = $this->curlText("https://www.googleapis.com/youtube/v3/captions/" . rawurlencode($captionId) . "?tfmt=srt", array("Authorization: Bearer " . $accessToken));
        if ($download->success) {
            $out->transcript = $this->captionTextToPlainText($download->text);
            $out->language = $this->languageName($captionLanguage);
        } else {
            $out->message = "YouTube caption download failed for the selected caption.";
        }
        return $out;
    }

    private function fetchYouTubePublicCaption($videoId, $preferredLanguage) {
        $out = (object)array("transcript" => "", "language" => "", "message" => "");
        $list = $this->curlText("https://video.google.com/timedtext?type=list&v=" . rawurlencode($videoId), array());
        if (!$list->success || trim($list->text) === "") {
            $out->message = "No public YouTube caption tracks were listed.";
            return $out;
        }

        $tracks = $this->parseYouTubeCaptionTracks($list->text);
        if (!count($tracks)) {
            $out->message = "No public YouTube caption tracks were parsed.";
            return $out;
        }

        $preferredCode = $this->languageCode($preferredLanguage);
        $track = $tracks[0];
        foreach ($tracks as $candidate) {
            if ($preferredCode !== "" && strtolower($candidate["lang"]) === strtolower($preferredCode)) {
                $track = $candidate;
                break;
            }
            if (!empty($candidate["default"])) {
                $track = $candidate;
            }
        }

        $captionUrl = "https://video.google.com/timedtext?v=" . rawurlencode($videoId)
            . "&lang=" . rawurlencode($track["lang"])
            . (isset($track["name"]) && $track["name"] !== "" ? "&name=" . rawurlencode($track["name"]) : "");
        $caption = $this->curlText($captionUrl, array());
        if ($caption->success && trim($caption->text) !== "") {
            $out->transcript = $this->youtubeTimedTextToPlainText($caption->text);
            $out->language = $this->languageName($track["lang"]);
        }
        return $out;
    }

    private function parseYouTubeCaptionTracks($xmlText) {
        $tracks = array();
        if (function_exists("simplexml_load_string")) {
            $xml = @simplexml_load_string($xmlText);
            if ($xml !== false) {
                foreach ($xml->track as $track) {
                    $tracks[] = array(
                        "lang" => (string)$track["lang_code"],
                        "name" => (string)$track["name"],
                        "default" => (string)$track["lang_default"] === "true"
                    );
                }
            }
        }

        if (!count($tracks) && preg_match_all('/<track\s+([^>]+)>/i', $xmlText, $matches)) {
            foreach ($matches[1] as $attrs) {
                $tracks[] = array(
                    "lang" => $this->xmlAttribute($attrs, "lang_code"),
                    "name" => $this->xmlAttribute($attrs, "name"),
                    "default" => $this->xmlAttribute($attrs, "lang_default") === "true"
                );
            }
        }

        return array_values(array_filter($tracks, function ($track) {
            return isset($track["lang"]) && $track["lang"] !== "";
        }));
    }

    private function youtubeTimedTextToPlainText($xmlText) {
        $lines = array();
        if (function_exists("simplexml_load_string")) {
            $xml = @simplexml_load_string($xmlText);
            if ($xml !== false) {
                foreach ($xml->text as $node) {
                    $line = html_entity_decode((string)$node, ENT_QUOTES, "UTF-8");
                    $line = trim(preg_replace('/\s+/', ' ', $line));
                    if ($line !== "") {
                        $lines[] = $line;
                    }
                }
            }
        }

        if (!count($lines) && preg_match_all('/<text[^>]*>(.*?)<\/text>/is', $xmlText, $matches)) {
            foreach ($matches[1] as $line) {
                $line = html_entity_decode(strip_tags($line), ENT_QUOTES, "UTF-8");
                $line = trim(preg_replace('/\s+/', ' ', $line));
                if ($line !== "") {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function captionTextToPlainText($captionText) {
        $captionText = preg_replace('/^\xEF\xBB\xBF/', '', (string)$captionText);
        $captionText = preg_replace('/WEBVTT[^\n]*\n/i', '', $captionText);
        $lines = preg_split('/\r\n|\r|\n/', $captionText, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === "" || preg_match('/^\d+$/', $line) || strpos($line, "-->") !== false) {
                continue;
            }
            $line = preg_replace('/<[^>]+>/', '', $line);
            $line = html_entity_decode($line, ENT_QUOTES, "UTF-8");
            if ($line !== "") {
                $out[] = $line;
            }
        }
        return implode("\n", $out);
    }

    private function platformCredentials($platform, $body) {
        $credentials = new stdClass();
        foreach (array("apiKey", "accessToken", "pageId", "appId", "appSecret") as $field) {
            if (is_object($body) && isset($body->{$field}) && trim((string)$body->{$field}) !== "") {
                $credentials->{$field} = trim((string)$body->{$field});
            }
        }

        $envMap = array(
            "apiKey" => array("YOUTUBE_API_KEY", "DAVVAG_YOUTUBE_API_KEY"),
            "accessToken" => array("YOUTUBE_ACCESS_TOKEN", "FACEBOOK_ACCESS_TOKEN", "META_ACCESS_TOKEN", "DAVVAG_PLATFORM_ACCESS_TOKEN")
        );
        foreach ($envMap as $field => $names) {
            if (!isset($credentials->{$field})) {
                foreach ($names as $name) {
                    $value = getenv($name);
                    if ($value !== false && trim($value) !== "") {
                        $credentials->{$field} = trim($value);
                        break;
                    }
                }
            }
        }

        $result = SOSSData::Query($this->socialAccountNamespace, "", null, "desc", 200, 0);
        if ($result->success) {
            foreach ($result->result as $account) {
                if (!$this->accountMatchesPlatform($account, $platform)) {
                    continue;
                }
                if (isset($account->raw)) {
                    $storedRaw = $this->normalizeStoredObject($account->raw);
                    foreach ($storedRaw as $key => $value) {
                        if (!isset($credentials->{$key}) && trim((string)$value) !== "" && trim((string)$value) !== "********") {
                            $credentials->{$key} = trim((string)$value);
                        }
                    }
                }
            }
        }

        return $credentials;
    }

    private function accountMatchesPlatform($account, $platform) {
        $accountPlatform = isset($account->platform) ? strtolower((string)$account->platform) : "";
        $platform = strtolower((string)$platform);
        if ($accountPlatform === "" || $platform === "") {
            return false;
        }
        if (strpos($platform, "youtube") !== false) {
            return strpos($accountPlatform, "youtube") !== false;
        }
        if (strpos($platform, "tiktok") !== false) {
            return strpos($accountPlatform, "tiktok") !== false;
        }
        if (strpos($platform, "facebook") !== false || strpos($platform, "meta") !== false) {
            return strpos($accountPlatform, "facebook") !== false || strpos($accountPlatform, "meta") !== false;
        }
        return $accountPlatform === $platform;
    }

    private function credentialValue($credentials, $fields) {
        foreach ($fields as $field) {
            if (is_object($credentials) && isset($credentials->{$field}) && trim((string)$credentials->{$field}) !== "") {
                return trim((string)$credentials->{$field});
            }
        }
        return "";
    }

    private function curlJson($url, $headers) {
        $response = $this->curlText($url, $headers);
        $response->data = array();
        if ($response->success) {
            $json = json_decode($response->text, true);
            if (is_array($json)) {
                $response->data = $json;
            } else {
                $response->success = false;
                $response->error = "Invalid JSON response.";
            }
        }
        return $response;
    }

    private function curlText($url, $headers) {
        $out = (object)array("success" => false, "text" => "", "error" => "", "httpStatus" => 0);
        if (!function_exists("curl_init")) {
            $out->error = "PHP cURL is not enabled.";
            return $out;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, "DAVVAG Viral Content Manager");
        if (is_array($headers) && count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $text = curl_exec($ch);
        $out->error = curl_error($ch);
        $out->httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($text !== false && $out->httpStatus < 400) {
            $out->success = true;
            $out->text = $text;
        }
        return $out;
    }

    private function facebookObjectIdFromUrl($url) {
        $path = trim((string)parse_url($url, PHP_URL_PATH), "/");
        $query = (string)parse_url($url, PHP_URL_QUERY);
        if ($query !== "") {
            parse_str($query, $params);
            if (isset($params["v"]) && preg_match('/^\d+$/', $params["v"])) {
                return $params["v"];
            }
        }
        if (preg_match('/(?:videos|reel|watch)\/(\d+)/', $path, $match)) {
            return $match[1];
        }
        if (preg_match('/(\d{8,})/', $path, $match)) {
            return $match[1];
        }
        return "";
    }

    private function xmlAttribute($attrs, $name) {
        if (preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $attrs, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES, "UTF-8");
        }
        return "";
    }

    private function detectLanguage($text) {
        $text = (string)$text;
        if ($text === "") {
            return "";
        }
        if (preg_match('/[\x{0D80}-\x{0DFF}]/u', $text)) {
            return "Sinhala";
        }
        if (preg_match('/[\x{0B80}-\x{0BFF}]/u', $text)) {
            return "Tamil";
        }
        if (preg_match('/[A-Za-z]/', $text)) {
            return "English";
        }
        return "";
    }

    private function languageName($code) {
        $code = strtolower(trim((string)$code));
        $code = str_replace("_", "-", $code);
        $base = explode("-", $code);
        $base = count($base) ? $base[0] : $code;
        $map = array(
            "en" => "English",
            "si" => "Sinhala",
            "ta" => "Tamil",
            "hi" => "Hindi",
            "ar" => "Arabic",
            "fr" => "French",
            "de" => "German",
            "es" => "Spanish",
            "pt" => "Portuguese",
            "it" => "Italian",
            "id" => "Indonesian",
            "zh" => "Chinese",
            "ja" => "Japanese",
            "ko" => "Korean"
        );
        return isset($map[$base]) ? $map[$base] : strtoupper($code);
    }

    private function languageCode($language) {
        $language = strtolower(trim((string)$language));
        $map = array(
            "english" => "en",
            "sinhala" => "si",
            "sinhalese" => "si",
            "tamil" => "ta",
            "hindi" => "hi",
            "arabic" => "ar"
        );
        if (isset($map[$language])) {
            return $map[$language];
        }
        if (preg_match('/^[a-z]{2}/', $language, $match)) {
            return $match[0];
        }
        return "";
    }

    private function maskFetchedRaw($raw) {
        if (is_array($raw)) {
            $copy = $raw;
            foreach ($copy as $key => $value) {
                $keyName = strtolower((string)$key);
                if (strpos($keyName, "token") !== false || strpos($keyName, "key") !== false || strpos($keyName, "secret") !== false) {
                    $copy[$key] = "********";
                }
            }
            return $copy;
        }
        return $raw;
    }

    private function copySecretField($source, $target, $field) {
        if (!is_object($source) || !isset($source->{$field})) {
            return;
        }
        $value = trim((string)$source->{$field});
        if ($value !== "" && $value !== "********") {
            $target->{$field} = $value;
        }
    }

    private function normalizeStoredObject($value) {
        if (is_object($value)) {
            return clone $value;
        }
        if (is_array($value)) {
            return (object)$value;
        }
        if (is_string($value) && trim($value) !== "") {
            $decoded = json_decode($value);
            if (is_object($decoded)) {
                return $decoded;
            }
            if (is_array($decoded)) {
                return (object)$decoded;
            }
        }
        return new stdClass();
    }

    private function existingAccountRaw($source) {
        $raw = new stdClass();
        if (!is_object($source) || !isset($source->socialAccountId) || intval($source->socialAccountId) <= 0) {
            return $raw;
        }

        $existing = SOSSData::Query($this->socialAccountNamespace, "socialAccountId:" . intval($source->socialAccountId), null, "desc", 1, 0);
        if ($existing->success && count($existing->result) > 0 && isset($existing->result[0]->raw)) {
            return $this->normalizeStoredObject($existing->result[0]->raw);
        }
        return $raw;
    }

    private function secretText($obj, $field, $maxLen, $default = "") {
        $value = $this->text($obj, $field, $maxLen, $default);
        return $value === "********" ? $default : $value;
    }

    private function safeAccountsForClient($accounts) {
        $safe = array();
        foreach ($accounts as $account) {
            $safe[] = $this->safeAccountForClient($account);
        }
        return $safe;
    }

    private function safeAccountForClient($account) {
        if (is_array($account)) {
            $out = array();
            foreach ($account as $item) {
                $out[] = $this->safeAccountForClient($item);
            }
            return $out;
        }
        if (!is_object($account)) {
            return $account;
        }
        $copy = clone $account;
        $storedRaw = isset($account->raw) ? $this->normalizeStoredObject($account->raw) : new stdClass();
        $copy->credentialStatus = (object)array(
            "apiKey" => $this->storedCredentialExists($storedRaw, array("apiKey", "youtubeApiKey")),
            "accessToken" => $this->storedCredentialExists($storedRaw, array("accessToken", "youtubeAccessToken", "pageAccessToken", "facebookAccessToken")),
            "clientSecret" => $this->storedCredentialExists($storedRaw, array("clientSecret", "appSecret"))
        );
        $copy->raw = $storedRaw;
        if (isset($copy->raw)) {
            foreach ($copy->raw as $key => $value) {
                $keyName = strtolower((string)$key);
                if (strpos($keyName, "token") !== false || strpos($keyName, "key") !== false || strpos($keyName, "secret") !== false || strpos($keyName, "password") !== false) {
                    $copy->raw->{$key} = "********";
                }
            }
        }
        return $copy;
    }

    private function storedCredentialExists($raw, $fields) {
        foreach ($fields as $field) {
            if (is_object($raw) && isset($raw->{$field})) {
                $value = trim((string)$raw->{$field});
                if ($value !== "" && $value !== "********") {
                    return true;
                }
            }
        }
        return false;
    }

    private function oauthProvider($platform) {
        $platform = strtolower((string)$platform);
        if (strpos($platform, "youtube") !== false) {
            return $this->oauthProviderByCode("youtube");
        }
        if (strpos($platform, "tiktok") !== false) {
            return $this->oauthProviderByCode("tiktok");
        }
        if (strpos($platform, "facebook") !== false || strpos($platform, "meta") !== false) {
            return $this->oauthProviderByCode("facebook");
        }
        return null;
    }

    private function oauthProviderByCode($code) {
        $providers = array(
            "youtube" => array(
                "code" => "youtube",
                "label" => "YouTube",
                "authorizeUrl" => "https://accounts.google.com/o/oauth2/v2/auth",
                "tokenUrl" => "https://oauth2.googleapis.com/token",
                "scopeSeparator" => " ",
                "clientIdField" => "client_id",
                "clientSecretField" => "client_secret",
                "scopes" => array(
                    "https://www.googleapis.com/auth/youtube.readonly",
                    "https://www.googleapis.com/auth/youtube.force-ssl"
                )
            ),
            "tiktok" => array(
                "code" => "tiktok",
                "label" => "TikTok",
                "authorizeUrl" => "https://www.tiktok.com/v2/auth/authorize/",
                "tokenUrl" => "https://open.tiktokapis.com/v2/oauth/token/",
                "scopeSeparator" => ",",
                "clientIdField" => "client_key",
                "clientSecretField" => "client_secret",
                "scopes" => array("user.info.basic", "video.upload", "video.publish")
            ),
            "facebook" => array(
                "code" => "facebook",
                "label" => "Facebook Pages",
                "authorizeUrl" => "https://www.facebook.com/v19.0/dialog/oauth",
                "tokenUrl" => "https://graph.facebook.com/v19.0/oauth/access_token",
                "scopeSeparator" => ",",
                "clientIdField" => "client_id",
                "clientSecretField" => "client_secret",
                "scopes" => array("pages_show_list", "pages_read_engagement", "pages_manage_posts", "pages_read_user_content")
            )
        );
        return isset($providers[$code]) ? $providers[$code] : null;
    }

    private function oauthAuthorizeUrl($provider, $stateData) {
        $params = array(
            $provider["clientIdField"] => $stateData["clientId"],
            "redirect_uri" => $stateData["redirectUri"],
            "response_type" => "code",
            "scope" => implode($provider["scopeSeparator"], $stateData["scopes"]),
            "state" => $stateData["state"]
        );

        if ($provider["code"] === "youtube") {
            $params["access_type"] = "offline";
            $params["prompt"] = "consent";
            $params["include_granted_scopes"] = "true";
        }
        if ($provider["code"] === "tiktok") {
            $params["client_key"] = $stateData["clientId"];
        }

        return $provider["authorizeUrl"] . "?" . http_build_query($params);
    }

    private function oauthExchangeCode($provider, $stateData, $code) {
        $fields = array(
            $provider["clientIdField"] => $stateData["clientId"],
            $provider["clientSecretField"] => $stateData["clientSecret"],
            "code" => $code,
            "grant_type" => "authorization_code",
            "redirect_uri" => $stateData["redirectUri"]
        );

        if ($provider["code"] === "youtube") {
            $fields["client_id"] = $stateData["clientId"];
            $fields["client_secret"] = $stateData["clientSecret"];
        }
        if ($provider["code"] === "tiktok") {
            $fields["client_key"] = $stateData["clientId"];
        }

        return $this->curlFormJson($provider["tokenUrl"], $fields, array("Content-Type: application/x-www-form-urlencoded"));
    }

    private function accountFromOAuthToken($provider, $stateData, $tokenData) {
        $raw = new stdClass();
        foreach ($tokenData as $key => $value) {
            $raw->{$key} = $value;
        }
        $raw->clientId = $stateData["clientId"];
        $raw->clientSecret = $stateData["clientSecret"];
        $raw->redirectUri = $stateData["redirectUri"];
        $raw->scopesText = implode(",", $stateData["scopes"]);
        if ($stateData["pageId"] !== "") {
            $raw->pageId = $stateData["pageId"];
        }

        $accountName = $stateData["accountName"];
        $accountHandle = $stateData["accountHandle"];
        $accountId = "";
        if (isset($tokenData["access_token"])) {
            $raw->accessToken = $tokenData["access_token"];
        }
        if (isset($tokenData["refresh_token"])) {
            $raw->refreshToken = $tokenData["refresh_token"];
        }
        if (isset($tokenData["open_id"])) {
            $accountId = $tokenData["open_id"];
            $raw->openId = $tokenData["open_id"];
        }

        if ($provider["code"] === "facebook" && isset($tokenData["access_token"])) {
            $page = $this->firstFacebookPageToken($tokenData["access_token"], $stateData["pageId"]);
            if ($page !== null) {
                $accountName = isset($page["name"]) ? $page["name"] : $accountName;
                $accountId = isset($page["id"]) ? $page["id"] : $accountId;
                $raw->pageId = $accountId;
                if (isset($page["access_token"])) {
                    $raw->pageAccessToken = $page["access_token"];
                    $raw->accessToken = $page["access_token"];
                }
                $raw->facebookPage = $page;
            }
        }

        $account = new stdClass();
        $account->platform = $provider["label"];
        $account->accountName = $accountName;
        $account->accountHandle = $accountHandle;
        $account->accountId = $accountId;
        $account->connectionType = "OAuth";
        $account->connectionStatus = "Connected";
        $account->scopes = $stateData["scopes"];
        $account->notes = $stateData["notes"];
        $account->raw = $raw;
        $account->createdAt = $this->now();
        $account->updatedAt = $account->createdAt;
        return $account;
    }

    private function firstFacebookPageToken($userAccessToken, $preferredPageId) {
        $url = "https://graph.facebook.com/v19.0/me/accounts?fields=id,name,access_token&access_token=" . rawurlencode($userAccessToken);
        $response = $this->curlJson($url, array());
        if (!$response->success || !isset($response->data["data"]) || !is_array($response->data["data"]) || !count($response->data["data"])) {
            return null;
        }

        if ($preferredPageId !== "") {
            foreach ($response->data["data"] as $page) {
                if (isset($page["id"]) && (string)$page["id"] === (string)$preferredPageId) {
                    return $page;
                }
            }
        }
        return $response->data["data"][0];
    }

    private function oauthRedirectUri() {
        if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"])) {
            $scheme = $_SERVER["HTTP_X_FORWARDED_PROTO"];
        } elseif (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
            $scheme = "https";
        } else {
            $scheme = "http";
        }
        $host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "localhost";
        $uri = isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : "/components/davvag-viral-content-manager/viral-api/service/StartOAuth";
        $pos = strpos($uri, "/components/");
        $base = $pos === false ? "" : substr($uri, 0, $pos);
        return $scheme . "://" . $host . $base . "/components/davvag-viral-content-manager/viral-api/service/OAuthCallback";
    }

    private function scopeList($scopesText) {
        $parts = preg_split('/[\s,]+/', trim((string)$scopesText), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? array_values(array_unique($parts)) : array();
    }

    private function oauthStateDir() {
        $base = defined("TENANT_RESOURCE_LOCATION") ? TENANT_RESOURCE_LOCATION : dirname(__DIR__, 4);
        return rtrim($base, "\\/") . "/data/davvag-viral-content-manager/oauth-state";
    }

    private function saveOAuthState($state, $stateData) {
        $dir = $this->oauthStateDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return false;
        }
        return file_put_contents($dir . "/" . $this->safeFileName($state) . ".json", json_encode($stateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    private function loadOAuthState($state) {
        $file = $this->oauthStateDir() . "/" . $this->safeFileName($state) . ".json";
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data["createdAt"]) && time() - intval($data["createdAt"]) > 1800) {
            $this->deleteOAuthState($state);
            return null;
        }
        return $data;
    }

    private function deleteOAuthState($state) {
        $file = $this->oauthStateDir() . "/" . $this->safeFileName($state) . ".json";
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    private function safeFileName($name) {
        return preg_replace('/[^A-Za-z0-9_\-]/', "", (string)$name);
    }

    private function oauthCallbackHtml($success, $message) {
        header("Content-Type: text/html; charset=utf-8");
        $title = $success ? "Connected" : "Connection failed";
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . $title . "</title></head><body style=\"font-family:Arial,sans-serif;padding:24px;line-height:1.5;\">";
        echo "<h2>" . $title . "</h2><p>" . $message . "</p><p>You may close this window and return to DAVVAG Viral Content Manager.</p>";
        echo "<script>if(window.opener){try{window.opener.postMessage({type:'davvag-oauth-complete',success:" . ($success ? "true" : "false") . "},'*');}catch(e){}}</script>";
        echo "</body></html>";
        exit();
    }

    private function curlFormJson($url, $fields, $headers) {
        $out = (object)array("success" => false, "data" => array(), "error" => "", "httpStatus" => 0);
        if (!function_exists("curl_init")) {
            $out->error = "PHP cURL is not enabled.";
            return $out;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_USERAGENT, "DAVVAG Viral Content Manager");
        if (is_array($headers) && count($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $text = curl_exec($ch);
        $out->error = curl_error($ch);
        $out->httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = is_string($text) ? json_decode($text, true) : null;
        if ($text !== false && $out->httpStatus < 400 && is_array($json)) {
            $out->success = true;
            $out->data = $json;
        } else {
            if (is_array($json) && isset($json["error_description"])) {
                $out->error = $json["error_description"];
            } elseif (is_array($json) && isset($json["error"])) {
                $out->error = is_string($json["error"]) ? $json["error"] : json_encode($json["error"]);
            } elseif ($out->error === "") {
                $out->error = "HTTP " . $out->httpStatus;
            }
        }
        return $out;
    }

    private function now() {
        return date("Y-m-d H:i:s");
    }

    private function normalizeUrl($url) {
        $url = trim((string)$url);
        if ($url !== "" && !preg_match('/^https?:\/\//i', $url)) {
            $url = "https://" . $url;
        }
        return $url;
    }

    private function isSupportedUrl($url) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return strpos($host, "youtube.com") !== false
            || strpos($host, "youtu.be") !== false
            || strpos($host, "tiktok.com") !== false
            || strpos($host, "facebook.com") !== false
            || strpos($host, "fb.watch") !== false
            || strpos($host, "instagram.com") !== false;
    }

    private function platformFromUrl($url, $fallback) {
        $fallback = trim((string)$fallback);
        if ($fallback !== "") {
            return $fallback;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (strpos($host, "youtube.com") !== false || strpos($host, "youtu.be") !== false) {
            return "YouTube";
        }
        if (strpos($host, "tiktok.com") !== false) {
            return "TikTok";
        }
        if (strpos($host, "facebook.com") !== false || strpos($host, "fb.watch") !== false) {
            return "Facebook";
        }
        if (strpos($host, "instagram.com") !== false) {
            return "Instagram Reels";
        }
        return "Content";
    }

    private function fetchPublicMetadata($platform, $url) {
        $metadata = array("source" => "local");
        if (!function_exists("curl_init")) {
            $metadata["status"] = "curl_unavailable";
            return $metadata;
        }

        $endpoint = "";
        if ($platform === "YouTube") {
            $endpoint = "https://www.youtube.com/oembed?format=json&url=" . rawurlencode($url);
        } elseif ($platform === "TikTok") {
            $endpoint = "https://www.tiktok.com/oembed?url=" . rawurlencode($url);
        }

        if ($endpoint === "") {
            $metadata["status"] = "no_public_oembed";
            return $metadata;
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, "DAVVAG Viral Content Manager");
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            $metadata["status"] = "fetch_failed";
            $metadata["error"] = $error;
            $metadata["httpStatus"] = $status;
            return $metadata;
        }

        $json = json_decode($response, true);
        if (is_array($json)) {
            $metadata = array_merge($metadata, $json);
            $metadata["source"] = "oembed";
            $metadata["status"] = "ok";
        }
        return $metadata;
    }

    private function topicFromText($text, $fallback) {
        $keywords = $this->keywordsFromText($text, "");
        if (count($keywords) >= 2) {
            return ucwords($keywords[0] . " " . $keywords[1]);
        }
        if (count($keywords) === 1) {
            return ucwords($keywords[0]);
        }
        return $fallback === "" ? "Creator Growth" : $fallback;
    }

    private function keywordsFromText($text, $fallbackTopic) {
        $text = strtolower(strip_tags((string)$text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array(
            "the", "and", "for", "with", "that", "this", "from", "you", "your", "about", "into",
            "are", "was", "were", "will", "can", "how", "why", "what", "when", "where", "who",
            "a", "an", "to", "of", "in", "on", "is", "it", "we", "our", "or", "as", "by", "be",
            "not", "but", "if", "so", "do", "does", "did", "has", "have", "had", "i", "me", "my"
        );
        $counts = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) < 3 || in_array($part, $stop)) {
                continue;
            }
            if (!isset($counts[$part])) {
                $counts[$part] = 0;
            }
            $counts[$part]++;
        }
        arsort($counts);
        $keywords = array_slice(array_keys($counts), 0, 10);

        if (!count($keywords) && $fallbackTopic !== "") {
            $fallbackParts = preg_split('/\s+/', strtolower($fallbackTopic), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($fallbackParts as $part) {
                if (strlen($part) >= 3) {
                    $keywords[] = $part;
                }
            }
        }

        if (!count($keywords)) {
            $keywords = array("creator", "content", "growth");
        }

        return array_values(array_unique($keywords));
    }

    private function hashtagsFromKeywords($keywords, $platform) {
        $hashtags = array();
        foreach ($keywords as $keyword) {
            $tag = preg_replace('/[^\p{L}\p{N}]+/u', '', ucwords((string)$keyword));
            if ($tag !== "") {
                $hashtags[] = "#" . $tag;
            }
            if (count($hashtags) >= 8) {
                break;
            }
        }

        if ($platform === "YouTube" || $platform === "YouTube Shorts") {
            $hashtags[] = "#YouTube";
            $hashtags[] = "#Shorts";
        } elseif ($platform === "TikTok") {
            $hashtags[] = "#TikTok";
            $hashtags[] = "#ForYou";
        } elseif (strpos($platform, "Facebook") !== false) {
            $hashtags[] = "#FacebookReels";
        }

        return array_values(array_unique($hashtags));
    }

    private function scoreSeo($title, $description, $keywords, $hashtags, $transcript) {
        $score = 20;
        $titleLen = strlen(trim((string)$title));
        $descriptionLen = strlen(trim((string)$description));
        $wordCount = $this->wordCount($transcript);

        if ($titleLen >= 38 && $titleLen <= 75) {
            $score += 20;
        } elseif ($titleLen >= 25 && $titleLen <= 95) {
            $score += 12;
        }
        if (count($keywords) >= 5) {
            $score += 15;
        } elseif (count($keywords) >= 3) {
            $score += 10;
        }
        if ($descriptionLen >= 160) {
            $score += 15;
        } elseif ($descriptionLen >= 80) {
            $score += 8;
        }
        if (count($hashtags) >= 5) {
            $score += 12;
        } elseif (count($hashtags) >= 3) {
            $score += 8;
        }
        if ($wordCount >= 300) {
            $score += 18;
        } elseif ($wordCount >= 80) {
            $score += 10;
        }

        return min(100, $score);
    }

    private function titleOptions($source, $topic, $keywords) {
        $key = count($keywords) ? ucwords($keywords[0]) : $topic;
        $topic = $this->titleCase($topic);
        $options = array(
            $topic . ": What Every Creator Should Know",
            "How " . $topic . " Can Change Your Next Post",
            "The " . $key . " Mistake That Holds Content Back",
            "Before You Post Again, Watch This About " . $topic,
            "Why " . $topic . " Is Getting More Attention Now",
            "Use This " . $key . " Strategy for Better Reach"
        );

        $cleanSource = $this->titleCase($this->limitWords($source, 9));
        if ($cleanSource !== "" && strlen($cleanSource) <= 85) {
            array_unshift($options, $cleanSource . " - Better Title Angle");
        }

        return array_slice(array_values(array_unique($options)), 0, 6);
    }

    private function improvedDescription($title, $topic, $keywords, $hashtags, $audience) {
        $keywordLine = implode(", ", array_slice($keywords, 0, 8));
        $tagLine = implode(" ", array_slice($hashtags, 0, 8));
        return $title . "\n\n"
            . "This video/post is created for " . $audience . " and focuses on " . strtolower($topic) . ". "
            . "It highlights the core problem, the practical lesson, and the next step viewers can take today.\n\n"
            . "Topics: " . $keywordLine . "\n\n"
            . "Share this with someone who needs the message, then follow for the next practical teaching.\n\n"
            . $tagLine;
    }

    private function thumbnailPrompts($topic, $platform, $audience) {
        $topic = $this->titleCase($topic);
        return array(
            "Close-up expressive face, bold readable text: \"" . $topic . "\", high contrast, clean background, platform-safe composition.",
            "Creator pointing toward three-word overlay about " . $topic . ", warm light, clear subject separation, mobile-first crop.",
            "Before/after visual tension for " . $topic . ", large central subject, minimal clutter, readable text for small screens."
        );
    }

    private function cta($platform, $topic) {
        if ($platform === "YouTube") {
            return "Subscribe and comment with the part of " . strtolower($topic) . " you want explained next.";
        }
        if ($platform === "TikTok") {
            return "Follow for the next short teaching and share this with someone who needs it today.";
        }
        return "Comment your takeaway and share this post with someone who will value it.";
    }

    private function pinnedComment($topic, $hashtags) {
        return "What stood out most about " . strtolower($topic) . "? Add your takeaway below. " . implode(" ", array_slice($hashtags, 0, 4));
    }

    private function hooks($topic, $idea) {
        $topic = strtolower($topic);
        return array(
            "Most people miss this part of " . $topic . ".",
            "Here is the simple way to understand " . $topic . ".",
            "Before you scroll, listen to this about " . $topic . ".",
            "This changed how I think about " . $topic . "."
        );
    }

    private function captionForPlatform($platform, $topic, $hashtags) {
        $base = "A quick thought on " . strtolower($topic) . " that can change the way you create and respond today.";
        return $base . " " . implode(" ", array_slice($hashtags, 0, 6));
    }

    private function facebookPost($topic, $idea, $hashtags) {
        return "A practical thought on " . strtolower($topic) . ":\n\n"
            . $this->limitWords($idea, 48) . "\n\n"
            . "What would you add to this?\n\n"
            . implode(" ", array_slice($hashtags, 0, 6));
    }

    private function bestPlatformFormat($platform, $idea) {
        $words = $this->wordCount($idea);
        if ($platform === "YouTube" && $words > 80) {
            return "Long-form teaching with 3-5 chapters and 3 shorts cut from the strongest moments.";
        }
        if ($platform === "TikTok") {
            return "Vertical short with a first-second hook, one clear point, and caption-led retention.";
        }
        return "Post plus reel pair: concise caption, one visual hook, and a comment prompt.";
    }

    private function shortFormVersion($topic, $idea) {
        return "Open with: \"Here is one thing about " . strtolower($topic) . ".\" Make one point, give one example, end with one question.";
    }

    private function longFormVersion($topic, $idea) {
        return "Structure: hook, context, three teaching points, example, recap, CTA. Keep the title focused on " . strtolower($topic) . ".";
    }

    private function buildClips($transcript, $topic, $hashtags, $platform) {
        if (trim((string)$transcript) === "") {
            return array();
        }

        $segments = $this->transcriptSegments($transcript);
        usort($segments, function ($a, $b) {
            if ($a["score"] === $b["score"]) {
                return $a["start"] - $b["start"];
            }
            return $b["score"] - $a["score"];
        });

        $clips = array();
        $max = min(5, count($segments));
        for ($i = 0; $i < $max; $i++) {
            $segment = $segments[$i];
            $clip = new stdClass();
            $clip->contentUid = "";
            $clip->startTime = $this->formatTime($segment["start"]);
            $clip->endTime = $this->formatTime($segment["end"]);
            $clip->clipTitle = $this->clipTitle($segment["text"], $topic);
            $clip->hook = $this->clipHook($segment["text"], $topic);
            $clip->transcriptSection = $this->limitWords($segment["text"], 70);
            $clip->reason = $this->clipReason($segment["text"]);
            $clip->caption = $this->captionForPlatform($platform, $topic, $hashtags);
            $clip->hashtags = array_slice($hashtags, 0, 8);
            $clip->overlayIdea = "Text overlay: " . $this->limitWords($clip->hook, 8);
            $clip->score = min(100, max(55, $segment["score"]));
            $clips[] = $clip;
        }

        usort($clips, function ($a, $b) {
            return strcmp($a->startTime, $b->startTime);
        });

        return $clips;
    }

    private function transcriptSegments($transcript) {
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$transcript), -1, PREG_SPLIT_NO_EMPTY);
        $segments = array();
        $buffer = "";
        $currentStart = 0;
        $hasTimestamps = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\s*(?:\[|\()?((?:\d{1,2}:)?\d{1,2}:\d{2})(?:\]|\))?\s*[-:]?\s*(.+)$/u', $line, $match)) {
                $hasTimestamps = true;
                if ($buffer !== "") {
                    $segments[] = $this->segment($currentStart, $currentStart + 45, $buffer);
                }
                $currentStart = $this->timeToSeconds($match[1]);
                $buffer = trim($match[2]);
            } else {
                $buffer .= ($buffer === "" ? "" : " ") . $line;
            }
        }

        if ($buffer !== "") {
            $segments[] = $this->segment($currentStart, $currentStart + 45, $buffer);
        }

        if ($hasTimestamps && count($segments)) {
            for ($i = 0; $i < count($segments); $i++) {
                if (isset($segments[$i + 1])) {
                    $segments[$i]["end"] = max($segments[$i]["start"] + 20, min($segments[$i]["start"] + 75, $segments[$i + 1]["start"]));
                }
            }
            return $segments;
        }

        $sentences = $this->sentences($transcript);
        $segments = array();
        $bucket = "";
        $start = 0;
        foreach ($sentences as $sentence) {
            $next = trim($bucket . " " . $sentence);
            if ($this->wordCount($next) > 70 && $bucket !== "") {
                $segments[] = $this->segment($start, $start + 45, $bucket);
                $start += 45;
                $bucket = $sentence;
            } else {
                $bucket = $next;
            }
        }
        if ($bucket !== "") {
            $segments[] = $this->segment($start, $start + 45, $bucket);
        }

        return $segments;
    }

    private function segment($start, $end, $text) {
        return array(
            "start" => max(0, intval($start)),
            "end" => max(intval($start) + 20, intval($end)),
            "text" => trim($text),
            "score" => $this->segmentScore($text)
        );
    }

    private function segmentScore($text) {
        $lower = strtolower($text);
        $score = 50;
        $signals = array("why", "how", "mistake", "secret", "truth", "promise", "delay", "change", "never", "always", "important", "remember", "listen", "believe", "faith", "story");
        foreach ($signals as $signal) {
            if (strpos($lower, $signal) !== false) {
                $score += 6;
            }
        }
        $words = $this->wordCount($text);
        if ($words >= 35 && $words <= 80) {
            $score += 14;
        }
        if (strpos($text, "?") !== false) {
            $score += 8;
        }
        return min(100, $score);
    }

    private function clipTitle($text, $topic) {
        $first = $this->limitWords($text, 8);
        if ($first !== "") {
            return $this->titleCase($first);
        }
        return $this->titleCase($topic);
    }

    private function clipHook($text, $topic) {
        $first = $this->limitWords($text, 10);
        if ($first !== "") {
            return "\"" . $first . "\"";
        }
        return "\"Why " . strtolower($topic) . " matters\"";
    }

    private function clipReason($text) {
        $lower = strtolower($text);
        if (strpos($lower, "?") !== false || strpos($lower, "why") !== false) {
            return "Question-led moment with strong hook potential.";
        }
        if (strpos($lower, "story") !== false || strpos($lower, "remember") !== false) {
            return "Narrative moment that can hold attention in short form.";
        }
        if (strpos($lower, "faith") !== false || strpos($lower, "promise") !== false || strpos($lower, "delay") !== false) {
            return "Emotional teaching moment with shareable language.";
        }
        return "Focused standalone section that can work as a short clip.";
    }

    private function competitorResearchSeeds($platform, $keywords, $topic, $contentUid) {
        $rows = array();
        $top = array_slice($keywords, 0, 5);
        foreach ($top as $index => $keyword) {
            $row = new stdClass();
            $row->contentUid = $contentUid;
            $row->platform = $platform;
            $row->keyword = $keyword;
            $row->title = "Search " . $platform . " for: " . $keyword . " " . strtolower($topic);
            $row->channelName = "Competitor research";
            $row->url = "";
            $row->rankPosition = $index + 1;
            $row->reason = "Use this keyword to compare ranking titles, thumbnails, captions, and comment patterns.";
            $row->metrics = new stdClass();
            $row->source = "research_seed";
            $row->createdAt = $this->now();
            $rows[] = $row;
        }
        return $rows;
    }

    private function createContentItem($sourceType, $platform, $url, $title, $description, $topic, $audience, $language, $raw) {
        $contentUid = $this->uid("content");
        $item = new stdClass();
        $item->contentUid = $contentUid;
        $item->sourceType = $sourceType;
        $item->platform = $platform;
        $item->url = $url;
        $item->externalId = $this->externalIdFromUrl($url);
        $item->title = $this->limitText($title, 300);
        $item->description = $this->limitText($description, 8000);
        $item->status = "Draft";
        $item->topic = $this->limitText($topic, 180);
        $item->audience = $this->limitText($audience, 300);
        $item->language = $this->limitText($language, 80);
        $item->raw = $raw;
        $item->createdAt = $this->now();
        $item->updatedAt = $item->createdAt;
        SOSSData::Insert($this->contentNamespace, $item);
        return $contentUid;
    }

    private function saveTranscript($contentUid, $platform, $language, $source, $transcript) {
        $row = new stdClass();
        $row->contentUid = $contentUid;
        $row->platform = $platform;
        $row->language = $language;
        $row->source = $source;
        $row->transcriptText = $this->limitText($transcript, 50000);
        $row->segments = $this->transcriptSegments($transcript);
        $row->wordCount = $this->wordCount($transcript);
        $row->createdAt = $this->now();
        SOSSData::Insert($this->transcriptNamespace, $row);
    }

    private function saveAnalysis($contentUid, $analysisType, $platform, $topic, $audience, $before, $after, $keywords, $hashtags, $findings, $agentNotes) {
        $row = new stdClass();
        $row->contentUid = $contentUid;
        $row->analysisType = $analysisType;
        $row->platform = $platform;
        $row->topic = $topic;
        $row->audience = $audience;
        $row->seoScoreBefore = intval($before);
        $row->seoScoreAfter = intval($after);
        $row->keywords = $keywords;
        $row->hashtags = $hashtags;
        $row->findings = $findings;
        $row->agentNotes = $agentNotes;
        $row->createdAt = $this->now();
        SOSSData::Insert($this->analysisNamespace, $row);
    }

    private function saveSeoSuggestion($contentUid, $suggestionType, $titles, $description, $keywords, $hashtags, $thumbnailPrompts, $cta, $pinnedComment, $before, $after) {
        $row = new stdClass();
        $row->contentUid = $contentUid;
        $row->suggestionType = $suggestionType;
        $row->titleOptions = $titles;
        $row->descriptionSuggestion = $description;
        $row->tags = $keywords;
        $row->hashtags = $hashtags;
        $row->thumbnailPrompts = $thumbnailPrompts;
        $row->cta = $cta;
        $row->pinnedComment = $pinnedComment;
        $row->scoreBefore = intval($before);
        $row->scoreAfter = intval($after);
        $row->createdAt = $this->now();
        SOSSData::Insert($this->seoNamespace, $row);
    }

    private function saveCompetitors($rows) {
        if (count($rows)) {
            SOSSData::Insert($this->competitorNamespace, $rows);
        }
    }

    private function saveClips($contentUid, $platform, $clips) {
        $rows = array();
        foreach ($clips as $clip) {
            $clip->contentUid = $contentUid;
            $row = new stdClass();
            $row->contentUid = $contentUid;
            $row->platform = $platform;
            $row->startTime = $clip->startTime;
            $row->endTime = $clip->endTime;
            $row->clipTitle = $clip->clipTitle;
            $row->hook = $clip->hook;
            $row->transcriptSection = $clip->transcriptSection;
            $row->reason = $clip->reason;
            $row->caption = $clip->caption;
            $row->hashtags = $clip->hashtags;
            $row->overlayIdea = $clip->overlayIdea;
            $row->score = $clip->score;
            $row->createdAt = $this->now();
            $rows[] = $row;
        }
        if (count($rows)) {
            SOSSData::Insert($this->clipNamespace, $rows);
        }
    }

    private function maybeRunAgent($body, $agentCode, $prompt, $contentUid, $payload) {
        $notes = new stdClass();
        $notes->agentCode = $agentCode;
        $notes->status = "not_requested";
        $notes->response = "";
        $notes->error = "";

        if (!$this->boolValue($body, "useAgents")) {
            return $notes;
        }

        $savedAgents = $this->savedAgentsByCode();
        if ($agentCode === "" || !isset($savedAgents[$agentCode])) {
            $notes->status = "unavailable";
            $notes->error = $agentCode === ""
                ? "No agent is mapped for this optimizer task."
                : "The mapped agent '" . $agentCode . "' is not available in AI Agent Creator.";
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $prompt, "", $notes->error);
            return $notes;
        }

        if (!defined("TENANT_RESOURCE_LOCATION")) {
            $notes->status = "unavailable";
            $notes->error = "Tenant path is not available.";
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $prompt, "", $notes->error);
            return $notes;
        }

        $serviceFile = TENANT_RESOURCE_LOCATION . "/apps/ai-agent-creator/services/creator-api/service.php";
        if (!file_exists($serviceFile)) {
            $notes->status = "unavailable";
            $notes->error = "ai-agent-creator service is not installed.";
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $prompt, "", $notes->error);
            return $notes;
        }

        require_once($serviceFile);
        if (!class_exists("\\ai_agent_creator\\CreatorService")) {
            $notes->status = "unavailable";
            $notes->error = "CreatorService class was not loaded.";
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $prompt, "", $notes->error);
            return $notes;
        }

        try {
            $creator = new \ai_agent_creator\CreatorService();
            $agentMessage = $this->agentMessageWithPayload($prompt, $payload);
            $result = $creator->interactWithAgent(array(
                "agentCode" => $agentCode,
                "message" => $agentMessage,
                "appCode" => "davvag-viral-content-manager",
                "appName" => "DAVVAG Viral Content Manager",
                "profile" => array(
                    "profileId" => $this->currentProfileId()
                ),
                "conversationKey" => $contentUid,
                "context" => array(
                    "contentUid" => $contentUid,
                    "agentRole" => $agentCode
                ),
                "payload" => $payload
            ));

            $notes->status = isset($result->success) && $result->success ? "completed" : "failed";
            $notes->response = isset($result->response) ? $result->response : (isset($result->reply) ? $result->reply : "");
            $notes->error = $notes->status === "failed" && isset($result->error) ? $result->error : "";
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $agentMessage, $notes->response, $notes->error);
        } catch (Exception $ex) {
            $notes->status = "failed";
            $notes->error = $ex->getMessage();
            $this->logAgent($contentUid, $agentCode, "InteractWithAgent", $notes->status, $prompt, "", $notes->error);
        }

        return $notes;
    }

    private function agentMessageWithPayload($prompt, $payload) {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === "") {
            $json = "{}";
        }

        $message = trim((string)$prompt)
            . "\n\nAll available content data is included below. Analyze it immediately."
            . " Do not ask the user to provide or repeat the URL, title, description, transcript, audience, language, keywords, or metadata."
            . " Treat empty fields as unavailable and do not invent missing facts."
            . "\n\nCONTENT_DATA_JSON\n" . $json . "\nEND_CONTENT_DATA_JSON";

        return $this->limitText($message, 24000);
    }

    private function logAgent($contentUid, $agentCode, $action, $status, $prompt, $response, $error) {
        $row = new stdClass();
        $row->contentUid = $contentUid;
        $row->agentCode = $agentCode;
        $row->action = $action;
        $row->status = $status;
        $row->prompt = $this->limitText($prompt, 10000);
        $row->response = $this->limitText($response, 10000);
        $row->error = $this->limitText($error, 2000);
        $row->createdAt = $this->now();
        SOSSData::Insert($this->agentLogNamespace, $row);
    }

    private function agentCatalogResponse() {
        $tasks = $this->agentTasks();
        $savedAgents = $this->savedAgents();
        $savedByCode = $this->savedAgentsByCode($savedAgents);
        $mappings = $this->agentMappingsByTask();
        $mappingObject = new stdClass();

        foreach ($tasks as $index => $task) {
            $taskCode = $task["code"];
            $selected = "";
            if (isset($mappings[$taskCode]) && isset($mappings[$taskCode]->selectedAgentCode)) {
                $selected = (string)$mappings[$taskCode]->selectedAgentCode;
            }
            if ($selected === "" && isset($savedByCode[$task["expectedAgentCode"]])) {
                $selected = $task["expectedAgentCode"];
            }

            $tasks[$index]["selectedAgentCode"] = $selected;
            $tasks[$index]["savedAgentExists"] = isset($savedByCode[$task["expectedAgentCode"]]);
            $tasks[$index]["mappedAgentExists"] = $selected !== "" && isset($savedByCode[$selected]);
            $tasks[$index]["mappedAgentName"] = $tasks[$index]["mappedAgentExists"] ? $savedByCode[$selected]["name"] : "";
            $tasks[$index]["status"] = $selected === "" ? "Not Mapped" : ($tasks[$index]["mappedAgentExists"] ? "Mapped" : "Missing Agent");
            $mappingObject->{$taskCode} = $selected;
        }

        $out = new stdClass();
        $out->tasks = $tasks;
        $out->savedAgents = $savedAgents;
        $out->mappings = $mappingObject;
        $out->creatorAppCode = "ai-agent-creator";
        return $out;
    }

    private function agentCodeForTask($body, $taskCode) {
        $expected = $taskCode;
        $task = $this->taskByCode($taskCode);
        if ($task !== null) {
            $expected = $task["expectedAgentCode"];
        }

        if (is_object($body) && isset($body->agentMappings) && is_object($body->agentMappings) && isset($body->agentMappings->{$taskCode})) {
            $selected = trim((string)$body->agentMappings->{$taskCode});
            if ($selected !== "") {
                return $selected;
            }
        }

        if (is_object($body) && isset($body->agentCode)) {
            $selected = trim((string)$body->agentCode);
            if ($selected !== "") {
                return $selected;
            }
        }

        $mappings = $this->agentMappingsByTask();
        if (isset($mappings[$taskCode]) && isset($mappings[$taskCode]->selectedAgentCode)) {
            $selected = trim((string)$mappings[$taskCode]->selectedAgentCode);
            if ($selected !== "") {
                return $selected;
            }
        }

        return $expected;
    }

    private function taskByCode($taskCode) {
        $tasks = $this->agentTasks();
        foreach ($tasks as $task) {
            if ($task["code"] === $taskCode) {
                return $task;
            }
        }
        return null;
    }

    private function savedAgents($preset = null) {
        if (is_array($preset)) {
            return $preset;
        }

        if (!defined("TENANT_RESOURCE_LOCATION")) {
            return array();
        }

        $serviceFile = TENANT_RESOURCE_LOCATION . "/apps/ai-agent-creator/services/creator-api/service.php";
        if (!file_exists($serviceFile)) {
            return array();
        }

        require_once($serviceFile);
        if (!class_exists("\\ai_agent_creator\\CreatorService")) {
            return array();
        }

        try {
            $creator = new \ai_agent_creator\CreatorService();
            $result = $creator->getListAgents(null, null);
            $agents = isset($result->agents) && is_array($result->agents) ? $result->agents : array();
            $safe = array();
            foreach ($agents as $agent) {
                $agentArray = is_array($agent) ? $agent : (array)$agent;
                $configuration = isset($agentArray["configuration"]) && is_array($agentArray["configuration"]) ? $agentArray["configuration"] : array();
                $provider = isset($configuration["provider"]) && is_array($configuration["provider"]) ? $configuration["provider"] : array();
                $safe[] = array(
                    "agentCode" => isset($agentArray["agentCode"]) ? $agentArray["agentCode"] : "",
                    "name" => isset($agentArray["name"]) ? $agentArray["name"] : "",
                    "description" => isset($agentArray["description"]) ? $agentArray["description"] : "",
                    "provider" => isset($provider["type"]) ? $provider["type"] : "",
                    "model" => isset($provider["model"]) ? $provider["model"] : ""
                );
            }
            return $safe;
        } catch (Exception $ex) {
            return array();
        }
    }

    private function savedAgentsByCode($savedAgents = null) {
        $savedAgents = $this->savedAgents($savedAgents);
        $out = array();
        foreach ($savedAgents as $agent) {
            if (isset($agent["agentCode"]) && $agent["agentCode"] !== "") {
                $out[$agent["agentCode"]] = $agent;
            }
        }
        return $out;
    }

    private function agentMappingsByTask() {
        $result = SOSSData::Query($this->agentMappingNamespace, "", null, "asc", 200, 0);
        $out = array();
        if ($result->success) {
            foreach ($result->result as $row) {
                if (isset($row->taskCode)) {
                    $out[$row->taskCode] = $row;
                }
            }
        }
        return $out;
    }

    private function agentTasks() {
        return array(
            array(
                "code" => "content-url-analyzer-agent",
                "expectedAgentCode" => "content-url-analyzer-agent",
                "name" => "Content URL Analyzer Agent",
                "summary" => "Reads a pasted platform URL and extracts optimization opportunities.",
                "suggestedPrompt" => "You are the Content URL Analyzer Agent for DAVVAG Viral Content Manager. Analyze YouTube, TikTok, Facebook, and Instagram content URLs. Use the provided URL, title, description, transcript, platform, audience, and metadata to identify the topic, viewer intent, content strengths, weak metadata, missing search signals, and clear optimization actions. Return concise structured recommendations for title angle, description angle, target audience, keywords, hashtags, CTA, pinned comment, and risks. Do not claim live API access unless data is provided in the payload."
            ),
            array(
                "code" => "transcript-analyzer-agent",
                "expectedAgentCode" => "transcript-analyzer-agent",
                "name" => "Transcript Analyzer Agent",
                "summary" => "Finds themes, hooks, emotional moments, and retention signals in transcripts.",
                "suggestedPrompt" => "You are the Transcript Analyzer Agent for DAVVAG Viral Content Manager. Analyze creator transcripts for topic clusters, hooks, emotional peaks, teachable moments, audience questions, retention drops, quotable lines, and search-friendly phrasing. Return a structured summary with key themes, audience intent, strongest moments, weak sections, keyword opportunities, and transcript-based rewrite suggestions. Preserve the source language when useful."
            ),
            array(
                "code" => "competitor-research-agent",
                "expectedAgentCode" => "competitor-research-agent",
                "name" => "Competitor Research Agent",
                "summary" => "Compares ranking title, keyword, thumbnail, and caption patterns.",
                "suggestedPrompt" => "You are the Competitor Research Agent for DAVVAG Viral Content Manager. Given a platform, topic, keywords, and any competitor results supplied in the payload, compare likely ranking patterns. Identify title structures, keyword positioning, thumbnail concepts, captions, hashtags, content gaps, and differentiation angles. If live competitor data is not supplied, create search queries and research instructions instead of inventing rankings."
            ),
            array(
                "code" => "seo-suggestion-agent",
                "expectedAgentCode" => "seo-suggestion-agent",
                "name" => "SEO Suggestion Agent",
                "summary" => "Creates improved titles, descriptions, tags, and SEO score reasoning.",
                "suggestedPrompt" => "You are the SEO Suggestion Agent for DAVVAG Viral Content Manager. Create platform-specific SEO improvements for creator content. Generate title options, description rewrites, keyword/tag sets, hashtag sets, chapter or section ideas when relevant, and before/after reasoning. Prioritize clarity, search intent, emotional hook, and platform-safe phrasing. Return concise structured output that a creator can copy."
            ),
            array(
                "code" => "hashtag-agent",
                "expectedAgentCode" => "hashtag-agent",
                "name" => "Hashtag Agent",
                "summary" => "Builds platform-specific hashtag groups from topic and audience.",
                "suggestedPrompt" => "You are the Hashtag Agent for DAVVAG Viral Content Manager. Build hashtag sets for YouTube, TikTok, Facebook Reels, and Instagram Reels using the supplied topic, audience, language, transcript, and keywords. Balance broad discovery tags, niche community tags, content-type tags, and branded tags. Avoid spammy repetition. Return grouped hashtags with a short reason for each group."
            ),
            array(
                "code" => "thumbnail-prompt-agent",
                "expectedAgentCode" => "thumbnail-prompt-agent",
                "name" => "Thumbnail Prompt Agent",
                "summary" => "Creates thumbnail and text-overlay prompts for creators.",
                "suggestedPrompt" => "You are the Thumbnail Prompt Agent for DAVVAG Viral Content Manager. Create thumbnail prompts and short text-overlay concepts for creator content. Use the supplied topic, title, platform, audience, and transcript moments. Prioritize mobile readability, clear focal subject, emotional contrast, high click intent, and truthful representation of the content. Return multiple prompt options with overlay text and visual composition notes."
            ),
            array(
                "code" => "short-clip-finder-agent",
                "expectedAgentCode" => "short-clip-finder-agent",
                "name" => "Short Clip Finder Agent",
                "summary" => "Selects short-form clip timestamps from long transcripts.",
                "suggestedPrompt" => "You are the Short Clip Finder Agent for DAVVAG Viral Content Manager. Analyze long-form video transcripts and identify the best short-form clips for TikTok, Reels, and YouTube Shorts. Use timestamps when provided. Each clip must include start time, end time, clip title, hook, transcript section, reason it can work, caption, hashtags, and overlay idea. Favor complete standalone moments with emotional or practical payoff."
            ),
            array(
                "code" => "publishing-assistant-agent",
                "expectedAgentCode" => "publishing-assistant-agent",
                "name" => "Publishing Assistant Agent",
                "summary" => "Turns an idea or approved suggestion into platform-ready copy.",
                "suggestedPrompt" => "You are the Publishing Assistant Agent for DAVVAG Viral Content Manager. Turn content ideas and optimization results into ready-to-publish platform copy. Generate YouTube titles and descriptions, TikTok captions, Facebook posts, keywords, hashtags, hook lines, CTAs, pinned comments, and short-form or long-form variants. Keep the output concise, practical, and easy to copy into the platform."
            ),
            array(
                "code" => "performance-review-agent",
                "expectedAgentCode" => "performance-review-agent",
                "name" => "Performance Review Agent",
                "summary" => "Reviews metrics and recommends weekly optimization actions.",
                "suggestedPrompt" => "You are the Performance Review Agent for DAVVAG Viral Content Manager. Review platform metrics such as views, likes, comments, shares, CTR, watch time, retention notes, and posting dates. Identify what improved, what underperformed, and what should be changed next. Recommend title tests, thumbnail tests, caption changes, hashtag changes, posting cadence, and short-clip opportunities. Do not invent metrics not supplied in the payload."
            )
        );
    }

    private function currentProfileId() {
        if (class_exists("Auth")) {
            $user = Auth::Autendicate();
            if (isset($user->userid)) {
                return (string)$user->userid;
            }
        }
        return "viral-content-manager";
    }

    private function externalIdFromUrl($url) {
        $parts = parse_url($url);
        if (!isset($parts["host"])) {
            return "";
        }
        $host = strtolower($parts["host"]);
        if (strpos($host, "youtu.be") !== false && isset($parts["path"])) {
            return trim($parts["path"], "/");
        }
        if (strpos($host, "youtube.com") !== false && isset($parts["query"])) {
            parse_str($parts["query"], $query);
            if (isset($query["v"])) {
                return $query["v"];
            }
        }
        if (strpos($host, "youtube.com") !== false && isset($parts["path"])) {
            $path = trim($parts["path"], "/");
            if (preg_match('/^(shorts|embed|live)\/([^\/\?]+)/', $path, $match)) {
                return $match[2];
            }
        }
        return isset($parts["path"]) ? trim($parts["path"], "/") : "";
    }

    private function uid($prefix) {
        return $prefix . "_" . date("YmdHis") . "_" . substr(hash("sha256", microtime(true) . mt_rand()), 0, 10);
    }

    private function querySafe($value) {
        return str_replace(array(",", ":"), " ", (string)$value);
    }

    private function limitText($text, $maxLen) {
        $text = trim((string)$text);
        if ($maxLen > 0 && strlen($text) > $maxLen) {
            return substr($text, 0, $maxLen);
        }
        return $text;
    }

    private function limitWords($text, $maxWords) {
        $words = preg_split('/\s+/u', trim(strip_tags((string)$text)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || !count($words)) {
            return "";
        }
        return implode(" ", array_slice($words, 0, $maxWords));
    }

    private function wordCount($text) {
        $words = preg_split('/\s+/u', trim(strip_tags((string)$text)), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    private function titleCase($text) {
        return ucwords(strtolower(trim((string)$text)));
    }

    private function sentences($text) {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($sentences) || !count($sentences)) {
            $sentences = preg_split('/\r\n|\r|\n/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY);
        }
        return is_array($sentences) ? $sentences : array();
    }

    private function timeToSeconds($time) {
        $parts = array_map("intval", explode(":", $time));
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        return 0;
    }

    private function formatTime($seconds) {
        $seconds = max(0, intval($seconds));
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remain = $seconds % 60;
        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $remain);
        }
        return sprintf("%02d:%02d", $minutes, $remain);
    }
}
?>
