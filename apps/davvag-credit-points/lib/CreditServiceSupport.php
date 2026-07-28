<?php
namespace davvag_credit_points;
if(defined("PLUGIN_PATH")){if(file_exists(PLUGIN_PATH."/auth/auth.php"))require_once(PLUGIN_PATH."/auth/auth.php");if(defined("PLUGIN_PATH_LOCAL")&&file_exists(PLUGIN_PATH_LOCAL."/profile/profile.php"))require_once(PLUGIN_PATH_LOCAL."/profile/profile.php");}
require_once __DIR__."/CreditLedgerService.php";
class CreditServiceSupport {
    public static function body($req){$data=$req->Body(true);return is_object($data)?$data:new \stdClass();}
    public static function profile(){if(class_exists("\\Profile")){$stored=\Profile::getUserProfile();$profile=is_object($stored)&&isset($stored->profile)?$stored->profile:$stored;if(is_object($profile)&&isset($profile->id)&&intval($profile->id)>0)return$profile;}throw new CreditException("An active profile is required.");}
    public static function role(){if(defined("GROUPID"))return strtolower(strval(GROUPID));if(class_exists("\\Auth")){$user=\Auth::Autendicate();if(is_object($user)&&isset($user->group))return strtolower(strval($user->group));}return"anonymous";}
    public static function isAdmin(){return in_array(self::role(),array("sysadmin","admin"),true);}
    public static function requireAdmin(){if(!self::isAdmin())throw new CreditException("Administrator permission is required.");return self::profile();}
    public static function value($body,$name,$default=null){return isset($body->{$name})?$body->{$name}:$default;}
    public static function idempotency($body,$prefix){$value=trim(strval(self::value($body,"idempotency_key","")));return$value!==""?$value:$prefix."-".bin2hex(random_bytes(12));}
    public static function context($body,$source,$referenceType,$referenceId,$description){$profile=self::profile();return array("programCode"=>strval(self::value($body,"program_code","CREDIT")),"sourceApp"=>$source,"referenceType"=>$referenceType,"referenceId"=>strval($referenceId),"idempotencyKey"=>self::idempotency($body,$source),"description"=>$description,"metadata"=>self::value($body,"metadata",array()),"actorProfileId"=>intval($profile->id));}
    public static function fail($res,$error){$message=$error instanceof \Throwable?$error->getMessage():strval($error);$res->SetError($message);return null;}
}
?>
