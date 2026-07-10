<?php
namespace davvag_mesh_events;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class EventApiService {
    private $networks = "davvag_mesh_networks";
    private $members = "davvag_mesh_network_members";
    private $endpoints = "davvag_mesh_endpoints";
    private $events = "davvag_mesh_events";
    private $gateways = "davvag_mesh_event_gateways";

    public function postIngestEvents($req, $res) {
        $body = $this->body($req);
        $items = array();
        if (isset($body->events) && is_array($body->events)) {
            $items = $body->events;
        } elseif (isset($body->event) && is_object($body->event)) {
            $items = array($body->event);
        } elseif (isset($body->network_id)) {
            $items = array($body);
        }
        if (!count($items)) {
            $res->SetError("At least one event is required.");
            return null;
        }

        $out = new \stdClass();
        $out->accepted = array();
        $out->duplicates = array();
        $out->errors = array();
        foreach ($items as $index => $item) {
            $error = new MeshEventMemoryResponse();
            $result = $this->ingestOne($item, $body, $error);
            if ($error->message !== null) {
                $out->errors[] = (object)array("index" => $index, "message" => $error->message);
                continue;
            }
            if ($result->duplicate) {
                $out->duplicates[] = $result->event;
            } else {
                $out->accepted[] = $result->event;
            }
        }
        if (!count($out->accepted) && !count($out->duplicates) && count($out->errors)) {
            $res->SetError($out->errors[0]->message);
            return null;
        }
        return $out;
    }

    public function getGetEvent($req, $res) {
        $q = $req->Query();
        $id = isset($q->id) ? intval($q->id) : 0;
        if ($id < 1) {
            $res->SetError("Event id is required.");
            return null;
        }
        $event = $this->findOne($this->events, "id:" . $id);
        if ($event === null) {
            $res->SetError("Event not found.");
            return null;
        }
        if (!$this->access(intval($event->network_id), $res)) {
            return null;
        }
        $event->gateway_observations = $this->rows($this->gateways, "event_id:" . $id, "asc", 1000, 0);
        return $event;
    }

    public function getListEvents($req, $res) {
        $q = $req->Query();
        $networkId = isset($q->network_id) ? intval($q->network_id) : (isset($q->networkId) ? intval($q->networkId) : 0);
        $pageSize = isset($q->pageSize) ? min(max(intval($q->pageSize), 1), 500) : 100;
        if ($networkId > 0) {
            if (!$this->access($networkId, $res)) {
                return null;
            }
            return $this->rows($this->events, "network_id:" . $networkId, "desc", $pageSize, 0);
        }
        $rows = array();
        foreach ($this->authorizedNetworkIds($res) as $id) {
            $rows = array_merge($rows, $this->rows($this->events, "network_id:" . $id, "desc", $pageSize, 0));
        }
        usort($rows, function($a, $b) {
            return strcmp((string)$this->value($b, "received_at_cloud", ""), (string)$this->value($a, "received_at_cloud", ""));
        });
        return array_slice($rows, 0, $pageSize);
    }

    public function getGetEventState($req, $res) {
        $q = $req->Query();
        $networkId = isset($q->network_id) ? intval($q->network_id) : (isset($q->networkId) ? intval($q->networkId) : 0);
        if ($networkId > 0 && !$this->access($networkId, $res)) {
            return null;
        }
        $events = array();
        if ($networkId > 0) {
            $events = $this->rows($this->events, "network_id:" . $networkId, "desc", 200, 0);
        } else {
            foreach ($this->authorizedNetworkIds($res) as $id) {
                $events = array_merge($events, $this->rows($this->events, "network_id:" . $id, "desc", 200, 0));
            }
        }
        usort($events, function($a, $b) {
            return strcmp((string)$this->value($b, "received_at_cloud", ""), (string)$this->value($a, "received_at_cloud", ""));
        });
        $out = new \stdClass();
        $out->recent_count = count($events);
        $out->last_event_type = count($events) ? $this->value($events[0], "event_type", "None") : "None";
        $out->last_event_at = count($events) ? $this->value($events[0], "received_at_cloud", "") : "";
        $out->priority_counts = (object)array("critical" => 0, "high" => 0, "normal" => 0, "low" => 0);
        foreach ($events as $event) {
            $priority = strtolower((string)$this->value($event, "priority", "normal"));
            if (isset($out->priority_counts->$priority)) {
                $out->priority_counts->$priority++;
            }
        }
        return $out;
    }

    private function ingestOne($item, $batch, $res) {
        $event = $this->validateEvent($item, $res);
        if ($event === null) {
            return null;
        }
        if (!$this->access($event->network_id, $res)) {
            return null;
        }
        $origin = $this->findOne($this->endpoints, "id:" . intval($event->origin_endpoint_id));
        if ($origin === null || intval($origin->network_id) !== intval($event->network_id)) {
            $res->SetError("Origin endpoint must belong to the event network.");
            return null;
        }

        $existing = $this->findOne($this->events, "network_id:" . intval($event->network_id) . ",origin_endpoint_id:" . intval($event->origin_endpoint_id) . ",session_id:" . urlencode($event->session_id) . ",sequence:" . intval($event->sequence));
        $duplicate = false;
        if ($existing !== null) {
            $event = $existing;
            $duplicate = true;
        } else {
            $insert = \SOSSData::Insert($this->events, $event);
            if (!$insert->success) {
                $res->SetError(isset($insert->message) ? $insert->message : "Unable to store event.");
                return null;
            }
            if (isset($insert->result->generatedId)) {
                $event->id = intval($insert->result->generatedId);
            }
        }
        $this->recordGatewayObservation($event, $item, $batch, $res);
        if ($res->message !== null) {
            return null;
        }
        return (object)array("duplicate" => $duplicate, "event" => $event);
    }

    private function validateEvent($item, $res) {
        if (!is_object($item)) {
            $res->SetError("Event payload must be an object.");
            return null;
        }
        $networkId = isset($item->network_id) ? intval($item->network_id) : 0;
        $originEndpointId = isset($item->origin_endpoint_id) ? intval($item->origin_endpoint_id) : 0;
        $sequence = isset($item->sequence) ? intval($item->sequence) : 0;
        $sessionId = $this->code($this->text($item, "session_id", 120), "");
        if ($networkId < 1 || $originEndpointId < 1 || $sequence < 1 || $sessionId === "") {
            $res->SetError("network_id, origin_endpoint_id, session_id, and sequence are required.");
            return null;
        }
        $eventType = strtoupper($this->code($this->text($item, "event_type", 80), "STATUS_REPORTED"));
        if ($eventType === "") {
            $res->SetError("Event type is required.");
            return null;
        }
        $event = new \stdClass();
        $event->network_id = $networkId;
        $event->origin_endpoint_id = $originEndpointId;
        $event->session_id = $sessionId;
        $event->sequence = $sequence;
        $event->event_ref = $networkId . ":" . $originEndpointId . ":" . $sessionId . ":" . $sequence;
        $event->schema_version = $this->text($item, "schema_version", 20, "1");
        $event->event_type = $eventType;
        $event->priority = $this->allowed($item, "priority", array("Critical", "High", "Normal", "Low"), "Normal");
        $event->created_at_device = $this->dateText($this->value($item, "created_at_device", ""));
        $event->received_at_cloud = gmdate("Y-m-d H:i:s");
        $event->time_quality = $this->allowed($item, "time_quality", array("DEVICE", "GATEWAY", "SERVER", "UNKNOWN"), "SERVER");
        $event->payload_json = $this->jsonText($this->value($item, "payload_json", "{}"), $res);
        $event->verification_status = "Accepted";
        return $event->payload_json === null ? null : $event;
    }

    private function recordGatewayObservation($event, $item, $batch, $res) {
        if (empty($event->id)) {
            return;
        }
        $gatewayId = isset($item->gateway_endpoint_id) ? intval($item->gateway_endpoint_id) : (isset($batch->gateway_endpoint_id) ? intval($batch->gateway_endpoint_id) : 0);
        $uploadSession = $this->code($this->text($item, "upload_session", 120, $this->text($batch, "upload_session", 120, "cloud")), "cloud");
        if ($gatewayId > 0) {
            $gateway = $this->findOne($this->endpoints, "id:" . $gatewayId);
            if ($gateway === null || intval($gateway->network_id) !== intval($event->network_id)) {
                $res->SetError("Gateway endpoint must belong to the event network.");
                return;
            }
        }
        $existing = $this->findOne($this->gateways, "event_id:" . intval($event->id) . ",gateway_endpoint_id:" . $gatewayId . ",upload_session:" . urlencode($uploadSession));
        if ($existing !== null) {
            return;
        }
        $observation = new \stdClass();
        $observation->event_id = intval($event->id);
        $observation->gateway_endpoint_id = $gatewayId;
        $observation->received_at = gmdate("Y-m-d H:i:s");
        $observation->upload_session = $uploadSession;
        $insert = \SOSSData::Insert($this->gateways, $observation);
        if (!$insert->success) {
            $res->SetError(isset($insert->message) ? $insert->message : "Unable to store gateway observation.");
        }
    }

    private function authorizedNetworkIds($res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return array();
        }
        if ($this->admin()) {
            $ids = array();
            foreach ($this->rows($this->networks, "", "asc", 2000, 0) as $network) {
                if (isset($network->id)) {
                    $ids[] = intval($network->id);
                }
            }
            return $ids;
        }
        $ids = array();
        foreach ($this->rows($this->members, "profile_id:" . $profile, "asc", 2000, 0) as $member) {
            if (!empty($member->network_id) && (!isset($member->status) || strtolower($member->status) === "active")) {
                $ids[] = intval($member->network_id);
            }
        }
        return array_values(array_unique($ids));
    }

    private function access($networkId, $res) {
        if ($this->admin()) {
            return true;
        }
        $profile = $this->profileId($res);
        if ($profile === null) {
            return false;
        }
        $member = $this->findOne($this->members, "network_id:" . intval($networkId) . ",profile_id:" . $profile);
        if ($member !== null && (!isset($member->status) || strtolower($member->status) === "active")) {
            return true;
        }
        $res->SetError("You do not have access to this network.");
        return false;
    }

    private function profileId($res) {
        $storeProfile = \Profile::getUserProfile();
        if ($storeProfile === null) {
            $res->SetError("Authentication is required.");
            return null;
        }
        $profile = isset($storeProfile->profile) ? $storeProfile->profile : $storeProfile;
        if ($profile === null || !isset($profile->id) || intval($profile->id) < 1) {
            $res->SetError("An active profile is required.");
            return null;
        }
        return intval($profile->id);
    }

    private function rows($namespace, $query, $sorting, $pageSize, $fromPage) {
        $result = \SOSSData::Query($namespace, $query, null, $sorting, $pageSize, $fromPage);
        return $result->success ? $result->result : array();
    }

    private function findOne($namespace, $query) {
        $rows = $this->rows($namespace, $query, "desc", 1, 0);
        return count($rows) ? $rows[0] : null;
    }

    private function admin() {
        return defined("GROUPID") && strtolower(GROUPID) === "sysadmin";
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function text($object, $field, $max, $fallback = "") {
        return substr(trim((string)$this->value($object, $field, $fallback)), 0, $max);
    }

    private function value($object, $field, $fallback) {
        return is_object($object) && isset($object->$field) ? $object->$field : $fallback;
    }

    private function allowed($object, $field, $allowed, $fallback) {
        $value = $this->value($object, $field, $fallback);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function code($value, $fallback) {
        $value = strtolower(trim((string)$value));
        if ($value === "") {
            $value = strtolower(trim((string)$fallback));
        }
        $value = preg_replace('/[^a-z0-9_.:-]+/', '-', $value);
        return substr(trim($value, '-'), 0, 120);
    }

    private function dateText($value) {
        $value = trim((string)$value);
        return $value === "" ? gmdate("Y-m-d H:i:s") : substr($value, 0, 40);
    }

    private function jsonText($value, $res) {
        if (is_object($value) || is_array($value)) {
            return json_encode($value);
        }
        $text = trim((string)$value);
        if ($text === "") {
            return "{}";
        }
        json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $res->SetError("Event payload must be valid JSON.");
            return null;
        }
        return substr($text, 0, 20000);
    }
}

class MeshEventMemoryResponse {
    public $message = null;
    public function SetError($message) {
        $this->message = $message;
    }
}
?>
