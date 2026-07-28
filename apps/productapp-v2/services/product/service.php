<?php
require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once (PLUGIN_PATH . "/phpcache/cache.php");
require_once (PLUGIN_PATH . "/auth/auth.php");
require_once (TENANT_RESOURCE_LOCATION . "/apps/currency-configuration/services/currency-configuration-handler/service.php");
require_once (PLUGIN_PATH_LOCAL . "/davvag-attributes/davvag-attributes.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class ProductService {
    private function applyConfiguredCurrency($product, $res){
        try{
            $currency = new \currency_configuration\CurrencyConfigurationService();
            $code = isset($product->currencycode) ? $product->currencycode : null;
            $product->currencycode = $currency->resolveCurrencyCode($code);
            return true;
        }catch(\Exception $error){
            $res->SetError($error->getMessage());
            return false;
        }
    }
    
    private function saveAttributes($product){
        if(isset($product->attributes)){
            $attributes = $product->attributes;
            //$attributes->itemid=$product->itemid;
            $r=null;
            if(isset($product->attributes->itemid))
                $r=SOSSData::Update ("products_attributes", $attributes,$tenantId = null);
            else{
                $attributes->itemid=$product->itemid;
                $r=SOSSData::Insert ("products_attributes", $attributes,$tenantId = null);
            }
            if($r->success){
                $product->attributes=$attributes;
            }else{
                $product->attributes=null;
            }
            //return $product;

        } 
        if(isset($product->sellsInfo_data)){
            $product->sellsInfo_data->data->itemid=$product->itemid;
            $product->sellsInfo_data=Davvag_Attributes::Save($product->sellsInfo_data);
        }
        return $product;
        

    }

    private function ensureTaxMasterSeed(){
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
        $item->applyTo = "all";
        $item->isDefault = "Y";
        $item->status = "active";
        $item->sortOrder = 1;
        SOSSData::Insert("tax_master", $item);
        CacheData::clearObjects("tax_master");
    }

    private function activeTaxes(){
        $this->ensureTaxMasterSeed();
        $result = SOSSData::Query("tax_master", "");
        if(!$result->success){
            return array();
        }
        $items = array();
        foreach($result->result as $item){
            if(!isset($item->status) || strtolower($item->status) === "active"){
                $items[] = $item;
            }
        }
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

    private function resolveTaxSelection($transaction){
        $tax = null;
        if(isset($transaction->taxid) && intval($transaction->taxid) > 0){
            $result = SOSSData::Query("tax_master", urlencode("id:".intval($transaction->taxid)));
            if($result->success && count($result->result) > 0){
                $tax = $result->result[0];
            }
        }
        if($tax === null && isset($transaction->taxcode) && trim($transaction->taxcode) !== ""){
            $result = SOSSData::Query("tax_master", urlencode("code:".trim($transaction->taxcode)));
            if($result->success && count($result->result) > 0){
                $tax = $result->result[0];
            }
        }
        if($tax === null && (!isset($transaction->taxid) || intval($transaction->taxid) <= 0) && (!isset($transaction->taxcode) || trim($transaction->taxcode) === "")){
            return null;
        }
        if($tax === null){
            $items = $this->activeTaxes();
            foreach($items as $item){
                if(isset($item->isDefault) && strtoupper($item->isDefault) === "Y"){
                    $tax = $item;
                    break;
                }
            }
            if($tax === null && count($items) > 0){
                $tax = $items[0];
            }
        }
        return $tax;
    }

    private function updateSupplierLedger($ledgertran){
        $result = SOSSData::Insert("ledger", $ledgertran, $tenantId = null);
        if(isset($result->success) && !$result->success){
            return $result;
        }

        CacheData::clearObjects("ledger");
        $statusResult = SOSSData::Query("profilestatus", urlencode("profileid:".intval($ledgertran->profileid)));
        CacheData::clearObjects("profilestatus");
        if(isset($statusResult->success) && $statusResult->success && count($statusResult->result) > 0){
            $status = $statusResult->result[0];
            if(!isset($status->outstanding)){
                $status->outstanding = 0;
            }
            $status->outstanding += floatval($ledgertran->amount);
            switch(strtolower(isset($ledgertran->trantype) ? $ledgertran->trantype : "")){
                case "grn":
                case "grn-edit":
                    $status->totalGRNAmount = isset($status->totalGRNAmount) ? $status->totalGRNAmount + floatval($ledgertran->amount) : floatval($ledgertran->amount);
                    break;
                case "payment":
                    $status->totalPaymentAmount = isset($status->totalPaymentAmount) ? $status->totalPaymentAmount + floatval($ledgertran->amount) : floatval($ledgertran->amount);
                    break;
            }
            $statusResult = SOSSData::Update("profilestatus", $status, $tenantId = null);
        }else{
            $status = new stdClass();
            $status->profileid = intval($ledgertran->profileid);
            $status->outstanding = floatval($ledgertran->amount);
            $status->currencycode = isset($ledgertran->currencycode) ? $ledgertran->currencycode : null;
            $status->totalInvoicedAmount = 0;
            $status->totalPaidAmount = 0;
            $status->totalGRNAmount = 0;
            $status->totalPaymentAmount = 0;
            switch(strtolower(isset($ledgertran->trantype) ? $ledgertran->trantype : "")){
                case "grn":
                case "grn-edit":
                    $status->totalGRNAmount = floatval($ledgertran->amount);
                    break;
                case "payment":
                    $status->totalPaymentAmount = floatval($ledgertran->amount);
                    break;
            }
            $statusResult = SOSSData::Insert("profilestatus", $status, $tenantId = null);
        }
        return $statusResult;
    }

    public function getInvoiceTaxes($req,$res){
        return $this->activeTaxes();
    }
    public function postDelete($req,$res){
        $product=$req->Body(true);
        if(isset($product->itemid)){
            $r=SOSSData::Delete("products",$product);
            if($r->success){
                return $product;    
            }else{
                $res->SetError($r);
                return $r;
            }
             
        }else{
            $res->SetError("Error SavingDeleting.");
            return $product;
        }
    }

    public function postSave($req,$res){
        
        $product=$req->Body(true);
        if(!$this->applyConfiguredCurrency($product, $res)){
            return null;
        }
        //return $product;
        //return $product;
        //$user= Auth::Autendicate("product","save",$res);
        $summery =new stdClass();
        $summery->summery=substr(isset($product->caption)?$product->caption:'',0,500);
        $summery->title=$product->name;
        
        //if(isset())
        $summery->imgname=isset($product->imgurl)? $product->imgurl : '';
        //echo "im in"
        $Store_profile= Profile::getProfile(0,0);
        $product->storeid=isset($product->storeid)?$product->storeid:0;
            if($product->storeid==0){
                if($Store_profile->profile){
                    $product->storeid=$Store_profile->profile->id;
                    $product->storename=$Store_profile->profile->name;
                }
            }
        if(!isset($product->itemid)){
            
            
            $result=SOSSData::Insert ("products", $product,$tenantId = null);
            //return $result;
            //var_dump($result);
            if($result->success){
                $product->itemid = $result->result->generatedId;
                $summery->id=$result->result->generatedId;
                $product=$this->saveAttributes($product);
                //$summery->imgname=$result->result->generatedId;
                SOSSData::Insert ("d_all_summery", $summery,$tenantId = null);
                //return $product;
            }else{
                $res->SetError ($result);
                //exit();
                return $res;
            }
        }else{
            
            $result=SOSSData::Update ("products", $product,$tenantId = null);
            $summery->id=$product->itemid;
            if($result->success){
                $product=$this->saveAttributes($product);
                SOSSData::Update ("d_all_summery", $summery,$tenantId = null);
            }else{
                $res->SetError ("Error Saving.");
                //exit();
                return $res;
            }
        }
        CacheData::clearObjects("products");
        CacheData::clearObjects("d_all_summery");
        CacheData::clearObjects("products_attributes");
        if(count(isset($product->RemoveImages)?$product->RemoveImages:[])>0){
            $product->removedStatus=SOSSData::Delete("products_image",$product->RemoveImages);
        }

        foreach((isset($product->Images)?$product->Images:[]) as $key=>$value){
            $product->Images[$key]->articalid=$product->itemid;
            if($product->Images[$key]->id==0){
                $result2=SOSSData::Insert ("products_image", $product->Images[$key],$tenantId = null);
                if($result2->success){
                    $product->Images[$key]->id = $result2->result->generatedId;
                }

            }else{
                $result2=SOSSData::Update ("products_image", $product->Images[$key],$tenantId = null);
            }
            
            //var_dump($invoice->InvoiceItems[$key]->invoiceNo);
        }
        CacheData::clearObjects("products_image");
        return $product;
        
    }

    function postDeleteProduct($req,$res){
        $product=$req->Body(true);
        $product->removedStatus=SOSSData::Delete("products",$product);
        CacheData::clearObjects("products");
        CacheData::clearObjects("d_all_summery");
        CacheData::clearObjects("products_attributes");
    }

    function getproductid($req){
        //echo "imain";
        $data =null;
        if(isset($_GET["q"])){
            $result= CacheData::getObjects_fullcache(md5("id:".$_GET["q"]),"d_all_summery");
            if(!isset($result)){
                $result = SOSSData::Query ("d_all_summery",urlencode("id:".$_GET["q"]));
                if($result->success){
                    //$f->{$s->storename}=$result->result;
                    if(isset($result->result[0])){
                        $data= $result->result[0];
                        CacheData::setObjects(md5("id:".$_GET["q"]),"d_all_summery",$result->result);
                    }
                }
            }else{
                $data= $result[0];
            }
            //$result = SOSSData::Query ("d_cms_artical_v1",urlencode("id:".$_GET["q"]));
            //var_dump($result);
            //echo "imain";
            if(isset($data)){
                
                
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8" />
                    <meta name="description" content="'.urldecode($data->summery).'">
                    <meta name="tags" content="'.urldecode($data->tags).'">
                    <meta name="og:title" content="'.urldecode($data->title).'">
                    <meta name="og:description" content="'.urldecode($data->summery).'">
                    <meta name="og:tags"  content="'.urldecode($data->tags).'">
                    <meta name="og:image"  content="http://'.$_SERVER["HTTP_HOST"].'/components/davvag-cms-davvag/soss-uploader/service/get/d_cms_artical/'.$_GET["q"]."-".$data->imgname.'">
                    <title>'.urldecode($data->title).'</title>
                    
                </head>
                <body>
                    loading.....
                    <script type="text/javascript">
                        setTimeout(function(){ window.location = "/#/app/davvag-cms-generalapps/a?id='.$_GET["q"].'"; }, 1000);
                        
                    </script>    
                </body>
                </html>';
                exit();      

            }
        }
    }
    
    public function getAllProducts($req){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
            $mainObj->parameters->search = isset($_GET["q"]) ?  $_GET["q"] : "";
            $resultObj = SOSSData::ExecuteRaw("nearproducts", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function postInventoryDashboard($req,$res){
        $body=$req->Body(true);
        $productsResult=SOSSData::Query("products", "");
        $stockResult=SOSSData::Query("product_inventrymaster", "");
        $poResult=SOSSData::Query("poheader", "", null, "desc", 50, 0);
        $poDetailsResult=SOSSData::Query("podetails", "");
        $grnResult=SOSSData::Query("grnheader", "", null, "desc", 50, 0);
        $historyResult=SOSSData::Query("inventoryhistory", "", null, "desc", 50, 0);
        $barcodeUnitResult=SOSSData::Query("product_barcode_units", "", null, "desc", 50, 0);

        $stockByProduct=array();
        if($stockResult->success){
            foreach($stockResult->result as $stock){
                $stockByProduct[(string)$stock->itemid]=$stock;
            }
        }
        $poDetailsByTran=array();
        if($poDetailsResult->success){
            foreach($poDetailsResult->result as $detail){
                $tranKey=(string)$detail->tranNo;
                if(!isset($poDetailsByTran[$tranKey])){
                    $poDetailsByTran[$tranKey]=array();
                }
                array_push($poDetailsByTran[$tranKey], $detail);
            }
        }

        $dashboard=new stdClass();
        $dashboard->products=array();
        $dashboard->lowStock=array();
        $dashboard->openPurchaseOrders=array();
        $dashboard->recentGrns=array();
        $dashboard->recentMovements=$historyResult->success ? $historyResult->result : array();
        $dashboard->barcodeUnits=$barcodeUnitResult->success ? $barcodeUnitResult->result : array();
        $dashboard->totals=new stdClass();
        $dashboard->totals->inventoryItems=0;
        $dashboard->totals->lowStock=0;
        $dashboard->totals->stockValue=0;
        $dashboard->totals->openPOs=0;

        if($productsResult->success){
            foreach($productsResult->result as $product){
                if(!$this->isInventoryProduct($product)){
                    continue;
                }
                $dashboard->totals->inventoryItems++;
                $stockQty=isset($product->qty) ? floatval($product->qty) : 0;
                if(isset($stockByProduct[(string)$product->itemid])){
                    $stockQty=floatval($stockByProduct[(string)$product->itemid]->qty);
                }
                $product->qty=$stockQty;
                $product->stockQty=$stockQty;
                $product->barcode=isset($product->barcode) ? $product->barcode : "";
                $product->reorder_qty=isset($product->reorder_qty) ? floatval($product->reorder_qty) : 0;
                $dashboard->totals->stockValue += $stockQty * floatval(isset($product->cost) ? $product->cost : 0);
                array_push($dashboard->products, $product);
                if($product->reorder_qty > 0 && $stockQty <= $product->reorder_qty){
                    array_push($dashboard->lowStock, $product);
                }
            }
        }

        if($poResult->success){
            foreach($poResult->result as $po){
                $status = isset($po->status) ? strtolower(trim($po->status)) : "";
                if((!isset($po->Complete) || strtoupper($po->Complete) !== "Y") && !$this->isCancelledStatus($status)){
                    $po->InvoiceItems=isset($poDetailsByTran[(string)$po->tranNo]) ? $poDetailsByTran[(string)$po->tranNo] : array();
                    $po=$this->attachUomContextToDocument($po);
                    array_push($dashboard->openPurchaseOrders, $po);
                }
            }
        }
        if($grnResult->success){
            $dashboard->recentGrns=$grnResult->result;
        }

        $dashboard->totals->lowStock=count($dashboard->lowStock);
        $dashboard->totals->openPOs=count($dashboard->openPurchaseOrders);
        return $dashboard;
    }

    public function postProfileLookup($req,$res){
        $body=$req->Body(true);
        $column=isset($body->column) && $body->column !== "" ? $body->column : "name";
        $value=isset($body->value) ? trim($body->value) : "";
        if($value === ""){
            return array();
        }
        $safeColumn=$this->safeSearchColumn($column, array("id","name","email","contactno","organization","catogory","id_number","passportno"));
        $result=SOSSData::Query("profile", urlencode($safeColumn.":".$value), null, "asc", 25, 0);
        return $result->success ? $result->result : array();
    }

    public function postProductLookup($req,$res){
        $body=$req->Body(true);
        $value=isset($body->value) ? trim($body->value) : "";
        $column=isset($body->column) ? $body->column : "all";
        if($value === ""){
            return array();
        }

        $products=SOSSData::Query("products", "");
        $unitProductIds=array();
        $barcodeUnitResult=null;
        if($column === "barcode" || $column === "all"){
            $barcodeUnitResult=SOSSData::Query("product_barcode_units", urlencode("barcode:".$value));
            if($barcodeUnitResult->success){
                foreach($barcodeUnitResult->result as $unit){
                    if(isset($unit->itemid)){
                        $unitProductIds[(string)$unit->itemid]=true;
                    }
                }
            }
        }
        if(!$products->success){
            return array();
        }

        $matches=array();
        $needle=strtolower($value);
        foreach($products->result as $product){
            if(!$this->isInventoryProduct($product)){
                continue;
            }
            if(isset($unitProductIds[(string)$product->itemid]) || $this->productMatches($product, $column, $needle)){
                array_push($matches, $product);
            }
            if(count($matches) >= 25){
                break;
            }
        }
        return $matches;
    }

    public function postSavePurchaseOrder($req,$res){
        $transaction=$req->Body(true);
        $user= Auth::Autendicate("product","SavePurchaseOrder",$res);
        if(!isset($transaction->profileId) || intval($transaction->profileId) <= 0){
            $res->SetError("Select a supplier profile.");
            return null;
        }
        if(!isset($transaction->InvoiceItems) || count($transaction->InvoiceItems) === 0){
            $res->SetError("Add at least one product.");
            return null;
        }

        $supplier=SOSSData::Query("profile", urlencode("id:".$transaction->profileId));
        if(!$supplier->success || count($supplier->result) === 0){
            $res->SetError("Supplier profile was not found.");
            return null;
        }

        $transaction=$this->prepareCommercialHeader($transaction, $supplier->result[0], $user);
        $transaction->Complete="N";
        $transaction->balance=$transaction->total;
        $result=SOSSData::Insert("poheader", $transaction);
        if(!$result->success){
            $res->SetError($result);
            return null;
        }

        $transaction->tranNo=$result->result->generatedId;
        foreach($transaction->InvoiceItems as $key=>$value){
            $transaction->InvoiceItems[$key]->tranNo=$transaction->tranNo;
            $transaction->InvoiceItems[$key]->total=$this->lineTotal($value);
        }
        $detailsResult=SOSSData::Insert("podetails", $transaction->InvoiceItems);
        if(!$detailsResult->success){
            $res->SetError($detailsResult);
            return null;
        }

        CacheData::clearObjects("poheader");
        CacheData::clearObjects("podetails");
        return $transaction;
    }

    public function postReceiveGoods($req,$res){
        $transaction=$req->Body(true);
        $user= Auth::Autendicate("product","ReceiveGoods",$res);
        if(!isset($transaction->InvoiceItems) || count($transaction->InvoiceItems) === 0){
            $res->SetError("Add at least one received product.");
            return null;
        }
        if(isset($transaction->poid) && intval($transaction->poid) > 0){
            $poResult=SOSSData::Query("poheader", urlencode("tranNo:".$transaction->poid));
            if(!$poResult->success || count($poResult->result) === 0){
                $res->SetError("Purchase order was not found.");
                return null;
            }
            $po=$poResult->result[0];
            if(isset($po->Complete) && strtoupper($po->Complete) === "Y"){
                $res->SetError("Goods are already received for this purchase order.");
                return null;
            }
        }

        $supplier=null;
        if(isset($transaction->profileId) && intval($transaction->profileId) > 0){
            $supplierResult=SOSSData::Query("profile", urlencode("id:".$transaction->profileId));
            if($supplierResult->success && count($supplierResult->result) > 0){
                $supplier=$supplierResult->result[0];
            }
        }
        $transaction=$this->prepareCommercialHeader($transaction, $supplier, $user);
        $transaction->Complete="Y";
        $transaction->balance=$transaction->total;
        $result=SOSSData::Insert("grnheader", $transaction);
        if(!$result->success){
            $res->SetError($result);
            return null;
        }

        $transaction->tranNo=$result->result->generatedId;
        foreach($transaction->InvoiceItems as $key=>$value){
            $transaction->InvoiceItems[$key]->tranNo=$transaction->tranNo;
            $transaction->InvoiceItems[$key]->total=$this->lineTotal($value);
            $this->moveStock($transaction->InvoiceItems[$key], floatval($transaction->InvoiceItems[$key]->qty), "GRN", $transaction->tranNo, isset($transaction->profileId) ? $transaction->profileId : 0);
            $this->saveReceivedBarcodes($transaction->InvoiceItems[$key], $transaction);
            unset($transaction->InvoiceItems[$key]->barcodes);
            unset($transaction->InvoiceItems[$key]->barcodeInput);
        }
        $detailsResult=SOSSData::Insert("grndetails", $transaction->InvoiceItems);
        if(!$detailsResult->success){
            $res->SetError($detailsResult);
            return null;
        }

        if(isset($transaction->profileId) && intval($transaction->profileId) > 0){
            $ledgertran = new stdClass();
            $ledgertran->profileid = intval($transaction->profileId);
            $ledgertran->tranid = intval($transaction->tranNo);
            $ledgertran->trantype = "grn";
            $ledgertran->tranDate = isset($transaction->tranDate) ? $transaction->tranDate : date("Y-m-d H:i:s");
            $ledgertran->description = "Goods received note #".$transaction->tranNo;
            $ledgertran->amount = -1 * floatval($transaction->total);
            $this->updateSupplierLedger($ledgertran);
        }

        if(isset($po)){
            $po->Complete="Y";
            SOSSData::Update("poheader", $po);
        }

        CacheData::clearObjects("grnheader");
        CacheData::clearObjects("grndetails");
        CacheData::clearObjects("poheader");
        return $transaction;
    }

    public function postIssueGoods($req,$res){
        $body=$req->Body(true);
        $barcode=isset($body->barcode) ? trim($body->barcode) : "";
        if($barcode === ""){
            $res->SetError("Scan or enter a barcode to issue.");
            return null;
        }

        $unitResult=SOSSData::Query("product_barcode_units", urlencode("barcode:".$barcode));
        if(!$unitResult->success || count($unitResult->result) === 0){
            $res->SetError("Barcode was not found in received stock.");
            return null;
        }

        $unit=$unitResult->result[0];
        if(isset($unit->status) && strtoupper($unit->status) === "ISSUED"){
            $res->SetError("This barcode is already issued.");
            return null;
        }

        $line=new stdClass();
        $line->itemid=$unit->itemid;
        $line->name=isset($unit->name) ? $unit->name : "";
        $line->uom=isset($unit->uom) ? $unit->uom : "";
        $line->qty=isset($unit->receivedBaseQty) && floatval($unit->receivedBaseQty) > 0 ? floatval($unit->receivedBaseQty) : 1;
        $unit->status="Issued";
        $unit->issuedDate=date("Y-m-d H:i:s");
        $unit->issuedRef=isset($body->issuedRef) ? $body->issuedRef : "";
        $unit->issuedTo=isset($body->issuedTo) ? $body->issuedTo : "";
        $unit->remarks=isset($body->remarks) ? $body->remarks : "";

        $updateResult=SOSSData::Update("product_barcode_units", $unit);
        if(!$updateResult->success){
            $res->SetError($updateResult);
            return null;
        }

        $this->moveStock($line, -1, "ISSUE", 0, 0);
        CacheData::clearObjects("product_barcode_units");
        return $unit;
    }

    public function postDocumentList($req,$res){
        $body=$req->Body(true);
        $type=isset($body->type) ? strtolower(trim($body->type)) : "po";
        $stores=$this->documentStores($type);
        $headerStore=$stores["header"];
        $detailStore=$stores["detail"];
        $idField="tranNo";
        $limit=isset($body->limit) ? intval($body->limit) : 100;
        if($limit <= 0 || $limit > 500){
            $limit=100;
        }

        $headersResult=SOSSData::Query($headerStore, "", null, "desc", $limit, 0);
        $detailsResult=SOSSData::Query($detailStore, "");
        $detailsByTran=array();
        if($detailsResult->success){
            foreach($detailsResult->result as $detail){
                $key=isset($detail->{$idField}) ? (string)$detail->{$idField} : "";
                if($key === ""){
                    continue;
                }
                if(!isset($detailsByTran[$key])){
                    $detailsByTran[$key]=array();
                }
                array_push($detailsByTran[$key], $detail);
            }
        }

        $records=array();
        if($headersResult->success){
            foreach($headersResult->result as $header){
                $key=isset($header->{$idField}) ? (string)$header->{$idField} : "";
                $header->InvoiceItems=isset($detailsByTran[$key]) ? $detailsByTran[$key] : array();
                array_push($records, $header);
            }
        }
        return $records;
    }

    public function postDocumentDetails($req,$res){
        $body=$req->Body(true);
        $type=isset($body->type) ? strtolower(trim($body->type)) : "po";
        $tranNo=isset($body->tranNo) ? intval($body->tranNo) : (isset($body->tid) ? intval($body->tid) : 0);
        if($tranNo <= 0){
            $res->SetError("Document number is required.");
            return null;
        }

        $document=$this->loadDocument($type, $tranNo);
        if($document === null){
            $res->SetError("Document was not found.");
            return null;
        }
        return $document;
    }

    public function postCancelDocument($req,$res){
        $body=$req->Body(true);
        $type=isset($body->type) ? strtolower(trim($body->type)) : "po";
        $tranNo=isset($body->tranNo) ? intval($body->tranNo) : (isset($body->tid) ? intval($body->tid) : 0);
        if($tranNo <= 0){
            $res->SetError("Document number is required.");
            return null;
        }

        $stores=$this->documentStores($type);
        $document=$this->loadDocument($type, $tranNo);
        if($document === null){
            $res->SetError("Document was not found.");
            return null;
        }
        if(isset($document->status) && $this->isCancelledStatus($document->status)){
            return $document;
        }

        if($type === "grn"){
            if(isset($document->InvoiceItems) && is_array($document->InvoiceItems)){
                foreach($document->InvoiceItems as $line){
                    $qty=isset($line->qty) ? floatval($line->qty) : 0;
                    if(abs($qty) > 0.0001){
                        $this->moveStock($line, -1 * $qty, "GRN_CANCEL", $document->tranNo, isset($document->profileId) ? $document->profileId : 0);
                    }
                }
            }

            if(isset($document->profileId) && intval($document->profileId) > 0){
                $ledgertran = new stdClass();
                $ledgertran->profileid = intval($document->profileId);
                $ledgertran->tranid = intval($document->tranNo);
                $ledgertran->trantype = "grn-cancel";
                $ledgertran->tranDate = isset($document->tranDate) ? $document->tranDate : date("Y-m-d H:i:s");
                $ledgertran->description = "Goods receive note cancelled #".$document->tranNo;
                $ledgertran->amount = floatval($document->total);
                $this->updateSupplierLedger($ledgertran);
            }

            $barcodeUnits = SOSSData::Query("product_barcode_units", urlencode("grnNo:".$document->tranNo));
            if($barcodeUnits->success && count($barcodeUnits->result) > 0){
                foreach($barcodeUnits->result as $unit){
                    if(isset($unit->status) && strtolower($unit->status) === "issued"){
                        continue;
                    }
                    $unit->status = "Cancelled";
                    SOSSData::Update("product_barcode_units", $unit);
                }
                CacheData::clearObjects("product_barcode_units");
            }
        }

        $document->status = isset($body->status) && trim($body->status) !== "" ? trim($body->status) : "Cancelled";
        $document->Complete = "N";
        $headerForSave = clone $document;
        unset($headerForSave->InvoiceItems);
        $updateResult = SOSSData::Update($stores["header"], $headerForSave);
        if(!$updateResult->success){
            $res->SetError($updateResult);
            return null;
        }

        CacheData::clearObjects($stores["header"]);
        CacheData::clearObjects($stores["detail"]);
        return $document;
    }

    public function postSaveDocument($req,$res){
        $body=$req->Body(true);
        $type=isset($body->type) ? strtolower(trim($body->type)) : "po";
        $document=isset($body->document) ? $body->document : $body;
        $stores=$this->documentStores($type);
        $user= Auth::Autendicate("product","SaveDocument",$res);

        if(!isset($document->tranNo) || intval($document->tranNo) <= 0){
            $res->SetError("Document number is required.");
            return null;
        }
        if($type === "po" && (!isset($document->profileId) || intval($document->profileId) <= 0)){
            $res->SetError("Select a supplier profile.");
            return null;
        }
        if(!isset($document->InvoiceItems) || count($document->InvoiceItems) === 0){
            $res->SetError("Add at least one product.");
            return null;
        }

        $oldDocument=$this->loadDocument($type, intval($document->tranNo));
        if($oldDocument === null){
            $res->SetError("Document was not found.");
            return null;
        }

        $supplier=null;
        if(isset($document->profileId) && intval($document->profileId) > 0){
            $supplierResult=SOSSData::Query("profile", urlencode("id:".$document->profileId));
            if($supplierResult->success && count($supplierResult->result) > 0){
                $supplier=$supplierResult->result[0];
            }else if($type === "po"){
                $res->SetError("Supplier profile was not found.");
                return null;
            }
        }

        $document=$this->prepareCommercialHeader($document, $supplier, $user);
        $document->tranNo=intval($oldDocument->tranNo);
        $document->balance=$document->total;
        $document->Complete=isset($document->Complete) && $document->Complete !== "" ? strtoupper($document->Complete) : ($type === "grn" ? "Y" : "N");

        if($type === "grn"){
            $this->applyGrnStockDelta($oldDocument->InvoiceItems, $document->InvoiceItems, $document->tranNo, isset($document->profileId) ? $document->profileId : 0);
        }

        $headerForSave=clone $document;
        unset($headerForSave->InvoiceItems);
        $updateResult=SOSSData::Update($stores["header"], $headerForSave);
        if(!$updateResult->success){
            $res->SetError($updateResult);
            return null;
        }

        $detailResult=$this->replaceDocumentDetails($stores["detail"], $document->tranNo, $document->InvoiceItems);
        if(!$detailResult->success){
            $res->SetError($detailResult);
            return null;
        }

        if($type === "grn" && isset($document->profileId) && intval($document->profileId) > 0){
            $ledgertran = new stdClass();
            $ledgertran->profileid = intval($document->profileId);
            $ledgertran->tranid = intval($document->tranNo);
            $ledgertran->trantype = "grn-edit";
            $ledgertran->tranDate = isset($document->tranDate) ? $document->tranDate : date("Y-m-d H:i:s");
            $ledgertran->description = "Goods received note edit #".$document->tranNo;
            $ledgertran->amount = floatval($oldDocument->total) - floatval($document->total);
            $this->updateSupplierLedger($ledgertran);
        }

        CacheData::clearObjects($stores["header"]);
        CacheData::clearObjects($stores["detail"]);
        if($type === "grn"){
            CacheData::clearObjects("product_inventrymaster");
            CacheData::clearObjects("product_inventryhistory");
            CacheData::clearObjects("inventoryhistory");
            CacheData::clearObjects("products");
        }
        return $document;
    }

    public function postSaveSupplierPayment($req,$res){
        $payment = $req->Body(true);
        $user = Auth::Autendicate("product","SaveSupplierPayment",$res);
        if(!isset($payment->profileId) || intval($payment->profileId) <= 0){
            $res->SetError("Select a supplier profile.");
            return null;
        }
        $amount = isset($payment->paymentAmount) ? floatval($payment->paymentAmount) : (isset($payment->amount) ? floatval($payment->amount) : 0);
        if($amount <= 0){
            $res->SetError("Payment amount must be greater than zero.");
            return null;
        }

        $supplierResult = SOSSData::Query("profile", urlencode("id:".intval($payment->profileId)));
        if(!$supplierResult->success || count($supplierResult->result) === 0){
            $res->SetError("Supplier profile was not found.");
            return null;
        }
        $supplier = $supplierResult->result[0];

        $payment->receiptDate = isset($payment->receiptDate) && $payment->receiptDate !== "" ? $payment->receiptDate : date("Y-m-d H:i:s");
        $payment->paymentType = isset($payment->paymentType) && $payment->paymentType !== "" ? $payment->paymentType : "supplier-payment";
        $payment->status = isset($payment->status) && $payment->status !== "" ? $payment->status : "new";
        $payment->advanceAmount = isset($payment->advanceAmount) ? floatval($payment->advanceAmount) : 0;
        $payment->advanceUtilized = isset($payment->advanceUtilized) ? floatval($payment->advanceUtilized) : 0;
        $payment->paymentAmount = $amount;
        $payment->outstandingAmount = isset($payment->outstandingAmount) ? floatval($payment->outstandingAmount) : 0;
        $payment->balanceAmount = isset($payment->balanceAmount) ? floatval($payment->balanceAmount) : 0;
        $payment->detailsString = isset($payment->detailsString) && $payment->detailsString !== "" ? $payment->detailsString : json_encode(array());
        $payment->supplier_profileId = isset($supplier->id) ? $supplier->id : $payment->profileId;
        $payment->supplier_name = isset($supplier->name) ? $supplier->name : "";
        $payment->supplier_email = isset($supplier->email) ? $supplier->email : "";
        $payment->supplier_city = isset($supplier->city) ? $supplier->city : "";
        $payment->supplier_address = isset($supplier->address) ? $supplier->address : "";
        $payment->supplier_country = isset($supplier->country) ? $supplier->country : "";
        $payment->collectedByID = isset($user->userid) ? $user->userid : "";
        $payment->collectedBy = isset($user->email) ? $user->email : "";

        $result = SOSSData::Insert("paymentheader", $payment);
        if(!$result->success){
            $res->SetError($result);
            return null;
        }

        $payment->receiptNo = $result->result->generatedId;
        $detail = new stdClass();
        $detail->receiptNo = $payment->receiptNo;
        $detail->transactionid = isset($payment->source_id) && intval($payment->source_id) > 0 ? intval($payment->source_id) : intval($payment->profileId);
        $detail->tranType = "payment";
        $detail->description = isset($payment->remarks) && trim($payment->remarks) !== "" ? $payment->remarks : "Supplier payment";
        $detail->DueAmount = $amount;
        $detail->PaidAmout = $amount;
        $detail->Balance = 0;
        $detailsResult = SOSSData::Insert("paymentdetails", $detail);
        if(!$detailsResult->success){
            $res->SetError($detailsResult);
            return null;
        }

        $ledgertran = new stdClass();
        $ledgertran->profileid = intval($payment->profileId);
        $ledgertran->tranid = intval($payment->receiptNo);
        $ledgertran->trantype = "payment";
        $ledgertran->tranDate = $payment->receiptDate;
        $ledgertran->description = "Supplier payment #".$payment->receiptNo;
        $ledgertran->amount = -1 * $amount;
        $this->updateSupplierLedger($ledgertran);

        CacheData::clearObjects("paymentheader");
        CacheData::clearObjects("paymentdetails");
        return $payment;
    }

    public function postStockAdjustment($req,$res){
        $body=$req->Body(true);
        if(!isset($body->itemid) || intval($body->itemid) <= 0){
            $res->SetError("Select a product to adjust.");
            return null;
        }
        $qty=isset($body->qty) ? floatval($body->qty) : 0;
        if($qty == 0){
            $res->SetError("Adjustment quantity cannot be zero.");
            return null;
        }

        $productResult=SOSSData::Query("products", urlencode("itemid:".$body->itemid));
        if(!$productResult->success || count($productResult->result) === 0){
            $res->SetError("Product was not found.");
            return null;
        }
        $product=$productResult->result[0];
        $line=new stdClass();
        $line->itemid=$product->itemid;
        $line->name=$product->name;
        $line->uom=isset($product->uom) ? $product->uom : "";
        $line->qty=abs($qty);
        $movementType=$qty > 0 ? "ADJUSTMENT_IN" : "ADJUSTMENT_OUT";
        $this->moveStock($line, $qty, $movementType, 0, 0);
        $body->product=$product;
        $body->movementType=$movementType;
        $body->adjustedAt=date("Y-m-d H:i:s");
        return $body;
    }

    public function postProductToStore($req){
        //MAIN_STORE_DOMAIN
        $product=$req->Body(true);
        $product->tname=HOST_NAME;
        $product->tenant=HOST_NAME;
        $product->itemid=null;
        $data =Auth::CrossDomainAPICall(MAIN_STORE_DOMAIN,"/components/raha/product-handler/service/ProductToStore","POST",$product);
        if($data->success){
            $Transaction=$data->result;
            $result = SOSSData::Query ("product_published", urlencode("itemid:".$Transaction->itemid.""));
            if(count($result->result)!=0){
                //$Transaction->itemid=$result->result[0]->itemid;
                $result=SOSSData::Update ("product_published", $Transaction,$tenantId = null);
                if($result->success){
                    return $Transaction;
                }else{
                    return null;
                }
            }else{
                $result = SOSSData::Insert ("product_published", $Transaction,$tenantId = null);
                if($result->success){
                    //$Transaction->itemid=$result->result->generatedId;
                    return $Transaction;
                }else{
                    return null;
                }
            }
            return $data->result;
        }else{
            return null;
        }

    }

    
    private function isInventoryProduct($product){
        $type=strtolower(trim(isset($product->invType) ? $product->invType : ""));
        return $type === "inventory" || $type === "inventry";
    }

    private function safeSearchColumn($column,$allowed){
        foreach($allowed as $item){
            if($item === $column){
                return $column;
            }
        }
        return $allowed[0];
    }

    private function productMatches($product,$column,$needle){
        $fields=$column === "barcode"
            ? array("barcode")
            : ($column === "itemid" ? array("itemid") : array("itemid","barcode","name","catogory","uom","keywords"));
        foreach($fields as $field){
            if(isset($product->{$field}) && strpos(strtolower((string)$product->{$field}), $needle) !== false){
                return true;
            }
        }
        return false;
    }

    private function loadUomCatalog(){
        static $catalog=null;
        if($catalog !== null){
            return $catalog;
        }
        $catalog=array();
        $result=SOSSData::Query("uom", "");
        if($result->success && isset($result->result)){
            $catalog=$result->result;
        }
        return $catalog;
    }

    private function isConversionUom($record){
        return (isset($record->recordtype) && strtolower(trim($record->recordtype)) === "conversion")
            || (isset($record->fromSymbol) && isset($record->toSymbol) && trim((string)$record->fromSymbol) !== "" && trim((string)$record->toSymbol) !== "");
    }

    private function getProductBaseUom($itemid){
        $productResult=SOSSData::Query("products", urlencode("itemid:".intval($itemid)));
        if(!$productResult->success || count($productResult->result) === 0){
            return "";
        }
        $product=$productResult->result[0];
        return isset($product->uom) ? trim((string)$product->uom) : "";
    }

    private function buildUomOptions($baseUom){
        $options=array();
        $baseUom=trim((string)$baseUom);
        if($baseUom === ""){
            return $options;
        }

        $catalog=$this->loadUomCatalog();
        $unitNames=array();
        foreach($catalog as $record){
            if($this->isConversionUom($record)){
                continue;
            }
            $symbol=isset($record->symbol) ? trim((string)$record->symbol) : "";
            if($symbol === ""){
                continue;
            }
            $unitNames[strtolower($symbol)]=isset($record->name) && trim((string)$record->name) !== "" ? trim((string)$record->name) : $symbol;
        }

        $seen=array();
        $options[]=array(
            "symbol"=>$baseUom,
            "name"=>isset($unitNames[strtolower($baseUom)]) ? $unitNames[strtolower($baseUom)] : $baseUom,
            "factorToBase"=>1
        );
        $seen[strtolower($baseUom)]=true;

        foreach($catalog as $record){
            if(!$this->isConversionUom($record)){
                continue;
            }
            if(isset($record->status) && strtolower(trim((string)$record->status)) === "inactive"){
                continue;
            }

            $fromSymbol=isset($record->fromSymbol) ? trim((string)$record->fromSymbol) : "";
            $toSymbol=isset($record->toSymbol) ? trim((string)$record->toSymbol) : "";
            $fromQty=isset($record->fromQty) ? floatval($record->fromQty) : 0;
            $toQty=isset($record->toQty) ? floatval($record->toQty) : 0;
            if($fromQty <= 0 || $toQty <= 0){
                continue;
            }

            if(strtolower($fromSymbol) === strtolower($baseUom) && $toSymbol !== ""){
                $key=strtolower($toSymbol);
                if(!isset($seen[$key])){
                    $options[]=array(
                        "symbol"=>$toSymbol,
                        "name"=>isset($unitNames[$key]) ? $unitNames[$key] : $toSymbol,
                        "factorToBase"=>$fromQty / $toQty
                    );
                    $seen[$key]=true;
                }
            }

            if(strtolower($toSymbol) === strtolower($baseUom) && $fromSymbol !== ""){
                $key=strtolower($fromSymbol);
                if(!isset($seen[$key])){
                    $options[]=array(
                        "symbol"=>$fromSymbol,
                        "name"=>isset($unitNames[$key]) ? $unitNames[$key] : $fromSymbol,
                        "factorToBase"=>$toQty / $fromQty
                    );
                    $seen[$key]=true;
                }
            }
        }

        return $options;
    }

    private function resolveLineBaseQuantity($line){
        $qty=isset($line->qty) ? floatval($line->qty) : 0;
        if(!isset($line->itemid) || intval($line->itemid) <= 0){
            return $qty;
        }
        $baseUom=$this->getProductBaseUom($line->itemid);
        if($baseUom === ""){
            return $qty;
        }
        $selectedUom=isset($line->uom) ? trim((string)$line->uom) : "";
        if($selectedUom === "" || strcasecmp($selectedUom, $baseUom) === 0){
            return $qty;
        }

        $options=$this->buildUomOptions($baseUom);
        foreach($options as $option){
            if(isset($option["symbol"]) && strcasecmp($option["symbol"], $selectedUom) === 0){
                $factor=isset($option["factorToBase"]) ? floatval($option["factorToBase"]) : 1;
                return round($qty * $factor, 6);
            }
        }
        return $qty;
    }

    private function attachUomContextToLine($line){
        if(!isset($line->itemid) || intval($line->itemid) <= 0){
            return $line;
        }
        $baseUom=$this->getProductBaseUom($line->itemid);
        $line->baseUom=$baseUom;
        if(!isset($line->uom) || trim((string)$line->uom) === ""){
            $line->uom=$baseUom;
        }
        $line->baseQty=$this->resolveLineBaseQuantity($line);
        $line->uomOptions=$this->buildUomOptions($baseUom);
        $line->uomEditable=count($line->uomOptions) > 1 ? "Y" : "N";
        return $line;
    }

    private function attachUomContextToDocument($document){
        if(!isset($document->InvoiceItems) || !is_array($document->InvoiceItems)){
            $document->InvoiceItems=array();
            return $document;
        }
        foreach($document->InvoiceItems as $key=>$line){
            $document->InvoiceItems[$key]=$this->attachUomContextToLine($line);
        }
        return $document;
    }

    private function normalizeDocumentLines($document){
        if(!isset($document->InvoiceItems) || !is_array($document->InvoiceItems)){
            $document->InvoiceItems=array();
            return $document;
        }
        foreach($document->InvoiceItems as $key=>$line){
            $document->InvoiceItems[$key]=$this->prepareDocumentLine($line);
        }
        return $document;
    }

    private function prepareDocumentLine($line){
        if(!isset($line->itemid) || intval($line->itemid) <= 0){
            return $line;
        }
        $line->qty=isset($line->qty) ? floatval($line->qty) : 0;
        $line->price=isset($line->price) ? floatval($line->price) : 0;
        $line->uom=isset($line->uom) ? trim((string)$line->uom) : "";
        $line->baseUom=$this->getProductBaseUom($line->itemid);
        if($line->baseUom !== "" && $line->uom === ""){
            $line->uom=$line->baseUom;
        }
        $line->baseQty=$this->resolveLineBaseQuantity($line);
        unset($line->uomOptions);
        unset($line->uomEditable);
        return $line;
    }

    private function prepareCommercialHeader($transaction,$profile,$user){
        if($profile !== null){
            $transaction->profileId=isset($transaction->profileId) ? $transaction->profileId : $profile->id;
            $transaction->email=isset($profile->email) ? $profile->email : "";
            $transaction->contactno=isset($profile->contactno) ? $profile->contactno : "";
            $transaction->name=isset($profile->name) ? $profile->name : "";
            $transaction->address=isset($profile->address) ? $profile->address : "";
            $transaction->city=isset($profile->city) ? $profile->city : "";
            $transaction->country=isset($profile->country) ? $profile->country : "";
        }
        $transaction->tranDate=isset($transaction->tranDate) && $transaction->tranDate !== "" ? $transaction->tranDate : date("Y-m-d H:i:s");
        $transaction->invoiceDueDate=isset($transaction->invoiceDueDate) && $transaction->invoiceDueDate !== "" ? $transaction->invoiceDueDate : $transaction->tranDate;
        $tax = $this->resolveTaxSelection($transaction);
        if($tax !== null){
            $transaction->taxid = isset($tax->id) ? intval($tax->id) : (isset($tax->taxid) ? intval($tax->taxid) : 0);
            $transaction->taxcode = isset($tax->code) ? $tax->code : "";
            $transaction->taxname = isset($tax->name) ? $tax->name : "";
            $transaction->tax = isset($tax->rate) ? floatval($tax->rate) : 0;
        }else{
            $transaction->tax = isset($transaction->tax) ? floatval($transaction->tax) : 0;
        }
        $transaction->subtotal=0;
        $this->normalizeDocumentLines($transaction);
        foreach($transaction->InvoiceItems as $key=>$value){
            $transaction->subtotal += $this->lineTotal($value);
        }
        $transaction->taxamount=round($transaction->subtotal * ($transaction->tax / 100), 2);
        $transaction->total=round($transaction->subtotal + $transaction->taxamount, 2);
        $transaction->paidamount=isset($transaction->paidamount) ? floatval($transaction->paidamount) : 0;
        $transaction->status=isset($transaction->status) && $transaction->status !== "" ? $transaction->status : "Approved";
        $transaction->detailsString=json_encode($transaction->InvoiceItems);
        $transaction->preparedByID=isset($user->userid) ? $user->userid : "";
        $transaction->preparedBy=isset($user->email) ? $user->email : "";
        return $transaction;
    }

    private function documentStores($type){
        $isGrn=strtolower(trim($type)) === "grn";
        return array(
            "header"=>$isGrn ? "grnheader" : "poheader",
            "detail"=>$isGrn ? "grndetails" : "podetails"
        );
    }

    private function loadDocument($type,$tranNo){
        $stores=$this->documentStores($type);
        $headerResult=SOSSData::Query($stores["header"], urlencode("tranNo:".$tranNo));
        if(!$headerResult->success || count($headerResult->result) === 0){
            return null;
        }
        $document=$headerResult->result[0];
        $detailsResult=SOSSData::Query($stores["detail"], urlencode("tranNo:".$tranNo));
        $document->InvoiceItems=$detailsResult->success ? $detailsResult->result : array();
        return $this->attachUomContextToDocument($document);
    }

    private function replaceDocumentDetails($detailStore,$tranNo,$items){
        $oldDetails=SOSSData::Query($detailStore, urlencode("tranNo:".$tranNo));
        if($oldDetails->success && count($oldDetails->result) > 0){
            $deleteResult=SOSSData::Delete($detailStore, $oldDetails->result);
            if(!$deleteResult->success){
                return $deleteResult;
            }
        }

        foreach($items as $key=>$line){
            $items[$key]=$this->prepareDocumentLine($line);
            $items[$key]->tranNo=$tranNo;
            $items[$key]->total=$this->lineTotal($items[$key]);
            unset($items[$key]->barcodes);
            unset($items[$key]->barcodeInput);
            unset($items[$key]->uomOptions);
            unset($items[$key]->uomEditable);
        }
        return SOSSData::Insert($detailStore, $items);
    }

    private function applyGrnStockDelta($oldItems,$newItems,$tranNo,$profileId){
        $oldByItem=$this->sumLinesByItem($oldItems);
        $newByItem=$this->sumLinesByItem($newItems);
        foreach($newByItem as $itemKey=>$newLine){
            $oldQty=isset($oldByItem[$itemKey]) ? $oldByItem[$itemKey]->qty : 0;
            $delta=floatval($newLine->qty) - floatval($oldQty);
            if(abs($delta) > 0.0001){
                $this->moveStock($newLine, $delta, "GRN_EDIT", $tranNo, $profileId);
            }
        }
        foreach($oldByItem as $itemKey=>$oldLine){
            if(isset($newByItem[$itemKey])){
                continue;
            }
            $delta=0 - floatval($oldLine->qty);
            if(abs($delta) > 0.0001){
                $this->moveStock($oldLine, $delta, "GRN_EDIT", $tranNo, $profileId);
            }
        }
    }

    private function sumLinesByItem($lines){
        $summary=array();
        foreach($lines as $line){
            if(!isset($line->itemid)){
                continue;
            }
            $uomKey=isset($line->uom) ? strtolower(trim((string)$line->uom)) : "";
            $key=(string)$line->itemid."|".$uomKey;
            if(!isset($summary[$key])){
                $copy=clone $line;
                $copy->qty=0;
                $summary[$key]=$copy;
            }
            $summary[$key]->qty += isset($line->qty) ? floatval($line->qty) : 0;
            if(isset($line->price)){
                $summary[$key]->price=floatval($line->price);
            }
        }
        return $summary;
    }

    private function lineTotal($line){
        $qty=isset($line->qty) ? floatval($line->qty) : 0;
        $price=isset($line->price) ? floatval($line->price) : 0;
        return round($qty * $price, 2);
    }

    private function moveStock($line,$signedQty,$type,$refid,$entityid){
        $itemid=intval($line->itemid);
        $attributeid=isset($line->attributeid) ? intval($line->attributeid) : 0;
        $selectedUom=isset($line->uom) ? trim((string)$line->uom) : "";
        $uom=$this->getProductBaseUom($itemid);
        if($uom === ""){
            $uom=$selectedUom;
        }
        $stockQty=$this->resolveLineBaseQuantity((object)array(
            "itemid"=>$itemid,
            "uom"=>$selectedUom,
            "qty"=>$signedQty
        ));
        $existing=SOSSData::Query("product_inventrymaster", urlencode("itemid:".$itemid));
        if($existing->success && count($existing->result) > 0){
            $stock=$existing->result[0];
            $stock->qty=floatval(isset($stock->qty) ? $stock->qty : 0) + $stockQty;
            $stock->uom=$uom;
            SOSSData::Update("product_inventrymaster", $stock);
        }else{
            $stock=new stdClass();
            $stock->itemid=$itemid;
            $stock->attributeid=$attributeid;
            $stock->uom=$uom;
            $stock->qty=$stockQty;
            SOSSData::Insert("product_inventrymaster", $stock);
        }

        $productResult=SOSSData::Query("products", urlencode("itemid:".$itemid));
        if($productResult->success && count($productResult->result) > 0){
            $product=$productResult->result[0];
            $product->qty=floatval($stock->qty);
            SOSSData::Update("products", $product);
        }

        $history=new stdClass();
        $history->invdate=date("Y-m-d H:i:s");
        $history->transactiontype=$type;
        $history->entityid=intval($entityid);
        $history->transactionid=intval($refid);
        $history->productid=$itemid;
        $history->qty=$stockQty;
        $history->uom=$uom;
        $history->unitprice=isset($line->price) ? floatval($line->price) : 0;
        SOSSData::Insert("inventoryhistory", $history);

        $legacyHistory=new stdClass();
        $legacyHistory->itemid=$itemid;
        $legacyHistory->attributeid=$attributeid;
        $legacyHistory->refid=intval($refid);
        $legacyHistory->TranDate=date("Y-m-d H:i:s");
        $legacyHistory->uom=$uom;
        $legacyHistory->qty=$stockQty;
        SOSSData::Insert("product_inventryhistory", $legacyHistory);

        CacheData::clearObjects("product_inventrymaster");
        CacheData::clearObjects("product_inventryhistory");
        CacheData::clearObjects("inventoryhistory");
        CacheData::clearObjects("products");
    }

    private function saveReceivedBarcodes($line,$transaction){
        if(!isset($line->barcodes) || !is_array($line->barcodes)){
            return;
        }
        $cleanBarcodes=array();
        foreach($line->barcodes as $barcode){
            $barcode=trim((string)$barcode);
            if($barcode !== "" && !isset($cleanBarcodes[$barcode])){
                $cleanBarcodes[$barcode]=true;
            }
        }
        $barcodeCount=count($cleanBarcodes);
        $lineBaseQty=isset($line->baseQty) ? floatval($line->baseQty) : $this->resolveLineBaseQuantity($line);
        $perBarcodeBaseQty=$barcodeCount > 0 ? round($lineBaseQty / $barcodeCount, 6) : 1;
        foreach(array_keys($cleanBarcodes) as $barcode){
            $existing=SOSSData::Query("product_barcode_units", urlencode("barcode:".$barcode));
            if($existing->success && count($existing->result) > 0){
                continue;
            }
            $unit=new stdClass();
            $unit->barcode=$barcode;
            $unit->itemid=$line->itemid;
            $unit->name=isset($line->name) ? $line->name : "";
            $unit->uom=isset($line->baseUom) && trim((string)$line->baseUom) !== "" ? $line->baseUom : (isset($line->uom) ? $line->uom : "");
            $unit->receivedUom=isset($line->uom) ? $line->uom : "";
            $unit->receivedQty=isset($line->qty) ? floatval($line->qty) : 0;
            $unit->receivedBaseQty=$perBarcodeBaseQty;
            $unit->status="Available";
            $unit->grnNo=isset($transaction->tranNo) ? $transaction->tranNo : 0;
            $unit->poNo=isset($transaction->poid) ? $transaction->poid : 0;
            $unit->receivedDate=date("Y-m-d H:i:s");
            SOSSData::Insert("product_barcode_units", $unit);
        }
        CacheData::clearObjects("product_barcode_units");
    }

}

?>
