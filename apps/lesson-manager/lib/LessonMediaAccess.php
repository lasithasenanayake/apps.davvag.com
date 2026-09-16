<?php
namespace lesson_manager;
final class LessonMediaAccess
{
    public static function authorize($store,$name,$method,$action)
    {
        require_once PLUGIN_PATH.'/auth/auth.php';
        require_once PLUGIN_PATH_LOCAL.'/profile/profile.php';
        $user=\Auth::Autendicate();$role=strtolower(defined('GROUPID')?GROUPID:($user->group ?? 'anonymous'));
        // Package covers are public; all teaching resources require real authentication.
        if($method==='GET' && $action==='get' && $store==='lmp_cover')return true;
        if(!$user || $role==='anonymous')return false;
        $stored=\Profile::getUserProfile();$profile=(int)(($stored->profile ?? $stored)->id ?? 0);if($profile<1)return false;
        if($method==='POST') {
            if(!in_array($action,['upload','upload_uncompressed'],true))return false;
            if($store!=='lesson_assignment_submission' && !in_array($role,['admin','sysadmin','staff','teacher'],true))return false;
            if(is_file(rtrim(MEDIA_FOLDER,'/\\').'/'.DATASTORE_DOMAIN.'/'.$store.'/'.$name))return false;
            $_SESSION['lesson_staged_media'][$store.'/'.$name]=$profile;
            return true;
        }
        if($action!=='get')return false;
        if(self::ownsStaged($store,$name,$profile))return true;
        require_once dirname(__DIR__).'/services/api/service.php';
        return (new ApiService())->canReadMedia($store,$name,$profile);
    }
    public static function ownsStaged($store,$name,$profile)
    {
        return (int)($_SESSION['lesson_staged_media'][$store.'/'.$name] ?? 0)===(int)$profile && (int)$profile>0;
    }
}
