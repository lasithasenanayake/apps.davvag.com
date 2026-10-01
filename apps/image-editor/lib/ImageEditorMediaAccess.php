<?php
namespace image_editor;

final class ImageEditorMediaAccess
{
    private static $namespaces = array("image_editor_asset", "image_editor_font", "image_editor_template");

    public static function authorize($store, $name, $method, $action)
    {
        require_once PLUGIN_PATH . "/auth/auth.php";
        require_once PLUGIN_PATH . "/sossdata/SOSSData.php";
        require_once PLUGIN_PATH_LOCAL . "/profile/profile.php";
        $user = \Auth::Autendicate();
        $role = strtolower(defined("GROUPID") ? GROUPID : (is_object($user) && isset($user->group) ? $user->group : "anonymous"));
        if (!$user || $role === "anonymous") return false;
        $stored = \Profile::getUserProfile(); $profile = isset($stored->profile) ? $stored->profile : $stored;
        $profileId = is_object($profile) && isset($profile->id) ? intval($profile->id) : 0;
        if ($profileId < 1) return false;
        if ($method === "POST") {
            if (!in_array($action, array("upload", "upload_uncompressed"), true)) return false;
            if (self::mediaExists($store, $name)) return false;
            if (!isset($_SESSION["image_editor_staged_media"])) $_SESSION["image_editor_staged_media"] = array();
            $_SESSION["image_editor_staged_media"][$store . "/" . $name] = $profileId;
            return true;
        }
        if ($method !== "GET" || $action !== "get") return false;
        if (self::ownsStaged($store, $name, $profileId)) return true;
        return \SOSSData::WithServiceNamespaces(self::$namespaces, function () use ($store, $name, $profileId, $role) {
            if ($store === "image_editor_asset") {
                foreach (self::findAllByReference("image_editor_asset", $store, $name) as $row) {
                    if (intval($row->owner_profile_id) === $profileId && in_array($row->status, array("active", "archived"), true)) return true;
                }
                return false;
            }
            if ($store === "image_editor_font") {
                $row = self::findByReference("image_editor_font", $store, $name);
                if (!$row || !in_array($row->status, array("active", "archived"), true)) return false;
                return $row->visibility === "tenant" || intval($row->owner_profile_id) === $profileId || in_array($role, array("admin", "sysadmin"), true);
            }
            if ($store === "image_editor_font_license") {
                $row = self::findByLicenseReference($store, $name);
                return $row && (intval($row->owner_profile_id) === $profileId || in_array($role, array("admin", "sysadmin"), true));
            }
            return false;
        });
    }

    public static function ownsStaged($store, $name, $profileId)
    {
        return intval(isset($_SESSION["image_editor_staged_media"][$store . "/" . $name]) ? $_SESSION["image_editor_staged_media"][$store . "/" . $name] : 0) === intval($profileId) && intval($profileId) > 0;
    }

    private static function findByReference($namespace, $store, $name) { return self::first($namespace, "media_reference:" . urlencode("components/dock/soss-uploader/service/get/" . $store . "/" . $name)); }
    private static function findAllByReference($namespace, $store, $name) { $query="media_reference:".urlencode("components/dock/soss-uploader/service/get/".$store."/".$name);$result=\SOSSData::Query($namespace,$query,null,"DESC",250,0);return !empty($result->success)&&isset($result->result)&&is_array($result->result)?$result->result:array(); }
    private static function findByLicenseReference($store, $name) { return self::first("image_editor_font", "license_media_reference:" . urlencode("components/dock/soss-uploader/service/get/" . $store . "/" . $name)); }
    private static function first($namespace, $query) { $result = \SOSSData::Query($namespace, $query, null, "DESC", 1, 0); return !empty($result->success) && !empty($result->result[0]) ? $result->result[0] : null; }
    private static function mediaExists($store, $name) { return defined("MEDIA_FOLDER") && defined("DATASTORE_DOMAIN") && is_file(rtrim(MEDIA_FOLDER, "/\\") . "/" . DATASTORE_DOMAIN . "/" . $store . "/" . $name); }
}
