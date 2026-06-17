<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once (PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
class ProfileService{
    //public var $appname="profileapp";
    private function sortConfigItems($items){
        usort($items, function($a, $b){
            $ao = isset($a->sortOrder) ? intval($a->sortOrder) : 0;
            $bo = isset($b->sortOrder) ? intval($b->sortOrder) : 0;
            if($ao === $bo){
                return strcmp(isset($a->name) ? $a->name : "", isset($b->name) ? $b->name : "");
            }
            return $ao < $bo ? -1 : 1;
        });
        return $items;
    }

    private function defaultProfileCatogories(){
        return array("Customer","Vender","Company","Guest","Staff","Student","Student-MDiv","Student-Diploma","Student-BTH","Student-Digree","Student-HCD","Visiting","Church","Pastor");
    }

    private function seedProfileCatogories(){
        $result = SOSSData::Query("profile_catogory", "");
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return;
        }
        $items = array();
        foreach($this->defaultProfileCatogories() as $index => $name){
            $item = new stdClass();
            $item->name = $name;
            $item->code = strtoupper(preg_replace("/[^A-Za-z0-9]+/", "_", $name));
            $item->status = "active";
            $item->isDefault = $index === 0 ? "Y" : "N";
            $item->sortOrder = $index + 1;
            $items[] = $item;
        }
        SOSSData::Insert("profile_catogory", $items);
        CacheData::clearObjects("profile_catogory");
    }

    private function getCurrencyCode(){
        $result = SOSSData::Query("currency_configuration", "");
        if(isset($result->success) && $result->success && isset($result->result)){
            foreach($result->result as $item){
                $active = !isset($item->status) || strtolower($item->status) === "active";
                if($active && isset($item->isBase) && strtoupper($item->isBase) === "Y" && isset($item->code)){
                    return $item->code;
                }
            }
            foreach($result->result as $item){
                $active = !isset($item->status) || strtolower($item->status) === "active";
                if($active && isset($item->code)){
                    return $item->code;
                }
            }
        }
        if(defined("CURRENCY_CODE")){
            return CURRENCY_CODE;
        }
        return null;
    }

    private function seedInvoiceTaxes(){
        $result = SOSSData::Query("tax_master", "");
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return;
        }
        $item = new stdClass();
        $item->code = "NO_TAX";
        $item->name = "No Tax";
        $item->description = "Default zero tax mapping";
        $item->rate = 0;
        $item->taxType = "percentage";
        $item->applyTo = "invoice";
        $item->isDefault = "Y";
        $item->status = "active";
        $item->sortOrder = 1;
        SOSSData::Insert("tax_master", $item);
        CacheData::clearObjects("tax_master");
    }

    private function updateLedger($ledgertran){
        $Transaction=$ledgertran;
        $result=SOSSData::Insert ("ledger", $ledgertran,$tenantId = null);
        $result = SOSSData::Query ("profilestatus", urlencode("profileid:".$Transaction->profileid.""));
        CacheData::clearObjects("profilestatus");
        CacheData::clearObjects("ledger");
        
        if(count($result->result)!=0){
            $status= $result->result[0];
            $status->outstanding+=$Transaction->amount;
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $status->currencycode=$currencyCode;
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
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $status->currencycode=$currencyCode;
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

    private function updateInternalLedger($ledgertran){
        $Transaction=$ledgertran;
        $result=SOSSData::Insert ("internal_ledger", $ledgertran,$tenantId = null);
        $result = SOSSData::Query ("internal_profilestatus", urlencode("profileid:".$Transaction->profileid.""));
        CacheData::clearObjects("internal_profilestatus");
        CacheData::clearObjects("internal_ledger");
        
        if(count($result->result)!=0){
            $status= $result->result[0];
            $status->outstanding+=$Transaction->amount;
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $status->currencycode=$currencyCode;
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
            $result=SOSSData::Update ("internal_profilestatus", $status,$tenantId = null);
        }else{
            $status=new stdClass();
            $status->profileid=$Transaction->profileid;
            $status->outstanding=$Transaction->amount;
            $status->totalInvoicedAmount=0;
            $status->totalPaidAmount=0;
            $status->totalGRNAmount=0;
            $status->totalPaymentAmount=0;
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $status->currencycode=$currencyCode;
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
            $result=SOSSData::Insert ("internal_profilestatus", $status,$tenantId = null);
                    
        }
    }

    public function getSupplierData($req,$res){
        
        $Store_profile= Profile::getProfile(0,0);
        if(isset($Store_profile->profile)){
            return $Store_profile->profile;
        }else{return null;}
    }

    public function getProfileCatogories($req,$res){
        $this->seedProfileCatogories();
        $result = SOSSData::Query("profile_catogory", "");
        if(!$result->success){
            $res->SetError("Unable to load profile catogories.");
            return null;
        }
        $items = array_values(array_filter($result->result, function($item){
            return !isset($item->status) || strtolower($item->status) === "active";
        }));
        return $this->sortConfigItems($items);
    }

    public function getInvoiceTaxes($req,$res){
        $this->seedInvoiceTaxes();
        $result = SOSSData::Query("tax_master", "");
        if(!$result->success){
            return array();
        }
        $items = array_values(array_filter($result->result, function($item){
            $active = !isset($item->status) || strtolower($item->status) === "active";
            $applyTo = isset($item->applyTo) ? strtolower($item->applyTo) : "invoice";
            return $active && ($applyTo === "invoice" || $applyTo === "all");
        }));
        return $this->sortConfigItems($items);
    }

    public function getCurrencyConfig($req,$res){
        $code = $this->getCurrencyCode();
        if($code === null){
            return null;
        }
        $result = SOSSData::Query("currency_configuration", urlencode("code:".$code));
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return $result->result[0];
        }
        $item = new stdClass();
        $item->code = $code;
        return $item;
    }

    private function transactionListConfig($type){
        $type = strtolower(trim($type));
        if($type == "reciept"){
            $type = "receipt";
        }
        if($type == "diposit"){
            $type = "deposit";
        }

        $config = new stdClass();
        switch($type){
            case "invoice":
                $config->type = "invoice";
                $config->store = "orderheader";
                $config->title = "Invoices";
                $config->idField = "invoiceNo";
                $config->dateField = "invoiceDate";
                $config->amountField = "total";
                $config->statusField = "status";
                $config->nameField = "name";
                $config->contactField = "contactno";
                $config->profileColumn = "profileId";
                $config->printRoute = "invoice";
                $config->columns = array("invoiceNo","profileId","name","email","contactno","status","preparedBy","PaymentComplete");
                return $config;
            case "receipt":
                $config->type = "receipt";
                $config->store = "paymentheader";
                $config->title = "Receipts";
                $config->idField = "receiptNo";
                $config->dateField = "receiptDate";
                $config->amountField = "paymentAmount";
                $config->statusField = "status";
                $config->nameField = "name";
                $config->contactField = "contactno";
                $config->profileColumn = "profileId";
                $config->printRoute = "receipt";
                $config->columns = array("receiptNo","profileId","name","email","contactno","paymentType","status","collectedBy");
                return $config;
            case "deposit":
                $config->type = "deposit";
                $config->store = "dipositheader";
                $config->title = "Deposits";
                $config->idField = "TranNo";
                $config->dateField = "invoiceDate";
                $config->amountField = "total";
                $config->statusField = "status";
                $config->nameField = "name";
                $config->contactField = "contactno";
                $config->profileColumn = "profileId";
                $config->printRoute = "deposit";
                $config->columns = array("TranNo","profileId","name","email","contactno","paymenttype","status","preparedBy");
                return $config;
            case "collection":
                $config->type = "collection";
                $config->store = "ledger";
                $config->title = "Collections";
                $config->idField = "tranid";
                $config->dateField = "tranDate";
                $config->amountField = "amount";
                $config->statusField = "trantype";
                $config->nameField = "description";
                $config->contactField = "profileid";
                $config->profileColumn = "profileid";
                $config->printRoute = "";
                $config->columns = array("profileid","tranid","trantype","tranDate","amount");
                return $config;
            default:
                return null;
        }
    }

    private function transactionRecordValue($record,$field){
        if(is_object($record) && isset($record->{$field})){
            return $record->{$field};
        }
        if(is_array($record) && isset($record[$field])){
            return $record[$field];
        }
        return null;
    }

    private function transactionDateScore($value){
        if($value == null || $value === ""){
            return 0;
        }
        $value = trim(strval($value), "\"");
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }

    private function transactionColumnAllowed($column,$columns){
        foreach($columns as $allowedColumn){
            if($allowedColumn === $column){
                return true;
            }
        }
        return false;
    }

    public function postTransactionList($req,$res){
        $body = $req->Body(true);
        if(!is_object($body)){
            $body = new stdClass();
        }

        $type = isset($body->type) ? $body->type : "invoice";
        $config = $this->transactionListConfig($type);
        if($config == null){
            $res->SetError("Invalid transaction list type.");
            return null;
        }

        $searchValue = isset($body->searchValue) ? trim(strval($body->searchValue)) : "";
        $searchColumn = isset($body->searchColumn) ? trim(strval($body->searchColumn)) : $config->idField;
        $profileId = isset($body->profileId) ? trim(strval($body->profileId)) : "";
        $lastVersionId = null;
        if(isset($body->sysversionid) && trim(strval($body->sysversionid)) !== ""){
            $lastVersionId = trim(strval($body->sysversionid));
        }else if(isset($body->lastVersionId) && trim(strval($body->lastVersionId)) !== ""){
            $lastVersionId = trim(strval($body->lastVersionId));
        }
        $limit = isset($body->limit) ? intval($body->limit) : 50;
        if($limit <= 0 || $limit > 500){
            $limit = 50;
        }

        $search = "";
        if($searchValue !== ""){
            if(!$this->transactionColumnAllowed($searchColumn,$config->columns)){
                $res->SetError("Invalid search field.");
                return null;
            }
            $search = $searchColumn.":".$searchValue;
            if($profileId !== "" && $config->profileColumn !== ""){
                $search = $config->profileColumn.":".$profileId.",".$search;
            }
        }else if($profileId !== "" && $config->profileColumn !== ""){
            $search = $config->profileColumn.":".$profileId;
        }

        $result = SOSSData::Query ($config->store, urlencode($search), $lastVersionId);
        if(!$result->success){
            $res->SetError("Unable to load ".$config->title);
            return null;
        }

        $records = array();
        if(isset($result->result) && is_array($result->result)){
            $records = $result->result;
        }

        $idField = $config->idField;
        $dateField = $config->dateField;
        usort($records,function($a,$b) use ($idField,$dateField){
            $aVersion = $this->transactionRecordValue($a,"sysversionid");
            $bVersion = $this->transactionRecordValue($b,"sysversionid");
            if(is_numeric($aVersion) && is_numeric($bVersion)){
                $aVersion = floatval($aVersion);
                $bVersion = floatval($bVersion);
                if($aVersion != $bVersion){
                    return ($aVersion < $bVersion) ? 1 : -1;
                }
            }

            $aId = $this->transactionRecordValue($a,$idField);
            $bId = $this->transactionRecordValue($b,$idField);
            if(is_numeric($aId) && is_numeric($bId)){
                $aId = floatval($aId);
                $bId = floatval($bId);
                if($aId != $bId){
                    return ($aId < $bId) ? 1 : -1;
                }
            }

            $aDate = $this->transactionDateScore($this->transactionRecordValue($a,$dateField));
            $bDate = $this->transactionDateScore($this->transactionRecordValue($b,$dateField));
            if($aDate == $bDate){
                return 0;
            }
            return ($aDate < $bDate) ? 1 : -1;
        });

        $total = count($records);
        $hasMore = $total >= $limit;
        if($total > $limit){
            $records = array_slice($records,0,$limit);
        }

        $nextSysVersionId = null;
        foreach($records as $record){
            $versionId = $this->transactionRecordValue($record,"sysversionid");
            if(is_numeric($versionId)){
                $versionId = floatval($versionId);
                if($nextSysVersionId === null || $versionId < $nextSysVersionId){
                    $nextSysVersionId = $versionId;
                }
            }
        }

        $response = new stdClass();
        $response->config = $config;
        $response->records = $records;
        $response->total = $total;
        $response->search = $search;
        $response->limit = $limit;
        $response->hasMore = $hasMore && $nextSysVersionId !== null;
        $response->nextSysVersionId = $nextSysVersionId;
        $response->sysversionid = $nextSysVersionId;

        return $response;
    }

    public function postDipositSave($req,$res){
        
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
            $Store_profile= Profile::getProfile(empty($Transaction->company_profileId)?0:$Transaction->company_profileId,0);
            if(isset($Store_profile->profile)){
                //return $Store_profile->profile;
                //$dp=Profile::getProfile(empty($Transaction->company_profileId)?0:$Transaction->company_profileId,0);
                $Store_profile= $Store_profile->profile;//$Store_profile->profile;
                $Transaction->company_profileId = $Store_profile->id; 
                $Transaction->company_name = $Store_profile->name;
                $Transaction->company_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
                $Transaction->company_address = isset($Store_profile->address)?$Store_profile->address:null;
                $Transaction->company_city = isset($Store_profile->city)?$Store_profile->city:null;
                $Transaction->company_country = isset($Store_profile->country)?$Store_profile->country:null;
                $Transaction->company_email = isset($Store_profile->email)?$Store_profile->email:null;
            }else{
                $Store_profile= Profile::getProfile(0,0);
                if(isset($Store_profile->profile)){
                    $Store_profile= $Store_profile->profile;//$Store_profile->profile;
                    $Transaction->company_profileId = $Store_profile->id; 
                    $Transaction->company_name = $Store_profile->name;
                    $Transaction->company_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
                    $Transaction->company_address = isset($Store_profile->address)?$Store_profile->address:null;
                    $Transaction->company_city = isset($Store_profile->city)?$Store_profile->city:null;
                    $Transaction->company_country = isset($Store_profile->country)?$Store_profile->country:null;
                    $Transaction->company_email = isset($Store_profile->email)?$Store_profile->email:null;
                }
            }
            $Store_profile= Profile::getUserProfile();
            if(isset($Store_profile->profile)){
                //return $Store_profile->profile;
                $Store_profile= $Store_profile->profile;//$Store_profile->profile;
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
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $Transaction->currencycode=$currencyCode;
            }
            try {
                $handler =new Davvag_Order();
                return $handler->DipostSave($Transaction);
            } catch (\Throwable $th) {
                //throw $th;
                $res->SetError ("Error Saving Profile");
            }
        }else{
           $res->SetError ("Invalied Profile");
           return null;
        }
        
        
    }

    public function getDepositCancelation($req,$res){
        $query=$req->Query();
        $handler =new Davvag_Order();
        try{
            return $handler->DipostCancel($query->id);
        }catch(Exception $ex){
            $res->SetError($ex->getMessage());
            return null;
        }
    }

    public function getReceiptCancelation($req,$res){
        $query=$req->Query();
        if(!isset($query->id)){
            $res->SetError("Invalied Receipt");
            return null;
        }
        $handler =new Davvag_Order();
        try{
            return $handler->ReceiptCancel($query->id);
        }catch(Exception $ex){
            $res->SetError($ex->getMessage());
            return null;
        }
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
            $currencyCode = $this->getCurrencyCode();
            if(isset($currencyCode)){
                $Transaction->currencycode=$currencyCode;
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
                if(isset($currencyCode)){
                    $ledgertran->currencycode=$currencyCode;
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
                    foreach ($Transaction->InvoiceItems as $key => $value) {
                        # code...
                        $value->results = SOSSData::Insert ("orderdetails", $value);
                    }
                    
                    //$Transaction->DetailsError=$result;
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
           $res->SetError ("Invalied Profile");
           return null;
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
            return null;
            
        }
        if(!isset($Transaction->contactno)){
            $res->SetError ("provide contact no");
            return null;
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
           $res->SetError ("Invalied Profile");
           return null;
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
           $res->SetError ("Invalied PO");
           return null;
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
                $res->SetError ("Error saving payment: ".$th->getMessage());
                return null;
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
        if(!isset($profile->attributes) || !is_object($profile->attributes)){
            $profile->attributes = new stdClass();
        }
        $profileId = (isset($profile->id) && $profile->id != null) ? $profile->id : 0;
        $result = SOSSData::Query ("profile", urlencode("id:".$profileId.""));
        
        //return urlencode("id:".$profile->id."");
        if(count($result->result)==0)
        {
            $profile->createdate=date_format(new DateTime(), 'm-d-Y H:i:s');
            $profile->userid=$user->userid;
            $profile->Status="inactive";
            $result = SOSSData::Insert ("profile", $profile,$tenantId = null);
            if($result->success){
                $profile->id=$result->result->generatedId;
                $profile->attributes->id=$profile->id;
                if(count(get_object_vars($profile->attributes)) > 1){
                    $profile->attributes->id=$result->result->generatedId;

                    $r = SOSSData::Insert ("profile_attributes", $profile->attributes);
                }
                CacheData::clearObjects("profile");
                CacheData::clearObjects("profile_attributes");
                return $profile;
            }else{
                $res->SetError ("Profile Didn't get saved");
                return null;
            }
            
        }else{
            $profile->attributes->id=$profile->id;
            
            $result = SOSSData::Update("profile", $profile);
            if($result->success){
                SOSSData::Delete ("profile_attributes", $profile->attributes);
                if(count(get_object_vars($profile->attributes)) > 1){
                    SOSSData::Insert ("profile_attributes", $profile->attributes);
                }
                CacheData::clearObjects("profile");
                CacheData::clearObjects("profile_attributes");
                return $profile;
            }else{
                $res->SetError ("Profile Didn't get Update");
                return null;
            }
            
           
        }
        
        
    }

    public function getSearch($req){
        $query = $req->Query();
        if(isset($query->q)){
            $search  =$query->q;
        }else if(isset($_GET["q"])){
            $search  =$_GET["q"];
        }else{
            return array();
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
        $query = $req->Query();
        if(isset($query->column) && isset($query->value)){
            $search  =$query->column."_".$query->value;
        }else{
            return array();
        }
        $result= CacheData::getObjects(md5($search),"profiles_search_1");
        if(!isset($result)){
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->column = $query->column;
            $mainObj->parameters->value = $query->value;
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
        $query = $req->Query();
        if(isset($query->id)){
            $search  = strval($query->id);
        }else if(isset($_GET["id"])){
            $search  = strval($_GET["id"]);
        }else{
            return new stdClass();
        }
        $profile=new stdClass();
        $result= CacheData::getObjects(md5($search),"profile");
        if(!isset($result)){
            $result = SOSSData::Query ("profile",urlencode("id:".$search));
            if($result->success){
                if(count($result->result)!=0){
                    $profile=$result->result[0];
                    $result = SOSSData::Query ("profile_attributes",urlencode("id:".$search));
                    $profile->attributes=($result->success && count($result->result)!=0)?$result->result[0]:new stdClass();
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
        if(!is_array($sall)){
            return $f;
        }
        foreach($sall as $s){
            if(!isset($s->storename) || !isset($s->search)){
                continue;
            }
            $lastVersionId = null;
            if(isset($s->sysversionid) && trim(strval($s->sysversionid)) !== ""){
                $lastVersionId = trim(strval($s->sysversionid));
            }else if(isset($s->lastVersionId) && trim(strval($s->lastVersionId)) !== ""){
                $lastVersionId = trim(strval($s->lastVersionId));
            }
            $cacheKey = md5($s->search."|".$lastVersionId);
            $useCache = !(isset($s->nocache) && $s->nocache);
            $result = $useCache ? CacheData::getObjects($cacheKey,$s->storename) : null;
            if(!isset($result)){
                $result = SOSSData::Query ($s->storename,urlencode($s->search),$lastVersionId);
                if($result->success){
                    $f->{$s->storename}=$result->result;
                    if($useCache && isset($result->result)){
                        CacheData::setObjects($cacheKey,$s->storename,$result->result);
                    }
                }else{
                    $f->{$s->storename}=null;
                    $f->{$s->storename."_error"}=$result;
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
