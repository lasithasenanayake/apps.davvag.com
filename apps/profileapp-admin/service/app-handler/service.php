<?php
require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once (PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        return $data; 
    }

    public function postDelete($req,$res){
        $data = $req->Body(true);
        $handler =new Davvag_Order();
        $profile =Profile::getUserProfile();
        try {
            //code...
            if($profile->profile){
                $result=$handler->DeleteOrder($data->invoiceNo,$data->remarks,$profile->profile->id,$profile->profile->name,$data->profileId);
                return $data;
            }else{
                $res->SetError("User Not Authorized");
            }
        } catch (Exception $e) {
            $res->SetError($e);
        }
        
         
    }

    


}

?>