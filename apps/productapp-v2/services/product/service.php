<?php
require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once (PLUGIN_PATH . "/phpcache/cache.php");
require_once (PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH_LOCAL . "/davvag-attributes/davvag-attributes.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class ProductService {
    
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
                if(!isset($po->Complete) || strtoupper($po->Complete) !== "Y"){
                    $po->InvoiceItems=isset($poDetailsByTran[(string)$po->tranNo]) ? $poDetailsByTran[(string)$po->tranNo] : array();
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
        $line->qty=1;
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
        $transaction->tax=isset($transaction->tax) ? floatval($transaction->tax) : 0;
        $transaction->subtotal=0;
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
        return $document;
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
            $items[$key]->tranNo=$tranNo;
            $items[$key]->qty=isset($line->qty) ? floatval($line->qty) : 0;
            $items[$key]->price=isset($line->price) ? floatval($line->price) : 0;
            $items[$key]->total=$this->lineTotal($items[$key]);
            unset($items[$key]->barcodes);
            unset($items[$key]->barcodeInput);
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
            $key=(string)$line->itemid;
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
        $uom=isset($line->uom) ? $line->uom : "";
        $existing=SOSSData::Query("product_inventrymaster", urlencode("itemid:".$itemid));
        if($existing->success && count($existing->result) > 0){
            $stock=$existing->result[0];
            $stock->qty=floatval(isset($stock->qty) ? $stock->qty : 0) + $signedQty;
            $stock->uom=$uom;
            SOSSData::Update("product_inventrymaster", $stock);
        }else{
            $stock=new stdClass();
            $stock->itemid=$itemid;
            $stock->attributeid=$attributeid;
            $stock->uom=$uom;
            $stock->qty=$signedQty;
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
        $history->qty=$signedQty;
        $history->uom=$uom;
        $history->unitprice=isset($line->price) ? floatval($line->price) : 0;
        SOSSData::Insert("inventoryhistory", $history);

        $legacyHistory=new stdClass();
        $legacyHistory->itemid=$itemid;
        $legacyHistory->attributeid=$attributeid;
        $legacyHistory->refid=intval($refid);
        $legacyHistory->TranDate=date("Y-m-d H:i:s");
        $legacyHistory->uom=$uom;
        $legacyHistory->qty=$signedQty;
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
        foreach(array_keys($cleanBarcodes) as $barcode){
            $existing=SOSSData::Query("product_barcode_units", urlencode("barcode:".$barcode));
            if($existing->success && count($existing->result) > 0){
                continue;
            }
            $unit=new stdClass();
            $unit->barcode=$barcode;
            $unit->itemid=$line->itemid;
            $unit->name=isset($line->name) ? $line->name : "";
            $unit->uom=isset($line->uom) ? $line->uom : "";
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
