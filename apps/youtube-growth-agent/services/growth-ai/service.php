<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}
if (!class_exists(__NAMESPACE__ . "\\YouTubeCaptionParser")) {
    require_once(dirname(__DIR__) . "/growth-workbench/caption-parser.php");
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

    public function postGenerateCompetitorIdeas($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), false);
        if ($context === null) { return null; }
        $competitors = $this->query("ytg_competitors", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "active", "operator" => "=", "value" => true)), array(array("column" => "competitorId", "direction" => "DESC")), 25, 0);
        $videoRows = $this->query("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "shortlisted", "operator" => "=", "value" => true)), array(array("column" => "performanceRank", "direction" => "ASC")), 15, 0);
        if (!$videoRows->success || !count($videoRows->result)) {
            $videoRows = $this->query("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId)), array(array("column" => "views", "direction" => "DESC")), 15, 0);
        }
        if (!$videoRows->success || !count($videoRows->result)) { return $this->fail($res, "Add and refresh at least one competitor channel before generating competitor-led ideas."); }
        $labels = array(); foreach ($competitors->success ? $competitors->result : array() as $competitor) { $labels[intval($competitor->competitorId)] = $competitor->label; }
        $publicVideos = array(); $allowedIds = array();
        foreach (array_slice($videoRows->result, 0, 10) as $video) {
            $id = (string)$video->youtubeVideoId; $allowedIds[$id] = true;
            $publicVideos[] = array(
                "videoId" => $id, "channel" => isset($labels[intval($video->competitorId)]) ? $labels[intval($video->competitorId)] : "Competitor",
                "title" => $video->title, "description" => substr((string)$video->description, 0, 2500),
                "tags" => $this->decodeJson(isset($video->tags) ? $video->tags : array()), "hashtags" => $this->decodeJson(isset($video->hashtags) ? $video->hashtags : array()),
                "publishedAt" => $video->publishedAt, "views" => intval($video->views), "subscriberCountSnapshot" => intval(isset($video->subscriberCountSnapshot) ? $video->subscriberCountSnapshot : 0),
                "ageDays" => intval(isset($video->ageDays) ? $video->ageDays : 0), "viewsPerDay" => \YtgConfig::derivedMetricsEnabled() ? floatval(isset($video->viewsPerDay) ? $video->viewsPerDay : 0) : null,
                "viewsToSubscriberRatio" => \YtgConfig::derivedMetricsEnabled() && isset($video->viewsToSubscriberRatio) ? floatval($video->viewsToSubscriberRatio) : null,
                "performanceRank" => intval(isset($video->performanceRank) ? $video->performanceRank : 0), "source" => "YOUTUBE_DATA"
            );
        }
        $payload = array(
            "selectedChannelVideo" => $context->safeVideo,
            "selectedChannelTranscript" => $context->transcript === null ? null : substr((string)$context->transcript->plainText, 0, 30000),
            "competitorShortlist" => $publicVideos,
            "language" => $context->language,
            "metricPolicy" => \YtgConfig::derivedMetricsEnabled() ? "Derived ratios are enabled and labelled product calculations." : "Use raw public views, subscriber counts, and publish dates only; derived ratios are unavailable.",
            "competitorTranscriptPolicy" => "Competitor transcripts are not available through the documented API and were not downloaded. Do not claim to have analyzed them."
        );
        $specs = array(
            "COMPETITOR" => "Return strict JSON with observations. Each observations item must contain text and evidenceVideoIds. Identify repeatable patterns without copying a competitor and do not claim access to competitor transcripts.",
            "KEYWORD" => "Return strict JSON with suggestions. Each suggestions item must contain text and evidenceVideoIds. Suggest keyword and search-intent themes from supplied titles, descriptions, tags, hashtags, and the selected owned-video transcript. Exact search volume is unknown.",
            "TITLE" => "Return strict JSON with suggestions. Each suggestions item must contain text and evidenceVideoIds. Provide accurate title angles for the selected channel, not copied competitor titles, and keep each proposed title under 100 characters.",
            "DESCRIPTION" => "Return strict JSON with suggestions. Each suggestions item must contain text and evidenceVideoIds. Suggest a description structure, opening lines, and natural keyword placement grounded in supplied evidence.",
            "HASHTAG" => "Return strict JSON with suggestions. Each suggestions item must contain text and evidenceVideoIds. Suggest a small relevant hashtag set; do not present hashtags as a primary ranking mechanism.",
            "VIDEO_IDEA" => "Return strict JSON with suggestions. Each suggestions item must contain text and evidenceVideoIds, plus optional promise, format, and outline. Propose distinct videos for this channel based on validated competitor patterns and the selected channel context."
        );
        $results = array(); $sources = array(); $agents = array();
        foreach ($specs as $task => $message) {
            $agentCode = $this->agentCode($task, $task === "VIDEO_IDEA" || $task === "COMPETITOR" ? \YtgConfig::agentCode() : "seo-suggestion-agent");
            $run = $this->agentJson($context, $task, $agentCode, $message . " Use " . $context->language . ". Never promise growth or invent metrics.", $payload, "competitor-" . strtolower(str_replace("_", "-", $task)) . "-v1");
            $validated = $run->valid ? $this->validateCompetitorTask($task, $run->data, $allowedIds) : null;
            if ($validated === null) { $validated = $this->fallbackCompetitorTask($task, $publicVideos, $context); $sources[$task] = "RULE_BASED"; }
            else { $sources[$task] = "SAVED_AGENT"; }
            $agents[$task] = $agentCode;
            $results[$task] = $validated;
        }
        foreach (isset($results["VIDEO_IDEA"]->suggestions) ? array_slice($results["VIDEO_IDEA"]->suggestions, 0, 10) : array() as $idea) {
            \SOSSData::Insert("ytg_content_ideas", (object)array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "pillar" => "Competitor opportunity", "idea" => substr((string)$idea->text, 0, 1000), "intent" => "Evidence-led competitor response", "evidence" => array((object)array("source" => "YOUTUBE_DATA", "metric" => "video metadata", "value" => implode(",", $idea->evidenceVideoIds), "dateRange" => date("Y-m-d") . "/" . date("Y-m-d"))), "brief" => $idea, "language" => $context->language, "status" => "Idea", "createdAt" => $this->now(), "updatedAt" => $this->now()));
        }
        $this->audit("COMPETITOR_IDEAS_GENERATED", $context->channel->channelId, "video:" . $context->video->youtubeVideoId, null, array("tasks" => array_keys($results), "competitorVideos" => array_keys($allowedIds)));
        return (object)array("analysis" => (object)$results, "sources" => (object)$sources, "agentMappings" => (object)$agents, "competitorVideos" => $publicVideos, "message" => "Competitor patterns were converted into channel-specific keyword, title, description, hashtag, and video suggestions. Competitor transcripts were not accessed.");
    }

    public function postGenerateCompetitorVideoResearch($req, $res) {
        $context = $this->videoContext($req, $res, array("Owner", "Manager", "Editor"), true);
        if ($context === null) { return null; }
        $ownedTranscript = trim(isset($context->transcript->plainText) ? (string)$context->transcript->plainText : "");
        if ($this->textLength($ownedTranscript) < 100) { return $this->fail($res, "The selected video's transcript must contain at least 100 characters before title research can run."); }
        $body = $this->body($req);
        $query = $this->query("ytg_competitor_videos", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $context->video->youtubeVideoId)), array(array("column" => "performanceRank", "direction" => "ASC")), 20, 0);
        if (!$query->success || !count($query->result)) { return $this->fail($res, "Add at least one competitor video URL before researching it."); }
        $requestedIds = isset($body->competitorVideoIds) && is_array($body->competitorVideoIds) ? $body->competitorVideoIds : array(); $requested = array();
        foreach ($requestedIds as $id) { $id = $this->youtubeId($id); if ($id !== "") { $requested[$id] = true; } }
        $transcriptQuery = $this->query("ytg_competitor_transcripts", array(array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId), array("column" => "contextVideoId", "operator" => "=", "value" => $context->video->youtubeVideoId)), array(array("column" => "importedAt", "direction" => "DESC")), 50, 0);
        $transcripts = array(); foreach ($transcriptQuery->success ? $transcriptQuery->result : array() as $transcript) { $transcripts[(string)$transcript->competitorVideoId] = $transcript; }
        $videos = array(); $allowedIds = array(); $sources = array();
        foreach ($query->result as $video) {
            $id = (string)$video->youtubeVideoId; if (count($requested) && !isset($requested[$id])) { continue; }
            $allowedIds[$id] = true; $transcript = isset($transcripts[$id]) ? $transcripts[$id] : null;
            $videos[] = array("videoId" => $id, "channelTitle" => isset($video->channelTitle) ? $video->channelTitle : "Competitor", "title" => $video->title, "description" => substr((string)$video->description, 0, 5000), "tags" => $this->decodeJson(isset($video->tags) ? $video->tags : array()), "hashtags" => $this->decodeJson(isset($video->hashtags) ? $video->hashtags : array()), "publishedAt" => $video->publishedAt, "durationSeconds" => intval($video->durationSeconds), "views" => intval($video->views), "likes" => intval($video->likes), "comments" => intval($video->comments), "performanceRank" => intval(isset($video->performanceRank) ? $video->performanceRank : 0), "viewsPerDay" => \YtgConfig::derivedMetricsEnabled() ? floatval(isset($video->viewsPerDay) ? $video->viewsPerDay : 0) : null, "transcript" => $transcript === null ? null : array("language" => $transcript->language, "sourceType" => $transcript->sourceType, "plainText" => substr((string)$transcript->plainText, 0, 30000)));
            $sources[] = (object)array("videoId" => $id, "title" => $this->safeText($video->title, 500), "transcriptStatus" => $transcript === null ? "unavailable" : strtolower((string)$transcript->sourceType), "transcriptProvenance" => $transcript === null ? "Public metadata only" : $this->safeText($transcript->provenance, 1000), "freshness" => isset($video->refreshedAt) ? $video->refreshedAt : $this->now());
        }
        if (!count($videos)) { return $this->fail($res, "None of the selected competitor video IDs belong to this video workspace."); }
        $language = $this->detectGenerationLanguage($context, isset($body->languageOverride) ? $body->languageOverride : ""); $context->language = $language->name;
        $payload = array("selectedOwnedVideo" => $context->safeVideo, "selectedOwnedVideoTranscript" => $this->safeText($ownedTranscript, 50000), "competitorVideos" => $videos, "generationLanguage" => $language, "metricPolicy" => \YtgConfig::derivedMetricsEnabled() ? "viewsPerDay is a labelled product estimate" : "Use raw public metrics only", "titlePolicy" => array("primaryTruthSource" => "selectedOwnedVideoTranscript", "currentTitleMustNotBeReused" => (string)$context->video->title, "keywordUse" => "Use one or two high-relevance ranked keywords naturally in each title; never append a keyword list.", "hashtagUse" => "Hashtags guide topic relevance and belong in the hashtag output, not as a stuffed title suffix.", "pairCount" => 3), "outputContract" => $this->competitorResearchOutputContract());
        $message = "Return strict JSON with keywords, hashtags, description, tags, titlePromptPairs, and limitations. First understand the selected owned-video transcript: its central subject, key event or claim, people/organizations involved, viewer value, tone, and factual boundaries. The transcript is the primary truth source for every title; competitor data is secondary SEO and packaging evidence only. Rank only keywords and hashtags that are both relevant to the transcript and supported by supplied competitor evidence. Each keyword/hashtag item needs text, rationale, evidenceVideoIds, evidenceType, and estimated=true. Return exactly three genuinely different titlePromptPairs. Each pair needs: title under 100 characters; angle; transcriptEvidenceExcerpt copied exactly from the supplied owned transcript; usedKeywords containing one or two entries copied exactly from the returned ranked keywords; usedHashtags containing one to three entries copied exactly from the returned ranked hashtags; detailed 16:9 imagePrompt; optional onImageText of five words or fewer; rationale; and evidenceVideoIds. Titles must summarize a real transcript theme, use the ranked keyword naturally, be readable and compelling, and must not equal, begin with, end with, or merely add a suffix/prefix to the current video title. Do not put a list of keywords, language names, 'news protest Sri Lanka sinhala', or hashtags into a title. Do not use generic suffixes such as complete guide or 3 essential lessons unless the transcript itself supports that exact framing. Each image prompt must visualize its own title and transcript evidence with a specific subject, composition, focal point, authentic emotion, relevant setting, lighting, colour/contrast, safe negative space, and exclusions for logos, watermarks, misleading imagery, and unreadable text. Generate all viewer-facing text in " . $language->name . ". Do not copy competitor titles, invent facts or metrics, claim search volume, or promise reach/performance.";
        $run = $this->agentJson($context, "COMPETITOR_VIDEO_RESEARCH", $this->agentCode("COMPETITOR", \YtgConfig::agentCode()), $message, $payload, "competitor-video-research-v2");
        $repairRun = null;
        $validationErrors = array();
        $research = $run->valid ? $this->validateCompetitorVideoResearch($run->data, $allowedIds, $language, $sources, (string)$context->video->title, $ownedTranscript, $validationErrors) : null;
        if (!$run->valid) { $validationErrors[] = "The primary research agent did not return usable JSON."; }
        $source = "SAVED_AGENT";
        if ($research === null) {
            $repairPayload = $payload;
            $repairPayload["rejectedCandidate"] = $run->valid ? $run->data : null;
            $repairPayload["validationErrors"] = array_values(array_unique($validationErrors));
            $repairMessage = "Repair the rejected competitor-video research JSON and return the complete replacement JSON object, not commentary or Markdown. Correct every validation error supplied in the payload. Keep the ranked keywords and hashtags relevant to the owned transcript and competitor evidence. Return exactly three distinct titlePromptPairs. Each must use one or two returned keywords naturally in its title, cite one to three returned hashtags in usedHashtags without putting hashtag symbols in the title, include an exact continuous excerpt copied from selectedOwnedVideoTranscript, remain materially different from currentTitleMustNotBeReused, and provide a title-specific 16:9 image prompt. Preserve the requested generation language and all factual boundaries. Do not reuse a rejected title or append generic suffixes.";
            $repairRun = $this->agentJson($context, "COMPETITOR_VIDEO_RESEARCH_REPAIR", $this->agentCode("TITLE", "seo-suggestion-agent"), $repairMessage, $repairPayload, "competitor-video-research-repair-v2");
            $repairErrors = array();
            $research = $repairRun->valid ? $this->validateCompetitorVideoResearch($repairRun->data, $allowedIds, $language, $sources, (string)$context->video->title, $ownedTranscript, $repairErrors) : null;
            if (!$repairRun->valid) { $repairErrors[] = "The title repair agent did not return usable JSON."; }
            $validationErrors = $repairErrors;
            if ($research !== null) { $source = "SAVED_AGENT_REPAIRED"; }
        }
        if ($research === null) {
            $details = count($validationErrors) ? implode(" ", array_slice(array_values(array_unique($validationErrors)), 0, 4)) : "The returned structure was incomplete.";
            $diagnostics = array("validationErrors" => array_values(array_unique($validationErrors)), "primaryAgent" => $this->agentFailureSnapshot($run), "repairAgent" => $this->agentFailureSnapshot($repairRun));
            $diagnosticJson = json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $this->fail($res, "AI title generation failed quality checks after one automatic repair attempt. " . $details . " No low-quality titles were saved. Troubleshooting details: " . ($diagnosticJson === false ? '{"error":"Diagnostic encoding failed."}' : $diagnosticJson));
        }
        $row = (object)array("channelId" => $context->channel->channelId, "contextVideoId" => $context->video->youtubeVideoId, "sourceVideoIds" => array_keys($allowedIds), "detectedLanguage" => $language->name, "languageCode" => $language->code, "languageConfidence" => $language->confidence, "languageSource" => $language->source, "rankedKeywords" => $research->keywords, "rankedHashtags" => $research->hashtags, "description" => $research->description, "tags" => $research->tags, "titlePromptPairs" => $research->titlePromptPairs, "limitations" => $research->limitations, "resultSource" => $source, "modelVersion" => "competitor-video-research-v2", "createdAt" => $this->now());
        $save = \SOSSData::Insert("ytg_competitor_research", $row); if (!$save->success) { return $this->fail($res, "Competitor research was generated but could not be saved."); }
        $row->researchId = $this->generatedId($save); $research->researchId = $row->researchId; $research->source = $source;
        $this->audit("COMPETITOR_VIDEO_RESEARCH_GENERATED", $context->channel->channelId, "video:" . $context->video->youtubeVideoId, null, array("competitorVideoIds" => array_keys($allowedIds), "language" => $language->name, "source" => $source));
        return (object)array("research" => $research, "message" => "Competitor research generated in " . $language->name . " with three paired title and image-prompt concepts. No YouTube metadata was changed.");
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
        if ($transcript !== null && isset($transcript->sourceType) && $transcript->sourceType === "YOUTUBE_CAPTION") {
            $transcript->segments = YouTubeCaptionParser::normalizeSegments($this->decodeJson(isset($transcript->segments) ? $transcript->segments : array()));
            $transcript->plainText = YouTubeCaptionParser::plainText($transcript->segments);
        }
        $retention = $this->query("ytg_retention_points", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId)), array(array("column" => "elapsedRatio", "direction" => "ASC")), 200, 0);
        $safeVideo = array("videoId" => $video->youtubeVideoId, "title" => $video->title, "description" => substr($video->description, 0, 10000), "durationSeconds" => intval($video->durationSeconds), "contentType" => $video->contentType, "language" => isset($video->language) ? $video->language : "", "tags" => $this->decodeJson(isset($video->tags) ? $video->tags : array()));
        return (object)array("channel" => $channel, "video" => $video, "safeVideo" => $safeVideo, "transcript" => $transcript, "retention" => $retention->success ? $retention->result : array(), "language" => isset($channel->defaultLanguage) && trim($channel->defaultLanguage) !== "" ? $channel->defaultLanguage : "English");
    }

    private function agentJson($context, $agentType, $agentCode, $message, $payload, $promptVersion) {
        $fallback = (object)array("valid" => false, "data" => null, "rawResponse" => "", "error" => "");
        $path = TENANT_RESOURCE_LOCATION . "/apps/ai-agent-creator/services/creator-api/service.php";
        if ($agentCode === "" || !is_file($path)) { $fallback->error = "Saved agent is unavailable."; $this->recordRun($context, $agentType, "", $promptVersion, $payload, array("error" => "agent unavailable"), "FALLBACK", 0); return $fallback; }
        $text = "";
        try {
            require_once($path);
            if (!class_exists("\\ai_agent_creator\\CreatorService")) { throw new \RuntimeException("Saved-agent runtime is unavailable."); }
            $creator = new \ai_agent_creator\CreatorService(); $profile = $this->currentProfile();
            $run = $creator->interactWithAgent(array("agentCode" => $agentCode, "message" => $this->agentPromptMessage($message, $payload), "appCode" => "youtube-growth-agent", "appName" => "YouTube Growth Agent", "profile" => array("profileId" => $profile->id), "conversationKey" => $context->channel->channelId . ":" . $context->video->youtubeVideoId . ":" . strtolower($agentType), "context" => array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "language" => $context->language, "officialMetricsOnly" => true, "channelWriteBack" => false), "payload" => $payload));
            if (!isset($run->success) || !$run->success) { throw new \RuntimeException(isset($run->error) ? $run->error : "Saved-agent execution failed."); }
            $text = isset($run->response) ? $run->response : (isset($run->reply) ? $run->reply : ""); $data = $this->decodeAgentJson($text);
            if (!is_object($data) || $this->forbidden($data)) { throw new \RuntimeException("Saved-agent JSON was invalid or contained unsupported claims."); }
            $this->recordRun($context, $agentType, isset($run->model) ? $run->model : "", $promptVersion, $payload, $data, "STRUCTURE_VALID", isset($run->tokenUsage) ? intval($run->tokenUsage) : 0);
            return (object)array("valid" => true, "data" => $data, "rawResponse" => $this->safeText($text, 12000), "error" => "");
        } catch (\Throwable $error) {
            $fallback->rawResponse = $this->safeText($text, 12000); $fallback->error = $this->safeText($error->getMessage(), 1000);
            $this->recordRun($context, $agentType, "", $promptVersion, $payload, array("error" => $error->getMessage()), "REJECTED_FALLBACK", 0); return $fallback;
        }
    }

    private function agentPromptMessage($instructions, $payload) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new \RuntimeException("The application payload could not be encoded for the saved agent."); }
        return trim((string)$instructions) . "\n\nAUTHORITATIVE APPLICATION PAYLOAD JSON (use these exact values; do not ask the user to provide them again):\n" . $json;
    }

    private function agentFailureSnapshot($run) {
        if (!is_object($run)) { return null; }
        return (object)array(
            "validJson" => isset($run->valid) && $run->valid === true,
            "error" => $this->safeText(isset($run->error) ? $run->error : "", 1000),
            "rawResponse" => $this->safeText(isset($run->rawResponse) ? $run->rawResponse : "", 12000)
        );
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

    private function validateCompetitorTask($task, $data, $allowedIds) {
        $field = $task === "COMPETITOR" ? "observations" : "suggestions";
        if (!isset($data->{$field}) || !is_array($data->{$field})) { return null; }
        $items = array();
        foreach (array_slice($data->{$field}, 0, 12) as $value) {
            $item = is_object($value) ? $value : (object)$value;
            $text = substr(trim(isset($item->text) ? (string)$item->text : ""), 0, 3000);
            $ids = isset($item->evidenceVideoIds) && is_array($item->evidenceVideoIds) ? $item->evidenceVideoIds : array();
            $validIds = array(); foreach ($ids as $id) { $id = (string)$id; if (isset($allowedIds[$id])) { $validIds[] = $id; } }
            if ($text === "" || !count($validIds)) { continue; }
            if ($task === "TITLE" && strlen($text) > 100) { continue; }
            $out = (object)array("text" => $text, "evidenceVideoIds" => array_values(array_unique($validIds)));
            if (isset($item->promise)) { $out->promise = substr(trim((string)$item->promise), 0, 1000); }
            if (isset($item->format)) { $out->format = substr(trim((string)$item->format), 0, 40); }
            if (isset($item->outline) && is_array($item->outline)) { $out->outline = $this->strings($item->outline, 12, 1000); }
            $items[] = $out;
        }
        if (!count($items)) { return null; }
        return (object)array($field => $items, "task" => $task, "dataSource" => "PUBLIC_YOUTUBE_METADATA_AND_AUTHORIZED_CHANNEL_CONTEXT");
    }

    private function fallbackCompetitorTask($task, $videos, $context) {
        $top = count($videos) ? $videos[0] : array("videoId" => "", "title" => "the leading competitor topic", "tags" => array(), "hashtags" => array());
        $id = isset($top["videoId"]) ? $top["videoId"] : "";
        $title = trim(isset($top["title"]) ? (string)$top["title"] : "");
        $items = array();
        if ($task === "COMPETITOR") { $items[] = (object)array("text" => "Review the topic and packaging pattern behind '" . substr($title, 0, 180) . "' while creating a distinct promise for this channel.", "evidenceVideoIds" => array($id)); }
        elseif ($task === "KEYWORD") {
            $terms = array_merge(isset($top["tags"]) && is_array($top["tags"]) ? $top["tags"] : array(), isset($top["hashtags"]) && is_array($top["hashtags"]) ? $top["hashtags"] : array());
            $items[] = (object)array("text" => count($terms) ? "Validate these public metadata themes against channel search terms: " . implode(", ", array_slice($terms, 0, 8)) : "Use the leading video's topic words as seeds and validate them against this channel's actual search terms; exact search volume is unknown.", "evidenceVideoIds" => array($id));
        } elseif ($task === "TITLE") { $items[] = (object)array("text" => substr("A distinct channel-specific guide to " . $title, 0, 100), "evidenceVideoIds" => array($id)); }
        elseif ($task === "DESCRIPTION") { $items[] = (object)array("text" => "Open with the viewer problem, explain this channel's distinct promise, summarize the practical steps, and link to a relevant owned playlist.", "evidenceVideoIds" => array($id)); }
        elseif ($task === "HASHTAG") {
            $hashtags = isset($top["hashtags"]) && is_array($top["hashtags"]) ? array_slice($top["hashtags"], 0, 3) : array();
            $items[] = (object)array("text" => count($hashtags) ? "Consider only directly relevant public hashtags: " . implode(" ", $hashtags) : "Use one to three specific, accurate topic hashtags; hashtags are not treated as the primary discovery lever.", "evidenceVideoIds" => array($id));
        } else { $items[] = (object)array("text" => "Create an original follow-up that addresses the same audience need as '" . substr($title, 0, 180) . "' with this channel's own evidence and perspective.", "evidenceVideoIds" => array($id), "promise" => "Give the audience a clearly differentiated and useful answer.", "format" => isset($context->video->contentType) ? $context->video->contentType : "Long-form", "outline" => array("State the audience problem", "Explain the channel's distinct answer", "Show a concrete example", "Offer the next useful step")); }
        $field = $task === "COMPETITOR" ? "observations" : "suggestions";
        return (object)array($field => $items, "task" => $task, "dataSource" => "RULE_BASED_PUBLIC_METADATA");
    }

    private function competitorResearchOutputContract() {
        return array(
            "responseFormat" => "Return one JSON object only. Do not use Markdown fences or explanatory text.",
            "requiredTopLevelFields" => array("keywords", "hashtags", "description", "titlePromptPairs"),
            "optionalTopLevelFields" => array("tags", "limitations"),
            "keywords" => array("type" => "array", "itemFields" => array("text" => "string", "rank" => "integer", "rationale" => "string", "evidenceVideoIds" => "string[]", "evidenceType" => "metadata|transcript|metadata_and_transcript", "estimated" => "boolean")),
            "hashtags" => array("type" => "array", "itemFields" => array("text" => "#hashtag string", "rank" => "integer", "rationale" => "string", "evidenceVideoIds" => "string[]", "evidenceType" => "metadata|transcript|metadata_and_transcript", "estimated" => "boolean")),
            "description" => "string in generationLanguage",
            "tags" => "optional string[]; copy concise relevant terms from keywords, without # prefixes; the application derives this field from validated keywords and hashtags when omitted",
            "titlePromptPairs" => array("type" => "array", "exactItemCount" => 3, "itemFields" => array("title" => "string under 100 characters", "angle" => "string", "transcriptEvidenceExcerpt" => "exact continuous excerpt copied from selectedOwnedVideoTranscript", "usedKeywords" => "string[1..2], copied exactly from keywords[].text", "usedHashtags" => "string[1..3], copied exactly from hashtags[].text", "imagePrompt" => "specific 16:9 prompt", "onImageText" => "string of at most five words or empty", "rationale" => "string", "evidenceVideoIds" => "string[] containing only supplied competitor video IDs")),
            "limitations" => "string[]"
        );
    }

    private function validateCompetitorVideoResearch($data, $allowedIds, $language, $sources, $currentTitle, $ownedTranscript, &$validationErrors = null) {
        $validationErrors = array();
        if (!is_object($data)) { $validationErrors[] = "The response was not a JSON object."; return null; }
        foreach (array("keywords", "hashtags", "description", "titlePromptPairs") as $field) { if (!isset($data->{$field})) { $validationErrors[] = "Missing required field: " . $field . "."; } }
        if (count($validationErrors)) { return null; }
        if (!is_array($data->titlePromptPairs) || count($data->titlePromptPairs) !== 3) { $validationErrors[] = "Exactly three titlePromptPairs are required."; }
        $keywords = $this->validateRankedResearchItems($data->keywords, $allowedIds, false); $hashtags = $this->validateRankedResearchItems($data->hashtags, $allowedIds, true);
        if (!count($keywords)) { $validationErrors[] = "At least one ranked keyword with valid competitor evidence is required."; }
        if (!count($hashtags)) { $validationErrors[] = "At least one ranked hashtag with valid competitor evidence is required."; }
        $description = $this->safeText($data->description, 5000); $tags = $this->strings(isset($data->tags) ? $data->tags : array(), 30, 100);
        if ($description === "") { $validationErrors[] = "The recommended description is empty."; }
        if (count($validationErrors)) { return null; }
        if (!count($tags)) { $tags = $this->deriveResearchTags($keywords, $hashtags); }
        $keywordMap = array(); foreach ($keywords as $keyword) { $keywordMap[$this->normalizeSearchText($keyword->text)] = $keyword->text; }
        $hashtagMap = array(); foreach ($hashtags as $hashtag) { $hashtagMap[$this->normalizeSearchText($hashtag->text)] = $hashtag->text; }
        $normalizedTranscript = $this->normalizeSearchText($ownedTranscript); if ($normalizedTranscript === "") { $validationErrors[] = "The owned-video transcript is empty after normalization."; return null; }
        $pairs = array();
        foreach ($data->titlePromptPairs as $index => $value) {
            $position = intval($index) + 1; $item = is_object($value) ? $value : (object)$value; $pairErrors = array();
            $rawTitle = trim(isset($item->title) ? (string)$item->title : ""); $title = $this->safeText($rawTitle, 99); $angle = $this->safeText(isset($item->angle) ? $item->angle : "", 500); $prompt = $this->safeText(isset($item->imagePrompt) ? $item->imagePrompt : "", 4000); $rationale = $this->safeText(isset($item->rationale) ? $item->rationale : "", 1500);
            $ids = $this->validEvidenceIds(isset($item->evidenceVideoIds) ? $item->evidenceVideoIds : array(), $allowedIds);
            if ($rawTitle === "") { $pairErrors[] = "title is empty"; } elseif ($this->textLength($rawTitle) >= 100) { $pairErrors[] = "title must be under 100 characters"; }
            if ($angle === "") { $pairErrors[] = "angle is empty"; } if ($prompt === "") { $pairErrors[] = "imagePrompt is empty"; } if ($rationale === "") { $pairErrors[] = "rationale is empty"; } if (!count($ids)) { $pairErrors[] = "evidenceVideoIds does not cite a supplied competitor video"; }
            if ($rawTitle !== "" && $this->titleTooSimilar($title, $currentTitle)) { $pairErrors[] = "title repeats or trivially extends the current title"; }
            if (strpos($title, "#") !== false) { $pairErrors[] = "title contains a hashtag"; }
            $excerpt = $this->safeText(isset($item->transcriptEvidenceExcerpt) ? $item->transcriptEvidenceExcerpt : "", 500); $normalizedExcerpt = $this->normalizeSearchText($excerpt);
            if ($this->textLength($normalizedExcerpt) < 10) { $pairErrors[] = "transcriptEvidenceExcerpt is missing or too short"; } elseif (strpos($normalizedTranscript, $normalizedExcerpt) === false) { $pairErrors[] = "transcriptEvidenceExcerpt is not an exact excerpt from the supplied transcript"; }
            $usedKeywords = $this->validatedSelectedTerms(isset($item->usedKeywords) ? $item->usedKeywords : array(), $keywordMap, 2); if (!count($usedKeywords)) { $pairErrors[] = "usedKeywords must cite one or two returned ranked keywords"; }
            $normalizedTitle = $this->normalizeSearchText($title); $titleUsesKeyword = false; foreach ($usedKeywords as $keyword) { if (strpos($normalizedTitle, $this->normalizeSearchText($keyword)) !== false) { $titleUsesKeyword = true; break; } } if (count($usedKeywords) && !$titleUsesKeyword) { $pairErrors[] = "title does not naturally contain a cited usedKeyword"; }
            $keywordHits = 0; foreach ($keywordMap as $normalizedKeyword => $keyword) { if ($normalizedKeyword !== "" && strpos($normalizedTitle, $normalizedKeyword) !== false) { $keywordHits++; } } if ($keywordHits > 3) { $pairErrors[] = "title contains too many ranked keywords"; }
            $usedHashtags = $this->validatedSelectedTerms(isset($item->usedHashtags) ? $item->usedHashtags : array(), $hashtagMap, 3, true); if (!count($usedHashtags)) { $pairErrors[] = "usedHashtags must cite one to three returned ranked hashtags"; }
            $onImageText = $this->safeText(isset($item->onImageText) ? $item->onImageText : "", 80); if ($onImageText !== "" && count(preg_split('/\s+/u', $onImageText, -1, PREG_SPLIT_NO_EMPTY)) > 5) { $pairErrors[] = "onImageText exceeds five words"; }
            if (count($pairErrors)) { $validationErrors[] = "Title option " . $position . ": " . implode("; ", $pairErrors) . "."; continue; }
            if (stripos($prompt, "16:9") === false) { $prompt .= " Aspect ratio 16:9."; }
            $pairs[] = (object)array("title" => $title, "angle" => $angle, "transcriptEvidenceExcerpt" => $excerpt, "usedKeywords" => $usedKeywords, "usedHashtags" => $usedHashtags, "imagePrompt" => $prompt, "onImageText" => $onImageText, "rationale" => $rationale, "evidenceVideoIds" => $ids);
        }
        if (count($pairs) === 3) { $uniqueTitles = array(); foreach ($pairs as $pair) { $uniqueTitles[] = $this->normalizeSearchText($pair->title); } if (count(array_unique($uniqueTitles)) !== 3) { $validationErrors[] = "All three titles must be distinct."; } }
        if (count($validationErrors) || count($pairs) !== 3) { return null; }
        return (object)array("language" => $language, "sources" => $sources, "keywords" => $keywords, "hashtags" => $hashtags, "description" => $description, "tags" => $tags, "titlePromptPairs" => $pairs, "limitations" => $this->strings(isset($data->limitations) ? $data->limitations : array("Public metrics and product rankings do not guarantee performance."), 10, 1000));
    }

    private function validateRankedResearchItems($values, $allowedIds, $hashtag) {
        if (!is_array($values)) { return array(); } $out = array();
        foreach (array_slice($values, 0, 30) as $index => $value) {
            $item = is_object($value) ? $value : (object)$value; $text = $this->safeText(isset($item->text) ? $item->text : "", 120); if ($hashtag && $text !== "") { $text = "#" . ltrim($text, "#"); }
            $ids = $this->validEvidenceIds(isset($item->evidenceVideoIds) ? $item->evidenceVideoIds : array(), $allowedIds); $rationale = $this->safeText(isset($item->rationale) ? $item->rationale : "", 1000);
            if ($text === "" || $rationale === "" || !count($ids)) { continue; }
            $evidenceType = strtolower($this->safeText(isset($item->evidenceType) ? $item->evidenceType : "metadata", 30)); if (!in_array($evidenceType, array("metadata", "transcript", "metadata_and_transcript"), true)) { $evidenceType = "metadata"; }
            $out[] = (object)array("text" => $text, "rank" => count($out) + 1, "rationale" => $rationale, "evidenceVideoIds" => $ids, "evidenceType" => $evidenceType, "estimated" => true);
        }
        return $out;
    }

    private function deriveResearchTags($keywords, $hashtags) {
        $values = array();
        foreach (array_slice($keywords, 0, 15) as $keyword) { if (isset($keyword->text)) { $values[] = $keyword->text; } }
        foreach (array_slice($hashtags, 0, 5) as $hashtag) { if (isset($hashtag->text)) { $values[] = ltrim((string)$hashtag->text, "#"); } }
        return $this->strings($values, 20, 100);
    }

    private function detectGenerationLanguage($context, $override) {
        $value = trim((string)$override); $source = "user_override"; $confidence = "high";
        if ($value === "" && $context->transcript !== null && isset($context->transcript->language) && trim((string)$context->transcript->language) !== "") { $value = trim((string)$context->transcript->language); $source = "owned_video_transcript"; $confidence = "high"; }
        if ($value === "" && isset($context->video->language) && trim((string)$context->video->language) !== "") { $value = trim((string)$context->video->language); $source = "owned_video_metadata"; $confidence = "medium"; }
        if ($value === "" && isset($context->channel->defaultLanguage) && trim((string)$context->channel->defaultLanguage) !== "") { $value = trim((string)$context->channel->defaultLanguage); $source = "channel_default"; $confidence = "low"; }
        if ($value === "") { $value = "English"; $source = "application_default"; $confidence = "low"; }
        $normalized = $this->lowerText($value); $code = "und"; $name = $value;
        if (in_array($normalized, array("si", "sin", "sinhala", "සිංහල"), true)) { $code = "si"; $name = "Sinhala"; }
        elseif (in_array($normalized, array("en", "eng", "english"), true)) { $code = "en"; $name = "English"; }
        elseif (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]+)?$/i', $value)) { $code = strtolower(substr($value, 0, 12)); }
        return (object)array("name" => $this->safeText($name, 40), "code" => $code, "confidence" => $confidence, "source" => $source);
    }

    private function validatedSelectedTerms($values, $allowedMap, $limit, $allowEmpty = false) {
        if (!is_array($values)) { return $allowEmpty ? array() : array(); } $out = array();
        foreach ($values as $value) { $normalized = $this->normalizeSearchText($value); if ($normalized !== "" && isset($allowedMap[$normalized])) { $out[] = $allowedMap[$normalized]; } if (count($out) >= $limit) { break; } }
        return array_values(array_unique($out));
    }

    private function titleTooSimilar($candidate, $current) {
        $candidateNormalized = $this->normalizeSearchText($candidate); $currentNormalized = $this->normalizeSearchText($current);
        if ($candidateNormalized === "" || $currentNormalized === "") { return false; }
        if ($candidateNormalized === $currentNormalized || strpos($candidateNormalized, $currentNormalized . " ") === 0 || substr($candidateNormalized, -strlen(" " . $currentNormalized)) === " " . $currentNormalized || strpos($currentNormalized, $candidateNormalized . " ") === 0) { return true; }
        $candidateTokens = array_values(array_unique(preg_split('/\s+/u', $candidateNormalized, -1, PREG_SPLIT_NO_EMPTY))); $currentTokens = array_values(array_unique(preg_split('/\s+/u', $currentNormalized, -1, PREG_SPLIT_NO_EMPTY)));
        if (!count($candidateTokens) || !count($currentTokens)) { return false; }
        $intersection = count(array_intersect($candidateTokens, $currentTokens)); $union = count(array_unique(array_merge($candidateTokens, $currentTokens)));
        return $union > 0 && $intersection / $union >= 0.8;
    }

    private function normalizeSearchText($value) {
        $value = $this->lowerText(trim((string)$value)); $value = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $value); return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function validEvidenceIds($values, $allowedIds) { if (!is_array($values)) { return array(); } $out = array(); foreach ($values as $id) { $id = (string)$id; if (isset($allowedIds[$id])) { $out[] = $id; } } return array_values(array_unique($out)); }
    private function safeText($value, $maxLength) { $value = trim((string)$value); return function_exists("mb_substr") ? mb_substr($value, 0, $maxLength, "UTF-8") : substr($value, 0, $maxLength); }
    private function lowerText($value) { return function_exists("mb_strtolower") ? mb_strtolower((string)$value, "UTF-8") : strtolower((string)$value); }
    private function textLength($value) { return function_exists("mb_strlen") ? mb_strlen((string)$value, "UTF-8") : strlen((string)$value); }

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
    private function agentCode($type, $default) { return \YtgConfig::agentCodeForTask($type, $default); }
    private function recordRun($context, $agentType, $model, $promptVersion, $input, $output, $status, $tokens) { \SOSSData::Insert("ytg_agent_runs", (object)array("channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId, "agentType" => $agentType, "model" => $model, "promptVersion" => $promptVersion, "inputRefs" => array("payloadHash" => hash("sha256", json_encode($input)), "videoId" => $context->video->youtubeVideoId), "output" => $output, "validationStatus" => $status, "tokenUsage" => max(0, intval($tokens)), "createdAt" => $this->now())); }
}
?>
