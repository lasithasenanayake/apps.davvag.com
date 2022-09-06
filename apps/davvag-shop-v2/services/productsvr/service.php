<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
class ProductServices {


    public function getAllProductCatList($req){
        $result= CacheData::getObjects("cat","products");
        if(isset($result)){
            return $result;
        }
        $r=SOSSData::Query("productcat",null);
        $items=[];
        //return $r;
        if($r->success){
            $productcat = $r->result;
            for ($i=0; $i < count($productcat); $i++) { 
                # code...
                $mainObj = new stdClass();
                $mainObj->parameters = new stdClass();
                $mainObj->parameters->page = 0;
                $mainObj->parameters->size = 3;
                $mainObj->parameters->search = "";
                $mainObj->parameters->rad = '0';
                $mainObj->parameters->lon = '0';
                $mainObj->parameters->lan = '0';
                $mainObj->parameters->cat = $productcat[$i]->name;

                $resultObj = SOSSData::ExecuteRaw("ds_products_v2", $mainObj);
                if($resultObj->success && ($resultObj->result)>0){
                    array_push($items,array("name"=>$productcat[$i]->name,"id"=>$productcat[$i]->id,"products"=>$resultObj->result));
                }
            }
            
        }
        if(count($items)){
            CacheData::setObjects("cat","products",$items);
        }
        return $items;
    }
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
            $mainObj->parameters->cat = isset($_GET["cat"]) ? $_GET["cat"] : "";

            $resultObj = SOSSData::ExecuteRaw("ds_products_v2", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function postSaveBid($req,$res){
        $bid = $req->Body(true);
        $userProfile=Profile::getUserProfile();
        if(isset($userProfile->profile)){
            $r=SOSSData::Query("attr_bi","itemid:".$bid->itemid);
            if($r->success && count($r->result)>0){
                $item=$r->result[0];
                if($item->profileId==$userProfile->profile->id){
                    $res->SetError("You have already bided the best amount.");
                    return null;
                }
                if(floatval($item->current_bid)<floatval($bid->bid_amount)){
                    $upbid=new stdClass();
                    $upbid->itemid=$bid->itemid;
                    $upbid->current_bid=$bid->bid_amount;
                    $upbid->number_of_bids=intval(isset($item->number_of_bids)?$item->number_of_bids:0)+1;
                    $upbid->profileId=$userProfile->profile->id;
                    $bid->profileId=$userProfile->profile->id;
                    SOSSData::Update("attr_bi",$upbid);
                    SOSSData::Insert("products_bids",$bid);
                    if(isset($item->profileId)){
                        Profile::AddNotify($item->profileId,"product_bid_posted",$bid);
                        Profile::Send_Notify();
                    }

                    CacheData::clearObjects("products");
                    return $bid;
                }else{
                    $res->SetError("Need to bid over ".$r->result->cuurent_bid);

                }
            }else{
                $res->SetError("Bid not found.");

            }
        }else{
            $res->SetError("User Not Signed in");
        }

    }

    public function getProductDetails($req,$res)
    {
        # code...
        if(isset($_GET["id"])){
            $result= CacheData::getObjects(md5("full-itemid:".$_GET["id"]),"products");
            if(!isset($result)){
                //echo "in here";
                $result = SOSSData::Query("products",urlencode("itemid:".$_GET["id"]));
                //return $result;
                if($result->success){
                    //$f->{$s->storename}=$result->result;
                    if(isset($result->result[0])){
                        $data= $result->result[0];
                        $r=SOSSData::Query("products_image",urlencode("articalid:".$_GET["id"]));
                        $data->images=$r->success?$r->result:[];
                        $r=SOSSData::Query("products_attributes",urlencode("itemid:".$_GET["id"]));
                        $data->attributes=$r->success?(count($r->result)>0?$r->result[0]:null):[];
                        $r=SOSSData::Query("attr_".$data->sellstype,urlencode("itemid:".$_GET["id"]));
                        $data->sellstype_attributes=$r->success?(count($r->result)>0?$r->result[0]:null):[];
                        CacheData::setObjects(md5("full-itemid:".$_GET["id"]),"products",$data);
                        return $data;
                    }else{
                        return [];
                    }
                }
            }else{
                return $result;
            }
        }else{
            $res->SetError("Invalied request.");
        }
    }

    public function postCheckout($req,$res){
        require_once (PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
        $handler =new Davvag_Order();
        $profile = $req->Body(true);
        $profile->order = new stdClass();
        if(isset($_COOKIE["Location"])){
            $location = json_decode($_COOKIE["Location"]);
            $profile->order->lat = $location->lat;
            $profile->order->lon = $location->lng;
        }
        
        $profile->order->supplier_profileId = $profile->id;
        $profile->order->profileId = $profile->id;
        $profile->order->name = $profile->name;
        $profile->order->contactno = $profile->contactno;
        $profile->order->address = $profile->address;
        $profile->order->city = $profile->city;
        $profile->order->country = $profile->country;
        $profile->order->orderdate = date("m-d-Y H:i:s");
        $profile->order->InvoiceItems = array();
        $profile->order->deliverydate = $profile->deliverydate;
        $profile->order->paymenttype = $profile->paymenttype;
        $profile->order->status = "new";//$profile->orderstatus;
        $profile->order->remarks = isset($profile->remarks) ?$profile->remarks : "";
        $profile->order->total = 0;
        $profile->order->subtotal = 0;
        $profile->order->paidamount = 0;
        $profile->order->balance = 0;
        $profile->order->taxamount = 0;
        //$profile->order->tenant = $_SERVER["HTTP_HOST"];
        $profile->order->invoiceDate = date("m-d-Y H:i:s");
        $profile->order->invoiceDueDate = date("m-d-Y H:i:s");
        
        $authData = Auth::Autendicate();
        $profile->order->email = $profile->email;
        if(!isset($profile->items)){
            $req->SetError("Invalied call");
        }
        for ($i=0;$i<sizeof($profile->items);$i++){
            $item = $profile->items[$i];
            
            $detail = new stdClass();
            $detail->itemid = $item->itemid;
            $detail->productid = $item->itemid;
            $detail->invType= $item->invType;
            $detail->tid = $item->tid;
            $detail->tenant = HOST_NAME;
            $profile->order->tenant = HOST_NAME;
            $detail->name = $item->name;
            $detail->uom = $item->uom;
            $detail->qty = $item->qty;
            $detail->price = $item->price-($item->price*($item->discountper/100));
            $detail->total = $item->qty * $detail->price;
            $profile->order->total += ($detail->total);
            $profile->order->subtotal += ($detail->total);
            array_push($profile->order->InvoiceItems, $detail);
        }
        unset ($profile->items);
        try{
            $order= $handler->InvoiceSave($profile->order,$res);
            $result = SOSSData::Insert ("orderheader_pending", $order,$tenantId = null);
            $result = SOSSData::Insert ("orderdetails_pending", $order->InvoiceItems,$tenantId = null);
            return $order;
        }catch(Exception $e){
            $req->SetError($e);
        }
    }

    function getProduct($req){
        //echo "imain";
        $data =null;
        if(isset($_GET["q"])){
            //echo "in here";
            $result= CacheData::getObjects_fullcache(md5("itemid:".$_GET["q"]),"products");
            if(!isset($result)){
                //echo "in here";
                $result = SOSSData::Query("products",urlencode("itemid:".$_GET["q"]));
                //return $result;
                if($result->success){
                    //$f->{$s->storename}=$result->result;
                    if(isset($result->result[0])){
                        $data= $result->result[0];
                        CacheData::setObjects(md5("itemid:".$_GET["q"]),"products",$result->result);
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
                    <meta name="description" content="'.urldecode(strip_tags($data->caption)).'">
                    <meta name="tags" content="'.urldecode($data->tags).'">
                    <meta property="og:type" content="product">
                    <meta name="og:title" content="'.urldecode($data->name).'">
                    <meta property="og:category" content="'.urldecode($data->cat).'" />
                    <meta name="og:description" content="'.urldecode(strip_tags($data->caption)).'">
                    <meta name="og:tags"  content="'.urldecode($data->keywords).'">
                    <meta name="og:image"  content="http://'.$_SERVER["HTTP_HOST"].'/components/dock/soss-uploader/service/get/products/'.$_GET["q"]."-".$data->imgurl.'">
                    <meta property="og:price:amount" content="'.urldecode($data->price).'">
                    <meta property="og:price:currency" content="'.urldecode($data->currencycode).'">
                    <meta property="og:availability" content="instock" />
                    <title>'.urldecode($data->name).'</title>
                    
                </head>
                <body>
                    loading.....
                    <script type="text/javascript">
                        setTimeout(function(){ window.location = "/#/app/davvag-shop/a?id='.$_GET["q"].'"; }, 1000);
                        
                    </script>    
                </body>
                </html>';
                exit();      

            }
        }
    }
}

?>