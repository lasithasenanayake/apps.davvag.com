<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class ApiService extends \YtgServiceBase {
    public function postGetConfiguration($req, $res) {
        if ($this->requireProfile($res) === null) {
            return null;
        }
        return \YtgConfig::status();
    }

    public function postSaveConfiguration($req, $res) {
        if ($this->requireProfile($res) === null) {
            return null;
        }
        if (!$this->isSysAdmin()) {
            return $this->fail($res, "Only a system administrator can change the YouTube integration configuration.");
        }

        $body = $this->body($req);
        $clientId = $this->configurationText($body, "googleClientId", 1024);
        $clientSecret = $this->configurationText($body, "googleClientSecret", 2048);
        $encryptionKey = $this->configurationText($body, "encryptionKey", 4096);
        $privacyUrl = $this->configurationText($body, "privacyPolicyUrl", 2048);
        $termsUrl = $this->configurationText($body, "termsUrl", 2048);

        if ($clientSecret === "") {
            $clientSecret = \YtgConfig::clientSecret();
        }
        if ($encryptionKey === "") {
            $encryptionKey = \YtgConfig::encryptionKey();
        }
        if ($clientId === "" || !preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $clientId)) {
            return $this->fail($res, "Enter a valid Google OAuth web client ID ending in .apps.googleusercontent.com.");
        }
        if ($clientSecret === "") {
            return $this->fail($res, "Google client secret is required.");
        }
        if (strlen($encryptionKey) < 32) {
            return $this->fail($res, "Encryption key must contain at least 32 characters.");
        }
        if (!$this->validConfigurationUrl($privacyUrl) || !$this->validConfigurationUrl($termsUrl)) {
            return $this->fail($res, "Privacy policy and terms must be valid HTTP or HTTPS URLs.");
        }

        $oldKey = \YtgConfig::encryptionKey();
        if ($oldKey !== "" && !hash_equals($oldKey, $encryptionKey) && $this->hasStoredOAuthGrants()) {
            return $this->fail($res, "The encryption key cannot be changed while OAuth credentials are stored. Disconnect and delete connected channel data first.");
        }

        $before = \YtgConfig::status();
        try {
            \YtgConfig::save(array(
                "YTG_GOOGLE_CLIENT_ID" => $clientId,
                "YTG_GOOGLE_CLIENT_SECRET" => $clientSecret,
                "YTG_OAUTH_REDIRECT_URI" => \YtgConfig::currentServiceRedirectUri(),
                "YTG_ENCRYPTION_KEY" => $encryptionKey,
                "YTG_PRIVACY_POLICY_URL" => $privacyUrl,
                "YTG_TERMS_URL" => $termsUrl
            ));
        } catch (\Throwable $error) {
            return $this->fail($res, "Configuration could not be saved: " . $error->getMessage());
        }

        $configuration = \YtgConfig::status();
        $this->audit("CONFIGURATION_SAVED", "", "youtube-growth-agent:configuration", array(
            "ready" => $before->ready,
            "checks" => $before->checks
        ), array(
            "ready" => $configuration->ready,
            "checks" => $configuration->checks
        ));
        return (object)array(
            "message" => "YouTube integration configuration saved.",
            "configuration" => $configuration
        );
    }

    public function postListChannels($req, $res) {
        $profile = $this->requireProfile($res);
        if ($profile === null) {
            return null;
        }

        $channels = array();
        if ($this->isSysAdmin()) {
            $result = $this->query("ytg_channels", array(), array(array("column" => "updatedAt", "direction" => "DESC")), 500, 0);
            if ($result->success) {
                foreach ($result->result as $channel) {
                    $channels[] = $this->decorateChannel($channel, "Owner");
                }
            }
        } else {
            $accessRows = $this->query("ytg_channel_access", array(
                array("column" => "profileId", "operator" => "=", "value" => $profile->id),
                array("column" => "status", "operator" => "=", "value" => "Active")
            ), array(array("column" => "accessId", "direction" => "DESC")), 500, 0);
            if ($accessRows->success) {
                foreach ($accessRows->result as $access) {
                    $channel = $this->first("ytg_channels", array(array("column" => "channelId", "operator" => "=", "value" => $access->channelId)));
                    if ($channel !== null) {
                        $channels[] = $this->decorateChannel($channel, isset($access->role) ? $access->role : "Viewer");
                    }
                }
            }
        }

        return (object)array(
            "channels" => $channels,
            "configuration" => \YtgConfig::status(),
            "selectedChannelRequired" => true,
            "crossChannelTotalsAvailable" => false
        );
    }

    public function postGetDashboard($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $days = isset($body->days) ? intval($body->days) : 28;
        if (!in_array($days, array(7, 28, 90, 365), true)) {
            return $this->fail($res, "Dashboard range must be 7, 28, 90, or 365 days.");
        }

        $end = date("Y-m-d");
        $start = date("Y-m-d", strtotime("-" . ($days - 1) . " days"));
        $analytics = $this->query("ytg_analytics_daily", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => ""),
            array("column" => "date", "operator" => ">=", "value" => $start),
            array("column" => "date", "operator" => "<=", "value" => $end)
        ), array(array("column" => "date", "direction" => "ASC")), 1000, 0);

        $summary = (object)array(
            "views" => 0,
            "engagedViews" => 0,
            "watchMinutes" => 0.0,
            "subscribersGained" => 0,
            "subscribersLost" => 0,
            "likes" => 0,
            "comments" => 0,
            "shares" => 0,
            "latestAverageViewDuration" => null,
            "latestAverageViewPercentage" => null
        );
        $daily = array();
        if ($analytics->success) {
            foreach ($analytics->result as $row) {
                $summary->views += intval(isset($row->views) ? $row->views : 0);
                $summary->engagedViews += intval(isset($row->engagedViews) ? $row->engagedViews : 0);
                $summary->watchMinutes += floatval(isset($row->watchMinutes) ? $row->watchMinutes : 0);
                $summary->subscribersGained += intval(isset($row->subscribersGained) ? $row->subscribersGained : 0);
                $summary->subscribersLost += intval(isset($row->subscribersLost) ? $row->subscribersLost : 0);
                $summary->likes += intval(isset($row->likes) ? $row->likes : 0);
                $summary->comments += intval(isset($row->comments) ? $row->comments : 0);
                $summary->shares += intval(isset($row->shares) ? $row->shares : 0);
                $summary->latestAverageViewDuration = isset($row->avgViewDuration) ? floatval($row->avgViewDuration) : null;
                $summary->latestAverageViewPercentage = isset($row->avgViewPercentage) ? floatval($row->avgViewPercentage) : null;
                $daily[] = $row;
            }
        }

        $reach = $this->query("ytg_reach_daily", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "date", "operator" => ">=", "value" => $start),
            array("column" => "date", "operator" => "<=", "value" => $end)
        ), array(array("column" => "date", "direction" => "DESC")), 1000, 0);
        $reachSummary = (object)array("impressions" => 0, "latestImpressionsCtr" => null, "rows" => 0);
        if ($reach->success) {
            foreach ($reach->result as $row) {
                $reachSummary->impressions += intval(isset($row->impressions) ? $row->impressions : 0);
                if ($reachSummary->latestImpressionsCtr === null && isset($row->impressionsCtr)) {
                    $reachSummary->latestImpressionsCtr = floatval($row->impressionsCtr);
                }
                $reachSummary->rows++;
            }
        }

        $recommendations = $this->query("ytg_recommendations", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "status", "operator" => "IN", "value" => array("New", "Accepted", "In Progress", "Needs Data"))
        ), array(array("column" => "recommendationId", "direction" => "DESC")), 3, 0);

        return (object)array(
            "channel" => $this->decorateChannel($channel, isset($channel->_accessRole) ? $channel->_accessRole : "Viewer"),
            "range" => (object)array("days" => $days, "startDate" => $start, "endDate" => $end),
            "metrics" => $summary,
            "reach" => $reachSummary,
            "daily" => $daily,
            "topVideos" => $this->topVideos($channel->channelId, 5),
            "recommendations" => $recommendations->success ? $this->normalizeRecommendations($recommendations->result) : array(),
            "labels" => (object)array(
                "analytics" => "YouTube metric",
                "reach" => "YouTube Reporting metric",
                "recommendations" => "Product recommendation; expected outcomes are hypotheses"
            ),
            "freshness" => (object)array(
                "metadata" => isset($channel->lastMetadataSyncAt) ? $channel->lastMetadataSyncAt : null,
                "analytics" => isset($channel->lastAnalyticsSyncAt) ? $channel->lastAnalyticsSyncAt : null,
                "reporting" => isset($channel->lastReportingSyncAt) ? $channel->lastReportingSyncAt : null
            )
        );
    }

    public function postListVideos($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $pageSize = isset($body->pageSize) ? max(1, min(100, intval($body->pageSize))) : 25;
        $pageFrom = isset($body->pageFrom) ? max(0, intval($body->pageFrom)) : 0;
        $conditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId));
        $contentType = isset($body->contentType) ? trim((string)$body->contentType) : "";
        if ($contentType !== "") {
            if (!in_array($contentType, array("Long-form", "Short", "Live", "Archived Live"), true)) {
                return $this->fail($res, "Invalid video content type filter.");
            }
            $conditions[] = array("column" => "contentType", "operator" => "=", "value" => $contentType);
        }
        $search = isset($body->search) ? trim((string)$body->search) : "";
        if ($search !== "") {
            $conditions[] = array("column" => "title", "operator" => "LIKE", "value" => "%" . substr($search, 0, 120) . "%");
        }
        $sortMap = array("publishedAt" => "publishedAt", "title" => "title", "durationSeconds" => "durationSeconds");
        $sort = isset($body->sort) && isset($sortMap[$body->sort]) ? $sortMap[$body->sort] : "publishedAt";
        $direction = isset($body->direction) && strtoupper((string)$body->direction) === "ASC" ? "ASC" : "DESC";
        $result = $this->query("ytg_videos", $conditions, array(array("column" => $sort, "direction" => $direction)), $pageSize, $pageFrom);
        if (!$result->success) {
            return $this->fail($res, "Video catalogue query failed.");
        }
        return (object)array(
            "items" => $result->result,
            "numberOfRecords" => isset($result->numberOfRecords) ? intval($result->numberOfRecords) : count($result->result),
            "pageSize" => $pageSize,
            "pageFrom" => $pageFrom
        );
    }

    public function postGetVideoAnalysis($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $videoId = $this->youtubeId(isset($body->videoId) ? $body->videoId : "");
        if ($videoId === "") {
            return $this->fail($res, "A valid YouTube video ID is required.");
        }
        $video = $this->first("ytg_videos", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)
        ));
        if ($video === null) {
            return $this->fail($res, "Video was not found in this channel workspace.");
        }
        $statistics = $this->query("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $videoId)
        ), array(array("column" => "capturedAt", "direction" => "DESC")), 90, 0);
        $recommendations = $this->query("ytg_recommendations", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "videoId", "operator" => "=", "value" => $videoId)
        ), array(array("column" => "recommendationId", "direction" => "DESC")), 100, 0);
        return (object)array(
            "video" => $video,
            "statistics" => $statistics->success ? $statistics->result : array(),
            "recommendations" => $recommendations->success ? $this->normalizeRecommendations($recommendations->result) : array(),
            "retention" => array(),
            "transcript" => null,
            "phaseNotice" => "Retention curves and transcript analysis are Phase 2; no YouTube audiovisual content is downloaded."
        );
    }

    public function postListRecommendations($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $conditions = array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId));
        if (isset($body->status) && trim((string)$body->status) !== "") {
            $status = $this->recommendationStatus($body->status);
            if ($status === "") {
                return $this->fail($res, "Invalid recommendation status.");
            }
            $conditions[] = array("column" => "status", "operator" => "=", "value" => $status);
        }
        if (isset($body->type) && trim((string)$body->type) !== "") {
            $type = strtoupper(trim((string)$body->type));
            if (!in_array($type, $this->recommendationTypes(), true)) {
                return $this->fail($res, "Invalid recommendation type.");
            }
            $conditions[] = array("column" => "type", "operator" => "=", "value" => $type);
        }
        $pageSize = isset($body->pageSize) ? max(1, min(100, intval($body->pageSize))) : 50;
        $pageFrom = isset($body->pageFrom) ? max(0, intval($body->pageFrom)) : 0;
        $result = $this->query("ytg_recommendations", $conditions, array(array("column" => "recommendationId", "direction" => "DESC")), $pageSize, $pageFrom);
        if (!$result->success) {
            return $this->fail($res, "Recommendation query failed.");
        }
        return (object)array(
            "items" => $this->normalizeRecommendations($result->result),
            "numberOfRecords" => isset($result->numberOfRecords) ? intval($result->numberOfRecords) : count($result->result),
            "pageSize" => $pageSize,
            "pageFrom" => $pageFrom
        );
    }

    public function postUpdateRecommendationStatus($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) {
            return null;
        }
        $recommendationId = isset($body->recommendationId) ? intval($body->recommendationId) : 0;
        $status = $this->recommendationStatus(isset($body->status) ? $body->status : "");
        if ($recommendationId <= 0 || $status === "") {
            return $this->fail($res, "Recommendation ID and valid status are required.");
        }
        $item = $this->first("ytg_recommendations", array(
            array("column" => "recommendationId", "operator" => "=", "value" => $recommendationId),
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId)
        ));
        if ($item === null) {
            return $this->fail($res, "Recommendation was not found in this channel workspace.");
        }
        $before = clone $item;
        $item->status = $status;
        $item->updatedAt = $this->now();
        $item->completedAt = $status === "Completed" ? $this->now() : null;
        $result = \SOSSData::Update("ytg_recommendations", $item);
        if (!$result->success) {
            return $this->fail($res, "Recommendation status could not be updated.");
        }
        $this->audit("RECOMMENDATION_STATUS", $channel->channelId, "recommendation:" . $recommendationId, $before, $item);
        return $this->normalizeRecommendation($item);
    }

    public function postListSyncJobs($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $result = $this->query("ytg_sync_jobs", array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId)), array(array("column" => "jobId", "direction" => "DESC")), 50, 0);
        return $result->success ? $result->result : array();
    }

    private function decorateChannel($channel, $role) {
        $safe = $this->safeChannel($channel);
        $safe->role = $role;
        $open = $this->query("ytg_recommendations", array(
            array("column" => "channelId", "operator" => "=", "value" => $channel->channelId),
            array("column" => "status", "operator" => "IN", "value" => array("New", "Accepted", "In Progress", "Needs Data"))
        ), array(), 1, 0);
        $safe->openRecommendations = $open->success && isset($open->numberOfRecords) ? intval($open->numberOfRecords) : ($open->success ? count($open->result) : 0);
        $safe->quotaUsedToday = $this->quotaUsedToday($channel->channelId);
        $safe->quotaLimit = \YtgConfig::dailyQuotaLimit();
        return $safe;
    }

    private function topVideos($channelId, $limit) {
        $stats = $this->query("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "capturedDate", "operator" => "=", "value" => $this->today())
        ), array(array("column" => "views", "direction" => "DESC")), $limit, 0);
        $out = array();
        if ($stats->success) {
            foreach ($stats->result as $row) {
                $video = $this->first("ytg_videos", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "youtubeVideoId", "operator" => "=", "value" => $row->videoId)
                ));
                $out[] = (object)array(
                    "videoId" => $row->videoId,
                    "title" => $video !== null && isset($video->title) ? $video->title : $row->videoId,
                    "thumbnailUrl" => $video !== null && isset($video->thumbnailUrl) ? $video->thumbnailUrl : "",
                    "views" => intval(isset($row->views) ? $row->views : 0),
                    "likes" => intval(isset($row->likes) ? $row->likes : 0),
                    "comments" => intval(isset($row->comments) ? $row->comments : 0),
                    "source" => "YOUTUBE_DATA",
                    "capturedAt" => isset($row->capturedAt) ? $row->capturedAt : null
                );
            }
        }
        return $out;
    }

    private function normalizeRecommendations($items) {
        $out = array();
        foreach ($items as $item) {
            $out[] = $this->normalizeRecommendation($item);
        }
        return $out;
    }

    private function normalizeRecommendation($item) {
        $copy = clone $item;
        $copy->evidence = $this->decodeJson(isset($copy->evidence) ? $copy->evidence : array());
        $copy->actions = $this->decodeJson(isset($copy->actions) ? $copy->actions : array());
        $copy->requiresApproval = true;
        return $copy;
    }

    private function recommendationTypes() {
        return array("PACKAGING", "RETENTION", "SEO", "SHORTS", "COMMUNITY", "PLAYLIST", "CONTENT_IDEA", "TIMING");
    }

    private function recommendationStatus($value) {
        $map = array(
            "new" => "New",
            "accepted" => "Accepted",
            "in progress" => "In Progress",
            "completed" => "Completed",
            "dismissed" => "Dismissed",
            "needs data" => "Needs Data",
            "reopened" => "New"
        );
        $key = strtolower(trim((string)$value));
        return isset($map[$key]) ? $map[$key] : "";
    }

    private function configurationText($body, $name, $maxLength) {
        if (!is_object($body) || !isset($body->$name)) {
            return "";
        }
        return substr(trim((string)$body->$name), 0, $maxLength);
    }

    private function validConfigurationUrl($value) {
        if ($value === "" || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, array("http", "https"), true);
    }

    private function hasStoredOAuthGrants() {
        $result = $this->query("ytg_oauth_grants", array(), array(array("column" => "grantId", "direction" => "DESC")), 100, 0);
        if (!$result->success) {
            return false;
        }
        foreach ($result->result as $grant) {
            if (isset($grant->credentialRef) && trim((string)$grant->credentialRef) !== "") {
                return true;
            }
        }
        return false;
    }
}

?>
