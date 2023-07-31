<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once (PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
class ProfileService{
    //public var $appname="profileapp";
    private function updateLedger($ledgertran){
        $Transaction=$ledgertran;
        $result=SOSSData::Insert ("ledger", $ledgertran,$tenantId = null);
        $result = SOSSData::Query ("profilestatus", urlencode("profileid:".$Transaction->profileid.""));
        CacheData::clearObjects("profilestatus");
        CacheData::clearObjects("ledger");
        
        if(count($result->result)!=0){
            $status= $result->result[0];
            $status->outstanding+=$Transaction->amount;
            if(defined("CURRENCY_CODE")){
                $status->currencycode=CURRENCY_CODE;
            }
            switch(strtolower($ledgertran->trantype)){
                case "invoice":
                    $status->totalInvoicedAmount+=$Transaction->amount;
                    break;
                case "receipt":
                    $status->totalPaidAmount+=$Transaction->amount;
                    break;
                case "grn":
                    $status->totalGRNAmount+=$Transaction->amount;
                    break;
                case "payment":
                    $status->totalPaymentAmount+=$Transaction->amount;
                    break;
            }
            $result=SOSSData::Update ("profilestatus", $status,$tenantId = null);
        }else{
            $status=new stdClass();
            $status->profileid=$Transaction->profileid;
            $status->outstanding=$Transaction->amount;
            $status->totalInvoicedAmount=0;
            $status->totalPaidAmount=0;
            $status->totalGRNAmount=0;
            $status->totalPaymentAmount=0;
            if(defined("CURRENCY_CODE")){
                $status->currencycode=CURRENCY_CODE;
            }
            switch(strtolower($ledgertran->trantype)){
                case "invoice":
                    $status->totalInvoicedAmount+=$Transaction->amount;
                    break;
                case "receipt":
                    $status->totalPaidAmount+=$Transaction->amount;
                    break;
                case "grn":
                    $status->totalGRNAmount+=$Transaction->amount;
                    break;
                case "payment":
                    $status->totalPaymentAmount+=$Transaction->amount;
                    break;
            }
            $result=SOSSData::Insert ("profilestatus", $status,$tenantId = null);
                    
        }
    }

    public function getSupplierData($req,$res){
        
        $Store_profile= Profile::getProfile(0,0);
        if(isset($Store_profile->profile)){
            return $Store_profile->profile;
        }else{return null;}
    }

    public function postInvoiceSave($req,$res){
        
        $Transaction=$req->Body(true);
        $user= Auth::Autendicate("profile","postInvoiceSave",$res);
        if(!isset($Transaction->email)){
            $res->SetError ("provide email");
            return;
            
        }
        if(!isset($Transaction->contactno)){
            $res->SetError ("provide contact no");
            return;
        }
        
        $result = SOSSData::Query ("profile", urlencode("id:".$Transaction->profileId.""));
        $Transaction->status="new";
        //return $result;
        if(count($result->result)!=0)
        {
            $Store_profile= Profile::getProfile(0,0);
            if(isset($Store_profile->profile)){
                //return $Store_profile->profile;
                $Store_profile=$Store_profile->profile;
                $Transaction->supplier_profileId = $Store_profile->id; 
                $Transaction->supplier_name = $Store_profile->name;
                $Transaction->supplier_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
                $Transaction->supplier_address = isset($Store_profile->address)?$Store_profile->address:null;
                $Transaction->supplier_city = isset($Store_profile->city)?$Store_profile->city:null;
                $Transaction->supplier_country = isset($Store_profile->country)?$Store_profile->country:null;
                $Transaction->supplier_email = isset($Store_profile->email)?$Store_profile->email:null;
            }
            $Transaction->preparedByID=$user->userid;
            $Transaction->preparedBy=$user->email;
            $Transaction->PaymentComplete="N";
            $Transaction->balance=$Transaction->total;
            if(defined("CURRENCY_CODE")){
                $Transaction->currencycode=CURRENCY_CODE;
            }
            $result = SOSSData::Insert ("orderheader", $Transaction,$tenantId = null);
            CacheData::clearObjects("orderheader");
            if($result->success){
                $Transaction->invoiceNo = $result->result->generatedId;
                $ledgertran =new StdClass;
                $ledgertran->profileid=$Transaction->profileId;
                $ledgertran->tranid=$Transaction->invoiceNo;
                $ledgertran->trantype='invoice';
                $ledgertran->tranDate=$Transaction->invoiceDate;
                $ledgertran->description='Invoice No Has been generated';
                $ledgertran->amount=$Transaction->total;
                if(defined("CURRENCY_CODE")){
                    $ledgertran->currencycode=CURRENCY_CODE;
                }
                $this->updateLedger($ledgertran);   
                
                //return $Transaction;
                if($result->success){
                
                    $profileservices=array();
                    foreach($Transaction->InvoiceItems as $key=>$value){
                        $Transaction->InvoiceItems[$key]->invoiceNo=$Transaction->invoiceNo;
                        if(strtolower($value->invType)=="service"){
                            $serviceitems =new StdClass;
                            $serviceitems->invid=$Transaction->invoiceNo;
                            $serviceitems->profileId=$Transaction->profileId;
                            $serviceitems->itemid=$value->itemid;
                            $serviceitems->name=$value->name;
                            $serviceitems->purchaseddate=$Transaction->invoiceDate;
                            $serviceitems->price=$value->total;
                            $serviceitems->catogory=$value->catogory;
                            $serviceitems->uom=$value->uom;
                            $serviceitems->qty=$value->qty;
                            $serviceitems->status="ToBeActive";
                            
                            array_push($profileservices,$serviceitems);
                        }
                        //var_dump($Transaction->InvoiceItems[$key]->invoiceNo);
                        $this->updateInventry($value,-1);
                    }
                    //return $profileservices;
                    $result = SOSSData::Insert ("orderdetails", $Transaction->InvoiceItems,$tenantId = null);
                    if(count($profileservices)!=0){
                        $result = SOSSData::Insert ("profileservices", $profileservices,$tenantId = null);
                        CacheData::clearObjects("profileservices");
                    }
                    //return $result;
                    
                    CacheData::clearObjects("orderdetails");
                }else{
                    $res->SetError ("Erorr");
                    return $result;
                }
                //unset($value); 
                
                
                return $Transaction;
            }else{
                return $result;
            }
        }else{
           //var_dump($result->response[0]->id);
           //exit();
           $res->SetError ("Invalied Profile");
           exit();
        }
        
        
    }

    private function updateInventry($value,$s){
        if(strtolower($value->invType)=="inventry"){
            $resultitems = SOSSData::Query ("product_inventrymaster", urlencode("itemid:".$value->itemid.""));//SOSSData::Insert ("", $Transaction,$tenantId = null);
            if(count($resultitems->result)!=0){
                $itemInv=$resultitems->result[0];
                if($s<0){
                    $itemInv->qty=$itemInv->qty-$value->qty;
                }else{
                    $itemInv->qty=$itemInv->qty+$value->qty;
                }
                SOSSData::Update ("product_inventrymaster", $itemInv,$tenantId = null);
            }else{
                $itemInv =new StdClass;
                $itemInv->itemid=$value->itemid;
                $itemInv->uom=$value->uom;
                if($s<0){
                    $itemInv->qty=-1*$value->qty;
                }else{
                    $itemInv->qty=$value->qty;
                }
                SOSSData::Insert ("product_inventrymaster", $itemInv,$tenantId = null);
            }
        }
    }

    public function postPOSave($req,$res){
        
        $Transaction=$req->Body(true);
        $user= Auth::Autendicate("profile","postPOSave",$res);
        if(!isset($Transaction->email)){
            $res->SetError ("provide email");
            
        }
        if(!isset($Transaction->contactno)){
            $res->SetError ("provide contact no");
        }
        
        $result = SOSSData::Query ("profile", urlencode("id:".$Transaction->profileId.""));
        
        //return $result;
        if(count($result->result)!=0)
        {
            
            $Transaction->preparedByID=$user->userid;
            $Transaction->preparedBy=$user->email;
            $Transaction->Complete="N";
            $Transaction->balance=$Transaction->total;
            $result = SOSSData::Insert ("poheader", $Transaction,$tenantId = null);
            CacheData::clearObjects("poheader");
            if($result->success){
                $Transaction->tranNo = $result->result->generatedId;
                if($result->success){
                    
                    $profileservices=array();
                    foreach($Transaction->InvoiceItems as $key=>$value){
                        $Transaction->InvoiceItems[$key]->tranNo=$Transaction->tranNo;
                        //var_dump($Transaction->InvoiceItems[$key]->tranNo);
                    }
                    $result = SOSSData::Insert ("podetails", $Transaction->InvoiceItems,$tenantId = null);
                    
                    CacheData::clearObjects("podetails");
                }else{
                    $res->SetError ("Erorr");
                    return $result;
                }
                
                return $Transaction;
            }else{
                return $result;
            }
        }else{
           //var_dump($result->response[0]->id);
           //exit();
           $res->SetError ("Invalied Profile");
           exit();
        }
        
        
    }

    public function postGRNSave($req,$res){
        
        $Transaction=$req->Body(true);
        $user= Auth::Autendicate("profile","postGRNSave",$res);
        
        if(!isset($Transaction->poid)){
            $res->SetError ("PO is not corrrect");
            return;
        }
        $result = SOSSData::Query ("poheader", urlencode("tranNo:".$Transaction->poid.""));
        
        //return $result;
        if(count($result->result)!=0)
        {
            $PO =$result->result[0];
            if($PO->Complete=='Y'){
                $res->SetError ("GRN Already Generated for this PO");
                return;
            }
            $Transaction->preparedByID=$user->userid;
            $Transaction->preparedBy=$user->email;
            $Transaction->Complete="N";
            $Transaction->balance=$Transaction->total;
            
            $result = SOSSData::Insert ("grnheader", $Transaction,$tenantId = null);
            CacheData::clearObjects("grnheader");
            if($result->success){
                $Transaction->tranNo = $result->result->generatedId;
                $ledgertran =new StdClass;
                $ledgertran->profileid=$Transaction->profileId;
                $ledgertran->tranid=$Transaction->tranNo;
                $ledgertran->trantype='GRN';
                $ledgertran->tranDate=$Transaction->tranDate;
                $ledgertran->description='Invoice No Has been generated';
                $ledgertran->amount=-1*$Transaction->total;
                //$result=SOSSData::Insert ("ledger", $ledgertran,$tenantId = null);
                $this->updateLedger($ledgertran);
                if($result->success){
                    
                    $profileservices=array();
                    foreach($Transaction->InvoiceItems as $key=>$value){
                        $Transaction->InvoiceItems[$key]->tranNo=$Transaction->tranNo;
                        $this->updateInventry($value,1);
                        //var_dump($Transaction->InvoiceItems[$key]->tranNo);
                    }
                    $result = SOSSData::Insert ("grndetails", $Transaction->InvoiceItems,$tenantId = null);
                    $PO->Complete='Y';
                    $result=SOSSData::Update ("poheader", $PO,$tenantId = null);
                    CacheData::clearObjects("grndetails");
                }else{
                    $res->SetError ("Erorr");
                    return $result;
                }
                
                return $Transaction;
            }else{
                return $result;
            }
        }else{
           //var_dump($result->response[0]->id);
           //exit();
           $res->SetError ("Invalied PO");
           exit();
        }
        
        
    }

    public function postPaymentSave($req,$res){
        $payment=$req->Body(true);
        $user= Auth::Autendicate("profile","postPaymentSave",$res);
        if(!isset($payment->email)){
            $res->SetError ("provide email");
            return;
        }
        if(!isset($payment->contactno)){
            $res->SetError ("provide contact no");
            return;
        }
        
        //$result = SOSSData::Query ("profile", urlencode("id:".$payment->profileId.""));
        $payment->collectedByID=$user->userid;
        $payment->collectedBy=$user->email;
        
        //return $result;
        
            $Store_profile= Profile::getProfile(0,0);
            if(isset($Store_profile->profile)){
                //return $Store_profile->profile;
                $Store_profile=$Store_profile->profile;
                $payment->supplier_profileId = $Store_profile->id; 
                $payment->supplier_name = $Store_profile->name;
                $payment->supplier_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
                $payment->supplier_address = isset($Store_profile->address)?$Store_profile->address:null;
                $payment->supplier_city = isset($Store_profile->city)?$Store_profile->city:null;
                $payment->supplier_country = isset($Store_profile->country)?$Store_profile->country:null;
                $payment->supplier_email = isset($Store_profile->email)?$Store_profile->email:null;
            }
            try {
                $handler =new Davvag_Order();
                return $handler->SavePayment($payment);
            } catch (\Throwable $th) {
                //throw $th;
            }               
            
       
        
        
    }

    public function postSave($req,$res){
        $profile=$req->Body(true);
        $user= Auth::Autendicate("profile","postSave",$res);
        if(!isset($profile->email)){
            //http_response_code(500);
            $res->SetError ("provide email");
            return null;
            
        }
        if(!isset($profile->contactno)){
            //http_response_code(500);
            $res->SetError ("provide contact no");
            return null;
            
        }
        //var_dump($profile);
        //exit();
        $result = SOSSData::Query ("profile", urlencode("id:".($profile->id==null?0:$profile->id).""));
        
        //return urlencode("id:".$profile->id."");
        if(count($result->result)==0)
        {
            $profile->createdate=date_format(new DateTime(), 'm-d-Y H:i:s');
            $profile->userid=$user->userid;
            $profile->Status="inactive";
            $result = SOSSData::Insert ("profile", $profile,$tenantId = null);
            if($result->success){
                $profile->id=$result->result->generatedId;
                if(isset($profile->attribute)){
                    $profile->attributes->id=$result->result->generatedId;

                    $r = SOSSData::Insert ("profile_attributes", $profile->attributes);
                }
                //CacheData::clearObjects("profile");
                return $profile;
            }else{
                $res->SetError ("Profile Didn't get saved");
                return null;
            }
            
        }else{
            $profile->attributes->id=$profile->id;
            
            $result = SOSSData::Update("profile", $profile);
            if($result->success){
                $result = SOSSData::Delete ("profile_attributes", $profile->attributes);
                $result = SOSSData::Insert ("profile_attributes", $profile->attributes);
                CacheData::clearObjects("profile");
                return $profile;
            }else{
                $res->SetError ("Profile Didn't get Update");
                return null;
            }
            
           
        }
        
        
    }

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

    public function getSearchV1($req,$res){
        $s  =null;
        if(isset($req->Query()->column)){
            $search  =$req->Query()->column."_".$req->Query()->value;
        }
        $result= CacheData::getObjects(md5($search),"profiles_search_1");
        if(!isset($result)){
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->column = $req->Query()->column;
            $mainObj->parameters->value = $req->Query()->value;
            //$mainObj->parameters->search = isset($_GET["q"]) ?  $_GET["q"] : "";
            $result =SOSSData::ExecuteRaw("profiles_search_1",$mainObj);
            //$result = SOSSData::Query ("profile",urlencode($search),$mainObj);
            if($result->success){
                if(isset($result->result)){
                    CacheData::setObjects(md5($search),"profiles_search_1",$result->result);
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