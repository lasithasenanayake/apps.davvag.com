<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class AiOrchestratorService extends \YtgServiceBase {
    public function postValidateRecommendation($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) {
            return null;
        }
        $candidate = isset($body->recommendation) ? $body->recommendation : null;
        $validated = $this->validateCandidate($candidate, $channel->channelId);
        if (!$validated->success) {
            return $this->fail($res, implode(" ", $validated->errors));
        }
        return $validated;
    }

    public function postGenerateChannelPlan($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager"));
        if ($channel === null) {
            return null;
        }
        $profile = $this->currentProfile();
        $open = $this->query("ytg_recommendations", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "status", "operator" => "IN", "value" => array("New", "Accepted", "In Progress", "Needs Data"))
        ), array(array("column" => "recommendationId", "direction" => "DESC")), 25, 0);
        $existing = $open->success ? $open->result : array();
        $fallback = $this->fallbackPlan($channel, $existing);

        $creatorPath = TENANT_RESOURCE_LOCATION . "/apps/ai-agent-creator/services/creator-api/service.php";
        if (!is_file($creatorPath) || \YtgConfig::agentCode() === "") {
            $this->recordRun($channel->channelId, "CHANNEL_STRATEGIST", "", "deterministic-v1", array("recommendations" => count($existing)), $fallback, "FALLBACK", 0);
            return (object)array("source" => "RULE_BASED", "plan" => $fallback, "message" => "The saved AI agent is not configured; a deterministic plan was created from validated recommendations.");
        }

        try {
            require_once($creatorPath);
            if (!class_exists("\\ai_agent_creator\\CreatorService")) {
                throw new \RuntimeException("The shared saved-agent runtime is unavailable.");
            }
            $payload = array();
            foreach ($existing as $item) {
                $payload[] = array(
                    "recommendationId" => isset($item->recommendationId) ? $item->recommendationId : 0,
                    "type" => isset($item->type) ? $item->type : "",
                    "priority" => isset($item->priority) ? $item->priority : "",
                    "observation" => isset($item->observation) ? $item->observation : "",
                    "evidence" => isset($item->evidence) ? $this->decodeJson($item->evidence) : array(),
                    "actions" => isset($item->actions) ? $this->decodeJson($item->actions) : array(),
                    "status" => isset($item->status) ? $item->status : ""
                );
            }
            $message = "Create a concise seven-day YouTube channel action plan using only the supplied validated recommendations. Do not invent metrics, timestamps, search volume, or guaranteed outcomes. Return strict JSON with keys summary, doToday, doThisWeek, testNext, createNext, needsData. Every list item must reference a recommendationId.";
            $creator = new \ai_agent_creator\CreatorService();
            $run = $creator->interactWithAgent(array(
                "agentCode" => \YtgConfig::agentCode(),
                "message" => $message,
                "appCode" => "youtube-growth-agent",
                "appName" => "YouTube Growth Agent",
                "profile" => array("profileId" => $profile->id),
                "conversationKey" => $channel->channelId . ":weekly-plan:" . date("o-W"),
                "context" => array(
                    "channelId" => $channel->channelId,
                    "channelTitle" => $channel->title,
                    "language" => isset($channel->defaultLanguage) ? $channel->defaultLanguage : "English",
                    "derivedMetricsEnabled" => \YtgConfig::derivedMetricsEnabled()
                ),
                "payload" => array("recommendations" => $payload)
            ));
            if (!isset($run->success) || !$run->success) {
                throw new \RuntimeException(isset($run->error) ? $run->error : "Saved agent execution failed.");
            }
            $text = isset($run->response) ? $run->response : (isset($run->reply) ? $run->reply : "");
            $plan = $this->decodeAgentJson($text);
            if (!$this->validPlan($plan, $payload)) {
                throw new \RuntimeException("The saved agent returned an invalid or unsupported plan structure.");
            }
            $model = isset($run->model) ? $run->model : "";
            $tokenUsage = isset($run->tokenUsage) ? intval($run->tokenUsage) : 0;
            $this->recordRun($channel->channelId, "CHANNEL_STRATEGIST", $model, "weekly-plan-v1", array("recommendations" => count($payload)), $plan, "VALID", $tokenUsage);
            $this->audit("WEEKLY_PLAN_GENERATED", $channel->channelId, "weekly-plan:" . date("o-W"), null, array("source" => "SAVED_AGENT", "recommendations" => count($payload)));
            return (object)array("source" => "SAVED_AGENT", "plan" => $plan, "message" => "The plan was validated against current recommendation IDs.");
        } catch (\Throwable $error) {
            $this->recordRun($channel->channelId, "CHANNEL_STRATEGIST", "", "weekly-plan-v1", array("recommendations" => count($existing)), array("error" => $error->getMessage()), "REJECTED_FALLBACK", 0);
            return (object)array("source" => "RULE_BASED", "plan" => $fallback, "message" => "AI output was unavailable or rejected; a deterministic plan was returned instead.");
        }
    }

    private function validateCandidate($candidate, $channelId) {
        $errors = array();
        if (is_string($candidate)) {
            $candidate = json_decode($candidate);
        }
        if (!is_object($candidate)) {
            return (object)array("success" => false, "errors" => array("Recommendation must be a JSON object."), "recommendation" => null);
        }
        $required = array("type", "observation", "evidence", "reasoning", "actions", "hypothesis", "confidence", "effort", "metricToReview");
        foreach ($required as $field) {
            if (!isset($candidate->{$field}) || $candidate->{$field} === "" || $candidate->{$field} === array()) {
                $errors[] = $field . " is required.";
            }
        }
        $types = array("PACKAGING", "RETENTION", "SEO", "SHORTS", "COMMUNITY", "PLAYLIST", "CONTENT_IDEA", "TIMING");
        $type = isset($candidate->type) ? strtoupper(trim((string)$candidate->type)) : "";
        if (!in_array($type, $types, true)) {
            $errors[] = "Unsupported recommendation type.";
        }
        $confidence = isset($candidate->confidence) ? strtoupper(trim((string)$candidate->confidence)) : "";
        $effort = isset($candidate->effort) ? strtoupper(trim((string)$candidate->effort)) : "";
        if (!in_array($confidence, array("LOW", "MEDIUM", "HIGH"), true)) {
            $errors[] = "Confidence must be LOW, MEDIUM, or HIGH.";
        }
        if (!in_array($effort, array("SMALL", "MEDIUM", "LARGE"), true)) {
            $errors[] = "Effort must be SMALL, MEDIUM, or LARGE.";
        }
        $forbidden = strtolower((isset($candidate->observation) ? $candidate->observation : "") . " " . (isset($candidate->reasoning) ? $candidate->reasoning : "") . " " . (isset($candidate->hypothesis) ? $candidate->hypothesis : ""));
        if (preg_match('/\b(guarantee(?:d)?|will go viral|certain to grow|viral score)\b/', $forbidden)) {
            $errors[] = "Unsupported guarantee or viral-score language is not allowed.";
        }
        $evidence = isset($candidate->evidence) ? $candidate->evidence : array();
        if (is_object($evidence)) {
            $evidence = array($evidence);
        }
        if (!is_array($evidence) || !count($evidence)) {
            $errors[] = "At least one evidence item is required.";
        } else {
            $allowedSources = array("YOUTUBE_ANALYTICS", "YOUTUBE_REPORTING", "YOUTUBE_DATA", "USER_DATA", "PRODUCT_ESTIMATE");
            $allowedMetrics = array("views", "engagedViews", "estimatedMinutesWatched", "watchMinutes", "averageViewDuration", "averageViewPercentage", "subscribersGained", "subscribersLost", "likes", "comments", "shares", "thumbnailImpressions", "impressions", "thumbnailImpressionsCtr", "trafficSource", "searchTerm", "audienceWatchRatio", "relativeRetentionPerformance");
            foreach ($evidence as $index => $item) {
                $item = is_object($item) ? $item : (object)$item;
                if (!isset($item->source) || !in_array(strtoupper((string)$item->source), $allowedSources, true)) {
                    $errors[] = "Evidence " . ($index + 1) . " has an invalid source.";
                }
                if (!isset($item->metric) || !in_array((string)$item->metric, $allowedMetrics, true)) {
                    $errors[] = "Evidence " . ($index + 1) . " names an unsupported metric.";
                }
                if (!isset($item->dateRange) || !preg_match('/^\d{4}-\d{2}-\d{2}\/\d{4}-\d{2}-\d{2}$/', (string)$item->dateRange)) {
                    $errors[] = "Evidence " . ($index + 1) . " requires a valid date range.";
                }
                if (isset($item->source) && strtoupper((string)$item->source) === "PRODUCT_ESTIMATE" && !\YtgConfig::derivedMetricsEnabled()) {
                    $errors[] = "Product estimates are disabled by configuration.";
                }
            }
        }
        $videoId = isset($candidate->videoId) ? $this->youtubeId($candidate->videoId) : "";
        if (isset($candidate->videoId) && trim((string)$candidate->videoId) !== "" && $videoId === "") {
            $errors[] = "Invalid video ID.";
        }
        if ($videoId !== "") {
            $video = $this->first("ytg_videos", array(
                array("column" => "channelId", "operator" => "=", "value" => $channelId),
                array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)
            ));
            if ($video === null) {
                $errors[] = "The video does not belong to this channel workspace.";
            }
        }
        if (count($errors)) {
            return (object)array("success" => false, "errors" => $errors, "recommendation" => null);
        }
        $candidate->channelId = $channelId;
        $candidate->videoId = $videoId;
        $candidate->type = $type;
        $candidate->confidence = $confidence;
        $candidate->effort = $effort;
        $candidate->requiresApproval = true;
        return (object)array("success" => true, "errors" => array(), "recommendation" => $candidate);
    }

    private function fallbackPlan($channel, $items) {
        $plan = (object)array(
            "summary" => "Prioritized from the current evidence-backed recommendation inbox for " . $channel->title . ".",
            "doToday" => array(),
            "doThisWeek" => array(),
            "testNext" => array(),
            "createNext" => array(),
            "needsData" => array()
        );
        foreach ($items as $item) {
            $entry = (object)array(
                "recommendationId" => intval(isset($item->recommendationId) ? $item->recommendationId : 0),
                "type" => isset($item->type) ? $item->type : "",
                "observation" => isset($item->observation) ? $item->observation : "",
                "metricToReview" => isset($item->metricToReview) ? $item->metricToReview : ""
            );
            $priority = isset($item->priority) ? strtolower((string)$item->priority) : "";
            if ($priority === "do today") {
                $plan->doToday[] = $entry;
            } elseif ($priority === "test next") {
                $plan->testNext[] = $entry;
            } elseif ($priority === "create next") {
                $plan->createNext[] = $entry;
            } elseif ($priority === "needs more data" || (isset($item->status) && $item->status === "Needs Data")) {
                $plan->needsData[] = $entry;
            } else {
                $plan->doThisWeek[] = $entry;
            }
        }
        return $plan;
    }

    private function decodeAgentJson($text) {
        $text = trim((string)$text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        return json_decode($text);
    }

    private function validPlan($plan, $recommendations) {
        if (!is_object($plan)) {
            return false;
        }
        $allowedIds = array();
        foreach ($recommendations as $item) {
            $allowedIds[intval($item["recommendationId"])] = true;
        }
        foreach (array("doToday", "doThisWeek", "testNext", "createNext", "needsData") as $listName) {
            if (!isset($plan->{$listName}) || !is_array($plan->{$listName})) {
                return false;
            }
            foreach ($plan->{$listName} as $entry) {
                $entry = is_object($entry) ? $entry : (object)$entry;
                if (!isset($entry->recommendationId) || !isset($allowedIds[intval($entry->recommendationId)])) {
                    return false;
                }
            }
        }
        $encoded = strtolower(json_encode($plan));
        return !preg_match('/\b(guarantee(?:d)?|will go viral|viral score)\b/', $encoded);
    }

    private function recordRun($channelId, $agentType, $model, $promptVersion, $inputRefs, $output, $status, $tokenUsage) {
        \SOSSData::Insert("ytg_agent_runs", (object)array(
            "channelId" => $channelId,
            "videoId" => "",
            "agentType" => $agentType,
            "model" => $model,
            "promptVersion" => $promptVersion,
            "inputRefs" => $inputRefs,
            "output" => $output,
            "validationStatus" => $status,
            "tokenUsage" => $tokenUsage,
            "createdAt" => $this->now()
        ));
    }
}

?>
