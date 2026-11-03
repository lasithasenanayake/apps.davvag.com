<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile-stelup/profile.php");


class appService {

    function __construct(){
        
    } 


    public function postFollow($req,$res){
        $p=$req->Body(true);
        $userprofile=Profile::getUserProfile();
        if (!isset($userprofile->profile) || !isset($p->id)) {
            $res->SetError("User Not Authenticated.");
            return;
        }
        $p->pid=$userprofile->profile->id;
        $profile= Profile::getProfile($p->id,$p->pid);
        if($profile){
            if(isset($profile->profile)){
                if($p->liked){
                    SOSSData::Insert("profile_followers",$p);
                }else{
                    SOSSData::Delete("profile_followers",$p);
                    return $p;
                }
                $id=$userprofile->profile->id;
                Profile::getClearProfile($id,$id);
                Profile::getClearProfile($p->id,$id);
                return $p;
            }else{
                $res->SetError ("invalied Follower.");
                return;
            }
        }else{
            $res->SetError ("Follower profile was not found.");
            return;
        }
    }

    public function getProfile($req,$res){
        //SOSSData::Insert("products_reviews",array("itemid"=>0,"pid"=>"0"));
        $userprofile=Profile::getUserProfile();
        //return $userprofile;
        //return $userprofile;
        $id=isset($userprofile->profile)?$userprofile->profile->id:0;
        $profileId=isset($_GET["id"])?$_GET["id"]:0;
        $profile=Profile::getProfile($profileId,$id);
        if(isset($profile->profile)){
            $outprofile = $profile->profile;
            $outprofile->attributes=$profile->attributes;
            $outprofile->followed= $id==$outprofile->id?-1:$outprofile->followed;
            
            return $outprofile;
        }else{
            return array();
        }
    }

    public function postSendMessage($req,$res){
        $p=$req->Body(true);
        
        $userprofile=Profile::getUserProfile();
        if(isset($userprofile->profile) && isset($p->id, $p->message)){
            $profile= Profile::getProfile($p->id,$userprofile->profile->id);
            if(isset($profile->profile)){
                $message =new stdClass();
                $message->inboxid=$profile->profile->id;
                $message->userboxid=$userprofile->profile->id;
                $message->messagetype=isset($p->messagetype)?$p->messagetype:"message";
                $message->messagetext=trim((string)$p->message);
                if ($message->messagetext === "") {
                    $res->SetError("Message cannot be empty.");
                    return;
                }
                $message->m_read=0;
                $message->m_to=$profile->profile->id;
                $message->m_from=$userprofile->profile->id;
                $message->msgdate=date("m-d-Y H:i:s");
               
                $r=SOSSData::Insert("messages",$message);
                $data=array('name' =>$userprofile->profile->name, 'pid' =>$userprofile->profile->id);
                Profile::AddNotify($profile->profile->id,"message_received",$data);
                
                if($r->success){
                    $mybox=$message;
                    $mybox->inboxid=$userprofile->profile->id;
                    $mybox->userboxid=$profile->profile->id;
                    $rm=SOSSData::Insert("messages",$mybox);
                    //Profile::AddNotify($userprofile->profile->id,"message",$message);
                    if($rm->success){
                        $mybox->id=$rm->result->generatedId;
                        profile::Send_Notify();
                        return $mybox;
                    }else{
                        $res->SetError ($rm);
                        return;
                    }
                }else{
                    $res->SetError ($r);
                    return;
                }
            }else{
                $res->SetError ($profile);
                return;
            }
        }else{
            $res->SetError ("User Not Authendicated.");
            return;
        }
    }

    public function getMessageInbox($req,$res){
        $userprofile=Profile::getUserProfile();
        if(isset($userprofile->profile)){
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->profileId =$userprofile->profile->id;
            $resultObj = SOSSData::ExecuteRaw("messages_inbox_query", $mainObj);
            //return $resultObj;
            if($resultObj->success){
                return $resultObj->result;
            }else{
                $res->SetError ($resultObj);
                return;
            }
        }else{
            $res->SetError ("User Not Authendicated.");
            return;
        }
    }

    public function getMessages($req,$res){
        $userprofile=Profile::getUserProfile();
        if(isset($userprofile->profile)){
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->id =isset($_GET["id"])?$_GET["id"]:0;
            $mainObj->parameters->lastid =isset($_GET["lastid"])?$_GET["lastid"]:0;
            $mainObj->parameters->page =isset($_GET["page"])?$_GET["page"]:0;
            $mainObj->parameters->size =isset($_GET["size"])?$_GET["size"]:40;
            $mainObj->parameters->userid =$userprofile->profile->id;
            $resultObj = SOSSData::ExecuteRaw("messages_query", $mainObj);
            if($resultObj->success){
                return $resultObj->result;
            }else{
                $res->SetError ($resultObj);
                return;
            }
        }else{
            $res->SetError ("User Not Authendicated.");
            return;
        }
    }
    

    public function getAllProducts($req,$res){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
            $mainObj->parameters->id = isset($_GET["id"])?$_GET["id"]:0;
            $mainObj->parameters->pid = isset($_GET["pid"])?$_GET["pid"]:0;
            $mainObj->parameters->tid = isset($_GET["tid"])?$_GET["tid"]:0;


            $resultObj = SOSSData::ExecuteRaw("products_stelup_2", $mainObj);
            if($resultObj->success){
                return $resultObj->result;
            }else{
                //if(!$result->success){
                //}
                $res->SetError ($resultObj);
                return $resultObj;
            }
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function getSellerProducts($req,$res){
        $userprofile=Profile::getUserProfile();
        if (isset($userprofile->profile)){
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->pid = $userprofile->profile->id;
            $resultObj = SOSSData::ExecuteRaw("products_stelup_3", $mainObj);
            if($resultObj->success){
                return $resultObj->result;
            }else{
                $res->SetError ($resultObj);
                return $resultObj;
            }
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    


}

?>
