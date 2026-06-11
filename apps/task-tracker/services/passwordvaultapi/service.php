<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
if (file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
    require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
}

class PasswordVaultService {
    private $vaultNamespace = "task_manager_password_vault";
    private $secretNamespace = "task_manager_password_vault_secrets";
    private $projectNamespace = "task_manager_projects";
    private $projectAccessNamespace = "task_manager_project_access";

    public function postListVaults($req, $res) {
        $body = $this->body($req);
        if (!isset($body->projectId) || intval($body->projectId) <= 0) {
            return array();
        }
        if (!$this->canAccessProject($body->projectId)) {
            return array();
        }

        $result = SOSSData::Query($this->vaultNamespace, "projectId:" . $body->projectId, null, "desc", 500, 0);
        if (!$result->success) {
            return array();
        }

        $search = isset($body->search) ? strtolower(trim($body->search)) : "";
        $vaults = array();
        foreach ($result->result as $vault) {
            if ($search !== "" && !$this->matchesSearch($vault, $search)) {
                continue;
            }
            array_push($vaults, $this->publicVault($vault));
        }
        return $vaults;
    }

    public function postVaultDetails($req, $res) {
        $body = $this->body($req);
        if (!isset($body->vaultId)) {
            return null;
        }

        $vault = $this->getVault($body->vaultId);
        if ($vault !== null && !$this->canAccessProject($vault->projectId)) {
            return null;
        }
        return $vault === null ? null : $this->publicVault($vault);
    }

    public function postSaveVault($req, $res) {
        $vault = $this->body($req);
        $isNew = !isset($vault->vaultId) || intval($vault->vaultId) <= 0;
        $password = isset($vault->password) ? $vault->password : null;

        if (!isset($vault->title) || trim($vault->title) === "") {
            $vault->title = isset($vault->websiteUrl) ? trim($vault->websiteUrl) : "";
        }
        if ($vault->title === "") {
            $res->SetError("Title or website URL is required.");
            return null;
        }
        if (!isset($vault->username) || trim($vault->username) === "") {
            $res->SetError("Username is required.");
            return null;
        }
        if ($isNew && ($password === null || trim($password) === "")) {
            $res->SetError("Password is required.");
            return null;
        }
        if (!isset($vault->projectId) || intval($vault->projectId) <= 0) {
            $res->SetError("Project id is required.");
            return null;
        }
        if (!$this->canAccessProject($vault->projectId)) {
            $res->SetError("You do not have access to this project.");
            return null;
        }

        if (!$isNew) {
            $existing = $this->getVault($vault->vaultId);
            if ($existing === null) {
                $res->SetError("Vault record was not found or is not accessible.");
                return null;
            }
            if (!$this->canAccessProject($existing->projectId)) {
                $res->SetError("You do not have access to this vault record.");
                return null;
            }
            $vault->projectId = $existing->projectId;
            if (!isset($vault->sysviewobject) && isset($existing->sysviewobject)) {
                $vault->sysviewobject = $existing->sysviewobject;
            }
            if (!isset($vault->ownerProfileId) && isset($existing->ownerProfileId)) {
                $vault->ownerProfileId = $existing->ownerProfileId;
                $vault->ownerProfileName = isset($existing->ownerProfileName) ? $existing->ownerProfileName : "";
            }
            if (!isset($vault->createdate) && isset($existing->createdate)) {
                $vault->createdate = $existing->createdate;
            }
        } else {
            $profile = $this->currentProfile();
            $vault->ownerProfileId = $profile->id;
            $vault->ownerProfileName = $profile->name;
            $vault->createdate = date("Y-m-d H:i:s");
        }

        $vault->websiteUrl = isset($vault->websiteUrl) ? trim($vault->websiteUrl) : "";
        $vault->username = trim($vault->username);
        $vault->notes = isset($vault->notes) ? $vault->notes : "";
        $vault->status = isset($vault->status) && $vault->status !== "" ? $vault->status : "Active";
        $vault->updatedate = date("Y-m-d H:i:s");
        unset($vault->password);
        unset($vault->passwordConfirm);

        $result = $isNew ? SOSSData::Insert($this->vaultNamespace, $vault) : SOSSData::Update($this->vaultNamespace, $vault);
        if (!$result->success) {
            $res->SetError($result);
            return null;
        }
        if ($isNew && isset($result->result->generatedId)) {
            $vault->vaultId = $result->result->generatedId;
        }

        if ($password !== null && trim($password) !== "") {
            $this->saveSecret($vault, $password, $res);
        } else {
            $this->syncSecretViewObject($vault);
        }

        CacheData::clearObjects($this->vaultNamespace);
        CacheData::clearObjects($this->secretNamespace);
        return $this->publicVault($vault);
    }

    public function postDeleteVault($req, $res) {
        $body = $this->body($req);
        if (!isset($body->vaultId)) {
            $res->SetError("Vault id is required.");
            return null;
        }

        $vault = $this->getVault($body->vaultId);
        if ($vault === null) {
            $res->SetError("Vault record was not found or is not accessible.");
            return null;
        }

        $this->deleteByQuery($this->secretNamespace, "vaultId:" . $vault->vaultId);
        $result = SOSSData::Delete($this->vaultNamespace, $vault);
        CacheData::clearObjects($this->vaultNamespace);
        CacheData::clearObjects($this->secretNamespace);
        return $result->success ? $this->publicVault($vault) : null;
    }

    public function postCopyPassword($req, $res) {
        $body = $this->body($req);
        $out = new stdClass();
        $out->vaultId = isset($body->vaultId) ? $body->vaultId : 0;
        $out->password = "";

        if (!isset($body->vaultId)) {
            $res->SetError("Vault id is required.");
            return null;
        }

        $vault = $this->getVault($body->vaultId);
        if ($vault === null) {
            $res->SetError("Vault record was not found or is not accessible.");
            return null;
        }

        $secretResult = SOSSData::Query($this->secretNamespace, "vaultId:" . $vault->vaultId, null, "desc", 1, 0);
        if (!$secretResult->success || count($secretResult->result) === 0) {
            $res->SetError("Password was not found.");
            return null;
        }

        $out->vaultId = $vault->vaultId;
        $out->password = isset($secretResult->result[0]->password) ? $secretResult->result[0]->password : "";
        return $out;
    }

    private function saveSecret($vault, $password, $res) {
        $secretResult = SOSSData::Query($this->secretNamespace, "vaultId:" . $vault->vaultId, null, "desc", 1, 0);
        $secret = $secretResult->success && count($secretResult->result) > 0 ? $secretResult->result[0] : new stdClass();
        $secret->vaultId = $vault->vaultId;
        $secret->projectId = isset($vault->projectId) ? $vault->projectId : 0;
        $secret->password = $password;
        $secret->updatedate = date("Y-m-d H:i:s");
        $secret->sysviewobject = isset($vault->sysviewobject) ? $vault->sysviewobject : 0;

        if (isset($secret->secretId) && intval($secret->secretId) > 0) {
            $result = SOSSData::Update($this->secretNamespace, $secret);
        } else {
            $secret->createdate = date("Y-m-d H:i:s");
            $result = SOSSData::Insert($this->secretNamespace, $secret);
        }
        if (!$result->success) {
            $res->SetError($result);
        }
    }

    private function syncSecretViewObject($vault) {
        if (!isset($vault->vaultId)) {
            return;
        }
        $secretResult = SOSSData::Query($this->secretNamespace, "vaultId:" . $vault->vaultId, null, "desc", 10, 0);
        if (!$secretResult->success) {
            return;
        }
        foreach ($secretResult->result as $secret) {
            $secret->projectId = isset($vault->projectId) ? $vault->projectId : 0;
            $secret->sysviewobject = isset($vault->sysviewobject) ? $vault->sysviewobject : 0;
            SOSSData::Update($this->secretNamespace, $secret);
        }
    }

    private function getVault($vaultId) {
        $result = SOSSData::Query($this->vaultNamespace, "vaultId:" . $vaultId, null, "desc", 1, 0);
        if (!$result->success || count($result->result) === 0) {
            return null;
        }
        return $result->result[0];
    }

    private function publicVault($vault) {
        $out = clone $vault;
        unset($out->password);
        unset($out->passwordConfirm);
        return $out;
    }

    private function matchesSearch($vault, $search) {
        $values = array("title", "websiteUrl", "username", "status", "ownerProfileName");
        foreach ($values as $field) {
            if (isset($vault->{$field}) && strpos(strtolower($vault->{$field}), $search) !== false) {
                return true;
            }
        }
        return false;
    }

    private function deleteByQuery($namespace, $query) {
        $result = SOSSData::Query($namespace, $query);
        if ($result->success && count($result->result) > 0) {
            SOSSData::Delete($namespace, $result->result);
        }
    }

    private function body($req) {
        $data = $req->Body(true);
        return isset($data) ? $data : new stdClass();
    }

    private function currentProfile() {
        $out = new stdClass();
        $out->id = 0;
        $out->name = "Unknown";

        if (class_exists("Profile")) {
            $profile = Profile::getUserProfile();
            if (isset($profile->profile) && isset($profile->profile->id)) {
                $out->id = $profile->profile->id;
                $out->name = isset($profile->profile->name) ? $profile->profile->name : "Unknown";
                return $out;
            }
        }

        $user = Auth::Autendicate();
        if (isset($user->userid)) {
            $profileResult = SOSSData::Query("profile", "linkeduserid:" . $user->userid);
            if ($profileResult->success && count($profileResult->result) > 0) {
                $out->id = $profileResult->result[0]->id;
                $out->name = isset($profileResult->result[0]->name) ? $profileResult->result[0]->name : "Unknown";
                return $out;
            }
            $out->name = isset($user->email) ? $user->email : "Unknown";
        }
        return $out;
    }

    private function canAccessProject($projectId) {
        if ($this->isSysAdmin()) {
            return true;
        }

        $profileId = $this->currentProfileId();
        if ($profileId === null) {
            return false;
        }

        $access = SOSSData::Query($this->projectAccessNamespace, "projectId:" . $projectId . ",profileId:" . $profileId);
        return $access->success && count($access->result) > 0;
    }

    private function currentProfileId() {
        $profile = $this->currentProfile();
        return $profile->id === 0 ? null : $profile->id;
    }

    private function isSysAdmin() {
        return defined("GROUPID") && GROUPID === "sysadmin";
    }
}
?>
