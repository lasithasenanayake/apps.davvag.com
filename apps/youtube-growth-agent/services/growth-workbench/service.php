<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}
if (!class_exists(__NAMESPACE__ . "\\YouTubeCaptionParser")) {
    require_once(__DIR__ . "/caption-parser.php");
}

class GrowthWorkbenchService extends \YtgServiceBase {
    public function postGetWorkbench($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) { return null; }
        $videoId = $this->optionalOwnedVideoId($res, $channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($videoId === false) { return null; }
        $videoConditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId));
        $videos = $this->query("ytg_videos", $videoConditions, array(array("column" => "publishedAt", "direction" => "DESC")), 100, 0);
        $grant = $this->credentialGrant($channel->channelId);
        $captionAuthorized = $this->hasCaptionScope($grant);
        $competitors = $this->rows("ytg_competitors", $channel->channelId, array(array("column" => "competitorId", "direction" => "DESC")), 100);
        $competitorVideos = $this->rows("ytg_competitor_videos", $channel->channelId, array(array("column" => "publishedAt", "direction" => "DESC")), 250);
        $competitorAnalysis = $this->buildCompetitorAnalysis($competitors, $competitorVideos, false);
        $result = (object)array(
            "channel" => $this->safeChannel($channel),
            "videos" => $videos->success ? $videos->result : array(),
            "selectedVideoId" => $videoId === false ? "" : $videoId,
            "transcript" => null,
            "retention" => array(),
            "shortCandidates" => array(),
            "comments" => array(),
            "competitors" => $competitors,
            "competitorVideos" => $competitorAnalysis->videos,
            "competitorAnalysis" => $competitorAnalysis,
            "contentIdeas" => $this->rows("ytg_content_ideas", $channel->channelId, array(array("column" => "ideaId", "direction" => "DESC")), 100),
            "calendarItems" => $this->rows("ytg_calendar_items", $channel->channelId, array(array("column" => "plannedAt", "direction" => "ASC")), 100),
            "experiments" => $this->rows("ytg_experiments", $channel->channelId, array(array("column" => "experimentId", "direction" => "DESC")), 100),
            "capabilities" => (object)array(
                "authorizedCaptionImport" => $captionAuthorized,
                "userTranscriptUpload" => true,
                "retentionAnalytics" => true,
                "channelWriteBack" => false,
                "captionScopeRequired" => "https://www.googleapis.com/auth/youtube.force-ssl",
                "captionQuotaPerImport" => 250,
                "automaticTranscriptImport" => $captionAuthorized,
                "derivedCompetitorMetrics" => \YtgConfig::derivedMetricsEnabled()
            )
        );
        if ($videoId !== "") {
            $transcript = $this->first("ytg_transcripts", array(
                array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
                array("column" => "videoId", "operator" => "=", "value" => $videoId)
            ), array(array("column" => "transcriptId", "direction" => "DESC")));
            if ($transcript !== null) {
                $transcript->segments = $this->decodeTranscriptSegments(isset($transcript->segments) ? $transcript->segments : array());
                if (isset($transcript->sourceType) && $transcript->sourceType === "YOUTUBE_CAPTION") {
                    $transcript->segments = YouTubeCaptionParser::normalizeSegments($transcript->segments);
                    if (count($transcript->segments)) { $transcript->plainText = YouTubeCaptionParser::plainText($transcript->segments); }
                }
                $result->transcript = $transcript;
            }
            $result->retention = $this->rowsForVideo("ytg_retention_points", $channel->channelId, $videoId, array(array("column" => "elapsedRatio", "direction" => "ASC")), 200);
            $shorts = $this->rowsForVideo("ytg_short_candidates", $channel->channelId, $videoId, array(array("column" => "candidateId", "direction" => "DESC")), 100);
            foreach ($shorts as $short) {
                $short->titleOptions = $this->decodeJson(isset($short->titleOptions) ? $short->titleOptions : array());
                $short->evidence = $this->decodeJson(isset($short->evidence) ? $short->evidence : array());
            }
            $result->shortCandidates = $shorts;
            $result->comments = $this->rowsForVideo("ytg_comments", $channel->channelId, $videoId, array(array("column" => "publishedAt", "direction" => "DESC")), 100);
        }
        return $result;
    }

    public function postImportTranscript($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $language = $this->language(isset($body->language) ? $body->language : (isset($channel->defaultLanguage) ? $channel->defaultLanguage : "English"));
        $plainText = isset($body->plainText) ? trim((string)$body->plainText) : "";
        if ($plainText === "" || strlen($plainText) > 250000) { return $this->fail($res, "Transcript text is required and must be 250,000 characters or fewer."); }
        $durationMs = max(1000, intval(isset($video->durationSeconds) ? $video->durationSeconds : 0) * 1000);
        $segments = $this->normalizeTranscriptSegments(isset($body->segments) ? $body->segments : array(), $plainText, $durationMs);
        if ($segments === null) { return $this->fail($res, "Transcript timestamps are invalid or exceed the video duration."); }
        $existing = $this->first("ytg_transcripts", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $video->youtubeVideoId),
            array("column" => "sourceType", "operator" => "=", "value" => "USER_UPLOAD")
        ));
        $row = $existing !== null ? $existing : new \stdClass();
        $row->channelId = $channel->channelId;
        $row->videoId = $video->youtubeVideoId;
        $row->language = $language;
        $row->sourceType = "USER_UPLOAD";
        $row->segments = $segments;
        $row->plainText = $plainText;
        $row->durationMs = $durationMs;
        $row->provenance = "Uploaded by DAVVAG profile " . $this->currentProfile()->id . "; no audiovisual content was downloaded.";
        $row->refreshedAt = $this->now();
        if ($existing === null) { $row->createdAt = $this->now(); }
        $save = $existing === null ? \SOSSData::Insert("ytg_transcripts", $row) : \SOSSData::Update("ytg_transcripts", $row);
        if (!$save->success) { return $this->fail($res, "Transcript could not be saved."); }
        $this->audit("TRANSCRIPT_IMPORTED", $channel->channelId, "video:" . $video->youtubeVideoId, null, array("source" => "USER_UPLOAD", "language" => $language, "segments" => count($segments)));
        $row->segments = $segments;
        return (object)array("transcript" => $row, "message" => "User-provided transcript saved with validated timestamps.");
    }

    public function postDownloadTranscript($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required."); }
        if (!$this->hasCaptionScope($grant)) { return $this->fail($res, "Enable automatic captions for this channel first. Google requires separate youtube.force-ssl consent."); }

        $preferredLanguage = isset($body->language) ? strtolower(trim((string)$body->language)) : "";
        if ($preferredLanguage !== "" && !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $preferredLanguage)) {
            return $this->fail($res, "Preferred caption language must be a valid language code such as en or si.");
        }
        $quota = $this->consumeQuota($channel->channelId, "captions.list", 50);
        if (!$quota->success) { return $this->fail($res, $quota->message); }

        $google = new \YtgGoogleClient();
        $list = $google->dataGet($grant->credentialRef, "captions", array(
            "part" => "id,snippet",
            "videoId" => $video->youtubeVideoId
        ));
        if (!$list->success) {
            return $this->fail($res, $list->status === 403 ? "YouTube denied caption access. Re-enable automatic captions as the video owner." : ($list->error !== "" ? $list->error : "Caption tracks could not be listed."));
        }
        $track = $this->selectCaptionTrack(isset($list->data["items"]) ? $list->data["items"] : array(), $preferredLanguage);
        if ($track === null) {
            return (object)array("transcript" => null, "message" => "No downloadable caption track is available for this video. You can still paste a transcript manually.");
        }

        $quota = $this->consumeQuota($channel->channelId, "captions.download", 200);
        if (!$quota->success) { return $this->fail($res, $quota->message); }
        $token = $google->accessToken($grant->credentialRef);
        if (!$token->success) { return $this->fail($res, $token->error !== "" ? $token->error : "YouTube authorization could not be refreshed."); }
        $url = "https://www.googleapis.com/youtube/v3/captions/" . rawurlencode($track->id) . "?tfmt=vtt";
        $download = \YtgHttpClient::request("GET", $url, $token->accessToken, null, false, true);
        if (!$download->success) {
            return $this->fail($res, $download->status === 403 ? "YouTube did not allow this caption track to be downloaded. The connected account must be able to edit the video." : ($download->error !== "" ? $download->error : "The caption track could not be downloaded."));
        }

        $durationMs = max(1000, intval(isset($video->durationSeconds) ? $video->durationSeconds : 0) * 1000);
        $parsed = YouTubeCaptionParser::parseVtt($download->text, $durationMs);
        if (!$parsed->success) { return $this->fail($res, $parsed->error); }

        $oldRows = $this->query("ytg_transcripts", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $video->youtubeVideoId),
            array("column" => "sourceType", "operator" => "=", "value" => "YOUTUBE_CAPTION")
        ), array(), 100, 0);
        if ($oldRows->success) { foreach ($oldRows->result as $oldRow) { \SOSSData::Delete("ytg_transcripts", $oldRow); } }

        $row = (object)array(
            "channelId" => $channel->channelId,
            "videoId" => $video->youtubeVideoId,
            "language" => $this->language($track->language),
            "sourceType" => "YOUTUBE_CAPTION",
            "segments" => $parsed->segments,
            "plainText" => $parsed->plainText,
            "durationMs" => $durationMs,
            "provenance" => substr("YouTube captions.download; track=" . $track->id . "; language=" . $track->language . "; kind=" . $track->trackKind . "; autoGenerated=" . ($track->autoGenerated ? "yes" : "no"), 0, 500),
            "refreshedAt" => $this->now(),
            "createdAt" => $this->now()
        );
        $save = \SOSSData::Insert("ytg_transcripts", $row);
        if (!$save->success) { return $this->fail($res, "The timestamped caption transcript could not be saved."); }
        $this->audit("YOUTUBE_CAPTION_IMPORTED", $channel->channelId, "video:" . $video->youtubeVideoId, null, array("language" => $track->language, "trackKind" => $track->trackKind, "segments" => count($parsed->segments)));
        return (object)array(
            "transcript" => $row,
            "captionTrack" => (object)array("language" => $track->language, "name" => $track->name, "trackKind" => $track->trackKind, "autoGenerated" => $track->autoGenerated),
            "quotaUnits" => 250,
            "message" => count($parsed->segments) . " timestamped caption segments downloaded from YouTube."
        );
    }

    public function postSyncRetention($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required."); }
        $end = date("Y-m-d", strtotime("yesterday"));
        $start = date("Y-m-d", strtotime($end . " -364 days"));
        $google = new \YtgGoogleClient();
        $response = $google->analytics($grant->credentialRef, array(
            "ids" => "channel==MINE", "startDate" => $start, "endDate" => $end,
            "metrics" => "audienceWatchRatio,relativeRetentionPerformance,startedWatching,stoppedWatching",
            "dimensions" => "elapsedVideoTimeRatio", "filters" => "video==" . $video->youtubeVideoId,
            "sort" => "elapsedVideoTimeRatio", "maxResults" => 200
        ));
        if (!$response->success) { return $this->fail($res, $response->error !== "" ? $response->error : "Retention report could not be loaded."); }
        $rows = $this->analyticsRows($response->data);
        if (!count($rows)) { return (object)array("points" => array(), "message" => "YouTube returned no retention points for this video and date window."); }
        $this->deleteRowsForVideo("ytg_retention_points", $channel->channelId, $video->youtubeVideoId);
        $points = array();
        foreach ($rows as $item) {
            $ratio = isset($item["elapsedVideoTimeRatio"]) ? floatval($item["elapsedVideoTimeRatio"]) : -1;
            if ($ratio < 0 || $ratio > 1.01) { continue; }
            $point = (object)array(
                "channelId" => $channel->channelId, "videoId" => $video->youtubeVideoId,
                "elapsedRatio" => $ratio, "elapsedSeconds" => $ratio * intval($video->durationSeconds),
                "audienceWatchRatio" => $this->number($item, "audienceWatchRatio"),
                "relativeRetention" => $this->number($item, "relativeRetentionPerformance"),
                "startedWatching" => intval($this->number($item, "startedWatching")),
                "stoppedWatching" => intval($this->number($item, "stoppedWatching")),
                "source" => "YOUTUBE_ANALYTICS", "sourceDateStart" => $start, "sourceDateEnd" => $end, "refreshedAt" => $this->now()
            );
            if (\SOSSData::Insert("ytg_retention_points", $point)->success) { $points[] = $point; }
        }
        $this->audit("RETENTION_SYNCED", $channel->channelId, "video:" . $video->youtubeVideoId, null, array("points" => count($points), "dateRange" => $start . "/" . $end));
        return (object)array("points" => $points, "source" => "YOUTUBE_ANALYTICS", "dateRange" => $start . "/" . $end, "message" => count($points) . " official retention points imported.");
    }

    public function postSyncComments($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required."); }
        $quota = $this->consumeQuota($channel->channelId, "commentThreads.list", 1);
        if (!$quota->success) { return $this->fail($res, $quota->message); }
        $google = new \YtgGoogleClient();
        $response = $google->dataGet($grant->credentialRef, "commentThreads", array("part" => "snippet", "videoId" => $video->youtubeVideoId, "order" => "relevance", "textFormat" => "plainText", "maxResults" => 100));
        if (!$response->success) { return $this->fail($res, $response->error !== "" ? $response->error : "Comments could not be loaded."); }
        $count = 0;
        foreach (isset($response->data["items"]) && is_array($response->data["items"]) ? $response->data["items"] : array() as $item) {
            $snippet = isset($item["snippet"]["topLevelComment"]["snippet"]) ? $item["snippet"]["topLevelComment"]["snippet"] : array();
            $commentId = isset($item["snippet"]["topLevelComment"]["id"]) ? (string)$item["snippet"]["topLevelComment"]["id"] : "";
            if ($commentId === "" || !isset($snippet["textDisplay"])) { continue; }
            $save = $this->upsert("ytg_comments", array(
                array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
                array("column" => "youtubeCommentId", "operator" => "=", "value" => $commentId)
            ), (object)array(
                "channelId" => $channel->channelId, "videoId" => $video->youtubeVideoId, "youtubeCommentId" => $commentId,
                "text" => substr((string)$snippet["textDisplay"], 0, 10000),
                "publishedAt" => isset($snippet["publishedAt"]) ? date("Y-m-d H:i:s", strtotime($snippet["publishedAt"])) : null,
                "likeCount" => intval(isset($snippet["likeCount"]) ? $snippet["likeCount"] : 0),
                "replyCount" => intval(isset($item["snippet"]["totalReplyCount"]) ? $item["snippet"]["totalReplyCount"] : 0),
                "source" => "YOUTUBE_DATA", "refreshedAt" => $this->now()
            ));
            if ($save->success) { $count++; }
        }
        return (object)array("comments" => $this->rowsForVideo("ytg_comments", $channel->channelId, $video->youtubeVideoId, array(array("column" => "publishedAt", "direction" => "DESC")), 100), "message" => $count . " comments refreshed for insight analysis.");
    }

    public function postAddCompetitor($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $referenceValue = isset($body->competitorReference) ? $body->competitorReference : (isset($body->youtubeChannelId) ? $body->youtubeChannelId : "");
        $reference = $this->parseCompetitorReference($referenceValue);
        if ($reference === null) { return $this->fail($res, "Enter a YouTube channel URL, @handle, or UC channel ID. Custom /c/ URLs cannot be resolved by the documented API; use the channel's @handle or /channel/ URL."); }
        $youtubeChannelId = $reference->type === "id" ? $reference->value : "";
        if ($youtubeChannelId === "") {
            $grant = $this->credentialGrant($channel->channelId);
            if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required to resolve a channel handle or username."); }
            $quota = $this->consumeQuota($channel->channelId, "resolve competitor channel URL", 1);
            if (!$quota->success) { return $this->fail($res, isset($quota->message) ? $quota->message : "YouTube quota is unavailable."); }
            $google = new \YtgGoogleClient();
            $resolved = $this->resolveCompetitorReference($google, $grant->credentialRef, $reference);
            if ($resolved === null) { return $this->fail($res, "The public YouTube channel could not be resolved from that URL or handle."); }
            $youtubeChannelId = $resolved;
        }
        if ($youtubeChannelId === "" || $youtubeChannelId === (string)$channel->youtubeChannelId) { return $this->fail($res, "Enter a valid public comparison channel ID different from the connected channel."); }
        $existing = $this->first("ytg_competitors", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "competitorYoutubeChannelId", "operator" => "=", "value" => $youtubeChannelId)
        ));
        if ($existing !== null) { return (object)array("competitor" => $existing, "message" => "Comparison channel is already in this workspace."); }
        $row = (object)array("channelId" => $channel->channelId, "competitorYoutubeChannelId" => $youtubeChannelId, "sourceUrl" => substr(trim((string)$referenceValue), 0, 1000), "label" => substr(trim(isset($body->label) && trim((string)$body->label) !== "" ? (string)$body->label : $youtubeChannelId), 0, 255), "active" => true, "createdAt" => $this->now());
        $save = \SOSSData::Insert("ytg_competitors", $row);
        if (!$save->success) { return $this->fail($res, "Comparison channel could not be saved."); }
        $row->competitorId = $this->generatedId($save);
        $this->audit("COMPETITOR_ADDED", $channel->channelId, "competitor:" . $youtubeChannelId, null, array("label" => $row->label));
        return (object)array("competitor" => $row, "message" => "Comparison channel added. Refresh public metadata to rank its latest videos.");
    }

    public function postRefreshCompetitors($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required."); }
        $competitorId = isset($body->competitorId) ? intval($body->competitorId) : 0;
        $conditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "active", "operator" => "=", "value" => true));
        if ($competitorId > 0) { $conditions[] = array("column" => "competitorId", "operator" => "=", "value" => $competitorId); }
        $query = $this->query("ytg_competitors", $conditions, array(), 25, 0);
        $google = new \YtgGoogleClient();
        $refreshed = 0;
        foreach ($query->success ? $query->result : array() as $competitor) {
            $quota = $this->consumeQuota($channel->channelId, "competitor public metadata", 4);
            if (!$quota->success) { break; }
            $channelResponse = $google->dataGet($grant->credentialRef, "channels", array("part" => "snippet,contentDetails,statistics", "id" => $competitor->competitorYoutubeChannelId, "maxResults" => 1));
            if (!$channelResponse->success || empty($channelResponse->data["items"])) { continue; }
            $source = $channelResponse->data["items"][0];
            $snippet = isset($source["snippet"]) ? $source["snippet"] : array();
            $statistics = isset($source["statistics"]) ? $source["statistics"] : array();
            $competitor->label = isset($snippet["title"]) ? substr((string)$snippet["title"], 0, 255) : $competitor->label;
            $competitor->thumbnailUrl = isset($snippet["thumbnails"]["medium"]["url"]) ? $snippet["thumbnails"]["medium"]["url"] : "";
            $competitor->uploadsPlaylistId = isset($source["contentDetails"]["relatedPlaylists"]["uploads"]) ? $source["contentDetails"]["relatedPlaylists"]["uploads"] : "";
            $competitor->subscriberCount = intval(isset($statistics["subscriberCount"]) ? $statistics["subscriberCount"] : 0);
            $competitor->subscriberCountHidden = !empty($statistics["hiddenSubscriberCount"]);
            $competitor->videoCount = intval(isset($statistics["videoCount"]) ? $statistics["videoCount"] : 0);
            $competitor->viewCount = intval(isset($statistics["viewCount"]) ? $statistics["viewCount"] : 0);
            $competitor->refreshedAt = $this->now();
            \SOSSData::Update("ytg_competitors", $competitor);
            if ($competitor->uploadsPlaylistId !== "") { $this->refreshCompetitorVideos($google, $grant->credentialRef, $channel->channelId, $competitor); }
            $refreshed++;
        }
        $competitors = $this->rows("ytg_competitors", $channel->channelId, array(array("column" => "competitorId", "direction" => "DESC")), 100);
        $videos = $this->rows("ytg_competitor_videos", $channel->channelId, array(array("column" => "publishedAt", "direction" => "DESC")), 250);
        $analysis = $this->buildCompetitorAnalysis($competitors, $videos, true);
        return (object)array("refreshed" => $refreshed, "competitors" => $competitors, "competitorVideos" => $analysis->videos, "competitorAnalysis" => $analysis, "message" => $refreshed . " public comparison channels refreshed and their latest videos ranked without search.list.");
    }

    public function postGetCompetitorVideoWorkspace($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        return $this->competitorVideoWorkspace($channel, $video);
    }

    public function postAddCompetitorVideos($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $references = isset($body->urls) ? $body->urls : array();
        if (is_string($references)) { $references = preg_split('/[\r\n,]+/', $references); }
        if (!is_array($references)) { return $this->fail($res, "Enter one or more YouTube video URLs."); }
        $byId = array(); $results = array();
        foreach (array_slice($references, 0, 20) as $value) {
            $sourceUrl = trim((string)$value);
            $parsed = $this->parseCompetitorVideoReference($sourceUrl);
            if ($parsed === null) { $results[] = (object)array("sourceUrl" => substr($sourceUrl, 0, 1000), "status" => "INVALID", "message" => "Use a YouTube watch, Shorts, or youtu.be URL."); continue; }
            if (!isset($byId[$parsed->videoId])) { $byId[$parsed->videoId] = $parsed->sourceUrl; }
        }
        if (!count($byId)) { return (object)array("workspace" => $this->competitorVideoWorkspace($channel, $video), "results" => $results, "message" => "No valid competitor video URLs were supplied."); }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) { return $this->fail($res, "Connected YouTube credentials are required to retrieve public video metadata."); }
        $quota = $this->consumeQuota($channel->channelId, "competitor video public metadata", 1);
        if (!$quota->success) { return $this->fail($res, isset($quota->message) ? $quota->message : "YouTube quota is unavailable."); }
        $google = new \YtgGoogleClient();
        $response = $google->dataGet($grant->credentialRef, "videos", array("part" => "snippet,contentDetails,statistics,status", "id" => implode(",", array_keys($byId)), "maxResults" => 50));
        if (!$response->success) { return $this->fail($res, isset($response->message) ? $response->message : "Competitor video metadata could not be retrieved."); }
        $returned = array(); $saved = 0;
        foreach (isset($response->data["items"]) && is_array($response->data["items"]) ? $response->data["items"] : array() as $item) {
            $id = isset($item["id"]) ? $this->youtubeId($item["id"]) : "";
            if ($id === "" || !isset($byId[$id])) { continue; }
            $returned[$id] = true;
            $snippet = isset($item["snippet"]) && is_array($item["snippet"]) ? $item["snippet"] : array();
            $details = isset($item["contentDetails"]) && is_array($item["contentDetails"]) ? $item["contentDetails"] : array();
            $statistics = isset($item["statistics"]) && is_array($item["statistics"]) ? $item["statistics"] : array();
            $sourceChannelId = isset($snippet["channelId"]) ? (string)$snippet["channelId"] : "";
            if ($sourceChannelId !== "" && $sourceChannelId === (string)$channel->youtubeChannelId) { $results[] = (object)array("sourceUrl" => $byId[$id], "videoId" => $id, "status" => "REJECTED", "message" => "The URL belongs to the connected channel, not a competitor."); continue; }
            $description = isset($snippet["description"]) ? substr((string)$snippet["description"], 0, 10000) : "";
            $durationSeconds = $this->isoDurationSeconds(isset($details["duration"]) ? $details["duration"] : "PT0S");
            $existing = $this->first("ytg_competitor_videos", array(
                array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
                array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId),
                array("column" => "youtubeVideoId", "operator" => "=", "value" => $id)
            ));
            $row = $existing !== null ? $existing : new \stdClass();
            $row->channelId = $channel->channelId; $row->contextVideoId = $video->youtubeVideoId; $row->competitorId = 0;
            $row->sourceUrl = substr($byId[$id], 0, 1000); $row->youtubeVideoId = $id; $row->sourceChannelId = substr($sourceChannelId, 0, 90);
            $row->channelTitle = substr(isset($snippet["channelTitle"]) ? (string)$snippet["channelTitle"] : "", 0, 255);
            $row->title = substr(isset($snippet["title"]) ? (string)$snippet["title"] : "", 0, 500); $row->description = $description;
            $row->tags = isset($snippet["tags"]) && is_array($snippet["tags"]) ? array_slice($snippet["tags"], 0, 100) : array();
            $row->hashtags = $this->extractHashtags($row->title . "\n" . $description);
            $row->publishedAt = isset($snippet["publishedAt"]) ? date("Y-m-d H:i:s", strtotime($snippet["publishedAt"])) : null;
            $row->thumbnailUrl = isset($snippet["thumbnails"]["medium"]["url"]) ? (string)$snippet["thumbnails"]["medium"]["url"] : "";
            $row->durationSeconds = $durationSeconds; $row->contentType = $durationSeconds > 0 && $durationSeconds <= 180 && $this->hasShortsMarker($snippet) ? "Short" : "Long-form";
            $row->categoryId = substr(isset($snippet["categoryId"]) ? (string)$snippet["categoryId"] : "", 0, 30);
            $row->defaultLanguage = substr(isset($snippet["defaultLanguage"]) ? (string)$snippet["defaultLanguage"] : "", 0, 30);
            $row->defaultAudioLanguage = substr(isset($snippet["defaultAudioLanguage"]) ? (string)$snippet["defaultAudioLanguage"] : "", 0, 30);
            $row->views = intval(isset($statistics["viewCount"]) ? $statistics["viewCount"] : 0); $row->likes = intval(isset($statistics["likeCount"]) ? $statistics["likeCount"] : 0); $row->comments = intval(isset($statistics["commentCount"]) ? $statistics["commentCount"] : 0);
            $row->source = "YOUTUBE_DATA"; $row->retrievalStatus = "READY"; $row->calculationSource = ""; $row->refreshedAt = $this->now();
            if ($existing === null) { $row->createdAt = $this->now(); }
            $save = $existing === null ? \SOSSData::Insert("ytg_competitor_videos", $row) : \SOSSData::Update("ytg_competitor_videos", $row);
            if ($save->success) { $saved++; $row->competitorVideoId = $existing !== null ? $existing->competitorVideoId : $this->generatedId($save); $results[] = (object)array("sourceUrl" => $byId[$id], "videoId" => $id, "status" => "READY", "message" => "Public metadata retrieved."); }
            else { $results[] = (object)array("sourceUrl" => $byId[$id], "videoId" => $id, "status" => "ERROR", "message" => "Metadata could not be saved."); }
        }
        foreach ($byId as $id => $sourceUrl) { if (!isset($returned[$id])) { $results[] = (object)array("sourceUrl" => $sourceUrl, "videoId" => $id, "status" => "NOT_FOUND", "message" => "The video was unavailable, private, or not found."); } }
        $this->rankContextCompetitorVideos($channel->channelId, $video->youtubeVideoId, true);
        if ($saved > 0) { $this->clearContextResearch($channel->channelId, $video->youtubeVideoId); }
        $this->audit("COMPETITOR_VIDEOS_ADDED", $channel->channelId, "video:" . $video->youtubeVideoId, null, array("saved" => $saved, "requested" => count($byId)));
        return (object)array("workspace" => $this->competitorVideoWorkspace($channel, $video), "results" => $results, "message" => $saved . " competitor video" . ($saved === 1 ? "" : "s") . " added or refreshed.");
    }

    public function postImportCompetitorTranscript($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $competitorVideoId = $this->youtubeId(isset($body->competitorVideoId) ? $body->competitorVideoId : "");
        $competitorVideo = $this->first("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $competitorVideoId)));
        if ($competitorVideo === null) { return $this->fail($res, "Add the competitor video URL before importing its transcript."); }
        $rightsConfirmed = isset($body->rightsConfirmed) && filter_var($body->rightsConfirmed, FILTER_VALIDATE_BOOLEAN);
        if (!$rightsConfirmed) { return $this->fail($res, "Confirm that you have the rights or permission to analyse this transcript."); }
        $plainText = isset($body->plainText) ? trim((string)$body->plainText) : "";
        if ($plainText === "" || strlen($plainText) > 250000) { return $this->fail($res, "Transcript text is required and must be 250,000 characters or fewer."); }
        $sourceType = strtoupper(trim(isset($body->sourceType) ? (string)$body->sourceType : "MANUAL_TEXT"));
        if (!in_array($sourceType, array("MANUAL_TEXT", "USER_UPLOAD", "LICENSED_PROVIDER"), true)) { return $this->fail($res, "Transcript provenance is invalid."); }
        $language = $this->language(isset($body->language) ? $body->language : "");
        $durationMs = max(1000, intval(isset($competitorVideo->durationSeconds) ? $competitorVideo->durationSeconds : 0) * 1000);
        $segments = $this->normalizeTranscriptSegments(isset($body->segments) ? $body->segments : array(), $plainText, $durationMs);
        if ($segments === null) { return $this->fail($res, "Transcript timestamps are invalid or exceed the competitor video duration."); }
        $existing = $this->first("ytg_competitor_transcripts", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId), array("column" => "competitorVideoId", "operator" => "=", "value" => $competitorVideoId)));
        $row = $existing !== null ? $existing : new \stdClass();
        $row->channelId = $channel->channelId; $row->contextVideoId = $video->youtubeVideoId; $row->competitorVideoId = $competitorVideoId; $row->language = $language; $row->sourceType = $sourceType;
        $row->segments = $segments; $row->plainText = $plainText; $row->durationMs = $durationMs; $row->rightsConfirmedBy = intval($this->currentProfile()->id); $row->rightsConfirmedAt = $this->now(); $row->provenance = "Rights-confirmed transcript supplied by DAVVAG profile " . $this->currentProfile()->id . "; it was not downloaded or scraped from YouTube."; $row->importedAt = $this->now();
        $save = $existing === null ? \SOSSData::Insert("ytg_competitor_transcripts", $row) : \SOSSData::Update("ytg_competitor_transcripts", $row);
        if (!$save->success) { return $this->fail($res, "Competitor transcript could not be saved."); }
        $this->clearContextResearch($channel->channelId, $video->youtubeVideoId);
        $this->audit("COMPETITOR_TRANSCRIPT_IMPORTED", $channel->channelId, "competitor-video:" . $competitorVideoId, null, array("source" => $sourceType, "language" => $language));
        return (object)array("workspace" => $this->competitorVideoWorkspace($channel, $video), "message" => "Rights-confirmed competitor transcript saved. No YouTube caption track was downloaded.");
    }

    public function postRemoveCompetitorVideo($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $competitorVideoId = $this->youtubeId(isset($body->competitorVideoId) ? $body->competitorVideoId : "");
        $conditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId), array("column" => "competitorVideoId", "operator" => "=", "value" => $competitorVideoId));
        $transcripts = $this->query("ytg_competitor_transcripts", $conditions, array(), 20, 0);
        foreach ($transcripts->success ? $transcripts->result : array() as $transcript) { \SOSSData::Delete("ytg_competitor_transcripts", $transcript); }
        $row = $this->first("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $competitorVideoId)));
        if ($row === null) { return $this->fail($res, "Competitor video was not found in this workspace."); }
        \SOSSData::Delete("ytg_competitor_videos", $row);
        $this->clearContextResearch($channel->channelId, $video->youtubeVideoId);
        $this->audit("COMPETITOR_VIDEO_REMOVED", $channel->channelId, "competitor-video:" . $competitorVideoId, null, array("contextVideoId" => $video->youtubeVideoId));
        return (object)array("workspace" => $this->competitorVideoWorkspace($channel, $video), "message" => "Competitor video and its stored transcript were removed from this workspace.");
    }

    public function postSaveCalendarItem($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $title = substr(trim(isset($body->title) ? (string)$body->title : ""), 0, 500);
        $format = strtoupper(trim(isset($body->format) ? (string)$body->format : "VIDEO"));
        $plannedAt = $this->dateTime(isset($body->plannedAt) ? $body->plannedAt : "");
        if ($title === "" || !in_array($format, array("VIDEO", "SHORT", "LIVE", "COMMUNITY"), true) || $plannedAt === "") { return $this->fail($res, "Title, valid format, and planned date are required."); }
        $row = (object)array("channelId" => $channel->channelId, "ideaId" => max(0, intval(isset($body->ideaId) ? $body->ideaId : 0)), "title" => $title, "format" => $format, "plannedAt" => $plannedAt, "timezone" => isset($channel->timezone) ? $channel->timezone : "UTC", "notes" => substr(trim(isset($body->notes) ? (string)$body->notes : ""), 0, 5000), "status" => "Planned", "createdAt" => $this->now(), "updatedAt" => $this->now());
        $save = \SOSSData::Insert("ytg_calendar_items", $row);
        if (!$save->success) { return $this->fail($res, "Calendar item could not be saved."); }
        $row->calendarItemId = $this->generatedId($save);
        return (object)array("item" => $row, "message" => "Content calendar item saved in " . $row->timezone . ".");
    }

    public function postCreateExperiment($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $video = $this->ownedVideo($channel->channelId, isset($body->videoId) ? $body->videoId : "");
        if ($video === null) { return $this->fail($res, "A video in this channel workspace is required."); }
        $type = strtoupper(trim(isset($body->type) ? (string)$body->type : "PACKAGING"));
        $name = substr(trim(isset($body->name) ? (string)$body->name : ""), 0, 500);
        $hypothesis = substr(trim(isset($body->hypothesis) ? (string)$body->hypothesis : ""), 0, 5000);
        $metric = substr(trim(isset($body->primaryMetric) ? (string)$body->primaryMetric : "thumbnailImpressionsCtr"), 0, 160);
        $variants = $this->listValue(isset($body->variants) ? $body->variants : array(), 10, 1000);
        if ($name === "" || $hypothesis === "" || count($variants) < 2 || !in_array($type, array("PACKAGING", "TITLE", "THUMBNAIL", "HOOK", "PLAYLIST", "TIMING"), true)) { return $this->fail($res, "Experiment name, hypothesis, type, and at least two variants are required."); }
        $row = (object)array("channelId" => $channel->channelId, "videoId" => $video->youtubeVideoId, "type" => $type, "name" => $name, "hypothesis" => $hypothesis, "variants" => $variants, "primaryMetric" => $metric, "startAt" => null, "endAt" => null, "metrics" => array(), "result" => "", "limitations" => "Native YouTube A/B execution and comparable sample availability must be verified in YouTube Studio.", "status" => "Draft", "createdAt" => $this->now(), "updatedAt" => $this->now());
        $save = \SOSSData::Insert("ytg_experiments", $row);
        if (!$save->success) { return $this->fail($res, "Experiment could not be created."); }
        $row->experimentId = $this->generatedId($save);
        $this->audit("EXPERIMENT_CREATED", $channel->channelId, "experiment:" . $row->experimentId, null, array("type" => $type, "videoId" => $video->youtubeVideoId));
        $row->variants = $variants;
        return (object)array("experiment" => $row, "message" => "Draft experiment created. Launch it manually in YouTube Studio when native testing is available.");
    }

    public function postUpdateExperiment($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) { return null; }
        $id = max(0, intval(isset($body->experimentId) ? $body->experimentId : 0));
        $row = $this->first("ytg_experiments", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "experimentId", "operator" => "=", "value" => $id)));
        if ($row === null) { return $this->fail($res, "Experiment was not found in this channel workspace."); }
        $status = ucfirst(strtolower(trim(isset($body->status) ? (string)$body->status : "")));
        if (!in_array($status, array("Draft", "Running", "Completed", "Cancelled"), true)) { return $this->fail($res, "Invalid experiment status."); }
        $before = clone $row;
        $row->status = $status;
        $row->result = substr(trim(isset($body->result) ? (string)$body->result : (isset($row->result) ? $row->result : "")), 0, 10000);
        $row->limitations = substr(trim(isset($body->limitations) ? (string)$body->limitations : (isset($row->limitations) ? $row->limitations : "")), 0, 5000);
        if ($status === "Running" && empty($row->startAt)) { $row->startAt = $this->now(); }
        if ($status === "Completed") { $row->endAt = $this->now(); }
        $row->updatedAt = $this->now();
        if (!\SOSSData::Update("ytg_experiments", $row)->success) { return $this->fail($res, "Experiment could not be updated."); }
        $this->audit("EXPERIMENT_STATUS", $channel->channelId, "experiment:" . $id, $before, $row);
        return (object)array("experiment" => $row, "message" => "Experiment updated with its limitations preserved.");
    }

    private function refreshCompetitorVideos($google, $credentialRef, $channelId, $competitor) {
        $playlist = $google->dataGet($credentialRef, "playlistItems", array("part" => "snippet,contentDetails", "playlistId" => $competitor->uploadsPlaylistId, "maxResults" => 20));
        if (!$playlist->success || empty($playlist->data["items"])) { return; }
        $ids = array();
        foreach ($playlist->data["items"] as $item) {
            $id = isset($item["contentDetails"]["videoId"]) ? $this->youtubeId($item["contentDetails"]["videoId"]) : "";
            if ($id !== "") { $ids[] = $id; }
        }
        if (!count($ids)) { return; }
        $details = $google->dataGet($credentialRef, "videos", array("part" => "snippet,contentDetails,statistics", "id" => implode(",", $ids), "maxResults" => 50));
        foreach ($details->success && isset($details->data["items"]) ? $details->data["items"] : array() as $item) {
            $snippet = isset($item["snippet"]) ? $item["snippet"] : array();
            $contentDetails = isset($item["contentDetails"]) ? $item["contentDetails"] : array();
            $stats = isset($item["statistics"]) ? $item["statistics"] : array();
            $description = isset($snippet["description"]) ? substr($snippet["description"], 0, 10000) : "";
            $durationSeconds = $this->isoDurationSeconds(isset($contentDetails["duration"]) ? $contentDetails["duration"] : "PT0S");
            $this->upsert("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "competitorId", "operator" => "=", "value" => $competitor->competitorId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $item["id"])), (object)array("channelId" => $channelId, "competitorId" => $competitor->competitorId, "youtubeVideoId" => $item["id"], "title" => isset($snippet["title"]) ? substr((string)$snippet["title"], 0, 500) : "", "description" => $description, "tags" => isset($snippet["tags"]) && is_array($snippet["tags"]) ? array_slice($snippet["tags"], 0, 100) : array(), "hashtags" => $this->extractHashtags((isset($snippet["title"]) ? $snippet["title"] : "") . "\n" . $description), "publishedAt" => isset($snippet["publishedAt"]) ? date("Y-m-d H:i:s", strtotime($snippet["publishedAt"])) : null, "thumbnailUrl" => isset($snippet["thumbnails"]["medium"]["url"]) ? $snippet["thumbnails"]["medium"]["url"] : "", "durationSeconds" => $durationSeconds, "contentType" => $durationSeconds > 0 && $durationSeconds <= 180 && $this->hasShortsMarker($snippet) ? "Short" : "Long-form", "views" => intval(isset($stats["viewCount"]) ? $stats["viewCount"] : 0), "likes" => intval(isset($stats["likeCount"]) ? $stats["likeCount"] : 0), "comments" => intval(isset($stats["commentCount"]) ? $stats["commentCount"] : 0), "subscriberCountSnapshot" => intval(isset($competitor->subscriberCount) ? $competitor->subscriberCount : 0), "source" => "YOUTUBE_DATA", "calculationSource" => "", "refreshedAt" => $this->now()));
        }
    }

    private function competitorVideoWorkspace($channel, $video) {
        $conditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $video->youtubeVideoId));
        $query = $this->query("ytg_competitor_videos", $conditions, array(array("column" => "performanceRank", "direction" => "ASC")), 100, 0);
        $videos = $query->success ? $query->result : array();
        if (count($videos)) { $videos = $this->rankContextCompetitorVideos($channel->channelId, $video->youtubeVideoId, false); }
        $transcriptQuery = $this->query("ytg_competitor_transcripts", $conditions, array(array("column" => "importedAt", "direction" => "DESC")), 100, 0);
        $transcripts = array();
        foreach ($transcriptQuery->success ? $transcriptQuery->result : array() as $transcript) {
            $transcripts[(string)$transcript->competitorVideoId] = (object)array("competitorVideoId" => $transcript->competitorVideoId, "language" => $transcript->language, "sourceType" => $transcript->sourceType, "provenance" => $transcript->provenance, "importedAt" => $transcript->importedAt);
        }
        foreach ($videos as $item) {
            $item->tags = $this->decodeJson(isset($item->tags) ? $item->tags : array()); $item->hashtags = $this->decodeJson(isset($item->hashtags) ? $item->hashtags : array());
            $item->transcript = isset($transcripts[(string)$item->youtubeVideoId]) ? $transcripts[(string)$item->youtubeVideoId] : null;
            $item->watchUrl = "https://www.youtube.com/watch?v=" . rawurlencode((string)$item->youtubeVideoId);
        }
        $researchQuery = $this->query("ytg_competitor_research", $conditions, array(array("column" => "researchId", "direction" => "DESC")), 1, 0);
        $research = $researchQuery->success && count($researchQuery->result) ? $researchQuery->result[0] : null;
        if ($research !== null && (!isset($research->modelVersion) || $research->modelVersion !== "competitor-video-research-v2")) { $research = null; }
        if ($research !== null) {
            foreach (array("sourceVideoIds", "rankedKeywords", "rankedHashtags", "tags", "titlePromptPairs", "limitations") as $field) { $research->{$field} = $this->decodeJson(isset($research->{$field}) ? $research->{$field} : array()); }
        }
        return (object)array("contextVideoId" => $video->youtubeVideoId, "videos" => $videos, "research" => $research, "transcriptPolicy" => "Competitor captions are never downloaded or scraped. Only rights-confirmed user input or an approved licensed provider may be analysed.", "derivedMetricsEnabled" => \YtgConfig::derivedMetricsEnabled());
    }

    private function rankContextCompetitorVideos($channelId, $contextVideoId, $persist) {
        $query = $this->query("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $contextVideoId)), array(array("column" => "views", "direction" => "DESC")), 100, 0);
        $videos = $query->success ? $query->result : array(); $derived = \YtgConfig::derivedMetricsEnabled(); $now = time();
        foreach ($videos as $item) {
            $published = isset($item->publishedAt) ? strtotime((string)$item->publishedAt) : false; $ageDays = $published === false ? 1 : max(1, intval(floor(($now - $published) / 86400)) + 1);
            $item->ageDays = $ageDays; $item->viewsPerDay = $derived ? round(intval($item->views) / $ageDays, 2) : null; $item->viewsToSubscriberRatio = null; $item->calculationSource = $derived ? "PRODUCT_CALCULATION" : "DISABLED_BY_POLICY";
        }
        usort($videos, function($left, $right) use ($derived) { $leftValue = $derived ? floatval($left->viewsPerDay) : intval($left->views); $rightValue = $derived ? floatval($right->viewsPerDay) : intval($right->views); return $leftValue === $rightValue ? 0 : ($leftValue < $rightValue ? 1 : -1); });
        foreach ($videos as $index => $item) { $item->performanceRank = $index + 1; $item->shortlisted = $index < min(10, count($videos)); if ($persist) { \SOSSData::Update("ytg_competitor_videos", $item); } }
        return $videos;
    }

    private function clearContextResearch($channelId, $contextVideoId) {
        $query = $this->query("ytg_competitor_research", array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $contextVideoId)), array(), 100, 0);
        foreach ($query->success ? $query->result : array() as $row) { \SOSSData::Delete("ytg_competitor_research", $row); }
    }

    private function parseCompetitorVideoReference($value) {
        $value = trim((string)$value); if ($value === "" || strlen($value) > 1000) { return null; }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value)) { return (object)array("videoId" => $value, "sourceUrl" => "https://www.youtube.com/watch?v=" . $value); }
        if (!preg_match('/^https?:\/\//i', $value)) { $value = "https://" . $value; }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) { return null; }
        $host = strtolower((string)parse_url($value, PHP_URL_HOST)); $host = preg_replace('/^(?:www\.|m\.)/', '', $host); $path = trim((string)parse_url($value, PHP_URL_PATH), "/"); $id = "";
        if ($host === "youtu.be" && preg_match('/^([A-Za-z0-9_-]{11})(?:\/|$)/', $path, $match)) { $id = $match[1]; }
        elseif (in_array($host, array("youtube.com", "music.youtube.com"), true)) {
            if (preg_match('/^(?:shorts|embed|live)\/([A-Za-z0-9_-]{11})(?:\/|$)/', $path, $match)) { $id = $match[1]; }
            elseif ($path === "watch") { parse_str((string)parse_url($value, PHP_URL_QUERY), $query); $id = isset($query["v"]) ? (string)$query["v"] : ""; }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) { return null; }
        return (object)array("videoId" => $id, "sourceUrl" => substr($value, 0, 1000));
    }

    private function parseCompetitorReference($value) {
        $value = trim((string)$value);
        if ($value === "" || strlen($value) > 1000) { return null; }
        if (preg_match('/^UC[A-Za-z0-9_-]{20,30}$/', $value)) { return (object)array("type" => "id", "value" => $value); }
        if (preg_match('/^@([A-Za-z0-9._-]{3,30})$/', $value, $match)) { return (object)array("type" => "handle", "value" => $match[1]); }
        if (!preg_match('/^https?:\/\//i', $value) && preg_match('/^(?:www\.|m\.)?youtube\.com\//i', $value)) { $value = "https://" . $value; }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) { return null; }
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));
        $host = preg_replace('/^(?:www\.|m\.)/', '', $host);
        if (!in_array($host, array("youtube.com", "www.youtube.com"), true)) { return null; }
        $path = trim((string)parse_url($value, PHP_URL_PATH), "/");
        if (preg_match('/^channel\/(UC[A-Za-z0-9_-]{20,30})$/', $path, $match)) { return (object)array("type" => "id", "value" => $match[1]); }
        if (preg_match('/^@([A-Za-z0-9._-]{3,30})$/', $path, $match)) { return (object)array("type" => "handle", "value" => $match[1]); }
        if (preg_match('/^user\/([A-Za-z0-9._-]{1,100})$/', $path, $match)) { return (object)array("type" => "username", "value" => $match[1]); }
        return null;
    }

    private function resolveCompetitorReference($google, $credentialRef, $reference) {
        if (!is_object($reference) || !isset($reference->type, $reference->value)) { return null; }
        $params = array("part" => "id", "maxResults" => 1);
        if ($reference->type === "handle") { $params["forHandle"] = $reference->value; }
        elseif ($reference->type === "username") { $params["forUsername"] = $reference->value; }
        else { return $reference->type === "id" ? $reference->value : null; }
        $response = $google->dataGet($credentialRef, "channels", $params);
        if (!$response->success || empty($response->data["items"][0]["id"])) { return null; }
        $id = (string)$response->data["items"][0]["id"];
        return preg_match('/^UC[A-Za-z0-9_-]{20,30}$/', $id) ? $id : null;
    }

    private function buildCompetitorAnalysis($competitors, $videos, $persist) {
        $channels = array();
        foreach ($competitors as $competitor) { $channels[intval($competitor->competitorId)] = $competitor; }
        $derived = \YtgConfig::derivedMetricsEnabled();
        $now = time(); $decorated = array();
        foreach ($videos as $video) {
            $published = isset($video->publishedAt) ? strtotime((string)$video->publishedAt) : false;
            $ageDays = $published === false ? 1 : max(1, intval(floor(($now - $published) / 86400)) + 1);
            $competitor = isset($channels[intval($video->competitorId)]) ? $channels[intval($video->competitorId)] : null;
            $subscribers = $competitor !== null && empty($competitor->subscriberCountHidden) ? intval($competitor->subscriberCount) : 0;
            $video->competitorLabel = $competitor !== null ? $competitor->label : "Comparison channel";
            $video->subscriberCountSnapshot = $subscribers;
            $video->ageDays = $ageDays;
            $video->watchUrl = "https://www.youtube.com/watch?v=" . rawurlencode((string)$video->youtubeVideoId);
            $video->tags = $this->decodeJson(isset($video->tags) ? $video->tags : array());
            $video->hashtags = $this->decodeJson(isset($video->hashtags) ? $video->hashtags : array());
            $video->viewsPerDay = $derived ? round(intval($video->views) / $ageDays, 2) : null;
            $video->viewsToSubscriberRatio = $derived && $subscribers > 0 ? round(intval($video->views) / $subscribers, 4) : null;
            $video->calculationSource = $derived ? "PRODUCT_CALCULATION" : "DISABLED_BY_POLICY";
            $decorated[] = $video;
        }
        usort($decorated, function($left, $right) use ($derived) {
            if ($derived) {
                $leftRate = floatval($left->viewsPerDay); $rightRate = floatval($right->viewsPerDay);
                if ($leftRate !== $rightRate) { return $leftRate < $rightRate ? 1 : -1; }
                $leftRatio = $left->viewsToSubscriberRatio === null ? -1 : floatval($left->viewsToSubscriberRatio);
                $rightRatio = $right->viewsToSubscriberRatio === null ? -1 : floatval($right->viewsToSubscriberRatio);
                if ($leftRatio !== $rightRatio) { return $leftRatio < $rightRatio ? 1 : -1; }
            }
            return intval($left->views) === intval($right->views) ? 0 : (intval($left->views) < intval($right->views) ? 1 : -1);
        });
        foreach ($decorated as $index => $video) {
            $video->performanceRank = $index + 1;
            $video->shortlisted = $index < min(10, count($decorated));
            if ($persist) {
                $persisted = clone $video;
                unset($persisted->competitorLabel, $persisted->watchUrl);
                \SOSSData::Update("ytg_competitor_videos", $persisted);
            }
        }
        return (object)array(
            "videos" => $decorated,
            "shortlist" => array_slice($decorated, 0, 10),
            "derivedMetricsEnabled" => $derived,
            "formula" => $derived ? "Views per day = public views / days since publication; views-to-subscriber ratio = public views / current public subscriber count." : "Derived competitor ratios are disabled until the YouTube derived-metrics policy setting is enabled.",
            "source" => $derived ? "PRODUCT_CALCULATION_FROM_YOUTUBE_DATA" : "YOUTUBE_DATA",
            "generatedAt" => $this->now()
        );
    }

    private function extractHashtags($text) {
        preg_match_all('/(?<![\p{L}\p{M}\p{N}_])#[\p{L}\p{M}\p{N}_-]+/u', (string)$text, $matches);
        return array_slice(array_values(array_unique(isset($matches[0]) ? $matches[0] : array())), 0, 30);
    }

    private function isoDurationSeconds($value) {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper((string)$value), $match)) { return 0; }
        return intval(isset($match[1]) ? $match[1] : 0) * 86400 + intval(isset($match[2]) ? $match[2] : 0) * 3600 + intval(isset($match[3]) ? $match[3] : 0) * 60 + intval(isset($match[4]) ? $match[4] : 0);
    }

    private function hasShortsMarker($snippet) {
        $text = strtolower((isset($snippet["title"]) ? $snippet["title"] : "") . " " . (isset($snippet["description"]) ? $snippet["description"] : ""));
        return strpos($text, "#shorts") !== false;
    }

    private function ownedVideo($channelId, $videoId) {
        $videoId = $this->youtubeId($videoId);
        if ($videoId === "") { return null; }
        return $this->first("ytg_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)));
    }

    private function optionalOwnedVideoId($res, $channelId, $value) {
        if (trim((string)$value) === "") { return ""; }
        $video = $this->ownedVideo($channelId, $value);
        if ($video === null) { $this->fail($res, "The selected video is not available in this channel workspace."); return false; }
        return $video->youtubeVideoId;
    }

    private function rows($namespace, $channelId, $sorting, $limit) {
        $query = $this->query($namespace, array(array("column" => "channelId", "operator" => "=", "value" => $channelId)), $sorting, $limit, 0);
        return $query->success ? $query->result : array();
    }

    private function rowsForVideo($namespace, $channelId, $videoId, $sorting, $limit) {
        $query = $this->query($namespace, array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId)), $sorting, $limit, 0);
        return $query->success ? $query->result : array();
    }

    private function deleteRowsForVideo($namespace, $channelId, $videoId) {
        foreach ($this->rowsForVideo($namespace, $channelId, $videoId, array(), 10000) as $row) { \SOSSData::Delete($namespace, $row); }
    }

    private function hasCaptionScope($grant) {
        if ($grant === null || !isset($grant->scopes)) { return false; }
        $scopes = $grant->scopes;
        if (is_string($scopes)) {
            $decoded = json_decode($scopes, true);
            $scopes = is_array($decoded) ? $decoded : preg_split('/\s+/', trim($scopes));
        } elseif (is_object($scopes)) {
            $scopes = array_values((array)$scopes);
        }
        return is_array($scopes) && in_array("https://www.googleapis.com/auth/youtube.force-ssl", $scopes, true);
    }

    private function selectCaptionTrack($items, $preferredLanguage) {
        if (!is_array($items)) { return null; }
        $ranked = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item["id"], $item["snippet"]) || !is_array($item["snippet"])) { continue; }
            $id = trim((string)$item["id"]);
            if ($id === "" || strlen($id) > 500 || preg_match('/[\x00-\x1F\x7F]/', $id)) { continue; }
            $snippet = $item["snippet"];
            $status = strtolower(isset($snippet["status"]) ? (string)$snippet["status"] : "");
            if ($status === "failed") { continue; }
            $language = strtolower(isset($snippet["language"]) ? trim((string)$snippet["language"]) : "und");
            $trackKind = strtolower(isset($snippet["trackKind"]) ? trim((string)$snippet["trackKind"]) : "standard");
            $score = 0;
            if ($preferredLanguage !== "" && $language !== $preferredLanguage) { $score += 100; }
            if (!empty($snippet["isDraft"])) { $score += 20; }
            if ($status !== "serving") { $score += 10; }
            if ($trackKind === "asr") { $score += 5; }
            $ranked[] = (object)array(
                "id" => $id,
                "language" => $language,
                "name" => isset($snippet["name"]) ? substr(trim((string)$snippet["name"]), 0, 150) : "",
                "trackKind" => $trackKind,
                "autoGenerated" => $trackKind === "asr",
                "score" => $score
            );
        }
        if (!count($ranked)) { return null; }
        usort($ranked, function ($left, $right) { return $left->score === $right->score ? strcmp($left->language, $right->language) : $left->score - $right->score; });
        return $ranked[0];
    }

    private function normalizeTranscriptSegments($value, $plainText, $durationMs) {
        if (is_object($value)) { $value = (array)$value; }
        if (!is_array($value) || !count($value)) { return array((object)array("startMs" => 0, "endMs" => $durationMs, "text" => $plainText)); }
        $segments = array(); $previousEnd = 0;
        foreach ($value as $item) {
            $item = is_object($item) ? $item : (object)$item;
            $start = isset($item->startMs) ? intval($item->startMs) : -1;
            $end = isset($item->endMs) ? intval($item->endMs) : -1;
            $text = substr(trim(isset($item->text) ? (string)$item->text : ""), 0, 10000);
            if ($start < 0 || $end <= $start || $end > $durationMs + 1000 || $start < $previousEnd || $text === "") { return null; }
            $segments[] = (object)array("startMs" => $start, "endMs" => $end, "text" => $text);
            $previousEnd = $end;
            if (count($segments) > 5000) { return null; }
        }
        return $segments;
    }

    private function decodeTranscriptSegments($value) {
        for ($attempt = 0; $attempt < 4 && is_string($value); $attempt++) {
            $trimmed = trim($value);
            if ($trimmed === "") { return array(); }
            $decoded = json_decode($trimmed);
            if (json_last_error() !== JSON_ERROR_NONE) { return array(); }
            $value = $decoded;
        }
        if (is_object($value)) {
            foreach (array("segments", "items", "values", "data") as $wrapper) {
                if (isset($value->{$wrapper})) { return $this->decodeTranscriptSegments($value->{$wrapper}); }
            }
            $value = (array)$value;
        }
        if (!is_array($value)) { return array(); }
        if (isset($value["startMs"], $value["endMs"])) { $value = array($value); }
        $segments = array();
        foreach ($value as $item) {
            for ($attempt = 0; $attempt < 3 && is_string($item); $attempt++) {
                $decoded = json_decode(trim($item));
                if (json_last_error() !== JSON_ERROR_NONE) { break; }
                $item = $decoded;
            }
            if (is_object($item) || is_array($item)) { $segments[] = $item; }
        }
        return $segments;
    }

    private function analyticsRows($data) {
        if (!is_array($data) || empty($data["columnHeaders"]) || empty($data["rows"])) { return array(); }
        $headers = array(); foreach ($data["columnHeaders"] as $header) { $headers[] = isset($header["name"]) ? $header["name"] : ""; }
        $out = array(); foreach ($data["rows"] as $values) { $row = array(); foreach ($headers as $index => $name) { $row[$name] = isset($values[$index]) ? $values[$index] : null; } $out[] = $row; }
        return $out;
    }

    private function number($row, $key) { return isset($row[$key]) && is_numeric($row[$key]) ? floatval($row[$key]) : 0; }
    private function language($value) { $value = trim(substr((string)$value, 0, 30)); return $value === "" ? "English" : $value; }
    private function dateTime($value) { $time = strtotime((string)$value); return $time === false ? "" : date("Y-m-d H:i:s", $time); }
    private function listValue($value, $maxItems, $maxLength) {
        if (is_string($value)) { $value = preg_split('/[\r\n]+/', $value); }
        if (!is_array($value)) { return array(); }
        $out = array(); foreach ($value as $item) { $item = substr(trim((string)$item), 0, $maxLength); if ($item !== "") { $out[] = $item; } if (count($out) >= $maxItems) { break; } }
        return array_values(array_unique($out));
    }
}
?>
