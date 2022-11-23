<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-attributes/davvag-attributes.php");

class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        return Davvag_Attributes::Save($data); 
    }

    


}

?>