<?php
namespace davvag_mesh_networks;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class NetworkApiService {
    private $networks = "davvag_mesh_networks";
    private $members = "davvag_mesh_network_members";

    public function getListNetworks($req, $res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return null;
        }

        if ($this->admin()) {
            return $this->rows($this->networks, "", "asc", 500, 0);
        }

        $members = $this->rows($this->members, "profile_id:" . $profile, "asc", 500, 0);
        $seen = array();
        $out = array();
        foreach ($members as $member) {
            if (empty($member->network_id)) {
                continue;
            }
            if (isset($member->status) && strtolower($member->status) !== "active") {
                continue;
            }
            $networkId = intval($member->network_id);
            if (isset($seen[$networkId])) {
                continue;
            }
            $network = $this->findOne($this->networks, "id:" . $networkId);
            if ($network !== null) {
                $seen[$networkId] = true;
                $out[] = $network;
            }
        }
        return $out;
    }

    public function getGetNetwork($req, $res) {
        $q = $req->Query();
        $id = isset($q->networkId) ? intval($q->networkId) : (isset($q->id) ? intval($q->id) : 0);
        if ($id < 1) {
            $res->SetError("Network id is required.");
            return null;
        }
        if (!$this->access($id, $res)) {
            return null;
        }
        $network = $this->findOne($this->networks, "id:" . $id);
        if ($network === null) {
            $res->SetError("Network not found.");
            return null;
        }
        return $network;
    }

    public function getNetworkSummary($req, $res) {
        $networks = $this->getListNetworks($req, $res);
        if ($networks === null) {
            return null;
        }
        $summary = new \stdClass();
        $summary->total = count($networks);
        $summary->active = 0;
        $summary->maintenance = 0;
        $summary->archived = 0;
        $summary->networks = $networks;
        foreach ($networks as $network) {
            $status = isset($network->status) ? strtolower($network->status) : "";
            if ($status === "active") {
                $summary->active++;
            } elseif ($status === "maintenance") {
                $summary->maintenance++;
            } elseif ($status === "archived") {
                $summary->archived++;
            }
        }
        return $summary;
    }

    public function postCreateNetwork($req, $res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return null;
        }
        $network = $this->validateNetwork($req->Body(true), $res, false, null, $profile);
        if ($network === null) {
            return null;
        }
        if (!$this->codeAvailable($network->code, 0, $res)) {
            return null;
        }

        $result = \SOSSData::Insert($this->networks, $network);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Unable to create the network.");
            return null;
        }
        if (isset($result->result->generatedId)) {
            $network->id = intval($result->result->generatedId);
        }
        if (empty($network->id)) {
            $res->SetError("Network identity was not generated.");
            return null;
        }

        $member = (object)array("network_id" => $network->id, "profile_id" => $profile, "role" => "Owner", "status" => "Active");
        $memberResult = \SOSSData::Insert($this->members, $member);
        if (!$memberResult->success) {
            \SOSSData::Delete($this->networks, $network);
            $res->SetError("Unable to assign network ownership.");
            return null;
        }
        return $network;
    }

    public function postUpdateNetwork($req, $res) {
        $body = $this->body($req);
        $id = isset($body->id) ? intval($body->id) : 0;
        if ($id < 1) {
            $res->SetError("Network id is required.");
            return null;
        }
        if (!$this->manage($id, $res)) {
            return null;
        }
        $existing = $this->findOne($this->networks, "id:" . $id);
        if ($existing === null) {
            $res->SetError("Network not found.");
            return null;
        }
        $network = $this->validateNetwork($body, $res, true, $existing, null);
        if ($network === null) {
            return null;
        }
        if (!$this->codeAvailable($network->code, $id, $res)) {
            return null;
        }

        $result = \SOSSData::Update($this->networks, $network);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Unable to update the network.");
            return null;
        }
        return $network;
    }

    private function validateNetwork($body, $res, $isUpdate, $existing, $profile) {
        if (!is_object($body)) {
            $res->SetError("A network payload is required.");
            return null;
        }
        $name = $this->text($body, "name", 160);
        if ($name === "") {
            $res->SetError("Network name is required.");
            return null;
        }

        $network = new \stdClass();
        if ($isUpdate) {
            $network->id = intval($body->id);
            $network->created_by = isset($existing->created_by) ? intval($existing->created_by) : 0;
            $network->organization_id = isset($existing->organization_id) ? intval($existing->organization_id) : 0;
        } else {
            $network->created_by = intval($profile);
            $network->organization_id = isset($body->organization_id) ? intval($body->organization_id) : 0;
        }

        $network->name = $name;
        $network->code = $this->code($this->text($body, "code", 80), $name);
        $network->description = $this->text($body, "description", 1200);
        $network->country_code = strtoupper($this->text($body, "country_code", 2));
        $network->region_code = $this->code($this->text($body, "region_code", 40), "");
        $network->status = $this->allowed($body, "status", array("Active", "Maintenance", "Archived"), "Active");
        $network->template_code = $this->allowed($body, "template_code", array("private-operations", "people-teams", "smart-farming", "fishing-fleet", "custom"), $this->templateFromType($this->value($body, "network_type", "Private Operations")));
        $network->configuration_json = $this->jsonText($this->value($body, "configuration_json", "{}"), $res);
        if ($network->configuration_json === null) {
            return null;
        }
        $network->network_type = $this->allowed($body, "network_type", array("Private Operations", "People & Teams", "Smart Farming", "Fishing Fleet", "Custom"), "Private Operations");
        $network->region = $this->text($body, "region", 120);
        $network->radio_profile = $this->allowed($body, "radio_profile", array("LoRa + BLE", "LoRa", "BLE", "IP Gateway", "Mixed"), "LoRa + BLE");
        $color = $this->value($body, "color", "#18a875");
        $network->color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : "#18a875";
        return $network;
    }

    private function codeAvailable($code, $currentId, $res) {
        if ($code === "") {
            $res->SetError("Network code is required.");
            return false;
        }
        $rows = $this->rows($this->networks, "code:" . urlencode($code), "desc", 10, 0);
        foreach ($rows as $row) {
            if (!isset($row->id) || intval($row->id) !== intval($currentId)) {
                $res->SetError("Network code is already in use.");
                return false;
            }
        }
        return true;
    }

    private function access($networkId, $res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return false;
        }
        if ($this->admin()) {
            return true;
        }
        $member = $this->member($networkId, $profile);
        if ($member === null) {
            $res->SetError("You do not have access to this network.");
            return false;
        }
        return true;
    }

    private function manage($networkId, $res) {
        $profile = $this->profileId($res);
        if ($profile === null) {
            return false;
        }
        if ($this->admin()) {
            return true;
        }
        $member = $this->member($networkId, $profile);
        if ($member === null || !isset($member->role) || !in_array($member->role, array("Owner", "Administrator"), true)) {
            $res->SetError("You do not have permission to manage this network.");
            return false;
        }
        return true;
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

    private function admin() {
        return defined("GROUPID") && strtolower(GROUPID) === "sysadmin";
    }

    private function rows($namespace, $query, $sorting, $pageSize, $fromPage) {
        $result = \SOSSData::Query($namespace, $query, null, $sorting, $pageSize, $fromPage);
        return $result->success ? $result->result : array();
    }

    private function findOne($namespace, $query) {
        $rows = $this->rows($namespace, $query, "desc", 1, 0);
        return count($rows) ? $rows[0] : null;
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function text($object, $field, $max, $fallback = "") {
        $value = $this->value($object, $field, $fallback);
        return substr(trim((string)$value), 0, $max);
    }

    private function value($object, $field, $fallback) {
        return is_object($object) && isset($object->$field) ? $object->$field : $fallback;
    }

    private function allowed($object, $field, $allowed, $fallback) {
        $value = $this->value($object, $field, $fallback);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function code($value, $fallbackSource) {
        $value = trim(strtolower((string)$value));
        if ($value === "") {
            $value = trim(strtolower((string)$fallbackSource));
        }
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        $value = trim($value, '-_');
        return substr($value, 0, 80);
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
            $res->SetError("Network configuration must be valid JSON.");
            return null;
        }
        return substr($text, 0, 10000);
    }

    private function templateFromType($type) {
        $map = array(
            "People & Teams" => "people-teams",
            "Smart Farming" => "smart-farming",
            "Fishing Fleet" => "fishing-fleet",
            "Custom" => "custom"
        );
        return isset($map[$type]) ? $map[$type] : "private-operations";
    }
}
?>
