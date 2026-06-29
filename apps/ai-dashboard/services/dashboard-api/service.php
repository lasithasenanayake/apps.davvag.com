<?php
namespace ai_dashboard;

if (defined("PLUGIN_PATH") && file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) {
    require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
}

class DashboardService {
    public function postUsageDashboard($req, $res) {
        if (!class_exists("\\SOSSData")) {
            return $this->fail("SOSSData is not available for AI dashboard reporting.");
        }

        $filters = $this->dashboardFilters($this->body($req));
        $usageRows = $this->usageReportRows($filters);
        if (!$usageRows->success) {
            return $usageRows;
        }

        $errorRows = $this->dashboardErrorRows($filters);
        $dashboard = $this->buildUsageDashboard($filters, $usageRows->rows, $errorRows);

        $out = $this->ok();
        $out->filters = $filters;
        $out->summary = $dashboard["summary"];
        $out->series = $dashboard["series"];
        $out->profileBreakdown = $dashboard["profileBreakdown"];
        $out->applicationBreakdown = $dashboard["applicationBreakdown"];
        $out->agentBreakdown = $dashboard["agentBreakdown"];
        $out->recentErrors = $dashboard["recentErrors"];
        return $out;
    }

    private function dashboardFilters($body) {
        $period = strtolower($this->stringValue($body, "period", "daily"));
        if (!in_array($period, array("daily", "weekly", "monthly"))) {
            $period = "daily";
        }

        $endDate = $this->dateValue($this->stringValue($body, "endDate", ""), gmdate("Y-m-d"));
        $startDate = $this->dateValue($this->stringValue($body, "startDate", ""), $this->defaultDashboardStartDate($period, $endDate));
        if (strcmp($startDate, $endDate) > 0) {
            $swap = $startDate;
            $startDate = $endDate;
            $endDate = $swap;
        }

        return array(
            "period" => $period,
            "startDate" => $startDate,
            "endDate" => $endDate,
            "profileId" => $this->normalizeProfileId($this->stringValue($body, "profileId", "")),
            "appCode" => $this->normalizeCode($this->stringValue($body, "appCode", "")),
            "agentCode" => $this->normalizeCode($this->stringValue($body, "agentCode", "")),
            "page" => 0,
            "size" => 5000
        );
    }

    private function usageReportRows($filters) {
        $this->touchUsageStores();

        $params = new \stdClass();
        $params->parameters = new \stdClass();
        $params->parameters->startdate = $filters["startDate"] . " 00:00:00";
        $params->parameters->enddate = $filters["endDate"] . " 23:59:59";
        $params->parameters->profileId = $filters["profileId"];
        $params->parameters->appCode = $filters["appCode"];
        $params->parameters->agentCode = $filters["agentCode"];
        $params->parameters->page = 0;
        $params->parameters->size = $filters["size"];

        $result = \SOSSData::ExecuteRaw($this->usageReportNamespace($filters["period"]), $params);
        if (!isset($result->success) || !$result->success) {
            $message = isset($result->message) ? $result->message : "Usage report query failed.";
            return $this->fail($message);
        }

        $out = $this->ok();
        $out->rows = isset($result->result) && is_array($result->result) ? $result->result : array();
        return $out;
    }

    private function dashboardErrorRows($filters) {
        $result = \SOSSData::Query("ai_agent_error_log", "", null, "desc", 5000, 0, null, false);
        if (!isset($result->success) || !$result->success || !isset($result->result) || !is_array($result->result)) {
            return array();
        }

        $rows = array();
        foreach ($result->result as $row) {
            if ($this->rowMatchesDashboardFilters($row, $filters)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function buildUsageDashboard($filters, $usageRows, $errorRows) {
        $summary = $this->emptyDashboardMetrics();
        $series = array();
        $profiles = array();
        $applications = array();
        $agents = array();

        foreach ($usageRows as $row) {
            $period = $this->fieldValue($row, "reportPeriod", "");
            if ($period === "") {
                continue;
            }

            $profileId = $this->fieldValue($row, "profileId", "unknown");
            $profileName = $this->fieldValue($row, "profileName", "");
            $appCode = $this->fieldValue($row, "appCode", "unknown");
            $appName = $this->fieldValue($row, "appName", "");
            $agentCode = $this->fieldValue($row, "agentCode", "unknown");
            $agentName = $this->fieldValue($row, "agentName", "");
            $metrics = array(
                "interactions" => $this->intFieldValue($row, "interactions"),
                "inputTokens" => $this->intFieldValue($row, "inputTokens"),
                "outputTokens" => $this->intFieldValue($row, "outputTokens"),
                "totalTokens" => $this->intFieldValue($row, "totalTokens"),
                "estimatedInteractions" => $this->intFieldValue($row, "estimatedInteractions")
            );

            $this->addDashboardMetrics($summary, $metrics);
            $this->ensureDashboardBucket($series, $period, array("period" => $period));
            $this->addDashboardMetrics($series[$period], $metrics);

            $profileKey = $profileId === "" ? "unknown" : $profileId;
            $this->ensureDashboardBucket($profiles, $profileKey, array("profileId" => $profileKey, "profileName" => $profileName));
            if ($profiles[$profileKey]["profileName"] === "" && $profileName !== "") {
                $profiles[$profileKey]["profileName"] = $profileName;
            }
            $this->addDashboardMetrics($profiles[$profileKey], $metrics);

            $appKey = $appCode === "" ? "unknown" : $appCode;
            $this->ensureDashboardBucket($applications, $appKey, array("appCode" => $appKey, "appName" => $appName));
            if ($applications[$appKey]["appName"] === "" && $appName !== "") {
                $applications[$appKey]["appName"] = $appName;
            }
            $this->addDashboardMetrics($applications[$appKey], $metrics);

            $agentKey = $agentCode === "" ? "unknown" : $agentCode;
            $this->ensureDashboardBucket($agents, $agentKey, array("agentCode" => $agentKey, "agentName" => $agentName));
            if ($agents[$agentKey]["agentName"] === "" && $agentName !== "") {
                $agents[$agentKey]["agentName"] = $agentName;
            }
            $this->addDashboardMetrics($agents[$agentKey], $metrics);
        }

        $recentErrors = array();
        foreach ($errorRows as $row) {
            $createdAt = $this->fieldValue($row, "createdAt", "");
            $period = $this->periodForDate($createdAt, $filters["period"]);
            $profileId = $this->fieldValue($row, "profileId", "unknown");
            $appCode = $this->fieldValue($row, "appCode", "unknown");
            $agentCode = $this->fieldValue($row, "agentCode", "unknown");

            $summary["errors"]++;
            if ($period !== "") {
                $this->ensureDashboardBucket($series, $period, array("period" => $period));
                $series[$period]["errors"]++;
            }

            $profileKey = $profileId === "" ? "unknown" : $profileId;
            $this->ensureDashboardBucket($profiles, $profileKey, array("profileId" => $profileKey, "profileName" => ""));
            $profiles[$profileKey]["errors"]++;

            $appKey = $appCode === "" ? "unknown" : $appCode;
            $this->ensureDashboardBucket($applications, $appKey, array("appCode" => $appKey, "appName" => $this->fieldValue($row, "appName", "")));
            $applications[$appKey]["errors"]++;

            $agentKey = $agentCode === "" ? "unknown" : $agentCode;
            $this->ensureDashboardBucket($agents, $agentKey, array("agentCode" => $agentKey, "agentName" => ""));
            $agents[$agentKey]["errors"]++;

            $recentErrors[] = array(
                "createdAt" => $createdAt,
                "agentCode" => $agentCode,
                "appCode" => $appCode,
                "profileId" => $profileId,
                "provider" => $this->fieldValue($row, "provider", ""),
                "model" => $this->fieldValue($row, "model", ""),
                "stage" => $this->fieldValue($row, "stage", ""),
                "message" => $this->fieldValue($row, "message", "")
            );
        }

        usort($recentErrors, function($a, $b) {
            return strcmp($b["createdAt"], $a["createdAt"]);
        });

        return array(
            "summary" => $summary,
            "series" => $this->sortedDashboardRows($series, "period", "asc"),
            "profileBreakdown" => $this->sortedDashboardRows($profiles, "totalTokens", "desc"),
            "applicationBreakdown" => $this->sortedDashboardRows($applications, "totalTokens", "desc"),
            "agentBreakdown" => $this->sortedDashboardRows($agents, "totalTokens", "desc"),
            "recentErrors" => array_slice($recentErrors, 0, 20)
        );
    }

    private function touchUsageStores() {
        try {
            \SOSSData::Query("ai_agent_billing_usage", "", null, "desc", 1, 0, null, false);
            \SOSSData::Query("ai_agent_error_log", "", null, "desc", 1, 0, null, false);
        } catch (\Exception $ex) {
        }
    }

    private function defaultDashboardStartDate($period, $endDate) {
        $end = strtotime($endDate . " 00:00:00 UTC");
        if ($end === false) {
            $end = time();
        }
        if ($period === "monthly") {
            return gmdate("Y-m-d", strtotime("-11 months", $end));
        }
        if ($period === "weekly") {
            return gmdate("Y-m-d", strtotime("-11 weeks", $end));
        }
        return gmdate("Y-m-d", strtotime("-29 days", $end));
    }

    private function dateValue($value, $default) {
        $value = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $default;
        }
        $parts = explode("-", $value);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return $default;
        }
        return $value;
    }

    private function usageReportNamespace($period) {
        if ($period === "weekly") {
            return "ai_agent_billing_usage_weekly_report";
        }
        if ($period === "monthly") {
            return "ai_agent_billing_usage_monthly_report";
        }
        return "ai_agent_billing_usage_daily_report";
    }

    private function rowMatchesDashboardFilters($row, $filters) {
        $createdAt = $this->fieldValue($row, "createdAt", "");
        $date = substr($createdAt, 0, 10);
        if ($date !== "" && ($date < $filters["startDate"] || $date > $filters["endDate"])) {
            return false;
        }
        if ($filters["profileId"] !== "" && $this->fieldValue($row, "profileId", "") !== $filters["profileId"]) {
            return false;
        }
        if ($filters["appCode"] !== "" && $this->fieldValue($row, "appCode", "") !== $filters["appCode"]) {
            return false;
        }
        if ($filters["agentCode"] !== "" && $this->fieldValue($row, "agentCode", "") !== $filters["agentCode"]) {
            return false;
        }
        return true;
    }

    private function periodForDate($createdAt, $period) {
        $date = substr((string)$createdAt, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return "";
        }
        if ($period === "monthly") {
            return substr($date, 0, 7);
        }
        if ($period === "weekly") {
            $time = strtotime($date . " 00:00:00 UTC");
            if ($time === false) {
                return "";
            }
            $weekday = (int)gmdate("N", $time);
            return gmdate("Y-m-d", strtotime("-" . ($weekday - 1) . " days", $time));
        }
        return $date;
    }

    private function emptyDashboardMetrics() {
        return array(
            "interactions" => 0,
            "inputTokens" => 0,
            "outputTokens" => 0,
            "totalTokens" => 0,
            "estimatedInteractions" => 0,
            "errors" => 0
        );
    }

    private function ensureDashboardBucket(&$buckets, $key, $labels) {
        if (!isset($buckets[$key])) {
            $buckets[$key] = array_merge($labels, $this->emptyDashboardMetrics());
        }
    }

    private function addDashboardMetrics(&$bucket, $metrics) {
        foreach (array("interactions", "inputTokens", "outputTokens", "totalTokens", "estimatedInteractions") as $key) {
            $bucket[$key] += isset($metrics[$key]) ? (int)$metrics[$key] : 0;
        }
    }

    private function sortedDashboardRows($rows, $field, $direction) {
        $values = array_values($rows);
        usort($values, function($a, $b) use ($field, $direction) {
            $left = isset($a[$field]) ? $a[$field] : "";
            $right = isset($b[$field]) ? $b[$field] : "";
            if (is_numeric($left) && is_numeric($right)) {
                $compare = (int)$left - (int)$right;
            } else {
                $compare = strcmp((string)$left, (string)$right);
            }
            return $direction === "desc" ? -$compare : $compare;
        });
        return $values;
    }

    private function fieldValue($source, $key, $default) {
        if (is_array($source) && isset($source[$key])) {
            return trim((string)$source[$key]);
        }
        if (is_object($source) && isset($source->$key)) {
            return trim((string)$source->$key);
        }
        return $default;
    }

    private function intFieldValue($source, $key) {
        $value = $this->fieldValue($source, $key, "0");
        return is_numeric($value) ? (int)$value : 0;
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function stringValue($body, $key, $default) {
        if (!isset($body->$key)) {
            return $default;
        }
        return trim(substr((string)$body->$key, 0, 20000));
    }

    private function normalizeProfileId($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9@._:-]+/", "-", $value);
        $value = trim($value, "-_");
        return substr($value, 0, 120);
    }

    private function normalizeCode($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace("/[^a-z0-9_-]+/", "-", $value);
        $value = trim($value, "-_");
        return substr($value, 0, 80);
    }

    private function ok() {
        $out = new \stdClass();
        $out->success = true;
        return $out;
    }

    private function fail($message) {
        $out = new \stdClass();
        $out->success = false;
        $out->message = $message;
        return $out;
    }
}
?>
