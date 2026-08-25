<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class GrowthAiService extends \YtgServiceBase {
    public function postGenerateShortCandidates($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), true);
        if ($context === null) { return null; }
        $segments = $context->transcript === null ? array() : $this->decodeJson($context->transcript->segments);
        if (!count($segments)) { return $this->fail($res, "A timestamped or user-provided transcript is required before Shorts candidates can be generated."); }
        $payload = array("video" => $context->safeVideo, "transcriptSegments" => $segments, "retentionPoints" => $context->retention, "language" => $context->language);
        $message = "Return strict JSON with key candidates. Each candidate must contain startMs, endMs, hook, captionPlan, titleOptions, and evidence. Use only supplied transcript timestamps and retention evidence. Each clip must be 15 to 60 seconds, self-contained, accurate, and in the requested language. Do not invent quotes, metrics, or outcomes.";
        $run = $this->agentJson($context, "SHORTS", $this->agentCode("TRANSCRIPT", "transcript-analyzer-agent"), $message, $payload, "shorts-v1");
        $candidates = $run->valid ? $this->validateShorts($run->data, $segments, intval($context->video->durationSeconds) * 1000) : array();
        $source = "SAVED_AGENT";
        if (!count($candidates)) { $candidates = $this->fallbackShorts($segments, intval($context->video->durationSeconds) * 1000); $source = "RULE_BASED"; }
        foreach ($candidates as $candidate) {
            $fingerprint = $candidate->startMs . ":" . $candidate->endMs . ":" . substr(hash("sha256", $candidate->hook), 0, 16);
            $existing = $this->first("ytg_short_candidates", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId), array("column" => "hook", "operator" => "=", "value" => $candidate->hook)));
            $row = $existing !== null ? $existing : new \stdClass();
            $row->channelId = $context->channel->channelId; $row->videoId = $context->video->youtubeVideoId;
            $row->startMs = $candidate->startMs; $row->endMs = $candidate->endMs; $row->hook = $candidate->hook;
            $row->captionPlan = $candidate->captionPlan; $row->titleOptions = $candidate->titleOptions;
            $row->evidence = $candidate->evidence; $row->source = $source; $row->status = "New"; $row->updatedAt = $this->now();
            if ($existing === null) { $row->createdAt = $this->now(); \SOSSData::Insert("ytg_short_candidates", $row); } else { \SOSSData::Update("ytg_short_candidates", $row); }
            $candidate->fingerprint = $fingerprint; $candidate->source = $source;
        }
        $this->audit("SHORT_CANDIDATES_GENERATED", $context->channel->channelId, "video:" . $context->video->youtubeVideoId, null, array("source" => $source, "count" => count($candidates)));
        return (object)array("source" => $source, "candidates" => $candidates, "message" => $source === "SAVED_AGENT" ? "Saved-agent candidates passed timestamp and evidence validation." : "A deterministic transcript-based candidate set was returned because AI output was unavailable or invalid.");
    }

    public function postGenerateVideoBrief($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), false);
        if ($context === null) { return null; }
        $traffic = $this->query("ytg_traffic_sources", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)), array(array("column" => "views", "direction" => "DESC")), 25, 0);
        $payload = array("video" => $context->safeVideo, "transcript" => $context->transcript === null ? null : array("language" => $context->transcript->language, "plainText" => substr($context->transcript->plainText, 0, 60000)), "trafficSources" => $traffic->success ? $traffic->result : array(), "language" => $context->language);
        $message = "Return strict JSON with audiencePromise, primaryTopic, searchIntent, titleAngles, outline, evidence, and language. Treat keyword volume as unknown. Use only supplied YouTube or user evidence, preserve Sinhala or English as requested, and do not promise results.";
        $run = $this->agentJson($context, "DISCOVERY_SEO", $this->agentCode("SEO", "seo-suggestion-agent"), $message, $payload, "video-brief-v1");
        $brief = $run->valid ? $this->validateBrief($run->data, $context->language) : null;
        $source = "SAVED_AGENT";
        if ($brief === null) { $brief = $this->fallbackBrief($context, $traffic->success ? $traffic->result : array()); $source = "RULE_BASED"; }
        $row = (object)array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "pillar" => $brief->primaryTopic, "idea" => isset($brief->titleAngles[0]) ? $brief->titleAngles[0] : $context->video->title, "intent" => $brief->searchIntent, "evidence" => $brief->evidence, "brief" => $brief, "language" => $context->language, "status" => "Brief", "createdAt" => $this->now(), "updatedAt" => $this->now());
        $save = \SOSSData::Insert("ytg_content_ideas", $row); $row->ideaId = $this->generatedId($save);
        return (object)array("source" => $source, "brief" => $brief, "ideaId" => $row->ideaId, "message" => "Video brief created without external keyword-volume claims.");
    }

    public function postGeneratePackagingVariants($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), false);
        if ($context === null) { return null; }
        $latestReach = $this->query("ytg_reach_daily", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)), array(array("column" => "date", "direction" => "DESC")), 30, 0);
        $payload = array("video" => $context->safeVideo, "reach" => $latestReach->success ? $latestReach->result : array(), "language" => $context->language);
        $message = "Return strict JSON with titles, thumbnails, hookAlignment, testPlan, and evidence. Provide 2 to 5 accurate title variants under 100 characters and 2 to 4 thumbnail briefs. testPlan must contain hypothesis, primaryMetric, reviewAfterDays, and nativeYouTubeTest=true. Never claim a guaranteed CTR or outcome, and never fabricate reach evidence.";
        $run = $this->agentJson($context, "PACKAGING", $this->agentCode("PACKAGING", "seo-suggestion-agent"), $message, $payload, "packaging-v1");
        $packaging = $run->valid ? $this->validatePackaging($run->data) : null;
        $source = "SAVED_AGENT";
        if ($packaging === null) { $packaging = $this->fallbackPackaging($context, $latestReach->success ? $latestReach->result : array()); $source = "RULE_BASED"; }
        $variants = array(); foreach ($packaging->titles as $title) { $variants[] = $title; }
        $row = (object)array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "type" => "PACKAGING", "name" => "Packaging variants for " . substr($context->video->title, 0, 430), "hypothesis" => $packaging->testPlan->hypothesis, "variants" => $variants, "primaryMetric" => $packaging->testPlan->primaryMetric, "startAt" => null, "endAt" => null, "metrics" => array(), "result" => "", "limitations" => "Prepare and launch with native YouTube testing when available; compare only reasonably comparable samples.", "status" => "Draft", "createdAt" => $this->now(), "updatedAt" => $this->now());
        $save = \SOSSData::Insert("ytg_experiments", $row); $row->experimentId = $this->generatedId($save);
        return (object)array("source" => $source, "packaging" => $packaging, "experimentId" => $row->experimentId, "message" => "Packaging workshop variants were validated and saved as a draft experiment; no channel metadata was changed.");
    }

    public function postAnalyzeCommunity($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), false);
        if ($context === null) { return null; }
        $comments = $this->query("ytg_comments", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)), array(array("column" => "likeCount", "direction" => "DESC")), 100, 0);
        $items = array(); foreach ($comments->success ? $comments->result : array() as $comment) { $items[] = array("commentId" => $comment->youtubeCommentId, "text" => $comment->text, "likeCount" => intval($comment->likeCount)); }
        if (!count($items)) { return $this->fail($res, "Refresh a comment sample before community analysis."); }
        $message = "Return strict JSON with themes, replyDrafts, and contentIdeas. Every theme must cite evidenceCommentIds. Every reply draft must cite one supplied commentId and set requiresApproval true. Do not infer sensitive attributes, profile commenters, or claim that a reply was posted.";
        $run = $this->agentJson($context, "COMMUNITY", $this->agentCode("COMMUNITY", \YtgConfig::agentCode()), $message, array("comments" => $items, "language" => $context->language), "community-v1");
        $analysis = $run->valid ? $this->validateCommunity($run->data, $items) : null;
        $source = "SAVED_AGENT";
        if ($analysis === null) { $analysis = $this->fallbackCommunity($items, $context->language); $source = "RULE_BASED"; }
        return (object)array("source" => $source, "analysis" => $analysis, "message" => "Comment themes and reply drafts are advisory; every reply requires manual review and posting.");
    }

    public function postGenerateSessionRecommendations($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), false);
        if ($context === null) { return null; }
        $videos = $this->query("ytg_videos", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "status", "operator" => "=", "value" => "Active")), array(array("column" => "publishedAt", "direction" => "DESC")), 50, 0);
        $catalogue = array(); foreach ($videos->success ? $videos->result : array() as $video) { if ($video->youtubeVideoId !== $context->video->youtubeVideoId) { $catalogue[] = array("videoId" => $video->youtubeVideoId, "title" => $video->title, "description" => substr($video->description, 0, 1000), "contentType" => $video->contentType); } }
        $message = "Return strict JSON with endScreens and playlists. Each endScreens item must include targetVideoId and reasoning. Each playlists item must include name, videoIds, and reasoning. Use only supplied video IDs. Recommendations are proposals and must not claim that YouTube was changed.";
        $run = $this->agentJson($context, "SESSION_PATH", $this->agentCode("STRATEGIST", \YtgConfig::agentCode()), $message, array("currentVideo" => $context->safeVideo, "catalogue" => $catalogue, "language" => $context->language), "session-path-v1");
        $recommendations = $run->valid ? $this->validateSession($run->data, $catalogue) : null;
        $source = "SAVED_AGENT";
        if ($recommendations === null) { $recommendations = $this->fallbackSession($catalogue); $source = "RULE_BASED"; }
        return (object)array("source" => $source, "recommendations" => $recommendations, "message" => "End-screen and playlist proposals use only videos from this channel workspace.");
    }

    private function videoContext($req, $res, $roles, $requireTranscript) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", $roles);
        if ($channel === null) { return null; }
        $confirmed = isset($body->confirmAgentDataShare) && filter_var($body->confirmAgentDataShare, FILTER_VALIDATE_BOOLEAN);
        if (!$confirmed) { $this->fail($res, "Confirm that the selected video context may be sent to the configured saved-agent provider for this generation request."); return null; }
        $videoId = $this->youtubeId(isset($body->videoId) ? $body->videoId : "");
        $video = $videoId === "" ? null : $this->first("ytg_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)));
        if ($video === null) { $this->fail($res, "The selected video is not available in this channel workspace."); return null; }
        $transcript = $this->first("ytg_transcripts", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId)), array(array("column" => "transcriptId", "direction" => "DESC")));
        if ($requireTranscript && $transcript === null) { $this->fail($res, "A transcript is required for this analysis."); return null; }
        $retention = $this->query("ytg_retention_points", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId)), array(array("column" => "elapsedRatio", "direction" => "ASC")), 200, 0);
        $safeVideo = array("videoId" => $video->youtubeVideoId, "title" => $video->title, "description" => substr($video->description, 0, 10000), "durationSeconds" => intval($video->durationSeconds), "contentType" => $video->contentType, "language" => isset($video->language) ? $video->language : "", "tags" => $this->decodeJson(isset($video->tags) ? $video->tags : array()));
        return (object)array("channel" => $channel, "video" => $video, "safeVideo" => $safeVideo, "transcript" => $transcript, "retention" => $retention->success ? $retention->result : array(), "language" => isset($channel->defaultLanguage) && trim($channel->defaultLanguage) !== "" ? $channel->defaultLanguage : "English");
    }

    private function agentJson($context, $agentType, $agentCode, $message, $payload, $promptVersion) {
        $fallback = (object)array("valid" => false, "data" => null);
        $path = TENANT_RESOURCE_LOCATION . "/apps/ai-agent-creator/services/creator-api/service.php";
        if ($agentCode === "" || !is_file($path)) { $this->recordRun($context, $agentType, "", $promptVersion, $payload, array("error" => "agent unavailable"), "FALLBACK", 0); return $fallback; }
        try {
            require_once($path);
            if (!class_exists("\\ai_agent_creator\\CreatorService")) { throw new \RuntimeException("Saved-agent runtime is unavailable."); }
            $creator = new \ai_agent_creator\CreatorService(); $profile = $this->currentProfile();
            $run = $creator->interactWithAgent(array("agentCode" => $agentCode, "message" => $message, "appCode" => "youtube-growth-agent", "appName" => "YouTube Growth Agent", "profile" => array("profileId" => $profile->id), "conversationKey" => $context->channel->channelId . ":" . $context->video->youtubeVideoId . ":" . strtolower($agentType), "context" => array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "language" => $context->language, "officialMetricsOnly" => true, "channelWriteBack" => false), "payload" => $payload));
            if (!isset($run->success) || !$run->success) { throw new \RuntimeException(isset($run->error) ? $run->error : "Saved-agent execution failed."); }
            $text = isset($run->response) ? $run->response : (isset($run->reply) ? $run->reply : ""); $data = $this->decodeAgentJson($text);
            if (!is_object($data) || $this->forbidden($data)) { throw new \RuntimeException("Saved-agent JSON was invalid or contained unsupported claims."); }
            $this->recordRun($context, $agentType, isset($run->model) ? $run->model : "", $promptVersion, $payload, $data, "STRUCTURE_VALID", isset($run->tokenUsage) ? intval($run->tokenUsage) : 0);
            return (object)array("valid" => true, "data" => $data);
        } catch (\Throwable $error) {
            $this->recordRun($context, $agentType, "", $promptVersion, $payload, array("error" => $error->getMessage()), "REJECTED_FALLBACK", 0); return $fallback;
        }
    }

    private function validateShorts($data, $segments, $durationMs) {
        if (!isset($data->candidates) || !is_array($data->candidates)) { return array(); }
        $out = array(); foreach (array_slice($data->candidates, 0, 8) as $value) {
            $item = is_object($value) ? $value : (object)$value; $start = isset($item->startMs) ? intval($item->startMs) : -1; $end = isset($item->endMs) ? intval($item->endMs) : -1;
            if ($start < 0 || $end > $durationMs + 1000 || $end - $start < 15000 || $end - $start > 60000 || !$this->timestampCovered($segments, $start, $end)) { continue; }
            $hook = substr(trim(isset($item->hook) ? (string)$item->hook : ""), 0, 1000); $caption = substr(trim(isset($item->captionPlan) ? (string)$item->captionPlan : ""), 0, 5000); $titles = $this->strings(isset($item->titleOptions) ? $item->titleOptions : array(), 5, 100);
            if ($hook === "" || $caption === "" || !count($titles)) { continue; }
            $out[] = (object)array("startMs" => $start, "endMs" => $end, "hook" => $hook, "captionPlan" => $caption, "titleOptions" => $titles, "evidence" => array((object)array("source" => "USER_DATA", "metric" => "transcript timestamp", "value" => $start . "-" . $end, "dateRange" => date("Y-m-d") . "/" . date("Y-m-d"))));
        } return $out;
    }

    private function fallbackShorts($segments, $durationMs) {
        $out = array(); foreach ($segments as $value) { $item = is_object($value) ? $value : (object)$value; $start = max(0, intval($item->startMs)); $end = min($durationMs, intval($item->endMs)); if ($end - $start > 60000) { $end = $start + 45000; } if ($end - $start < 15000) { continue; } $text = trim((string)$item->text); if ($text === "") { continue; } $hook = substr($text, 0, 180); $out[] = (object)array("startMs" => $start, "endMs" => $end, "hook" => $hook, "captionPlan" => "Keep captions faithful to this transcript segment and emphasize its opening statement.", "titleOptions" => array(substr($hook, 0, 90)), "evidence" => array((object)array("source" => "USER_DATA", "metric" => "transcript timestamp", "value" => $start . "-" . $end, "dateRange" => date("Y-m-d") . "/" . date("Y-m-d")))); if (count($out) >= 3) { break; } } return $out;
    }

    private function validateBrief($data, $language) {
        foreach (array("audiencePromise", "primaryTopic", "searchIntent", "language") as $field) { if (!isset($data->{$field}) || trim((string)$data->{$field}) === "") { return null; } }
        $data->titleAngles = $this->strings(isset($data->titleAngles) ? $data->titleAngles : array(), 8, 100); $data->outline = $this->strings(isset($data->outline) ? $data->outline : array(), 12, 1000);
        $data->evidence = $this->evidenceItems(isset($data->evidence) ? $data->evidence : array(), array("video metadata", "trafficSource", "searchTerm", "transcript timestamp", "views", "watchMinutes"));
        if (!count($data->titleAngles) || !count($data->outline) || !count($data->evidence)) { return null; } $data->language = $language; return $data;
    }

    private function fallbackBrief($context, $traffic) {
        $topic = trim($context->video->title); $evidence = array((object)array("source" => "YOUTUBE_DATA", "metric" => "video metadata", "value" => $topic, "dateRange" => date("Y-m-d") . "/" . date("Y-m-d")));
        if (count($traffic)) { $evidence[] = (object)array("source" => "YOUTUBE_REPORTING", "metric" => "trafficSource", "value" => isset($traffic[0]->sourceType) ? $traffic[0]->sourceType : "Available", "dateRange" => date("Y-m-d") . "/" . date("Y-m-d")); }
        return (object)array("audiencePromise" => "Deliver the promise stated by the current video topic with clearer structure and evidence.", "primaryTopic" => substr($topic, 0, 160), "searchIntent" => "Use the channel's actual search and traffic evidence when available; keyword volume is unknown.", "titleAngles" => array(substr($topic, 0, 100), substr("What viewers should know about " . $topic, 0, 100)), "outline" => array("Open with the audience problem", "Deliver the core explanation", "Show a concrete example", "Close with the next useful step"), "evidence" => $evidence, "language" => $context->language);
    }

    private function validatePackaging($data) {
        $titles = $this->strings(isset($data->titles) ? $data->titles : array(), 5, 100); if (count($titles) < 2 || !isset($data->thumbnails) || !is_array($data->thumbnails) || !isset($data->testPlan)) { return null; }
        $thumbnails = array(); foreach (array_slice($data->thumbnails, 0, 4) as $value) { $item = is_object($value) ? $value : (object)$value; if (!isset($item->concept) || trim((string)$item->concept) === "") { continue; } $thumbnails[] = (object)array("concept" => substr((string)$item->concept, 0, 1000), "onImageText" => substr(isset($item->onImageText) ? (string)$item->onImageText : "", 0, 80), "visualDirection" => substr(isset($item->visualDirection) ? (string)$item->visualDirection : "", 0, 1500)); }
        if (count($thumbnails) < 2) { return null; } $test = is_object($data->testPlan) ? $data->testPlan : (object)$data->testPlan; if (!isset($test->hypothesis) || trim((string)$test->hypothesis) === "") { return null; }
        $evidence = $this->evidenceItems(isset($data->evidence) ? $data->evidence : array(), array("video metadata", "impressions", "thumbnailImpressions", "thumbnailImpressionsCtr", "views"));
        if (!count($evidence)) { return null; }
        return (object)array("titles" => $titles, "thumbnails" => $thumbnails, "hookAlignment" => substr(isset($data->hookAlignment) ? (string)$data->hookAlignment : "", 0, 3000), "testPlan" => (object)array("hypothesis" => substr((string)$test->hypothesis, 0, 5000), "primaryMetric" => substr(isset($test->primaryMetric) ? (string)$test->primaryMetric : "thumbnailImpressionsCtr", 0, 160), "reviewAfterDays" => max(7, min(90, intval(isset($test->reviewAfterDays) ? $test->reviewAfterDays : 14))), "nativeYouTubeTest" => true), "evidence" => $evidence);
    }

    private function fallbackPackaging($context, $reach) {
        $title = trim($context->video->title); $hasReach = count($reach) > 0;
        return (object)array("titles" => array(substr($title, 0, 100), substr($title . " - Explained Clearly", 0, 100)), "thumbnails" => array((object)array("concept" => "Show the video's core subject with one clear focal point.", "onImageText" => "", "visualDirection" => "High contrast, uncluttered, and faithful to the content."), (object)array("concept" => "Show the before/after or question implied by the title.", "onImageText" => "", "visualDirection" => "Use a distinct composition for a native comparison test.")), "hookAlignment" => "The opening should immediately deliver the same promise as the selected title and thumbnail.", "testPlan" => (object)array("hypothesis" => "A clearer promise may improve qualified viewer response; the outcome is not guaranteed.", "primaryMetric" => "thumbnailImpressionsCtr", "reviewAfterDays" => 14, "nativeYouTubeTest" => true), "evidence" => array((object)array("source" => $hasReach ? "YOUTUBE_REPORTING" : "YOUTUBE_DATA", "metric" => $hasReach ? "impressions" : "video metadata", "value" => $hasReach ? "Available" : "Reach data unavailable", "dateRange" => date("Y-m-d") . "/" . date("Y-m-d"))));
    }

    private function validateCommunity($data, $comments) {
        if (!isset($data->themes) || !is_array($data->themes) || !isset($data->replyDrafts) || !is_array($data->replyDrafts)) { return null; } $allowed = array(); foreach ($comments as $comment) { $allowed[$comment["commentId"]] = true; }
        foreach ($data->themes as $value) { $theme = is_object($value) ? $value : (object)$value; if (!isset($theme->theme, $theme->evidenceCommentIds) || trim((string)$theme->theme) === "" || !is_array($theme->evidenceCommentIds) || !count($theme->evidenceCommentIds)) { return null; } foreach ($theme->evidenceCommentIds as $id) { if (!isset($allowed[(string)$id])) { return null; } } }
        $drafts = array(); foreach (array_slice($data->replyDrafts, 0, 20) as $value) { $item = is_object($value) ? $value : (object)$value; $id = isset($item->commentId) ? (string)$item->commentId : ""; if (!isset($allowed[$id]) || !isset($item->draft) || trim((string)$item->draft) === "") { continue; } $drafts[] = (object)array("commentId" => $id, "draft" => substr((string)$item->draft, 0, 5000), "requiresApproval" => true); }
        $data->replyDrafts = $drafts; $data->contentIdeas = $this->strings(isset($data->contentIdeas) ? $data->contentIdeas : array(), 10, 1000); return $data;
    }

    private function fallbackCommunity($comments, $language) {
        $questions = array(); foreach ($comments as $comment) { if (strpos($comment["text"], "?") !== false) { $questions[] = $comment; } }
        $themes = array((object)array("theme" => count($questions) ? "Audience questions" : "Audience responses", "count" => count($questions) ? count($questions) : count($comments), "evidenceCommentIds" => array_map(function($item) { return $item["commentId"]; }, array_slice(count($questions) ? $questions : $comments, 0, 10))));
        $drafts = array(); foreach (array_slice($questions, 0, 5) as $comment) { $drafts[] = (object)array("commentId" => $comment["commentId"], "draft" => $language === "Sinhala" ? "Obage prashnayata sthuthiyi. Nivaradi pilithurak pal kirimata pera api meya samalochanaya karannemu." : "Thanks for the question. We will review it and share an accurate response.", "requiresApproval" => true); }
        return (object)array("themes" => $themes, "replyDrafts" => $drafts, "contentIdeas" => count($questions) ? array("Create a follow-up that answers the most repeated audience question.") : array());
    }

    private function validateSession($data, $catalogue) {
        if (!isset($data->endScreens) || !is_array($data->endScreens) || !isset($data->playlists) || !is_array($data->playlists)) { return null; } $allowed = array(); foreach ($catalogue as $video) { $allowed[$video["videoId"]] = true; }
        foreach ($data->endScreens as $item) { $item = is_object($item) ? $item : (object)$item; if (!isset($item->targetVideoId) || !isset($allowed[(string)$item->targetVideoId])) { return null; } }
        foreach ($data->playlists as $item) { $item = is_object($item) ? $item : (object)$item; if (!isset($item->videoIds) || !is_array($item->videoIds)) { return null; } foreach ($item->videoIds as $id) { if (!isset($allowed[(string)$id])) { return null; } } } return $data;
    }

    private function fallbackSession($catalogue) {
        $top = array_slice($catalogue, 0, 5); $end = array(); foreach (array_slice($top, 0, 2) as $video) { $end[] = (object)array("targetVideoId" => $video["videoId"], "reasoning" => "A recent video from the same authorized catalogue; review topical relevance before applying."); }
        return (object)array("endScreens" => $end, "playlists" => count($top) ? array((object)array("name" => "Related viewing path", "videoIds" => array_map(function($video) { return $video["videoId"]; }, $top), "reasoning" => "Draft grouping from the channel's own recent catalogue; validate topic continuity manually.")) : array());
    }

    private function timestampCovered($segments, $start, $end) { foreach ($segments as $value) { $item = is_object($value) ? $value : (object)$value; if (isset($item->startMs, $item->endMs) && intval($item->startMs) <= $start && intval($item->endMs) >= $end) { return true; } } return false; }
    private function evidenceItems($value, $allowedMetrics) { if (!is_array($value)) { return array(); } $allowedSources = array("YOUTUBE_ANALYTICS", "YOUTUBE_REPORTING", "YOUTUBE_DATA", "USER_DATA", "PRODUCT_ESTIMATE"); $out = array(); foreach (array_slice($value, 0, 20) as $item) { $item = is_object($item) ? $item : (object)$item; $source = isset($item->source) ? strtoupper(trim((string)$item->source)) : ""; $metric = isset($item->metric) ? trim((string)$item->metric) : ""; $dateRange = isset($item->dateRange) ? trim((string)$item->dateRange) : ""; if (!in_array($source, $allowedSources, true) || !in_array($metric, $allowedMetrics, true) || !preg_match('/^\d{4}-\d{2}-\d{2}\/\d{4}-\d{2}-\d{2}$/', $dateRange) || ($source === "PRODUCT_ESTIMATE" && !\YtgConfig::derivedMetricsEnabled())) { continue; } $out[] = (object)array("source" => $source, "metric" => $metric, "value" => isset($item->value) ? substr((string)$item->value, 0, 1000) : "", "dateRange" => $dateRange); } return $out; }
    private function strings($value, $maxItems, $maxLength) { if (!is_array($value)) { return array(); } $out = array(); foreach ($value as $item) { if (!is_scalar($item)) { continue; } $item = substr(trim((string)$item), 0, $maxLength); if ($item !== "") { $out[] = $item; } if (count($out) >= $maxItems) { break; } } return array_values(array_unique($out)); }
    private function forbidden($value) { return preg_match('/\b(guarantee(?:d)?|will go viral|viral score|certain to grow)\b/i', json_encode($value)) === 1; }
    private function decodeAgentJson($text) { $text = trim((string)$text); $text = preg_replace('/^```(?:json)?\s*/i', '', $text); $text = preg_replace('/\s*```$/', '', $text); return json_decode($text); }
    private function agentCode($type, $default) { $name = "YTG_" . strtoupper($type) . "_AGENT_CODE"; $value = getenv($name); if (($value === false || trim($value) === "") && defined($name)) { $value = constant($name); } $value = trim((string)$value); return preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $value) ? $value : (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', (string)$default) ? (string)$default : ""); }
    private function recordRun($context, $agentType, $model, $promptVersion, $input, $output, $status, $tokens) { \SOSSData::Insert("ytg_agent_runs", (object)array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "agentType" => $agentType, "model" => $model, "promptVersion" => $promptVersion, "inputRefs" => array("payloadHash" => hash("sha256", json_encode($input)), "videoId" => $context->video->youtubeVideoId), "output" => $output, "validationStatus" => $status, "tokenUsage" => max(0, intval($tokens)), "createdAt" => $this->now())); }
}
?>
