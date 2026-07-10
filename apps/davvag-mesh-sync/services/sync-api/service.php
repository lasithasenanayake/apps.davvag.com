<?php
namespace davvag_mesh_sync;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class SyncApiService {
    private $networks = "davvag_mesh_networks";
    private $members = "davvag_mesh_network_members";
    private $devices = "davvag_mesh_devices";
    private $endpoints = "davvag_mesh_endpoints";
    private $events = "davvag_mesh_events";

    public function postGetSyncPlan($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId < 1) {
            $res->SetError("Network is required.");
            return null;
        }
        if (!$this->access($networkId, $res)) {
            return null;
        }
        $known = $this->knownMap(isset($body->known_event_refs) && is_array($body->known_event_refs) ? $body->known_event_refs : array());
        $cloudEvents = $this->rows($this->events, "network_id:" . $networkId, "desc", 500, 0);
        $missing = array();
        foreach ($cloudEvents as $event) {
            $ref = $this->value($event, "event_ref", "");
            if ($ref !== "" && !isset($known[$ref])) {
                $missing[] = $ref;
            }
        }
        $out = new \stdClass();
        $out->server_time = gmdate("Y-m-d H:i:s");
        $out->network_id = $networkId;
        $out->missing_event_refs = $missing;
        $out->known_cloud_events = count($cloudEvents);
        $out->upload_window = (object)array("max_events" => 250, "max_payload_bytes" => 524288);
        $out->configuration = $this->configuration($networkId);
        return $out;
    }

    public function postCheckEvents($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId < 1) {
            $res->SetError("Network is required.");
            return null;
        }
        if (!$this->access($networkId, $res)) {
            return null;
        }
        $refs = isset($body->event_refs) && is_array($body->event_refs) ? $body->event_refs : array();
        $out = new \stdClass();
        $out->known = array();
        $out->missing = array();
        foreach ($refs as $ref) {
            $clean = $this->eventRef($ref);
            if ($clean === "") {
                continue;
            }
            $event = $this->findOne($this->events, "network_id:" . $networkId . ",event_ref:" . urlencode($clean));
            if ($event === null) {
                $out->missing[] = $clean;
            } else {
                $out->known[] = $clean;
            }
        }
        return $out;
    }

    public function postUploadBatch($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId < 1) {
            $res->SetError("Network is required.");
            return null;
        }
        if (!$this->access($networkId, $res)) {
            return null;
        }
        if (isset($body->events) && is_array($body->events)) {
            foreach ($body->events as $event) {
                if (is_object($event) && !isset($event->network_id)) {
                    $event->network_id = $networkId;
                }
            }
        }
        $file = dirname(dirname(dirname(__DIR__))) . "/davvag-mesh-events/services/event-api/service.php";
        if (!file_exists($file)) {
            $res->SetError("Mesh Events service is unavailable.");
            return null;
        }
        require_once($file);
        if (!class_exists("\\davvag_mesh_events\\EventApiService")) {
            $res->SetError("Mesh Events service class is unavailable.");
            return null;
        }
        $service = new \davvag_mesh_events\EventApiService();
        return $service->postIngestEvents(new MeshSyncRequest($body), new MeshSyncResponse($res));
    }

    public function postGetConfiguration($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId < 1) {
            $res->SetError("Network is required.");
            return null;
        }
        if (!$this->access($networkId, $res)) {
            return null;
        }
        return $this->configuration($networkId);
    }

    public function postSyncHealth($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId > 0 && !$this->access($networkId, $res)) {
            return null;
        }
        $events = array();
        if ($networkId > 0) {
            $events = $this->rows($this->events, "network_id:" . $networkId, "desc", 1, 0);
        } else {
            foreach ($this->authorizedNetworkIds($res) as $id) {
                $events = array_merge($events, $this->rows($this->events, "network_id:" . $id, "desc", 1, 0));
            }
        }
        $out = new \stdClass();
        $out->status = "Ready";
        $out->server_time = gmdate("Y-m-d H:i:s");
        $out->pending_uploads = 0;
        $out->known_cloud_events = $networkId > 0 ? count($this->rows($this->events, "network_id:" . $networkId, "desc", 5000, 0)) : count($events);
        return $out;
    }

    private function configuration($networkId) {
        $out = new \stdClass();
        $out->network = $this->findOne($this->networks, "id:" . intval($networkId));
        $out->devices = $this->rows($this->devices, "network_id:" . intval($networkId), "asc", 2000, 0);
        $out->endpoints = $this->rows($this->endpoints, "network_id:" . intval($networkId), "asc", 2000, 0);
        return $out;
    }

    private function knownMap($refs) {
        $out = array();
        foreach ($refs as $ref) {
            $clean = $this->eventRef($ref);
            if ($clean !== "") {
                $out[$clean] = true;
            }
        }
        return $out;
    }

    private function eventRef($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^a-zA-Z0-9_.:-]+/', '-', $value);
        return substr(trim($value, '-'), 0, 260);
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

    private function value($object, $field, $fallback) {
        return is_object($object) && isset($object->$field) ? $object->$field : $fallback;
    }
}

class MeshSyncRequest {
    private $body;
    public function __construct($body) {
        $this->body = $body;
    }
    public function Body($json = true) {
        return $this->body;
    }
    public function Query() {
        return new \stdClass();
    }
}

class MeshSyncResponse {
    private $outer;
    public function __construct($outer) {
        $this->outer = $outer;
    }
    public function SetError($message) {
        $this->outer->SetError($message);
    }
}
?>
