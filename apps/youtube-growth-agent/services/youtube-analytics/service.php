<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class YouTubeAnalyticsService extends \YtgServiceBase {
    public function postGetAnalyticsStatus($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "");
        if ($channel === null) {
            return null;
        }
        $namespaces = array(
            "analyticsDaily" => "ytg_analytics_daily",
            "reachDaily" => "ytg_reach_daily",
            "trafficSources" => "ytg_traffic_sources",
            "videoSnapshots" => "ytg_video_statistics"
        );
        $counts = array();
        foreach ($namespaces as $key => $namespace) {
            $result = $this->query($namespace, array(array("column" => "channelId", "operator" => "=", "value" => $channel->channelId)), array(), 1, 0);
            $counts[$key] = $result->success && isset($result->numberOfRecords) ? intval($result->numberOfRecords) : ($result->success ? count($result->result) : 0);
        }
        return (object)array(
            "channelId" => $channel->channelId,
            "counts" => (object)$counts,
            "freshness" => (object)array(
                "metadata" => isset($channel->lastMetadataSyncAt) ? $channel->lastMetadataSyncAt : null,
                "analytics" => isset($channel->lastAnalyticsSyncAt) ? $channel->lastAnalyticsSyncAt : null,
                "reporting" => isset($channel->lastReportingSyncAt) ? $channel->lastReportingSyncAt : null,
                "authorization" => isset($channel->lastAuthorizationVerifiedAt) ? $channel->lastAuthorizationVerifiedAt : null
            ),
            "sources" => array(
                (object)array("code" => "YOUTUBE_DATA", "label" => "YouTube metric", "official" => true),
                (object)array("code" => "YOUTUBE_ANALYTICS", "label" => "YouTube Analytics metric", "official" => true),
                (object)array("code" => "YOUTUBE_REPORTING", "label" => "YouTube Reporting metric", "official" => true),
                (object)array("code" => "USER_DATA", "label" => "User-provided data", "official" => false),
                (object)array("code" => "PRODUCT_ESTIMATE", "label" => "Product estimate", "official" => false, "enabled" => \YtgConfig::derivedMetricsEnabled())
            ),
            "derivedMetricsEnabled" => \YtgConfig::derivedMetricsEnabled(),
            "note" => \YtgConfig::derivedMetricsEnabled() ? "Product calculations must remain clearly labelled." : "Numeric product scores and derived comparisons are disabled until the applicable policy requirement is enabled."
        );
    }
}

?>
