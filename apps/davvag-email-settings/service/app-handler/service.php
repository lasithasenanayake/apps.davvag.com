<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/notify/notify.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        try{
            $location=TENANT_RESOURCE_LOCATION."/global/config/";
            file_put_contents($location."globals.conf",json_encode($data->otherdata));
            unset($data->otherdata);
            file_put_contents($location."emailsmtp.conf",json_encode($data));

            return $data; 
        }catch(Exceptin $e){
            $res->SetError($e);
        }
    }

    public function getSettings($req,$res){
        $location=TENANT_RESOURCE_LOCATION."/global/config/";
        $config=new stdClass();
        if(file_exists($location."emailsmtp.conf")){
            try{
                $jsonContents = file_get_contents($location."emailsmtp.conf");
                $config= json_decode($jsonContents);
            }catch(Exception $e){
                $config=new stdClass();
            }

        }
        if(file_exists($location."globals.conf")){
            try{
                $jsonContents = file_get_contents($location."globals.conf");
                $config->otherdata= json_decode($jsonContents);
            }catch(Exception $e){
                $config->otherdata=new stdClass();
            }

        }
            
        return $config;
        
    }

    public function getTestMail($req,$res){
        $profile=Profile::getUserProfile();
        if($profile->profile){
            return Notify::sendEmailMessage($profile->profile->name,$profile->profile->email,"test_mail",$profile->profile);
        }else{
            $res->SetError("Error retriving user");
        }
    }

    


}

?>