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
        $result = (object)array(
            "channel" => $this->safeChannel($channel),
            "videos" => $videos->success ? $videos->result : array(),
            "selectedVideoId" => $videoId === false ? "" : $videoId,
            "transcript" => null,
            "retention" => array(),
            "shortCandidates" => array(),
            "comments" => array(),
            "competitors" => $this->rows("ytg_competitors", $channel->channelId, array(array("column" => "competitorId", "direction" => "DESC")), 100),
            "competitorVideos" => $this->rows("ytg_competitor_videos", $channel->channelId, array(array("column" => "publishedAt", "direction" => "DESC")), 100),
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
                "automaticTranscriptImport" => $captionAuthorized
            )
        );
        if ($videoId !== "") {
            $transcript = $this->first("ytg_transcripts", array(
                array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
                array("column" => "videoId", "operator" => "=", "value" => $videoId)
            ), array(array("column" => "transcriptId", "direction" => "DESC")));
            if ($transcript !== null) {
                $transcript->segments = $this->decodeJson(isset($transcript->segments) ? $transcript->segments : array());
                if (isset($transcript->sourceType) && $transcript->sourceType === "YOUTUBE_CAPTION") {
                    $transcript->segments = YouTubeCaptionParser::normalizeSegments($transcript->segments);
                    $transcript->plainText = YouTubeCaptionParser::plainText($transcript->segments);
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
        $video->hasTranscript = true;
        \SOSSData::Update("ytg_videos", $video);
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
        $video->hasTranscript = true;
        \SOSSData::Update("ytg_videos", $video);
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
        $youtubeChannelId = $this->youtubeId(isset($body->youtubeChannelId) ? $body->youtubeChannelId : "");
        if ($youtubeChannelId === "" || $youtubeChannelId === (string)$channel->youtubeChannelId) { return $this->fail($res, "Enter a valid public comparison channel ID different from the connected channel."); }
        $existing = $this->first("ytg_competitors", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "competitorYoutubeChannelId", "operator" => "=", "value" => $youtubeChannelId)
        ));
        if ($existing !== null) { return (object)array("competitor" => $existing, "message" => "Comparison channel is already in this workspace."); }
        $row = (object)array("channelId" => $channel->channelId, "competitorYoutubeChannelId" => $youtubeChannelId, "label" => substr(trim(isset($body->label) ? (string)$body->label : $youtubeChannelId), 0, 255), "active" => true, "createdAt" => $this->now());
        $save = \SOSSData::Insert("ytg_competitors", $row);
        if (!$save->success) { return $this->fail($res, "Comparison channel could not be saved."); }
        $row->competitorId = $this->generatedId($save);
        $this->audit("COMPETITOR_ADDED", $channel->channelId, "competitor:" . $youtubeChannelId, null, array("label" => $row->label));
        return (object)array("competitor" => $row, "message" => "Comparison channel added. Refresh it to import public metadata.");
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
            $competitor->videoCount = intval(isset($statistics["videoCount"]) ? $statistics["videoCount"] : 0);
            $competitor->viewCount = intval(isset($statistics["viewCount"]) ? $statistics["viewCount"] : 0);
            $competitor->refreshedAt = $this->now();
            \SOSSData::Update("ytg_competitors", $competitor);
            if ($competitor->uploadsPlaylistId !== "") { $this->refreshCompetitorVideos($google, $grant->credentialRef, $channel->channelId, $competitor); }
            $refreshed++;
        }
        return (object)array("refreshed" => $refreshed, "competitors" => $this->rows("ytg_competitors", $channel->channelId, array(array("column" => "competitorId", "direction" => "DESC")), 100), "competitorVideos" => $this->rows("ytg_competitor_videos", $channel->channelId, array(array("column" => "publishedAt", "direction" => "DESC")), 100), "message" => $refreshed . " public comparison channels refreshed without search.list.");
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
        $details = $google->dataGet($credentialRef, "videos", array("part" => "snippet,statistics", "id" => implode(",", $ids), "maxResults" => 50));
        foreach ($details->success && isset($details->data["items"]) ? $details->data["items"] : array() as $item) {
            $snippet = isset($item["snippet"]) ? $item["snippet"] : array();
            $stats = isset($item["statistics"]) ? $item["statistics"] : array();
            $this->upsert("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "competitorId", "operator" => "=", "value" => $competitor->competitorId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $item["id"])), (object)array("channelId" => $channelId, "competitorId" => $competitor->competitorId, "youtubeVideoId" => $item["id"], "title" => isset($snippet["title"]) ? $snippet["title"] : "", "description" => isset($snippet["description"]) ? substr($snippet["description"], 0, 10000) : "", "publishedAt" => isset($snippet["publishedAt"]) ? date("Y-m-d H:i:s", strtotime($snippet["publishedAt"])) : null, "thumbnailUrl" => isset($snippet["thumbnails"]["medium"]["url"]) ? $snippet["thumbnails"]["medium"]["url"] : "", "views" => intval(isset($stats["viewCount"]) ? $stats["viewCount"] : 0), "likes" => intval(isset($stats["likeCount"]) ? $stats["likeCount"] : 0), "comments" => intval(isset($stats["commentCount"]) ? $stats["commentCount"] : 0), "source" => "YOUTUBE_DATA", "refreshedAt" => $this->now()));
        }
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
