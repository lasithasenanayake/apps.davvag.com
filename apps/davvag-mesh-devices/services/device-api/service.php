<?php
namespace davvag_mesh_devices;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class DeviceApiService {
    private $networks = "davvag_mesh_networks";
    private $members = "davvag_mesh_network_members";
    private $devices = "davvag_mesh_devices";
    private $endpoints = "davvag_mesh_endpoints";

    public function postListDevices($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        $rows = array();
        if ($networkId > 0) {
            if (!$this->access($networkId, $res)) {
                return null;
            }
            $rows = $this->rows($this->devices, "network_id:" . $networkId, "asc", 1000, 0);
        } else {
            foreach ($this->authorizedNetworkIds($res) as $id) {
                $rows = array_merge($rows, $this->rows($this->devices, "network_id:" . $id, "asc", 1000, 0));
            }
        }
        foreach ($rows as $row) {
            $row->endpoint_count = count($this->rows($this->endpoints, "device_id:" . intval($row->id), "asc", 1000, 0));
        }
        return $rows;
    }

    public function postGetDevice($req, $res) {
        $body = $this->body($req);
        $id = isset($body->id) ? intval($body->id) : 0;
        if ($id < 1) {
            $res->SetError("Device id is required.");
            return null;
        }
        $device = $this->findOne($this->devices, "id:" . $id);
        if ($device === null) {
            $res->SetError("Device not found.");
            return null;
        }
        if (!$this->access(intval($device->network_id), $res)) {
            return null;
        }
        $device->endpoints = $this->rows($this->endpoints, "device_id:" . $id, "asc", 1000, 0);
        return $device;
    }

    public function postSaveDevice($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId < 1) {
            $res->SetError("Network is required.");
            return null;
        }
        if (!$this->manage($networkId, $res)) {
            return null;
        }
        $existing = null;
        if (!empty($body->id)) {
            $existing = $this->findOne($this->devices, "id:" . intval($body->id));
            if ($existing === null) {
                $res->SetError("Device not found.");
                return null;
            }
            if (isset($existing->network_id) && intval($existing->network_id) !== $networkId && !$this->manage(intval($existing->network_id), $res)) {
                return null;
            }
        }
        $device = $this->validateDevice($body, $res);
        if ($device === null || !$this->serialAvailable($device, $res)) {
            return null;
        }
        return $this->persist($this->devices, "id", $device, $res);
    }

    public function postListEndpoints($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        $deviceId = isset($body->device_id) ? intval($body->device_id) : 0;
        if ($networkId > 0 && !$this->access($networkId, $res)) {
            return null;
        }
        if ($deviceId > 0) {
            $device = $this->findOne($this->devices, "id:" . $deviceId);
            if ($device === null) {
                $res->SetError("Device not found.");
                return null;
            }
            if (!$this->access(intval($device->network_id), $res)) {
                return null;
            }
            return $this->rows($this->endpoints, "device_id:" . $deviceId, "asc", 1000, 0);
        }
        if ($networkId > 0) {
            return $this->rows($this->endpoints, "network_id:" . $networkId, "asc", 1000, 0);
        }
        $rows = array();
        foreach ($this->authorizedNetworkIds($res) as $id) {
            $rows = array_merge($rows, $this->rows($this->endpoints, "network_id:" . $id, "asc", 1000, 0));
        }
        return $rows;
    }

    public function postSaveEndpoint($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        $deviceId = isset($body->device_id) ? intval($body->device_id) : 0;
        if ($networkId < 1 || $deviceId < 1) {
            $res->SetError("Network and device are required.");
            return null;
        }
        if (!$this->manage($networkId, $res)) {
            return null;
        }
        $device = $this->findOne($this->devices, "id:" . $deviceId);
        if ($device === null || intval($device->network_id) !== $networkId) {
            $res->SetError("Endpoint device must belong to the selected network.");
            return null;
        }
        $endpoint = $this->validateEndpoint($body, $res);
        if ($endpoint === null || !$this->endpointNumberAvailable($endpoint, $res)) {
            return null;
        }
        return $this->persist($this->endpoints, "id", $endpoint, $res);
    }

    public function postDeviceSummary($req, $res) {
        $body = $this->body($req);
        $networkId = isset($body->network_id) ? intval($body->network_id) : 0;
        if ($networkId > 0 && !$this->access($networkId, $res)) {
            return null;
        }
        $devices = $networkId > 0 ? $this->rows($this->devices, "network_id:" . $networkId, "asc", 2000, 0) : $this->postListDevices($req, $res);
        if ($devices === null) {
            return null;
        }
        $endpointCount = 0;
        $online = 0;
        $attention = 0;
        foreach ($devices as $device) {
            $endpointCount += count($this->rows($this->endpoints, "device_id:" . intval($device->id), "asc", 1000, 0));
            $status = isset($device->status) ? strtolower($device->status) : "";
            if ($status === "active") {
                $online++;
            } elseif (in_array($status, array("offline", "maintenance"), true)) {
                $attention++;
            }
        }
        $out = new \stdClass();
        $out->total_devices = count($devices);
        $out->total_endpoints = $endpointCount;
        $out->online_devices = $online;
        $out->attention_devices = $attention;
        return $out;
    }

    private function validateDevice($body, $res) {
        $name = $this->text($body, "name", 160);
        if ($name === "") {
            $res->SetError("Device name is required.");
            return null;
        }
        $device = new \stdClass();
        if (!empty($body->id)) {
            $device->id = intval($body->id);
        }
        $device->network_id = intval($body->network_id);
        $device->hardware_profile_id = isset($body->hardware_profile_id) ? intval($body->hardware_profile_id) : 0;
        $device->name = $name;
        $device->serial_number = $this->text($body, "serial_number", 120);
        $device->manufacturer = $this->text($body, "manufacturer", 120);
        $device->model = $this->text($body, "model", 120);
        $device->device_role = $this->allowed($body, "device_role", array("Sensor Node", "Gateway", "Tracker", "Mobile Device", "Server Adapter", "Asset Tag", "Controller"), "Sensor Node");
        $device->firmware_version = $this->text($body, "firmware_version", 80);
        $device->firmware_channel = $this->allowed($body, "firmware_channel", array("stable", "beta", "dev", "custom"), "stable");
        $device->provisioning_status = $this->allowed($body, "provisioning_status", array("Unclaimed", "Claimed", "Provisioned", "Revoked"), "Unclaimed");
        $device->last_seen_at = $this->text($body, "last_seen_at", 40);
        $device->status = $this->allowed($body, "status", array("Active", "Offline", "Maintenance", "Retired"), "Active");
        $device->configuration_json = $this->jsonText($this->value($body, "configuration_json", "{}"), $res);
        return $device->configuration_json === null ? null : $device;
    }

    private function validateEndpoint($body, $res) {
        $number = isset($body->endpoint_number) ? intval($body->endpoint_number) : 0;
        if ($number < 1) {
            $res->SetError("Endpoint number is required.");
            return null;
        }
        $endpoint = new \stdClass();
        if (!empty($body->id)) {
            $endpoint->id = intval($body->id);
        }
        $endpoint->network_id = intval($body->network_id);
        $endpoint->device_id = intval($body->device_id);
        $endpoint->profile_id = isset($body->profile_id) ? intval($body->profile_id) : 0;
        $endpoint->endpoint_number = $number;
        $endpoint->endpoint_type = $this->allowed($body, "endpoint_type", array("FIRMWARE", "MOBILE", "SERVER_ADAPTER", "FUTURE_GATEWAY"), "FIRMWARE");
        $endpoint->status = $this->allowed($body, "status", array("Active", "Suspended", "Revoked"), "Active");
        $endpoint->auth_key_version = $this->text($body, "auth_key_version", 40, "v1");
        $endpoint->label = $this->text($body, "label", 120);
        return $endpoint;
    }

    private function serialAvailable($device, $res) {
        if ($device->serial_number === "") {
            return true;
        }
        $rows = $this->rows($this->devices, "network_id:" . intval($device->network_id) . ",serial_number:" . urlencode($device->serial_number), "desc", 10, 0);
        foreach ($rows as $row) {
            if (!isset($device->id) || intval($row->id) !== intval($device->id)) {
                $res->SetError("Device serial number is already registered in this network.");
                return false;
            }
        }
        return true;
    }

    private function endpointNumberAvailable($endpoint, $res) {
        $query = "network_id:" . intval($endpoint->network_id) . ",endpoint_number:" . intval($endpoint->endpoint_number);
        $rows = $this->rows($this->endpoints, $query, "desc", 10, 0);
        foreach ($rows as $row) {
            if (!isset($endpoint->id) || intval($row->id) !== intval($endpoint->id)) {
                $res->SetError("Endpoint number is already registered in this network.");
                return false;
            }
        }
        return true;
    }

    private function authorizedNetworkIds($res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return array();
        }
        $ids = array();
        if ($this->admin()) {
            foreach ($this->rows($this->networks, "", "asc", 2000, 0) as $network) {
                if (isset($network->id)) {
                    $ids[] = intval($network->id);
                }
            }
            return $ids;
        }
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
        if ($this->member($networkId, $profile) !== null) {
            return true;
        }
        $res->SetError("You do not have access to this network.");
        return false;
    }

    private function manage($networkId, $res) {
        if ($this->admin()) {
            return true;
        }
        $profile = $this->profileId($res);
        if ($profile === null) {
            return false;
        }
        $member = $this->member($networkId, $profile);
        if ($member !== null && isset($member->role) && in_array($member->role, array("Owner", "Administrator"), true)) {
            return true;
        }
        $res->SetError("You do not have permission to manage this network.");
        return false;
    }

    private function member($networkId, $profileId) {
        $rows = $this->rows($this->members, "network_id:" . intval($networkId) . ",profile_id:" . intval($profileId), "desc", 5, 0);
        foreach ($rows as $row) {
            if (!isset($row->status) || strtolower($row->status) === "active") {
                return $row;
            }
        }
        return null;
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

    private function persist($namespace, $primaryKey, $object, $res) {
        $isUpdate = isset($object->$primaryKey) && intval($object->$primaryKey) > 0;
        $result = $isUpdate ? \SOSSData::Update($namespace, $object) : \SOSSData::Insert($namespace, $object);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Save failed.");
            return null;
        }
        if (!$isUpdate && isset($result->result->generatedId)) {
            $object->$primaryKey = intval($result->result->generatedId);
        }
        return $object;
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
            $res->SetError("Configuration must be valid JSON.");
            return null;
        }
        return substr($text, 0, 10000);
    }
}
?>
