<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class ProductServices {

    public function getAllProducts($req){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
            $mainObj->parameters->search = isset($_GET["q"]) ?  $_GET["q"] : "";
            $mainObj->parameters->rad = '0';
            $mainObj->parameters->lon = '0';
            $mainObj->parameters->lan = '0';
            $mainObj->parameters->cat = isset($_GET["cat"])?$_GET["cat"]:'0';

            $resultObj = SOSSData::ExecuteRaw("profiles_search", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function postPaymentRequest($req,$res){
        $data = $req->Body(true);
        $paymentProfile=null;
        $r = SOSSData::Query ("profile", urlencode("id:".$data->profileid.""));
        if(count($r->result)==0){
            $res->SetError("Profile not file to pay");
            return null;
        }else{
            $paymentProfile=$r->result[0];
            $data->profileId=$paymentProfile->id;
            $data->studentname=$paymentProfile->name;
            $Store_profile= Profile::getProfile(0,0);
            if(isset($Store_profile->profile)){
                //return $Store_profile->profile;
                $Store_profile=$Store_profile->profile;
                $data->supplier_profileId = $Store_profile->id; 
                $data->supplier_name = $Store_profile->name;
                $data->supplier_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
                $data->supplier_address = isset($Store_profile->address)?$Store_profile->address:null;
                $data->supplier_city = isset($Store_profile->city)?$Store_profile->city:null;
                $data->supplier_country = isset($Store_profile->country)?$Store_profile->country:null;
                $data->supplier_email = isset($Store_profile->email)?$Store_profile->email:null;
            }else{
                $data->supplier_profileId = 0;
            }
            $r = SOSSData::Query ("profilestatus", urlencode("profileid:".$data->profileid.""));
            if(count($r->result)==0){
                $res->SetError("No Outstanding amount to pay.");
                return null;
            }else{
                $profileBalance=$r->result[0];
                if($profileBalance->outstanding<$data->amount){
                    $res->SetError("Payment is lager than the required donation.");
                    return null;
                }
                $data->outstandingAmount=$profileBalance->outstanding;
                $data->paymentAmount=$data->amount;
                $data->balanceAmount=$profileBalance->outstanding-$data->amount;
                //$profileBalance->totalPaidUnrealized=$profileBalance->totalPaidUnrealized==null?$data->amount:$profileBalance->totalPaidUnrealized+$data->amount;
                //$result=SOSSData::Update ("profilestatus", $profileBalance);
            }
        }

        $r = SOSSData::Query ("profile", urlencode("email:".$data->email.""));
        if(count($r->result)!=0){
            $data->donorId =$r->result[0]->id;
        }else{
            $result=SOSSData::Insert ("profile", $data);
                    if($result->success){
                        $data->donorId=$result->result->generatedId;
                    }
                    
        }
        
        $result=SOSSData::Insert ("payment_ext_request", $data);
        if($result->success){
            $data->id=$result->result->generatedId;
            require_once(PLUGIN_PATH . "/notify/notify.php");
            return Notify::sendEmailMessage($data->name,$data->email,"com_qti_payment_request",$data);
        }
        
        return $data;

    }
}

?>