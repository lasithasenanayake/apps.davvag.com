<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
class BroadcastService {

    function __construct(){
        
    } 

    private function setError($res,$message){
        $res->SetError($message);
        return null;
    }

    private function trimValue($value){
        if(!isset($value)){
            return "";
        }

        return trim((string)$value);
    }

    private function isSafeGroupId($groupid){
        return preg_match('/^[A-Za-z0-9_-]+$/',$groupid)===1;
    }

    private function isValidEmail($email){
        return filter_var($email,FILTER_VALIDATE_EMAIL)!==false;
    }

    private function protectedGroups(){
        return array("anonymous","web_user","facebook_user","sysadmin","sysuser");
    }

    private function isProtectedGroup($groupid){
        return in_array($groupid,$this->protectedGroups(),true);
    }

    private function sanitizeUser($user){
        if(isset($user->password)){
            unset($user->password);
        }

        if(isset($user->confirmpassword)){
            unset($user->confirmpassword);
        }

        return $user;
    }

    private function getGroupById($groupid){
        $result=SOSSData::Query("usergroups","groupid:".$groupid,null,"asc",20,0,AUTH_DOMAIN,false);
        if($result->success && count($result->result)>0){
            return $result->result[0];
        }

        return null;
    }

    private function clearUserCaches(){
        CacheData::clearObjects("users");
        CacheData::clearObjects("usergroups");
        CacheData::clearObjects("sys_access");
        CacheData::clearObjects("domain_permision_e");
    }

    public function getallusers($req,$res){
        $allkeys=CacheData::getObjects("all","users");
        if(isset($allkeys)){
            return $allkeys;
        }else{
            $r = SOSSData::Query ("users", null);
            if($r->success){
                $users=array();
                foreach($r->result as $user){
                    array_push($users,$this->sanitizeUser($user));
                }
                CacheData::setObjects("all","users",$users);
                return $users;
            }else{
                $res->SetError ($r->result);
                return $r->result; 
            }
        }
    }

    public function getSearchUsersByEmail($req,$res){
        if(!isset($_GET["email"])){
            return $this->getallusers($req,$res);
        }

        $email=strtolower($this->trimValue($_GET["email"]));
        if($email===""){
            return $this->getallusers($req,$res);
        }

        $r = SOSSData::Query ("users", null);
        if(!$r->success){
            $res->SetError ($r->result);
            return $r->result;
        }

        $users=array();
        foreach($r->result as $user){
            $currentEmail=isset($user->email)?strtolower($user->email):"";
            if(strpos($currentEmail,$email)!==false){
                array_push($users,$this->sanitizeUser($user));
            }
        }

        return $users;
    }

    public function postRegisterUser($req,$res){
        //require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
        $bodyReguser= $req->Body(true);
        if(!isset($bodyReguser)){
            return $this->setError($res,"Invalid user data.");
        }

        $bodyReguser->email=$this->trimValue(isset($bodyReguser->email)?$bodyReguser->email:"");
        $bodyReguser->name=$this->trimValue(isset($bodyReguser->name)?$bodyReguser->name:"");
        $bodyReguser->groupid=$this->trimValue(isset($bodyReguser->groupid)?$bodyReguser->groupid:"");

        if($bodyReguser->name===""){
            return $this->setError($res,"Full name is required.");
        }

        if(!$this->isValidEmail($bodyReguser->email)){
            return $this->setError($res,"A valid email address is required.");
        }

        if(!isset($bodyReguser->password) || strlen($bodyReguser->password)<6){
            return $this->setError($res,"Password must contain at least 6 characters.");
        }

        if(!$this->isSafeGroupId($bodyReguser->groupid) || !$this->getGroupById($bodyReguser->groupid)){
            return $this->setError($res,"Please select a valid user group.");
        }

        $user =new stdclass();
        if(isset($bodyReguser->username)){
            $user->username=$this->trimValue($bodyReguser->username);
        }else{
            $user->username=$bodyReguser->email;
        }
        $user->email=$bodyReguser->email;
        $user->name=$bodyReguser->name;
        $user->password=$bodyReguser->password;
        $r = SOSSData::Query ("users", "email:$user->email");
        if(count($r->result)>0){
            return $this->setError($res,"Already registered. User could not be created again.");
        }

        $outObject = Auth::SaveUser($user);
        
        if(isset($outObject->userid)){
            $bodyReguser->usergroupRses=Auth::Join(HOST_NAME,$outObject->userid,$bodyReguser->groupid);
            $bodyReguser->createdate=date_format(new DateTime(), 'm-d-Y H:i:s');
            $bodyReguser->userid=$outObject->userid;
            $bodyReguser->linkeduserid=$outObject->userid;
            $bodyReguser->status="tobeactivated";
            unset($bodyReguser->password);
            unset($bodyReguser->confirmpassword);
            if(!isset($bodyReguser->catogory)){
                $bodyReguser->catogory="User";
            }
            $result = SOSSData::Insert ("profile", $bodyReguser,$tenantId = null);
            $bodyReguser->id= $result->result->generatedId;
            $this->clearUserCaches();
            return $this->sanitizeUser($bodyReguser);
        }else{
            $res->SetError ($outObject);
            return $outObject;
        }
        
    }

    public function getChangeGroup($req,$res){
        if(isset($_GET["userid"]) && isset($_GET["groupid"])){
            $userid=$this->trimValue($_GET["userid"]);
            $groupid=$this->trimValue($_GET["groupid"]);

            if($userid===""){
                return $this->setError($res,"User id is required.");
            }

            if(!$this->isSafeGroupId($groupid) || !$this->getGroupById($groupid)){
                return $this->setError($res,"Please select a valid user group.");
            }

            $this->clearUserCaches();
            return $this->changeGroup($userid,$groupid);
        }else{
            return $this->setError($res,"Invalid call.");
        }
    }
    
    public function getUserGroups($req,$res){
        return Auth::GetUserGroups();
    }

    public function getNewUserGroup($req,$res){
        if(isset($_GET["groupid"])){
            $groupid=$this->trimValue($_GET["groupid"]);
            if(!$this->isSafeGroupId($groupid)){
                return $this->setError($res,"Group id can contain only letters, numbers, underscore and dash.");
            }

            if($this->getGroupById($groupid)){
                return $this->setError($res,"This user group already exists.");
            }

            $result=Auth::NewUserGroup($groupid);
            $this->clearUserCaches();
            return $result;
        }else{
            return $this->setError($res,"Invalid call.");
        }
    }

    public function postSaveUserGroup($req,$res){
        $body=$req->Body(true);
        if(!isset($body)){
            return $this->setError($res,"Invalid group data.");
        }

        $groupid=$this->trimValue(isset($body->groupid)?$body->groupid:"");
        $oldGroupId=$this->trimValue(isset($body->oldGroupId)?$body->oldGroupId:"");

        if(!$this->isSafeGroupId($groupid)){
            return $this->setError($res,"Group id can contain only letters, numbers, underscore and dash.");
        }

        if($oldGroupId!=="" && $oldGroupId===$groupid){
            $existing=$this->getGroupById($groupid);
            if(!$existing){
                return $this->setError($res,"User group was not found.");
            }

            return $existing;
        }

        if($oldGroupId===""){
            if($this->getGroupById($groupid)){
                return $this->setError($res,"This user group already exists.");
            }

            $result=Auth::NewUserGroup($groupid);
            $this->clearUserCaches();
            return isset($result[0])?$result[0]:$result;
        }

        if(!$this->isSafeGroupId($oldGroupId)){
            return $this->setError($res,"Invalid original group id.");
        }

        if($this->isProtectedGroup($oldGroupId)){
            return $this->setError($res,"System user groups cannot be renamed.");
        }

        $existing=$this->getGroupById($oldGroupId);
        if(!$existing){
            return $this->setError($res,"Original user group was not found.");
        }

        if($this->getGroupById($groupid)){
            return $this->setError($res,"This user group already exists.");
        }

        $existing->groupid=$groupid;
        $update=SOSSData::Update("usergroups",$existing,AUTH_DOMAIN);
        if(!$update->success){
            $res->SetError($update->result);
            return $update->result;
        }

        $this->renameGroupAssignments($oldGroupId,$groupid);
        $this->clearUserCaches();
        return $existing;
    }

    public function postDeleteUserGroup($req,$res){
        $body=$req->Body(true);
        $groupid=$this->trimValue(isset($body->groupid)?$body->groupid:"");

        if(!$this->isSafeGroupId($groupid)){
            return $this->setError($res,"Invalid user group.");
        }

        if($this->isProtectedGroup($groupid)){
            return $this->setError($res,"System user groups cannot be deleted.");
        }

        $group=$this->getGroupById($groupid);
        if(!$group){
            return $this->setError($res,"User group was not found.");
        }

        $assigned=SOSSData::Query("domain_permision","groupid:".$groupid,null,"asc",20,0,AUTH_DOMAIN,false);
        if($assigned->success && count($assigned->result)>0){
            return $this->setError($res,"This group has users assigned to it. Move those users before deleting the group.");
        }

        $delete=SOSSData::Delete("usergroups",$group,AUTH_DOMAIN);
        if(!$delete->success){
            $res->SetError($delete->result);
            return $delete->result;
        }

        $permissions=SOSSData::Query("usergroup_permission","groupid:".$groupid,null,"asc",100,0,AUTH_DOMAIN,false);
        if($permissions->success && count($permissions->result)>0){
            SOSSData::Delete("usergroup_permission",$permissions->result,AUTH_DOMAIN);
        }

        $this->clearUserCaches();
        return $group;
    }

    public function postAdminResetPassword($req,$res){
        $body=$req->Body(true);
        if(!isset($body)){
            return $this->setError($res,"Invalid reset password request.");
        }

        $userid=$this->trimValue(isset($body->userid)?$body->userid:"");
        $password=isset($body->password)?$body->password:"";

        if($userid===""){
            return $this->setError($res,"User id is required.");
        }

        if(strlen($password)<6){
            return $this->setError($res,"Password must contain at least 6 characters.");
        }

        $users=SOSSData::Query("users","userid:".$userid,null,"asc",20,0,AUTH_DOMAIN,false);
        if(!$users->success || count($users->result)==0){
            return $this->setError($res,"User was not found.");
        }

        $user=$users->result[0];
        $user->password=md5($password);
        $update=SOSSData::Update("users",$user,AUTH_DOMAIN);
        if(!$update->success){
            $res->SetError($update->result);
            return $update->result;
        }

        $this->clearUserCaches();
        return $this->sanitizeUser($user);
    }

    private function renameGroupAssignments($oldGroupId,$groupid){
        $domains=SOSSData::Query("domain_permision","groupid:".$oldGroupId,null,"asc",1000,0,AUTH_DOMAIN,false);
        if($domains->success){
            foreach($domains->result as $item){
                $item->groupid=$groupid;
                SOSSData::Update("domain_permision",$item,AUTH_DOMAIN);
            }
        }

        $permissions=SOSSData::Query("usergroup_permission","groupid:".$oldGroupId,null,"asc",1000,0,AUTH_DOMAIN,false);
        if($permissions->success){
            foreach($permissions->result as $item){
                $item->groupid=$groupid;
                SOSSData::Update("usergroup_permission",$item,AUTH_DOMAIN);
            }
        }
    }

    private function changeGroup($userid,$groupid){
        return Auth::Join(HOST_NAME,$userid,$groupid);
    }


    public function postDeleteItem($req,$res){
        $body=$req->Body(true);
        $rd=SOSSData::Delete("schedule_pending", $body);
        if($rd->success){
            return $rd->result;
        }else{
            $res->SetError ($rd->result);
            return $rd->result; 
        }
    }


}

?>
