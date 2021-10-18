<?php
require_once (PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
class LoginService {

    function __construct(){
        
    } 

    public function getSession($req,$res){
        if(isset($_COOKIE["securityToken"])){
            setcookie("sosskey",$_COOKIE["securityToken"]);
            //return $_GET["token"];
            return AUTH::GetSession($_COOKIE["securityToken"]);
        }else{
            $res->SetError ("Session not Valied");
            return;
        }
    }

    public function getLogin($req){
        $authObject =  AUTH::Login($_GET["email"], $_GET["password"]);
        
        
        return $authObject;
    }

    public function getLogout($req){
        $user=Auth::Autendicate();
        if(isset($user->token)){
        Auth::GetLogout($user->token);
        }
        return true;
    }

    public function getGetResetToken($req){
        return AUTH::GetResetToken ($_GET["token"]);
    }

    public function getResetPassword($req){
        return AUTH::ResetPassword ($_GET["email"], $_GET["token"], $_GET["password"]);
    }

    public function getNotification($req){
        $userprofile=Profile::getUserProfile();
        if($userprofile->profile){
            $result = SOSSData::Query("profile_notify_u","pid:".$userprofile->profile->id);
            if($result->success){
                return $result->result;
            }else{
                return [];
            }
        }else{
            return [];
        }
    }

    public function getLaunchers($req,$res){
        $appCode=$_GET["appcode"];
        $component=$_GET["component"];
        $groupId=GROUPID;
        $idKey=$appCode."-".$component."-".$groupId;
        $data=CacheData::getObjects($idKey,"davvag_launchers");
        if(isset($data)){
            return $data;
        }else{
            $data = $this->getAppLaunchers($appCode,$component,$groupId,0);
            CacheData::setObjects($idKey,"davvag_launchers",$data);
            return $data;
        }
    }

    private function getAppLaunchers($appcode,$component,$groupId,$pid){
        $data=[];
        $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            
            $mainObj->parameters->groupId = $groupId;
            $mainObj->parameters->bid = $pid;
            
            if(isset($appcode)){
                $mainObj->parameters->appcode = $appcode;
                $mainObj->parameters->component = $component;
                $resultObj = SOSSData::ExecuteRaw("davvag_launchers_query", $mainObj);
            }else{
                $resultObj = SOSSData::ExecuteRaw("davvag_launchers_subquery", $mainObj);
            }
            if($resultObj->success){
                foreach ($resultObj->result as $key => $value) {
                    # code...
                    $value->Launchers=$this->getAppLaunchers(null,null,$groupId,$value->bid);
                    array_push($data,$value);
                }
            }else{
                return $resultObj;
            }
       
        return $data;
    }

    public function getClearNotiifcatiion($req,$res){
        $userprofile=Profile::getUserProfile();
        $result = SOSSData::Query("profile_notify_u","id:".$_GET["id"]);
        if($result->success && count($result->result)>0){
            $n=$result->result[0];
            if($n->pid==$userprofile->profile->id){
                
                SOSSData::Delete("profile_notify_u",$n);
                return $n;
            }else{
                $res->SetError ("UnAuthorized Access.");
                return false;
            }
        }else{
            $res->SetError ("Notification not found.");
            return false;
        }
    }
}

?>