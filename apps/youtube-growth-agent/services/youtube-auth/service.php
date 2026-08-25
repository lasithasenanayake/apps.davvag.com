<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class YouTubeAuthService extends \YtgServiceBase {
    public function postStartConnect($req, $res) {
        $profile = $this->requireProfile($res);
        if ($profile === null) {
            return null;
        }
        $status = \YtgConfig::status();
        if (!$status->ready) {
            return $this->fail($res, "YouTube OAuth is not fully configured. Open Settings & Privacy for the missing server configuration.");
        }

        try {
            $state = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
            $stateData = array(
                "profileId" => $profile->id,
                "profileName" => $profile->name,
                "redirectUri" => \YtgConfig::redirectUri(),
                "createdAt" => time()
            );
            $store = new \YtgSecretStore();
            $store->putState($state, $stateData);
            $google = new \YtgGoogleClient();
            $this->audit("OAUTH_STARTED", "", "youtube", null, array("scopes" => \YtgConfig::scopes()));
            return (object)array(
                "authUrl" => $google->authorizationUrl($state, \YtgConfig::redirectUri()),
                "scopes" => \YtgConfig::scopes(),
                "readOnly" => true
            );
        } catch (\Throwable $error) {
            return $this->fail($res, "Unable to start secure YouTube authorization: " . $error->getMessage());
        }
    }

    public function postStartCaptionConnect($req, $res) {
        $profile = $this->requireProfile($res);
        if ($profile === null) { return null; }
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner"));
        if ($channel === null) { return null; }
        $grant = $this->grantForProfile($channel->channelId, $profile->id);
        if ($grant === null || !isset($grant->credentialRef)) {
            return $this->fail($res, "Connect this YouTube channel before enabling automatic captions.");
        }
        $status = \YtgConfig::status();
        if (!$status->ready) { return $this->fail($res, "YouTube OAuth is not fully configured."); }

        try {
            $state = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
            $redirectUri = \YtgConfig::redirectUri() !== "" ? \YtgConfig::redirectUri() : \YtgConfig::currentServiceRedirectUri();
            $scope = "https://www.googleapis.com/auth/youtube.force-ssl";
            $scopes = array_values(array_unique(array_merge(\YtgConfig::scopes(), array($scope))));
            $store = new \YtgSecretStore();
            $store->putState($state, array(
                "profileId" => $profile->id,
                "profileName" => $profile->name,
                "redirectUri" => $redirectUri,
                "createdAt" => time(),
                "mode" => "caption",
                "channelId" => $channel->channelId
            ));
            $params = array(
                "client_id" => \YtgConfig::clientId(),
                "redirect_uri" => $redirectUri,
                "response_type" => "code",
                "scope" => implode(" ", $scopes),
                "state" => $state,
                "access_type" => "offline",
                "prompt" => "consent select_account",
                "include_granted_scopes" => "true"
            );
            $this->audit("CAPTION_OAUTH_STARTED", $channel->channelId, "youtube-caption-access", null, array("scope" => $scope));
            return (object)array(
                "authUrl" => "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986),
                "scope" => $scope,
                "captionDownloadOnly" => true,
                "message" => "Google consent is required once before automatic caption downloads."
            );
        } catch (\Throwable $error) {
            return $this->fail($res, "Unable to start caption authorization: " . $error->getMessage());
        }
    }

    public function getOAuthCallback($req, $res) {
        $query = $req->Query();
        $state = isset($query->state) ? trim((string)$query->state) : "";
        $code = isset($query->code) ? trim((string)$query->code) : "";
        $oauthError = isset($query->error) ? trim((string)$query->error) : "";
        if ($oauthError !== "") {
            $this->callbackHtml(false, "YouTube authorization was cancelled or denied.");
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,100}$/', $state) || $code === "" || strlen($code) > 4096) {
            $this->callbackHtml(false, "The OAuth callback is missing a valid state or authorization code.");
        }

        try {
            $store = new \YtgSecretStore();
            $stateData = $store->consumeState($state);
            if (!is_array($stateData) || !isset($stateData["profileId"], $stateData["redirectUri"])) {
                $this->callbackHtml(false, "The OAuth state expired or was already used.");
            }
            $profile = $this->currentProfile();
            if ($profile->id <= 0 || intval($stateData["profileId"]) !== $profile->id) {
                $this->callbackHtml(false, "The signed-in DAVVAG profile does not match the OAuth request.");
            }

            $google = new \YtgGoogleClient();
            $tokenResponse = $google->exchangeCode($code, $stateData["redirectUri"]);
            if (!$tokenResponse->success || !is_array($tokenResponse->data) || !isset($tokenResponse->data["access_token"])) {
                $message = $tokenResponse->error !== "" ? $tokenResponse->error : "Token exchange failed.";
                $this->callbackHtml(false, $message);
            }

            if (isset($stateData["mode"]) && $stateData["mode"] === "caption") {
                $this->completeCaptionAuthorization($stateData, $tokenResponse->data, $profile, $google);
            }

            $channelResponse = \YtgHttpClient::request(
                "GET",
                "https://www.googleapis.com/youtube/v3/channels?part=snippet,contentDetails,statistics&mine=true&maxResults=50",
                $tokenResponse->data["access_token"]
            );
            if (!$channelResponse->success || !isset($channelResponse->data["items"]) || !count($channelResponse->data["items"])) {
                $message = $channelResponse->error !== "" ? $channelResponse->error : "No owned YouTube channel was returned.";
                $this->callbackHtml(false, $message);
            }

            $connected = array();
            foreach ($channelResponse->data["items"] as $item) {
                if (!isset($item["id"]) || !preg_match('/^[A-Za-z0-9_-]{6,80}$/', $item["id"])) {
                    continue;
                }
                $youtubeChannelId = $item["id"];
                $channelId = "ytg_" . substr(hash("sha256", $youtubeChannelId), 0, 28);
                $credentialRef = "cred_" . substr(hash("sha256", $channelId . ":" . $profile->id . ":" . random_bytes(16)), 0, 40);
                $google->storeCredential($credentialRef, $tokenResponse->data);

                $snippet = isset($item["snippet"]) && is_array($item["snippet"]) ? $item["snippet"] : array();
                $content = isset($item["contentDetails"]["relatedPlaylists"]) ? $item["contentDetails"]["relatedPlaylists"] : array();
                $statistics = isset($item["statistics"]) && is_array($item["statistics"]) ? $item["statistics"] : array();
                $thumbnail = "";
                if (isset($snippet["thumbnails"]) && is_array($snippet["thumbnails"])) {
                    foreach (array("high", "medium", "default") as $size) {
                        if (isset($snippet["thumbnails"][$size]["url"])) {
                            $thumbnail = $snippet["thumbnails"][$size]["url"];
                            break;
                        }
                    }
                }
                $existing = $this->first("ytg_channels", array(array("column" => "channelId", "operator" => "=", "value" => $channelId)));
                $channel = (object)array(
                    "channelId" => $channelId,
                    "ownerProfileId" => $existing !== null && isset($existing->ownerProfileId) ? $existing->ownerProfileId : $profile->id,
                    "youtubeChannelId" => $youtubeChannelId,
                    "title" => isset($snippet["title"]) ? $snippet["title"] : $youtubeChannelId,
                    "handle" => isset($snippet["customUrl"]) ? $snippet["customUrl"] : "",
                    "description" => isset($snippet["description"]) ? $snippet["description"] : "",
                    "uploadsPlaylistId" => isset($content["uploads"]) ? $content["uploads"] : "",
                    "thumbnailUrl" => $thumbnail,
                    "timezone" => $existing !== null && isset($existing->timezone) ? $existing->timezone : "UTC",
                    "defaultLanguage" => isset($snippet["defaultLanguage"]) ? $snippet["defaultLanguage"] : ($existing !== null && isset($existing->defaultLanguage) ? $existing->defaultLanguage : "English"),
                    "scopes" => isset($tokenResponse->data["scope"]) ? preg_split('/\s+/', trim($tokenResponse->data["scope"])) : \YtgConfig::scopes(),
                    "status" => "Connected",
                    "connectionHealth" => "Connected",
                    "subscriberCount" => isset($statistics["subscriberCount"]) ? intval($statistics["subscriberCount"]) : 0,
                    "viewCount" => isset($statistics["viewCount"]) ? intval($statistics["viewCount"]) : 0,
                    "videoCount" => isset($statistics["videoCount"]) ? intval($statistics["videoCount"]) : 0,
                    "lastAuthorizationVerifiedAt" => $this->now(),
                    "createdAt" => $existing !== null && isset($existing->createdAt) ? $existing->createdAt : $this->now(),
                    "updatedAt" => $this->now()
                );
                $saveChannel = $this->upsert("ytg_channels", array(array("column" => "channelId", "operator" => "=", "value" => $channelId)), $channel);
                if (!$saveChannel->success) {
                    $google->deleteCredential($credentialRef);
                    continue;
                }

                $this->upsert("ytg_channel_access", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "profileId", "operator" => "=", "value" => $profile->id)
                ), (object)array(
                    "channelId" => $channelId,
                    "profileId" => $profile->id,
                    "role" => "Owner",
                    "status" => "Active",
                    "createdAt" => $this->now(),
                    "updatedAt" => $this->now()
                ));

                $expiresAt = isset($tokenResponse->data["expires_at"]) ? date("Y-m-d H:i:s", intval($tokenResponse->data["expires_at"])) : null;
                $oldGrant = $this->first("ytg_oauth_grants", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "profileId", "operator" => "=", "value" => $profile->id)
                ));
                if ($oldGrant !== null && isset($oldGrant->credentialRef) && $oldGrant->credentialRef !== $credentialRef) {
                    $google->deleteCredential($oldGrant->credentialRef);
                }
                $this->upsert("ytg_oauth_grants", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "profileId", "operator" => "=", "value" => $profile->id)
                ), (object)array(
                    "channelId" => $channelId,
                    "profileId" => $profile->id,
                    "credentialRef" => $credentialRef,
                    "scopes" => $channel->scopes,
                    "expiresAt" => $expiresAt,
                    "lastVerifiedAt" => $this->now(),
                    "status" => "Connected",
                    "createdAt" => $oldGrant !== null && isset($oldGrant->createdAt) ? $oldGrant->createdAt : $this->now(),
                    "updatedAt" => $this->now()
                ));

                $this->audit("OAUTH_CONNECTED", $channelId, "youtube-channel:" . $youtubeChannelId, null, array("scopes" => $channel->scopes));
                $this->queueSchedule($channelId, "RunInitialSync", date("Y-m-d H:i:s", time() + 60));
                $connected[] = $channel->title;
                // One OAuth grant maps to one isolated channel workspace. Connect
                // another Brand Account through a separate consent flow.
                break;
            }

            if (!count($connected)) {
                $this->callbackHtml(false, "Authorization succeeded, but no channel workspace could be stored.");
            }
            $this->callbackHtml(true, "YouTube channel workspace connected: " . implode(", ", $connected));
        } catch (\Throwable $error) {
            $this->callbackHtml(false, "Secure OAuth completion failed: " . $error->getMessage());
        }
    }

    public function postDisconnectChannel($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner"));
        if ($channel === null) {
            return null;
        }
        $profile = $this->currentProfile();
        $grant = $this->grantForProfile($channel->channelId, $profile->id);
        if ($grant === null) {
            return $this->fail($res, "No active OAuth grant was found for this owner.");
        }
        try {
            $google = new \YtgGoogleClient();
            $revocation = $google->revoke($grant->credentialRef);
            $google->deleteCredential($grant->credentialRef);
            $grant->status = "Disconnected";
            $grant->updatedAt = $this->now();
            \SOSSData::Update("ytg_oauth_grants", $grant);
            $channel->status = "Disconnected";
            $channel->connectionHealth = $revocation->success ? "Disconnected" : "Disconnected Locally";
            $channel->updatedAt = $this->now();
            unset($channel->_accessRole);
            \SOSSData::Update("ytg_channels", $channel);
            $this->audit("CHANNEL_DISCONNECTED", $channel->channelId, "youtube-channel:" . $channel->youtubeChannelId, null, array("revokedAtGoogle" => $revocation->success));
            return (object)array(
                "channelId" => $channel->channelId,
                "status" => $channel->connectionHealth,
                "storedDataDeleted" => false,
                "message" => "The local OAuth credential was removed. Stored analytics remain until Delete My Stored YouTube Data is confirmed."
            );
        } catch (\Throwable $error) {
            return $this->fail($res, "Channel disconnect failed: " . $error->getMessage());
        }
    }

    public function postDeleteChannelData($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner"));
        if ($channel === null) {
            return null;
        }
        if (!isset($body->confirmation) || trim((string)$body->confirmation) !== "DELETE") {
            return $this->fail($res, "Type DELETE to confirm removal of locally stored YouTube data.");
        }
        $channelId = $channel->channelId;
        $grants = $this->query("ytg_oauth_grants", array(array("column" => "channelId", "operator" => "=", "value" => $channelId)), array(), 1000, 0);
        $google = null;
        try {
            $google = new \YtgGoogleClient();
        } catch (\Throwable $ignored) {
        }
        if ($grants->success) {
            foreach ($grants->result as $grant) {
                if ($google !== null && isset($grant->credentialRef)) {
                    $google->revoke($grant->credentialRef);
                    $google->deleteCredential($grant->credentialRef);
                }
            }
        }

        $this->audit("CHANNEL_DATA_DELETE_CONFIRMED", $channelId, "youtube-channel:" . $channel->youtubeChannelId, null, array("youtubeDataUnaffected" => true));
        $namespaces = array(
            "ytg_video_statistics", "ytg_analytics_daily", "ytg_reach_daily", "ytg_traffic_sources",
            "ytg_recommendations", "ytg_sync_jobs", "ytg_quota_usage", "ytg_agent_runs", "ytg_videos",
            "ytg_retention_points", "ytg_transcripts", "ytg_short_candidates", "ytg_content_ideas",
            "ytg_calendar_items", "ytg_competitors", "ytg_competitor_videos", "ytg_comments", "ytg_experiments",
            "ytg_oauth_grants", "ytg_channel_access"
        );
        $deleted = array();
        foreach ($namespaces as $namespace) {
            $deleted[$namespace] = $this->deleteByChannel($namespace, $channelId);
        }
        $channelRecord = $this->first("ytg_channels", array(array("column" => "channelId", "operator" => "=", "value" => $channelId)));
        if ($channelRecord !== null) {
            \SOSSData::Delete("ytg_channels", $channelRecord);
            $deleted["ytg_channels"] = 1;
        }
        $schedule = $this->first("schedule_pending", array(array("column" => "recid", "operator" => "=", "value" => "ytg-rundailysync-" . $channelId)));
        if ($schedule !== null) {
            \SOSSData::Delete("schedule_pending", $schedule);
        }
        return (object)array(
            "channelId" => $channelId,
            "deleted" => (object)$deleted,
            "completedAt" => $this->now(),
            "youtubeDataUnaffected" => true,
            "message" => "Locally stored YouTube workspace data was deleted. This does not delete data or videos on YouTube."
        );
    }

    private function grantForProfile($channelId, $profileId) {
        if ($this->isSysAdmin()) {
            return $this->credentialGrant($channelId);
        }
        return $this->first("ytg_oauth_grants", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "profileId", "operator" => "=", "value" => $profileId),
            array("column" => "status", "operator" => "=", "value" => "Connected")
        ));
    }

    private function completeCaptionAuthorization($stateData, $newToken, $profile, $google) {
        $channelId = isset($stateData["channelId"]) ? $this->channelId($stateData["channelId"]) : "";
        $channel = $channelId !== "" ? $this->channelAccess($channelId, array("Owner")) : null;
        if ($channel === null) { $this->callbackHtml(false, "The caption authorization channel is unavailable to this owner."); }
        $grant = $this->grantForProfile($channelId, $profile->id);
        if ($grant === null || !isset($grant->credentialRef)) { $this->callbackHtml(false, "The existing channel credential could not be found."); }

        $owned = \YtgHttpClient::request(
            "GET",
            "https://www.googleapis.com/youtube/v3/channels?part=id&mine=true&maxResults=50",
            $newToken["access_token"]
        );
        $ownsChannel = false;
        foreach ($owned->success && isset($owned->data["items"]) ? $owned->data["items"] : array() as $item) {
            if (isset($item["id"]) && (string)$item["id"] === (string)$channel->youtubeChannelId) { $ownsChannel = true; break; }
        }
        if (!$ownsChannel) { $this->callbackHtml(false, "Authorize the Google account that owns the selected YouTube channel."); }

        $old = $google->accessToken($grant->credentialRef);
        $storedToken = $old->success && is_array($old->token) ? $old->token : array();
        $mergedToken = array_merge($storedToken, $newToken);
        if (!isset($mergedToken["refresh_token"]) && isset($storedToken["refresh_token"])) { $mergedToken["refresh_token"] = $storedToken["refresh_token"]; }
        $mergedToken["expires_at"] = time() + max(60, intval(isset($newToken["expires_in"]) ? $newToken["expires_in"] : 3600));
        $google->storeCredential($grant->credentialRef, $mergedToken);

        $scopes = isset($newToken["scope"]) ? preg_split('/\s+/', trim((string)$newToken["scope"])) : $this->scopeValues(isset($grant->scopes) ? $grant->scopes : array());
        $scopes[] = "https://www.googleapis.com/auth/youtube.force-ssl";
        $scopes = array_values(array_unique(array_filter($scopes)));
        $grant->scopes = $scopes;
        $grant->expiresAt = date("Y-m-d H:i:s", $mergedToken["expires_at"]);
        $grant->lastVerifiedAt = $this->now();
        $grant->status = "Connected";
        $grant->updatedAt = $this->now();
        \SOSSData::Update("ytg_oauth_grants", $grant);

        $channel->scopes = $scopes;
        $channel->lastAuthorizationVerifiedAt = $this->now();
        $channel->updatedAt = $this->now();
        unset($channel->_accessRole);
        \SOSSData::Update("ytg_channels", $channel);
        $this->audit("CAPTION_SCOPE_GRANTED", $channelId, "youtube-caption-access", null, array("scope" => "https://www.googleapis.com/auth/youtube.force-ssl"));
        $this->callbackHtml(true, "Automatic timestamped caption downloads are enabled for " . $channel->title . ".");
    }

    private function scopeValues($value) {
        if (is_array($value)) { return $value; }
        if (is_object($value)) { return array_values((array)$value); }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : preg_split('/\s+/', trim((string)$value));
    }

    private function callbackHtml($success, $message) {
        $origin = $this->applicationOrigin();
        header("Content-Type: text/html; charset=utf-8");
        header("Cache-Control: no-store");
        $title = $success ? "YouTube connected" : "YouTube connection failed";
        echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . "</title></head>";
        echo "<body style=\"font-family:Arial,sans-serif;padding:28px;line-height:1.5;background:#f5f7fb;color:#172033\">";
        echo "<h2>" . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . "</h2><p>" . htmlspecialchars($message, ENT_QUOTES, "UTF-8") . "</p>";
        echo "<p>You may close this window and return to YouTube Growth Agent.</p>";
        echo "<script>if(window.opener){try{window.opener.postMessage({type:'ytg-oauth-complete',success:" . ($success ? "true" : "false") . "}," . json_encode($origin) . ");}catch(e){}}</script>";
        echo "</body></html>";
        exit();
    }

    private function applicationOrigin() {
        $scheme = isset($_SERVER["HTTP_X_FORWARDED_PROTO"]) ? explode(",", $_SERVER["HTTP_X_FORWARDED_PROTO"])[0] : ((isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http");
        $host = isset($_SERVER["HTTP_HOST"]) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER["HTTP_HOST"]) : "localhost";
        return trim($scheme) . "://" . $host;
    }
}

?>
