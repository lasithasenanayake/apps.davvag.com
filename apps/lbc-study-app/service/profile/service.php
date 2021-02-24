<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");
class ProfileService{
    

    public function getSearch($req){
        $s  =null;
        if(isset($_GET["q"])){
            $search  =$_GET["q"];
        }
        $result= CacheData::getObjects(md5($search),"profile");
        if(!isset($result)){
            $result = SOSSData::Query ("profile",urlencode($search));
            if($result->success){
                if(isset($result->result)){
                    CacheData::setObjects(md5($search),"profile",$result->result);
                }
            }
            return $result->result;
        }else{
            return $result;
        }
    }

    public function getByID($req){
        $s  =null;
        $search=null;
        if(isset($_GET["id"])){
            $search  = strval($_GET["id"]);
        }
        $profile=new stdClass();
        $result= CacheData::getObjects(md5($search),"profile");
        if(!isset($result)){
            $result = SOSSData::Query ("profile",urlencode("id:".$search));
            if($result->success){
                if(count($result->result)!=0){
                    $profile=$result->result[0];
                    $result = SOSSData::Query ("profile_attributes",urlencode("id:".$search));
                    $profile->attributes=$result->result[0]!=null?$result->result[0]:new stdClass();
                    //return $profile;
                    //$profile->attributes=(count($result->$result)!=0?$result->result[0]:array());
                    CacheData::setObjects(md5($search),"profile",$profile);
                }
            }
            return $profile;
        }else{
            return $result;
        }
    }

    public function postq($req){
        $sall=$req->Body(true);
        $f=new stdClass();
        foreach($sall as $s){
            $result= CacheData::getObjects(md5($s->search),$s->storename);
            if(!isset($result)){
                $result = SOSSData::Query ($s->storename,urlencode($s->search));
                if($result->success){
                    $f->{$s->storename}=$result->result;
                    if(isset($result->result)){
                        CacheData::setObjects(md5($s->search),$s->storename,$result->result);
                    }
                }else{
                    $f->{$s->storename}=null;
                }
            }else{
                $f->{$s->storename}= $result;
            }
            
        }
        return $f;
    }

    public function postChangeStatus($req,$res){
        $profile = $req->Body(true);
        if(isset($profile->id)){
            $result = SOSSData::Query ("profile", urlencode("id:".($profile->id==null?0:$profile->id).""));
            if($result->success && count($result->result)>0){
                $tmpStatus=$profile->Status;
                $profile=$result->result[0];
                $profile->Status=$tmpStatus;
                //return $profile;
                $result = SOSSData::Update("profile", $profile);
                CacheData::clearObjects("profile");
                if($result->success){
                    return $profile;
                }else{
                    $res->SetError($result);
                    return null;
                }
            }else{
                $res->SetError("Invalied Request.");
                return null;
            }
        }else{
            $res->SetError("Invalied Request.");
            return null;
        }
    }
    
}

?>