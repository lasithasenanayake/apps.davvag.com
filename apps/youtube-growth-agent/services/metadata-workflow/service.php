<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class MetadataWorkflowService extends \YtgServiceBase {
    const BASELINE_DAYS = 7;

    public function postGetMetadataWorkspace($req, $res) {
        $context = $this->ownedVideoContext($req, $res, array());
        if ($context === null) { return null; }
        $suggestions = $this->query("ytg_metadata_suggestions", array(
            array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)
        ), array(array("column" => "suggestionId", "direction" => "DESC")), 25, 0);
        $applications = $this->query("ytg_metadata_applications", array(
            array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)
        ), array(array("column" => "applicationId", "direction" => "DESC")), 100, 0);
        $drafts = array();
        foreach ($suggestions->success ? $suggestions->result : array() as $row) { $drafts[] = $this->normalizeSuggestion($row); }
        $historyRows = $applications->success ? $applications->result : array();
        $history = array();
        foreach ($historyRows as $row) { $history[] = $this->applicationView($row, $historyRows); }
        return (object)array(
            "suggestions" => $drafts,
            "applications" => $history,
            "writeBackEnabled" => false,
            "applicationNotice" => "The current YouTube connection is read-only. Applied records are saved as marked as applied and do not claim that YouTube was updated."
        );
    }

    public function postMarkMetadataApplied($req, $res) {
        $context = $this->ownedVideoContext($req, $res, array("Owner", "Manager", "Editor"));
        if ($context === null) { return null; }
        $body = $this->body($req);
        $suggestionId = isset($body->suggestionId) ? intval($body->suggestionId) : 0;
        $suggestion = $this->first("ytg_metadata_suggestions", array(
            array("column" => "suggestionId", "operator" => "=", "value" => $suggestionId),
            array("column" => "channelId", "operator" => "=", "value" => $context->channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $context->video->youtubeVideoId)
        ));
        if ($suggestion === null) { return $this->fail($res, "Select a saved suggestion version before marking metadata as applied."); }
        $values = $this->validateFinalValues(isset($body->finalValues) ? $body->finalValues : null);
        if ($values === null) { return $this->fail($res, "A valid title, thumbnail prompt, description, chapters or topic outline, keywords, and hashtags are required."); }
        $appliedAt = $this->now();
        $baselineEnd = date("Y-m-d", strtotime(substr($appliedAt, 0, 10) . " -1 day"));
        $baselineStart = date("Y-m-d", strtotime($baselineEnd . " -" . (self::BASELINE_DAYS - 1) . " days"));
        $baseline = $this->metricPeriod($context->channel->channelId, $context->video->youtubeVideoId, $baselineStart, $baselineEnd);
        $before = (object)array(
            "title" => isset($context->video->title) ? (string)$context->video->title : "",
            "description" => isset($context->video->description) ? (string)$context->video->description : "",
            "keywords" => $this->stringList(isset($context->video->tags) ? $this->decodeJson($context->video->tags) : array(), 100, 120),
            "hashtags" => $this->extractHashtags(isset($context->video->description) ? $context->video->description : ""),
            "thumbnailUrl" => isset($context->video->thumbnailUrl) ? (string)$context->video->thumbnailUrl : "",
            "metadataRefreshedAt" => isset($context->video->metadataRefreshedAt) ? $context->video->metadataRefreshedAt : null
        );
        $profile = $this->currentProfile();
        $row = (object)array(
            "channelId" => $context->channel->channelId, "videoId" => $context->video->youtubeVideoId,
            "suggestionId" => $suggestionId, "suggestionVersion" => intval(isset($suggestion->version) ? $suggestion->version : 0),
            "metadataBefore" => $before, "finalValues" => $values, "baseline" => $baseline,
            "applicationMode" => "MARK_ONLY", "status" => "MARKED_AS_APPLIED", "appliedByProfileId" => intval($profile->id), "appliedAt" => $appliedAt
        );
        $save = \SOSSData::Insert("ytg_metadata_applications", $row);
        if (!$save->success) { return $this->fail($res, "The immutable application record could not be saved."); }
        $row->applicationId = $this->generatedId($save);
        $this->audit("METADATA_MARKED_APPLIED", $context->channel->channelId, "video:" . $context->video->youtubeVideoId, $before, array("applicationId" => $row->applicationId, "finalValues" => $values, "youtubeUpdated" => false));
        return (object)array("application" => $this->applicationView($row, array($row)), "message" => "Final edited values were saved as an immutable marked-as-applied record. YouTube was not updated.");
    }

    private function ownedVideoContext($req, $res, $roles) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", $roles);
        if ($channel === null) { return null; }
        $videoId = $this->youtubeId(isset($body->videoId) ? $body->videoId : "");
        $video = $this->first("ytg_videos", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId), array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)));
        if ($video === null) { return $this->fail($res, "Video was not found in this channel workspace."); }
        return (object)array("channel" => $channel, "video" => $video);
    }

    private function validateFinalValues($value) {
        if (!is_object($value)) { return null; }
        $title = $this->cleanText(isset($value->title) ? $value->title : "", 99);
        $prompt = $this->cleanText(isset($value->thumbnailPrompt) ? $value->thumbnailPrompt : "", 5000);
        $description = $this->cleanText(isset($value->description) ? $value->description : "", 50000);
        $chapters = $this->cleanText(isset($value->chapters) ? $value->chapters : "", 10000);
        $keywords = $this->stringList(isset($value->keywords) ? $value->keywords : array(), 100, 120);
        $hashtags = $this->stringList(isset($value->hashtags) ? $value->hashtags : array(), 30, 100, true);
        if ($title === "" || $prompt === "" || $description === "" || $chapters === "" || !count($keywords) || !count($hashtags)) { return null; }
        foreach ($hashtags as &$hashtag) { $hashtag = "#" . ltrim($hashtag, "#"); } unset($hashtag);
        return (object)array("title" => $title, "thumbnailPrompt" => $prompt, "description" => $description, "chapters" => $chapters, "keywords" => array_values(array_unique($keywords)), "hashtags" => array_values(array_unique($hashtags)));
    }

    private function normalizeSuggestion($row) {
        $copy = clone $row;
        $copy->competitorVideoIds = $this->decodeJson(isset($copy->competitorVideoIds) ? $copy->competitorVideoIds : array());
        $copy->competitorFindings = $this->decodeJson(isset($copy->competitorFindings) ? $copy->competitorFindings : array());
        $copy->suggestions = $this->decodeJson(isset($copy->suggestions) ? $copy->suggestions : array());
        return $copy;
    }

    private function applicationView($row, $allRows) {
        $copy = clone $row;
        $copy->metadataBefore = $this->decodeJson(isset($copy->metadataBefore) ? $copy->metadataBefore : array());
        $copy->finalValues = $this->decodeJson(isset($copy->finalValues) ? $copy->finalValues : array());
        $copy->baseline = $this->decodeJson(isset($copy->baseline) ? $copy->baseline : array());
        $next = null;
        $appliedTime = strtotime((string)$copy->appliedAt);
        foreach ($allRows as $candidate) {
            $candidateTime = strtotime((string)$candidate->appliedAt);
            if ($candidateTime > $appliedTime && ($next === null || $candidateTime < strtotime((string)$next->appliedAt))) { $next = $candidate; }
        }
        $baselineStart = is_object($copy->baseline) && isset($copy->baseline->startDate) ? $copy->baseline->startDate : (is_array($copy->baseline) && isset($copy->baseline["startDate"]) ? $copy->baseline["startDate"] : null);
        $baselineEnd = is_object($copy->baseline) && isset($copy->baseline->endDate) ? $copy->baseline->endDate : (is_array($copy->baseline) && isset($copy->baseline["endDate"]) ? $copy->baseline["endDate"] : null);
        $duration = ($baselineStart && $baselineEnd) ? max(1, intval((strtotime($baselineEnd) - strtotime($baselineStart)) / 86400) + 1) : self::BASELINE_DAYS;
        $afterStart = date("Y-m-d", $appliedTime);
        $afterEnd = date("Y-m-d", strtotime($afterStart . " +" . ($duration - 1) . " days"));
        $today = date("Y-m-d"); if ($afterEnd > $today) { $afterEnd = $today; }
        if ($next !== null) { $beforeNext = date("Y-m-d", strtotime(substr((string)$next->appliedAt, 0, 10) . " -1 day")); if ($beforeNext < $afterEnd) { $afterEnd = $beforeNext; } }
        $after = $afterEnd >= $afterStart ? $this->metricPeriod($copy->channelId, $copy->videoId, $afterStart, $afterEnd) : $this->emptyPeriod($afterStart, $afterEnd);
        $comparisonBefore = $copy->baseline;
        if ($after->calendarDays > 0 && $after->calendarDays < $duration && $baselineEnd) {
            $matchedStart = date("Y-m-d", strtotime($baselineEnd . " -" . ($after->calendarDays - 1) . " days"));
            $comparisonBefore = $this->metricPeriod($copy->channelId, $copy->videoId, $matchedStart, $baselineEnd);
        }
        $copy->comparison = $this->comparePeriods($comparisonBefore, $after, $duration, $next);
        $copy->youtubeUpdated = false;
        return $copy;
    }

    private function metricPeriod($channelId, $videoId, $startDate, $endDate) {
        $analytics = $this->query("ytg_analytics_daily", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId),
            array("column" => "date", "operator" => ">=", "value" => $startDate), array("column" => "date", "operator" => "<=", "value" => $endDate)
        ), array(array("column" => "date", "direction" => "ASC")), 1000, 0);
        $reach = $this->query("ytg_reach_daily", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId), array("column" => "videoId", "operator" => "=", "value" => $videoId),
            array("column" => "date", "operator" => ">=", "value" => $startDate), array("column" => "date", "operator" => "<=", "value" => $endDate)
        ), array(array("column" => "date", "direction" => "ASC")), 5000, 0);
        return $this->aggregateMetricRows($analytics->success ? $analytics->result : array(), $reach->success ? $reach->result : array(), $startDate, $endDate);
    }

    private function aggregateMetricRows($analytics, $reach, $startDate, $endDate) {
        $views = 0; $watch = 0.0; $durationWeighted = 0.0; $durationWeight = 0; $analyticsDates = array();
        foreach ($analytics as $row) { $rowViews = max(0, intval(isset($row->views) ? $row->views : 0)); $views += $rowViews; $watch += floatval(isset($row->watchMinutes) ? $row->watchMinutes : 0); if (isset($row->avgViewDuration)) { $durationWeighted += floatval($row->avgViewDuration) * max(1, $rowViews); $durationWeight += max(1, $rowViews); } if (isset($row->date)) { $analyticsDates[substr((string)$row->date, 0, 10)] = true; } }
        $impressions = 0; $ctrWeighted = 0.0; $ctrWeight = 0; $reachDates = array();
        foreach ($reach as $row) { $rowImpressions = max(0, intval(isset($row->impressions) ? $row->impressions : 0)); $impressions += $rowImpressions; if (isset($row->impressionsCtr)) { $ctrWeighted += floatval($row->impressionsCtr) * max(1, $rowImpressions); $ctrWeight += max(1, $rowImpressions); } if (isset($row->date)) { $reachDates[substr((string)$row->date, 0, 10)] = true; } }
        return (object)array("startDate" => $startDate, "endDate" => $endDate, "calendarDays" => $endDate >= $startDate ? intval((strtotime($endDate) - strtotime($startDate)) / 86400) + 1 : 0, "analyticsDays" => count($analyticsDates), "reachDays" => count($reachDates), "metrics" => (object)array(
            "impressions" => count($reach) ? $impressions : null, "impressionsCtr" => $ctrWeight ? $ctrWeighted / $ctrWeight : null,
            "views" => count($analytics) ? $views : null, "averageViewDuration" => $durationWeight ? $durationWeighted / $durationWeight : null,
            "watchMinutes" => count($analytics) ? $watch : null
        ));
    }

    private function emptyPeriod($startDate, $endDate) { return (object)array("startDate" => $startDate, "endDate" => $endDate, "calendarDays" => 0, "analyticsDays" => 0, "reachDays" => 0, "metrics" => (object)array("impressions" => null, "impressionsCtr" => null, "views" => null, "averageViewDuration" => null, "watchMinutes" => null)); }

    private function comparePeriods($beforeValue, $after, $targetDays, $next) {
        $before = is_object($beforeValue) ? $beforeValue : (object)(is_array($beforeValue) ? $beforeValue : array());
        $beforeMetrics = isset($before->metrics) ? (is_object($before->metrics) ? $before->metrics : (object)$before->metrics) : new \stdClass();
        $afterMetrics = isset($after->metrics) ? (is_object($after->metrics) ? $after->metrics : (object)$after->metrics) : new \stdClass();
        $metrics = array();
        foreach (array("impressions", "impressionsCtr", "views", "averageViewDuration", "watchMinutes") as $name) {
            $b = isset($beforeMetrics->{$name}) ? $beforeMetrics->{$name} : null; $a = isset($afterMetrics->{$name}) ? $afterMetrics->{$name} : null;
            $absolute = $b !== null && $a !== null ? floatval($a) - floatval($b) : null;
            $percent = $absolute !== null && floatval($b) != 0.0 ? ($absolute / abs(floatval($b))) * 100 : null;
            $status = ($b === null || $a === null) ? "MISSING_DATA" : (floatval($b) == 0.0 ? "ZERO_BASELINE" : "AVAILABLE");
            $metrics[$name] = (object)array("before" => $b, "after" => $a, "absoluteChange" => $absolute, "percentageChange" => $percent, "status" => $status);
        }
        $elapsedDays = isset($after->calendarDays) ? intval($after->calendarDays) : 0;
        $beforeDays = isset($before->calendarDays) ? intval($before->calendarDays) : 0;
        return (object)array("beforePeriod" => (object)array("startDate" => isset($before->startDate) ? $before->startDate : null, "endDate" => isset($before->endDate) ? $before->endDate : null), "afterPeriod" => (object)array("startDate" => $after->startDate, "endDate" => $after->endDate), "targetDays" => $targetDays, "elapsedDays" => $elapsedDays, "equalDuration" => $elapsedDays > 0 && $beforeDays === $elapsedDays, "status" => $elapsedDays < $targetDays ? "INSUFFICIENT_ELAPSED_TIME" : "READY", "subsequentApplication" => $next === null ? null : (object)array("applicationId" => intval($next->applicationId), "appliedAt" => $next->appliedAt), "metrics" => (object)$metrics, "notice" => "These are observed changes over the displayed periods. They do not establish that metadata changes caused the result.");
    }

    private function stringList($value, $maxItems, $maxLength, $split = false) {
        if (is_string($value) && $split) { $value = preg_split('/[\s,]+/u', $value, -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($value)) { return array(); } $out = array();
        foreach ($value as $item) { if (!is_scalar($item)) { continue; } $item = $this->cleanText($item, $maxLength); if ($item !== "") { $out[] = $item; } if (count($out) >= $maxItems) { break; } }
        return array_values(array_unique($out));
    }

    private function extractHashtags($text) { preg_match_all('/(?:^|\s)(#[\p{L}\p{M}\p{N}_]+)/u', (string)$text, $matches); return isset($matches[1]) ? array_values(array_unique(array_slice($matches[1], 0, 30))) : array(); }
    private function cleanText($value, $maxLength) { $value = trim((string)$value); return function_exists("mb_substr") ? mb_substr($value, 0, $maxLength, "UTF-8") : substr($value, 0, $maxLength); }
}

?>
