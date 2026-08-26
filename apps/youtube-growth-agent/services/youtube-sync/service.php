<?php
namespace youtube_growth_agent;

if (!class_exists("YtgServiceBase")) {
    require_once(PLUGIN_PATH_LOCAL . "/youtube-growth/youtube-growth.php");
}

class YouTubeSyncService extends \YtgServiceBase {
    public function postRunInitialSync($req, $res) {
        return $this->runSync($this->body($req), $res, "INITIAL_SYNC");
    }

    public function postRunDailySync($req, $res) {
        return $this->runSync($this->body($req), $res, "DAILY_SYNC");
    }

    public function postSyncVideo($req, $res) {
        $body = $this->body($req);
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager", "Editor"));
        if ($channel === null) {
            return null;
        }
        $youtubeVideoId = $this->youtubeId(isset($body->videoId) ? $body->videoId : "");
        if ($youtubeVideoId === "") {
            return $this->fail($res, "A valid YouTube video ID is required.");
        }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null) {
            return $this->fail($res, "This channel requires YouTube reconnection.");
        }
        $quota = $this->consumeQuota($channel->channelId, "videos.list", 1, true);
        if (!$quota->success) {
            return $this->fail($res, $quota->message);
        }
        try {
            $google = new \YtgGoogleClient();
            $response = $google->dataGet($grant->credentialRef, "videos", array(
                "part" => "snippet,contentDetails,statistics,status,liveStreamingDetails",
                "id" => $youtubeVideoId,
                "maxResults" => 1
            ));
            if (!$response->success || !isset($response->data["items"][0])) {
                return $this->fail($res, $response->error !== "" ? $response->error : "Video refresh failed.");
            }
            $video = $this->saveVideo($channel->channelId, $response->data["items"][0]);
            if ($video === null) {
                return $this->fail($res, "Video could not be stored.");
            }
            $analytics = $this->syncVideoAnalytics($google, $grant->credentialRef, $channel->channelId, $youtubeVideoId, 28);
            $this->generateRecommendations($channel->channelId);
            $message = "Video and Analytics metrics refreshed.";
            if (!$analytics->success) {
                $message = "Video details refreshed, but YouTube Analytics could not be refreshed: " . $analytics->error;
            } elseif (!$analytics->stored) {
                $message = "Video details refreshed. YouTube Analytics has no activity available for this video in the selected date range yet.";
            }
            $this->audit("VIDEO_SYNC", $channel->channelId, "youtube-video:" . $youtubeVideoId, null, array(
                "metadataSource" => "YOUTUBE_DATA",
                "analytics" => $analytics
            ));
            return (object)array(
                "video" => $video,
                "analytics" => $analytics,
                "quota" => $quota,
                "syncedAt" => $this->now(),
                "message" => $message
            );
        } catch (\Throwable $error) {
            return $this->fail($res, "Video sync failed: " . $error->getMessage());
        }
    }

    private function runSync($body, $res, $type) {
        $channel = $this->requireChannel($res, isset($body->channelId) ? $body->channelId : "", array("Owner", "Manager"));
        if ($channel === null) {
            return null;
        }
        $grant = $this->credentialGrant($channel->channelId);
        if ($grant === null || !isset($grant->credentialRef)) {
            return $this->fail($res, "This channel has no active OAuth grant. Reconnect it before synchronizing.");
        }

        $force = isset($body->force) && $body->force === true;
        $idempotencyKey = $type . ":" . $channel->channelId . ":" . ($type === "DAILY_SYNC" ? $this->today() : "catalogue");
        $prior = $this->first("ytg_sync_jobs", array(array("column" => "idempotencyKey", "operator" => "=", "value" => $idempotencyKey)), array(array("column" => "jobId", "direction" => "DESC")));
        if (!$force && $prior !== null && isset($prior->status) && $prior->status === "Completed") {
            return (object)array("job" => $prior, "idempotent" => true, "message" => "This synchronization window already completed.");
        }
        if (!$force && $prior !== null && isset($prior->status) && $prior->status === "Running" && isset($prior->startedAt) && strtotime($prior->startedAt) > time() - 3600) {
            return (object)array("job" => $prior, "idempotent" => true, "message" => "A synchronization job is already running.");
        }

        $resumeCursor = "";
        $processed = 0;
        if ($prior !== null && isset($prior->status) && in_array($prior->status, array("Partial", "Failed"), true)) {
            $resumeCursor = isset($prior->cursor) ? (string)$prior->cursor : "";
            $processed = isset($prior->processedItems) ? intval($prior->processedItems) : 0;
        }
        $job = (object)array(
            "channelId" => $channel->channelId,
            "type" => $type,
            "externalId" => "",
            "idempotencyKey" => $idempotencyKey,
            "cursor" => $resumeCursor,
            "status" => "Running",
            "attempts" => $prior !== null && isset($prior->attempts) ? intval($prior->attempts) + 1 : 1,
            "processedItems" => $processed,
            "quotaUsed" => $this->quotaUsedToday($channel->channelId),
            "startedAt" => $this->now(),
            "completedAt" => null,
            "error" => "",
            "details" => (object)array("mode" => $type, "resumed" => $resumeCursor !== "")
        );
        $insert = \SOSSData::Insert("ytg_sync_jobs", $job);
        if (!$insert->success) {
            return $this->fail($res, "Unable to create the synchronization job record.");
        }
        $job->jobId = $this->generatedId($insert);

        try {
            $google = new \YtgGoogleClient();
            $channel = $this->refreshChannel($google, $grant->credentialRef, $channel);
            if ($channel === null) {
                throw new \RuntimeException("The authorized YouTube channel was not returned. Reauthorization may be required.");
            }

            $maxPages = $type === "INITIAL_SYNC" ? 20 : 4;
            $catalogue = $this->syncCatalogue($google, $grant->credentialRef, $channel, $job, $maxPages);
            $analyticsDays = $type === "INITIAL_SYNC" ? 365 : 28;
            $analytics = $this->syncAnalytics($google, $grant->credentialRef, $channel->channelId, $analyticsDays);
            $reporting = $this->syncReporting($google, $grant->credentialRef, $channel->channelId, $type === "DAILY_SYNC");
            $recommendationCount = $this->generateRecommendations($channel->channelId);

            $channel->lastMetadataSyncAt = $this->now();
            if ($analytics->success) {
                $channel->lastAnalyticsSyncAt = $this->now();
            }
            if ($reporting->success) {
                $channel->lastReportingSyncAt = $this->now();
            }
            $channel->lastAuthorizationVerifiedAt = $this->now();
            $channel->lastAnalysisAt = $this->now();
            $channel->connectionHealth = "Connected";
            $channel->status = "Connected";
            $channel->updatedAt = $this->now();
            unset($channel->_accessRole);
            \SOSSData::Update("ytg_channels", $channel);

            $job->cursor = $catalogue->nextPageToken;
            $job->processedItems = intval($catalogue->processedItems);
            $job->quotaUsed = $this->quotaUsedToday($channel->channelId);
            $job->status = $catalogue->nextPageToken !== "" ? "Partial" : "Completed";
            $job->completedAt = $this->now();
            $job->details = (object)array(
                "catalogue" => $catalogue,
                "analytics" => $analytics,
                "reporting" => $reporting,
                "recommendationsCreatedOrUpdated" => $recommendationCount
            );
            \SOSSData::Update("ytg_sync_jobs", $job);
            $this->queueSchedule($channel->channelId, "RunDailySync", date("Y-m-d H:i:s", strtotime("tomorrow 02:00")));
            $this->audit("SYNC_COMPLETED", $channel->channelId, "sync-job:" . $job->jobId, null, array("status" => $job->status, "processedItems" => $job->processedItems));
            return (object)array(
                "job" => $job,
                "idempotent" => false,
                "channel" => $this->safeChannel($channel),
                "message" => $job->status === "Partial" ? "The catalogue page limit was reached. Run sync again to resume from the saved cursor." : "YouTube synchronization completed."
            );
        } catch (\Throwable $error) {
            $job->status = "Failed";
            $job->error = substr($error->getMessage(), 0, 9000);
            $job->completedAt = $this->now();
            $job->quotaUsed = $this->quotaUsedToday($channel->channelId);
            \SOSSData::Update("ytg_sync_jobs", $job);
            $channel->connectionHealth = stripos($error->getMessage(), "author") !== false ? "Reauthorization Required" : "Sync Error";
            $channel->updatedAt = $this->now();
            unset($channel->_accessRole);
            \SOSSData::Update("ytg_channels", $channel);
            return $this->fail($res, "YouTube synchronization failed: " . $error->getMessage());
        }
    }

    private function refreshChannel($google, $credentialRef, $channel) {
        $quota = $this->consumeQuota($channel->channelId, "channels.list", 1, true);
        if (!$quota->success) {
            throw new \RuntimeException($quota->message);
        }
        $response = $google->dataGet($credentialRef, "channels", array(
            "part" => "snippet,contentDetails,statistics",
            "mine" => "true",
            "maxResults" => 50
        ));
        if (!$response->success) {
            throw new \RuntimeException($response->error !== "" ? $response->error : "Channel identity refresh failed.");
        }
        $found = null;
        foreach (isset($response->data["items"]) ? $response->data["items"] : array() as $item) {
            if (isset($item["id"]) && $item["id"] === $channel->youtubeChannelId) {
                $found = $item;
                break;
            }
        }
        if ($found === null) {
            return null;
        }
        $snippet = isset($found["snippet"]) ? $found["snippet"] : array();
        $statistics = isset($found["statistics"]) ? $found["statistics"] : array();
        $related = isset($found["contentDetails"]["relatedPlaylists"]) ? $found["contentDetails"]["relatedPlaylists"] : array();
        $channel->title = isset($snippet["title"]) ? $snippet["title"] : $channel->title;
        $channel->handle = isset($snippet["customUrl"]) ? $snippet["customUrl"] : (isset($channel->handle) ? $channel->handle : "");
        $channel->description = isset($snippet["description"]) ? $snippet["description"] : "";
        $channel->uploadsPlaylistId = isset($related["uploads"]) ? $related["uploads"] : $channel->uploadsPlaylistId;
        $channel->subscriberCount = isset($statistics["subscriberCount"]) ? intval($statistics["subscriberCount"]) : 0;
        $channel->viewCount = isset($statistics["viewCount"]) ? intval($statistics["viewCount"]) : 0;
        $channel->videoCount = isset($statistics["videoCount"]) ? intval($statistics["videoCount"]) : 0;
        $channel->thumbnailUrl = $this->thumbnail(isset($snippet["thumbnails"]) ? $snippet["thumbnails"] : array());
        return $channel;
    }

    private function syncCatalogue($google, $credentialRef, $channel, $job, $maxPages) {
        if (!isset($channel->uploadsPlaylistId) || trim((string)$channel->uploadsPlaylistId) === "") {
            throw new \RuntimeException("The channel uploads playlist is unavailable.");
        }
        $pageToken = isset($job->cursor) ? (string)$job->cursor : "";
        $processed = isset($job->processedItems) ? intval($job->processedItems) : 0;
        $pages = 0;
        do {
            $quota = $this->consumeQuota($channel->channelId, "playlistItems.list", 1);
            if (!$quota->success) {
                throw new \RuntimeException($quota->message);
            }
            $params = array(
                "part" => "contentDetails",
                "playlistId" => $channel->uploadsPlaylistId,
                "maxResults" => 50
            );
            if ($pageToken !== "") {
                $params["pageToken"] = $pageToken;
            }
            $playlist = $google->dataGet($credentialRef, "playlistItems", $params);
            if (!$playlist->success) {
                throw new \RuntimeException($playlist->error !== "" ? $playlist->error : "Uploads playlist retrieval failed.");
            }
            $videoIds = array();
            foreach (isset($playlist->data["items"]) ? $playlist->data["items"] : array() as $item) {
                if (isset($item["contentDetails"]["videoId"]) && $this->youtubeId($item["contentDetails"]["videoId"]) !== "") {
                    $videoIds[] = $item["contentDetails"]["videoId"];
                }
            }
            if (count($videoIds)) {
                $quota = $this->consumeQuota($channel->channelId, "videos.list", 1);
                if (!$quota->success) {
                    throw new \RuntimeException($quota->message);
                }
                $videos = $google->dataGet($credentialRef, "videos", array(
                    "part" => "snippet,contentDetails,statistics,status,liveStreamingDetails",
                    "id" => implode(",", array_slice($videoIds, 0, 50)),
                    "maxResults" => 50
                ));
                if (!$videos->success) {
                    throw new \RuntimeException($videos->error !== "" ? $videos->error : "Video batch retrieval failed.");
                }
                foreach (isset($videos->data["items"]) ? $videos->data["items"] : array() as $video) {
                    if ($this->saveVideo($channel->channelId, $video) !== null) {
                        $processed++;
                    }
                }
            }
            $pageToken = isset($playlist->data["nextPageToken"]) ? (string)$playlist->data["nextPageToken"] : "";
            $pages++;
            $job->cursor = $pageToken;
            $job->processedItems = $processed;
            $job->quotaUsed = $this->quotaUsedToday($channel->channelId);
            \SOSSData::Update("ytg_sync_jobs", $job);
        } while ($pageToken !== "" && $pages < $maxPages);

        return (object)array("processedItems" => $processed, "pagesThisRun" => $pages, "nextPageToken" => $pageToken);
    }

    private function saveVideo($channelId, $item) {
        if (!isset($item["id"]) || $this->youtubeId($item["id"]) === "") {
            return null;
        }
        $snippet = isset($item["snippet"]) ? $item["snippet"] : array();
        $details = isset($item["contentDetails"]) ? $item["contentDetails"] : array();
        $statistics = isset($item["statistics"]) ? $item["statistics"] : array();
        $status = isset($item["status"]) ? $item["status"] : array();
        $durationSeconds = $this->durationSeconds(isset($details["duration"]) ? $details["duration"] : "PT0S");
        $live = isset($snippet["liveBroadcastContent"]) ? $snippet["liveBroadcastContent"] : "none";
        $contentType = "Long-form";
        $contentTypeSource = "PRODUCT_CLASSIFICATION";
        if ($live === "live" || $live === "upcoming") {
            $contentType = "Live";
            $contentTypeSource = "YOUTUBE_DATA";
        } elseif (isset($item["liveStreamingDetails"]["actualEndTime"])) {
            $contentType = "Archived Live";
            $contentTypeSource = "YOUTUBE_DATA";
        } elseif ($durationSeconds > 0 && $durationSeconds <= 180 && $this->hasShortsMarker($snippet)) {
            $contentType = "Short";
        }
        $existing = $this->first("ytg_videos", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "youtubeVideoId", "operator" => "=", "value" => $item["id"])
        ));
        $video = (object)array(
            "channelId" => $channelId,
            "youtubeVideoId" => $item["id"],
            "title" => isset($snippet["title"]) ? $snippet["title"] : "",
            "description" => isset($snippet["description"]) ? $snippet["description"] : "",
            "publishedAt" => isset($snippet["publishedAt"]) ? $this->apiDate($snippet["publishedAt"]) : null,
            "durationSeconds" => $durationSeconds,
            "contentType" => $contentType,
            "contentTypeSource" => $contentTypeSource,
            "language" => isset($snippet["defaultAudioLanguage"]) ? $snippet["defaultAudioLanguage"] : (isset($snippet["defaultLanguage"]) ? $snippet["defaultLanguage"] : ""),
            "categoryId" => isset($snippet["categoryId"]) ? $snippet["categoryId"] : "",
            "tags" => isset($snippet["tags"]) ? $snippet["tags"] : array(),
            "thumbnailUrl" => $this->thumbnail(isset($snippet["thumbnails"]) ? $snippet["thumbnails"] : array()),
            "privacyStatus" => isset($status["privacyStatus"]) ? $status["privacyStatus"] : "",
            "liveBroadcastContent" => $live,
            "hasTranscript" => $existing !== null && isset($existing->hasTranscript) ? $existing->hasTranscript : false,
            "metadataRefreshedAt" => $this->now(),
            "status" => "Active"
        );
        $save = $this->upsert("ytg_videos", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "youtubeVideoId", "operator" => "=", "value" => $item["id"])
        ), $video);
        if (!$save->success) {
            return null;
        }
        if ($existing !== null && isset($existing->videoId)) {
            $video->videoId = $existing->videoId;
        } else {
            $video->videoId = $this->generatedId($save);
        }
        $this->upsert("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "videoId", "operator" => "=", "value" => $item["id"]),
            array("column" => "capturedDate", "operator" => "=", "value" => $this->today()),
            array("column" => "source", "operator" => "=", "value" => "YOUTUBE_DATA")
        ), (object)array(
            "channelId" => $channelId,
            "videoId" => $item["id"],
            "capturedDate" => $this->today(),
            "capturedAt" => $this->now(),
            "views" => isset($statistics["viewCount"]) ? intval($statistics["viewCount"]) : 0,
            "likes" => isset($statistics["likeCount"]) ? intval($statistics["likeCount"]) : 0,
            "comments" => isset($statistics["commentCount"]) ? intval($statistics["commentCount"]) : 0,
            "source" => "YOUTUBE_DATA"
        ));
        return $video;
    }

    private function syncAnalytics($google, $credentialRef, $channelId, $days) {
        $end = date("Y-m-d", strtotime("yesterday"));
        $start = date("Y-m-d", strtotime($end . " -" . max(0, $days - 1) . " days"));
        $params = array(
            "ids" => "channel==MINE",
            "startDate" => $start,
            "endDate" => $end,
            "metrics" => "views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage,subscribersGained,subscribersLost,likes,comments,shares",
            "dimensions" => "day",
            "sort" => "day",
            "maxResults" => 500
        );
        $response = $google->analytics($credentialRef, $params);
        if (!$response->success) {
            return (object)array("success" => false, "error" => $response->error, "dailyRows" => 0, "videoRows" => 0);
        }
        $rows = $this->analyticsRows($response->data);
        $dailyCount = 0;
        foreach ($rows as $row) {
            if (!isset($row["day"])) {
                continue;
            }
            $save = $this->upsert("ytg_analytics_daily", array(
                array("column" => "channelId", "operator" => "=", "value" => $channelId),
                array("column" => "videoId", "operator" => "=", "value" => ""),
                array("column" => "date", "operator" => "=", "value" => $row["day"])
            ), (object)array(
                "channelId" => $channelId,
                "videoId" => "",
                "date" => $row["day"],
                "contentType" => "All",
                "views" => $this->metric($row, "views"),
                "engagedViews" => 0,
                "watchMinutes" => $this->metric($row, "estimatedMinutesWatched"),
                "avgViewDuration" => $this->metric($row, "averageViewDuration"),
                "avgViewPercentage" => $this->metric($row, "averageViewPercentage"),
                "subscribersGained" => $this->metric($row, "subscribersGained"),
                "subscribersLost" => $this->metric($row, "subscribersLost"),
                "likes" => $this->metric($row, "likes"),
                "comments" => $this->metric($row, "comments"),
                "shares" => $this->metric($row, "shares"),
                "source" => "YOUTUBE_ANALYTICS",
                "refreshedAt" => $this->now()
            ));
            if ($save->success) {
                $dailyCount++;
            }
        }

        $videoParams = $params;
        $videoParams["metrics"] = "views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage";
        $videoParams["dimensions"] = "video";
        $videoParams["sort"] = "-views";
        $videoParams["maxResults"] = 200;
        $videoParams["startIndex"] = 1;
        $videoCount = 0;
        $videoError = "";
        $videoPages = 0;
        do {
            $videoResponse = $google->analytics($credentialRef, $videoParams);
            if (!$videoResponse->success) {
                $videoError = $videoResponse->error;
                break;
            }
            $videoRows = $this->analyticsRows($videoResponse->data);
            foreach ($videoRows as $row) {
                if (!isset($row["video"]) || $this->youtubeId($row["video"]) === "") {
                    continue;
                }
                $save = $this->upsert("ytg_video_statistics", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "videoId", "operator" => "=", "value" => $row["video"]),
                    array("column" => "capturedDate", "operator" => "=", "value" => $this->today()),
                    array("column" => "source", "operator" => "=", "value" => "YOUTUBE_ANALYTICS")
                ), (object)array(
                    "channelId" => $channelId,
                    "videoId" => $row["video"],
                    "capturedDate" => $this->today(),
                    "capturedAt" => $this->now(),
                    "views" => $this->metric($row, "views"),
                    "watchMinutes" => $this->metric($row, "estimatedMinutesWatched"),
                    "avgViewDuration" => $this->metric($row, "averageViewDuration"),
                    "avgViewPercentage" => $this->metric($row, "averageViewPercentage"),
                    "analyticsStartDate" => $start,
                    "analyticsEndDate" => $end,
                    "source" => "YOUTUBE_ANALYTICS"
                ));
                if ($save->success) {
                    $videoCount++;
                }
            }
            $videoPages++;
            if (count($videoRows) < intval($videoParams["maxResults"])) {
                break;
            }
            $videoParams["startIndex"] += count($videoRows);
        } while ($videoPages < 25);
        if ($videoError === "" && $videoPages === 25 && count($videoRows) === intval($videoParams["maxResults"])) {
            $videoError = "The per-video Analytics import reached its 5,000-row safety limit.";
        }
        return (object)array(
            "success" => true,
            "startDate" => $start,
            "endDate" => $end,
            "dailyRows" => $dailyCount,
            "videoRows" => $videoCount,
            "videoPages" => $videoPages,
            "videoError" => $videoError
        );
    }

    private function syncVideoAnalytics($google, $credentialRef, $channelId, $videoId, $days) {
        $end = date("Y-m-d", strtotime("yesterday"));
        $start = date("Y-m-d", strtotime($end . " -" . max(0, $days - 1) . " days"));
        $response = $google->analytics($credentialRef, array(
            "ids" => "channel==MINE",
            "startDate" => $start,
            "endDate" => $end,
            "metrics" => "views,estimatedMinutesWatched,averageViewDuration,averageViewPercentage",
            "filters" => "video==" . $videoId,
            "maxResults" => 1
        ));
        if (!$response->success) {
            return (object)array(
                "success" => false,
                "stored" => false,
                "rows" => 0,
                "startDate" => $start,
                "endDate" => $end,
                "error" => $response->error !== "" ? $response->error : "The Analytics report request failed."
            );
        }
        $rows = $this->analyticsRows($response->data);
        if (!count($rows)) {
            return (object)array(
                "success" => true,
                "stored" => false,
                "rows" => 0,
                "startDate" => $start,
                "endDate" => $end,
                "error" => ""
            );
        }
        $row = $rows[0];
        $save = $this->upsert("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "videoId", "operator" => "=", "value" => $videoId),
            array("column" => "capturedDate", "operator" => "=", "value" => $this->today()),
            array("column" => "source", "operator" => "=", "value" => "YOUTUBE_ANALYTICS")
        ), (object)array(
            "channelId" => $channelId,
            "videoId" => $videoId,
            "capturedDate" => $this->today(),
            "capturedAt" => $this->now(),
            "views" => $this->metric($row, "views"),
            "watchMinutes" => $this->metric($row, "estimatedMinutesWatched"),
            "avgViewDuration" => $this->metric($row, "averageViewDuration"),
            "avgViewPercentage" => $this->metric($row, "averageViewPercentage"),
            "analyticsStartDate" => $start,
            "analyticsEndDate" => $end,
            "source" => "YOUTUBE_ANALYTICS"
        ));
        if (!$save->success) {
            return (object)array(
                "success" => false,
                "stored" => false,
                "rows" => 1,
                "startDate" => $start,
                "endDate" => $end,
                "error" => "The Analytics report was returned but could not be stored."
            );
        }
        return (object)array(
            "success" => true,
            "stored" => true,
            "rows" => 1,
            "startDate" => $start,
            "endDate" => $end,
            "error" => ""
        );
    }

    private function syncReporting($google, $credentialRef, $channelId, $importReports) {
        $jobsResponse = $google->reportingGet($credentialRef, "/v1/jobs");
        if (!$jobsResponse->success) {
            return (object)array("success" => false, "error" => $jobsResponse->error, "jobsCreated" => 0, "reportsImported" => 0);
        }
        $jobs = isset($jobsResponse->data["jobs"]) ? $jobsResponse->data["jobs"] : array();
        $byType = array();
        foreach ($jobs as $job) {
            if (isset($job["reportTypeId"])) {
                $byType[$job["reportTypeId"]] = $job;
            }
        }
        $required = array("channel_basic_a3", "channel_traffic_source_a3", "channel_reach_basic_a1", "channel_reach_combined_a1");
        $created = 0;
        foreach ($required as $reportTypeId) {
            if (!isset($byType[$reportTypeId])) {
                $create = $google->reportingPost($credentialRef, "/v1/jobs", array("reportTypeId" => $reportTypeId, "name" => "DAVVAG " . $reportTypeId));
                if ($create->success && is_array($create->data)) {
                    $byType[$reportTypeId] = $create->data;
                    $created++;
                }
            }
        }
        $imported = 0;
        if ($importReports) {
            foreach ($byType as $reportTypeId => $job) {
                if (!isset($job["id"]) || !in_array($reportTypeId, array("channel_traffic_source_a3", "channel_reach_basic_a1", "channel_reach_combined_a1"), true)) {
                    continue;
                }
                $reports = $google->reportingGet($credentialRef, "/v1/jobs/" . rawurlencode($job["id"]) . "/reports", array("pageSize" => 10));
                if (!$reports->success || !isset($reports->data["reports"])) {
                    continue;
                }
                foreach ($reports->data["reports"] as $report) {
                    if (!isset($report["id"], $report["downloadUrl"])) {
                        continue;
                    }
                    $already = $this->first("ytg_sync_jobs", array(
                        array("column" => "channelId", "operator" => "=", "value" => $channelId),
                        array("column" => "type", "operator" => "=", "value" => "REPORT_IMPORT"),
                        array("column" => "externalId", "operator" => "=", "value" => $report["id"]),
                        array("column" => "status", "operator" => "=", "value" => "Completed")
                    ));
                    if ($already !== null) {
                        continue;
                    }
                    $download = $google->downloadReport($credentialRef, $report["downloadUrl"]);
                    if (!$download->success || strlen($download->text) > 20971520) {
                        continue;
                    }
                    $count = $this->importReportCsv($channelId, $reportTypeId, $report["id"], $download->text);
                    \SOSSData::Insert("ytg_sync_jobs", (object)array(
                        "channelId" => $channelId,
                        "type" => "REPORT_IMPORT",
                        "externalId" => $report["id"],
                        "idempotencyKey" => "REPORT_IMPORT:" . $report["id"],
                        "cursor" => "",
                        "status" => "Completed",
                        "attempts" => 1,
                        "processedItems" => $count,
                        "quotaUsed" => $this->quotaUsedToday($channelId),
                        "startedAt" => $this->now(),
                        "completedAt" => $this->now(),
                        "error" => "",
                        "details" => (object)array("reportTypeId" => $reportTypeId)
                    ));
                    $imported++;
                }
            }
        }
        return (object)array("success" => true, "jobsCreated" => $created, "reportsImported" => $imported, "availableJobs" => count($byType));
    }

    private function importReportCsv($channelId, $reportTypeId, $reportId, $csv) {
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$csv));
        if (!is_array($lines) || count($lines) < 2) {
            return 0;
        }
        $headers = array_map(array($this, "normalizeHeader"), str_getcsv(array_shift($lines)));
        $count = 0;
        foreach ($lines as $line) {
            if (trim($line) === "") {
                continue;
            }
            $values = str_getcsv($line);
            if (count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $values);
            $date = $this->reportDate($this->firstValue($row, array("date", "day")));
            if ($date === "") {
                continue;
            }
            $videoId = $this->firstValue($row, array("video_id", "video"));
            if ($videoId !== "" && $this->youtubeId($videoId) === "") {
                $videoId = "";
            }
            if (strpos($reportTypeId, "reach") !== false) {
                $this->upsert("ytg_reach_daily", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "videoId", "operator" => "=", "value" => $videoId),
                    array("column" => "date", "operator" => "=", "value" => $date),
                    array("column" => "trafficSource", "operator" => "=", "value" => $this->firstValue($row, array("traffic_source_type", "traffic_source"))),
                    array("column" => "deviceType", "operator" => "=", "value" => $this->firstValue($row, array("device_type", "device")))
                ), (object)array(
                    "channelId" => $channelId,
                    "videoId" => $videoId,
                    "date" => $date,
                    "trafficSource" => $this->firstValue($row, array("traffic_source_type", "traffic_source")),
                    "deviceType" => $this->firstValue($row, array("device_type", "device")),
                    "impressions" => intval($this->firstValue($row, array("thumbnail_impressions", "impressions"))),
                    "impressionsCtr" => floatval($this->firstValue($row, array("thumbnail_impressions_ctr", "impressions_ctr"))),
                    "source" => "YOUTUBE_REPORTING",
                    "reportId" => $reportId,
                    "refreshedAt" => $this->now()
                ));
                $count++;
            } elseif (strpos($reportTypeId, "traffic_source") !== false) {
                $this->upsert("ytg_traffic_sources", array(
                    array("column" => "channelId", "operator" => "=", "value" => $channelId),
                    array("column" => "videoId", "operator" => "=", "value" => $videoId),
                    array("column" => "date", "operator" => "=", "value" => $date),
                    array("column" => "sourceType", "operator" => "=", "value" => $this->firstValue($row, array("traffic_source_type"))),
                    array("column" => "sourceDetail", "operator" => "=", "value" => $this->firstValue($row, array("traffic_source_detail")))
                ), (object)array(
                    "channelId" => $channelId,
                    "videoId" => $videoId,
                    "date" => $date,
                    "sourceType" => $this->firstValue($row, array("traffic_source_type")),
                    "sourceDetail" => $this->firstValue($row, array("traffic_source_detail")),
                    "views" => intval($this->firstValue($row, array("views"))),
                    "watchMinutes" => floatval($this->firstValue($row, array("estimated_minutes_watched", "watch_time_minutes"))),
                    "source" => "YOUTUBE_REPORTING",
                    "reportId" => $reportId,
                    "refreshedAt" => $this->now()
                ));
                $count++;
            }
        }
        return $count;
    }

    private function generateRecommendations($channelId) {
        $stats = $this->query("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "capturedDate", "operator" => "=", "value" => $this->today()),
            array("column" => "source", "operator" => "=", "value" => "YOUTUBE_DATA")
        ), array(array("column" => "views", "direction" => "DESC")), 100, 0);
        $top = $stats->success && count($stats->result) ? $stats->result[0] : null;
        $topComments = null;
        if ($stats->success) {
            foreach ($stats->result as $row) {
                if ($topComments === null || intval($row->comments) > intval($topComments->comments)) {
                    $topComments = $row;
                }
            }
        }
        $topVideo = $top !== null ? $this->videoRecord($channelId, $top->videoId) : null;
        $today = $this->today();
        $start = date("Y-m-d", strtotime("-27 days"));
        $count = 0;

        if ($top !== null && $topVideo !== null) {
            $count += $this->saveRecommendation($channelId, $top->videoId, "CONTENT_IDEA", "Create next", "Top-catalogue-follow-up", "This video currently has the highest official catalogue view count in the imported snapshot.", array($this->evidence("YOUTUBE_DATA", "views", (string)$top->views, $today, $today)), "The channel has demonstrated audience interest in this video's promise. A related follow-up should test whether that interest carries to a new, distinct angle.", array("Identify the viewer promise in this video.", "Draft one deeper follow-up and one adjacent question without copying the original."), "A related follow-up may serve an audience need already visible in official channel data.", "MEDIUM", "MEDIUM", "Views and watch time on the new video", "New");
            $count += $this->saveRecommendation($channelId, $top->videoId, "PLAYLIST", "Do this week", "Top-video-session-path", "A high-view video is a practical place to review the next-video path.", array($this->evidence("YOUTUBE_DATA", "views", (string)$top->views, $today, $today)), "Improving the relevance of the spoken CTA, end screen, and playlist path may help viewers continue to another closely related video.", array("Choose one genuinely relevant next video or playlist.", "Review the spoken CTA and end-screen destination in YouTube Studio."), "A clearer relevant path may increase continuation to another channel video; this is a hypothesis, not a guarantee.", "MEDIUM", "SMALL", "End-screen element click rate or playlist starts", "New");
            $descriptionLength = strlen(trim((string)$topVideo->description));
            $seoStatus = $descriptionLength < 80 ? "New" : "Needs Data";
            $seoObservation = $descriptionLength < 80 ? "A high-view video has a very short description and may not clearly explain its topic and viewer promise." : "The high-view video's metadata is present; query-level Search evidence is needed before recommending a rewrite.";
            $count += $this->saveRecommendation($channelId, $top->videoId, "SEO", $seoStatus === "New" ? "Do this week" : "Needs more data", "Top-video-search-alignment", $seoObservation, array($this->evidence("YOUTUBE_DATA", "description characters", (string)$descriptionLength, $today, $today)), "YouTube Search evaluates relevance across the title, description, video content, and resulting engagement. Exact keyword volume is not available from the official API.", $seoStatus === "New" ? array("Rewrite the first description lines to accurately state the topic and viewer benefit.", "Keep tags limited to useful variants or misspellings.") : array("Import traffic-source and search-term evidence before changing metadata that may already work."), "Clearer accurate topic alignment may improve discovery for relevant viewers.", "LOW", "SMALL", "YouTube Search views and watch time", $seoStatus);
        }

        if ($topComments !== null && intval($topComments->comments) > 0) {
            $count += $this->saveRecommendation($channelId, $topComments->videoId, "COMMUNITY", "Do today", "Most-commented-review", "This imported video snapshot has the channel's highest official comment count.", array($this->evidence("YOUTUBE_DATA", "comments", (string)$topComments->comments, $today, $today)), "Comments can reveal recurring questions and confusion, but individual commenters must not be profiled or assigned sensitive traits.", array("Review recent threads for repeated questions.", "Draft replies for human approval and record viable follow-up topics."), "Responding thoughtfully and turning repeated questions into content may strengthen audience usefulness.", "MEDIUM", "SMALL", "Comments and returning-viewer indicators", "New");
        } else {
            $count += $this->saveRecommendation($channelId, "", "COMMUNITY", "Needs more data", "Community-data-needed", "No usable comment-count evidence is available in the current snapshot.", array($this->evidence("YOUTUBE_DATA", "comment evidence availability", "Not available", $today, $today)), "Community advice should be based on documented comment data rather than invented audience themes.", array("Refresh recent comment data when the channel has eligible videos."), "With more evidence, the app can identify recurring questions for human review.", "LOW", "SMALL", "Comment evidence availability", "Needs Data");
        }

        $reach = $this->query("ytg_reach_daily", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "date", "operator" => ">=", "value" => $start)
        ), array(array("column" => "date", "direction" => "DESC")), 1, 0);
        if (!$reach->success || !count($reach->result)) {
            $count += $this->saveRecommendation($channelId, "", "PACKAGING", "Needs more data", "Packaging-reach-needed", "Thumbnail impression and CTR reports are not yet available for a defensible packaging diagnosis.", array($this->evidence("YOUTUBE_REPORTING", "reach dataset availability", "Not imported", $start, $today)), "Packaging advice must use comparable exposure evidence and must not rely on a universal CTR threshold.", array("Allow the Reporting API job to produce reach files.", "Sync again after reports are available, then compare like-for-like videos."), "With reach evidence, title and thumbnail variants can be prepared for a native YouTube test.", "LOW", "SMALL", "Thumbnail impressions and CTR", "Needs Data");
        }

        $analytics = $this->query("ytg_video_statistics", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "capturedDate", "operator" => "=", "value" => $today),
            array("column" => "source", "operator" => "=", "value" => "YOUTUBE_ANALYTICS")
        ), array(array("column" => "views", "direction" => "DESC")), 1, 0);
        if ($analytics->success && count($analytics->result)) {
            $row = $analytics->result[0];
            $count += $this->saveRecommendation($channelId, $row->videoId, "RETENTION", "Test next", "Retention-review-top-watch", "The leading 28-day video now has official average-view data available for a retention review.", array($this->evidence("YOUTUBE_ANALYTICS", "averageViewPercentage", (string)$row->avgViewPercentage, isset($row->analyticsStartDate) ? $row->analyticsStartDate : $start, isset($row->analyticsEndDate) ? $row->analyticsEndDate : $today)), "An average alone cannot identify exact drop-off timestamps; the retention curve must be reviewed before prescribing an edit.", array("Open the video's audience-retention report in YouTube Studio.", "Record early drop-offs and rewatched sections before designing the next hook."), "A future opening aligned more quickly with the viewer promise may improve retention.", "MEDIUM", "MEDIUM", "Average view duration and retention curve", "New");
        } else {
            $count += $this->saveRecommendation($channelId, "", "RETENTION", "Needs more data", "Retention-data-needed", "Per-video Analytics evidence is not yet available for a retention diagnosis.", array($this->evidence("YOUTUBE_ANALYTICS", "per-video retention evidence availability", "Not imported", $start, $today)), "Retention advice must be tied to official per-video data and should not compare unlike formats or durations.", array("Complete Analytics synchronization and select a video for deep analysis."), "With sufficient evidence, the app can prioritize a retention review.", "LOW", "SMALL", "Per-video Analytics availability", "Needs Data");
        }
        return $count;
    }

    private function saveRecommendation($channelId, $videoId, $type, $priority, $fingerprintSuffix, $observation, $evidence, $reasoning, $actions, $hypothesis, $confidence, $effort, $metric, $status) {
        $fingerprint = hash("sha256", $channelId . ":" . $videoId . ":" . $type . ":" . $fingerprintSuffix);
        $existing = $this->first("ytg_recommendations", array(array("column" => "fingerprint", "operator" => "=", "value" => $fingerprint)));
        if ($existing !== null && isset($existing->status) && in_array($existing->status, array("Completed", "Dismissed"), true)) {
            return 0;
        }
        $row = (object)array(
            "channelId" => $channelId,
            "videoId" => $videoId,
            "fingerprint" => $fingerprint,
            "type" => $type,
            "priority" => $priority,
            "observation" => $observation,
            "evidence" => $evidence,
            "reasoning" => $reasoning,
            "actions" => $actions,
            "suggestedAsset" => "",
            "hypothesis" => $hypothesis,
            "confidence" => $confidence,
            "effort" => $effort,
            "metricToReview" => $metric,
            "reviewAt" => date("Y-m-d", strtotime("+14 days")),
            "status" => $status,
            "requiresApproval" => true,
            "sourceDateStart" => isset($evidence[0]["dateRange"]) ? explode("/", $evidence[0]["dateRange"])[0] : $this->today(),
            "sourceDateEnd" => isset($evidence[0]["dateRange"]) ? explode("/", $evidence[0]["dateRange"])[1] : $this->today(),
            "createdAt" => $existing !== null && isset($existing->createdAt) ? $existing->createdAt : $this->now(),
            "updatedAt" => $this->now(),
            "completedAt" => null
        );
        $save = $this->upsert("ytg_recommendations", array(array("column" => "fingerprint", "operator" => "=", "value" => $fingerprint)), $row);
        return $save->success ? 1 : 0;
    }

    private function evidence($source, $metric, $value, $start, $end) {
        return array("source" => $source, "metric" => $metric, "value" => $value, "dateRange" => $start . "/" . $end);
    }

    private function videoRecord($channelId, $videoId) {
        return $this->first("ytg_videos", array(
            array("column" => "channelId", "operator" => "=", "value" => $channelId),
            array("column" => "youtubeVideoId", "operator" => "=", "value" => $videoId)
        ));
    }

    private function analyticsRows($data) {
        if (!is_array($data) || !isset($data["columnHeaders"], $data["rows"]) || !is_array($data["rows"])) {
            return array();
        }
        $headers = array();
        foreach ($data["columnHeaders"] as $header) {
            $headers[] = isset($header["name"]) ? $header["name"] : "";
        }
        $out = array();
        foreach ($data["rows"] as $values) {
            if (count($values) === count($headers)) {
                $out[] = array_combine($headers, $values);
            }
        }
        return $out;
    }

    private function metric($row, $name) {
        return isset($row[$name]) && is_numeric($row[$name]) ? $row[$name] + 0 : 0;
    }

    private function durationSeconds($duration) {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', (string)$duration, $matches)) {
            return 0;
        }
        return intval(isset($matches[1]) ? $matches[1] : 0) * 86400 + intval(isset($matches[2]) ? $matches[2] : 0) * 3600 + intval(isset($matches[3]) ? $matches[3] : 0) * 60 + intval(isset($matches[4]) ? $matches[4] : 0);
    }

    private function thumbnail($thumbnails) {
        if (!is_array($thumbnails)) {
            return "";
        }
        foreach (array("maxres", "standard", "high", "medium", "default") as $size) {
            if (isset($thumbnails[$size]["url"])) {
                return $thumbnails[$size]["url"];
            }
        }
        return "";
    }

    private function hasShortsMarker($snippet) {
        $title = isset($snippet["title"]) ? strtolower((string)$snippet["title"]) : "";
        if (strpos($title, "#shorts") !== false) {
            return true;
        }
        foreach (isset($snippet["tags"]) && is_array($snippet["tags"]) ? $snippet["tags"] : array() as $tag) {
            $tag = strtolower(trim((string)$tag, " #\t\r\n"));
            if ($tag === "short" || $tag === "shorts" || $tag === "youtubeshorts") {
                return true;
            }
        }
        return false;
    }

    private function apiDate($value) {
        $time = strtotime((string)$value);
        return $time === false ? null : date("Y-m-d H:i:s", $time);
    }

    public function normalizeHeader($value) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9_]+/', '_', (string)$value), '_'));
    }

    private function firstValue($row, $keys) {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                return trim((string)$row[$key]);
            }
        }
        return "";
    }

    private function reportDate($value) {
        $value = trim((string)$value);
        if (preg_match('/^\d{8}$/', $value)) {
            $value = substr($value, 0, 4) . "-" . substr($value, 4, 2) . "-" . substr($value, 6, 2);
        }
        $date = \DateTime::createFromFormat("!Y-m-d", $value);
        return $date && $date->format("Y-m-d") === $value ? $value : "";
    }
}

?>
