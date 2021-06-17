<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class DirectPay_IPG {

    function __construct(){
        
    } 

    public function postSave($req,$res){
      require_once(PLUGIN_PATH_LOCAL . "/davvag-ipg/davvag-ipg.php");
      $data=$req->Body(true);
      $Store_profile= Profile::getProfile(0,0);
      if($Store_profile->profile){
        $data->id=$Store_profile->profile->id;
      }else{
        $data->id=0;
      }
      
      $q=SOSSData::Query("davvag_directpay_lk","id:".$data->id);
      if($q->success){
        if(count($q->result)==0){
          
            //SOSSData::Insert("davvag_ipgs",)
            SOSSData::Insert("davvag_directpay_lk",$data);
            Davvag_IPG::SaveNewIPG("davvag-directpay-lk",$data->id,"Direct Pay","Direct Pay is a Sri Lanka Payment Gateway","http://www.directpay.lk","assets/davvag-directpay-lk/directpay.png");
            CacheData::clearObjects("davvag_directpay_lk");
            return $data;
        }else{
            SOSSData::Update("davvag_directpay_lk",$data);
            Davvag_IPG::SaveNewIPG("davvag-directpay-lk",$data->id,"Direct Pay","Direct Pay is a Sri Lanka Payment Gateway","http://www.directpay.lk","assets/davvag-directpay-lk/directpay.png");
            CacheData::clearObjects("davvag_directpay_lk");
            return $data;
        }
      }else{
        $res->SetError($q);

      }

    }


}

?>