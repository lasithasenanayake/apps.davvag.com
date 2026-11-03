<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");

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
            $mainObj->parameters->cat = isset($_GET["cat"]) ? $_GET["cat"] : "";

            $resultObj = SOSSData::ExecuteRaw("nearproducts", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function postCheckout($req,$res){
        require_once (PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
        require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");
        $handler =new Davvag_Order();
        $profile = $req->Body(true);
        $userProfile = Profile::getUserProfile();
        if (!isset($userProfile->profile)) {
            $res->SetError("User Not Authenticated.");
            return;
        }
        if (!isset($profile->data->storeid, $profile->data->items) ||
            !is_array($profile->data->items) || count($profile->data->items) === 0) {
            $res->SetError("Invalid checkout request.");
            return;
        }
        $profile->order = new stdClass();
        if(isset($_COOKIE["Location"])){
            $location = json_decode($_COOKIE["Location"]);
            if (isset($location->lat, $location->lng) && is_numeric($location->lat) && is_numeric($location->lng)) {
                $profile->order->lat = (float)$location->lat;
                $profile->order->lon = (float)$location->lng;
            }
        }
        $Store_profile= Profile::getProfile($profile->data->storeid,0);
        if(isset($Store_profile->profile)){
            //return $Store_profile->profile;
            $Store_profile=$Store_profile->profile;
            $profile->order->supplier_profileId = $Store_profile->id; 
            $profile->order->supplier_name = $Store_profile->name;
            $profile->order->supplier_contactno = isset($Store_profile->contactno)?$Store_profile->contactno:null;
            $profile->order->supplier_address = isset($Store_profile->address)?$Store_profile->address:null;
            $profile->order->supplier_city = isset($Store_profile->city)?$Store_profile->city:null;
            $profile->order->supplier_country = isset($Store_profile->country)?$Store_profile->country:null;
            $profile->order->supplier_email = isset($Store_profile->email)?$Store_profile->email:null;
        } else {
            $res->SetError("Seller profile was not found.");
            return;
        }
        
        $profile->order->profileId = $userProfile->profile->id;
        $profile->order->name = isset($profile->name)?trim((string)$profile->name):$userProfile->profile->name;
        $profile->order->contactno = isset($profile->contactno)?trim((string)$profile->contactno):null;
        $profile->order->address = isset($profile->address)?$profile->address:null;
        $profile->order->city = isset($profile->city)?trim((string)$profile->city):null;
        $profile->order->country = isset($profile->country)?trim((string)$profile->country):null;
        if ($profile->order->name === "" || $profile->order->contactno === null) {
            $res->SetError("Name and contact number are required.");
            return;
        }
        $profile->order->orderdate = date("m-d-Y H:i:s");
        $profile->order->currencycode='';
        $profile->order->InvoiceItems = array();
        $profile->order->deliverydate = isset($profile->deliverydate)?$profile->deliverydate:null;
        $profile->order->paymenttype = isset($profile->paymenttype)?$profile->paymenttype:"cashondelivery";
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
        
       //$authData = json_decode($_COOKIE["authData"]);
        $profile->order->email = $userProfile->profile->email;
        for ($i=0;$i<sizeof($profile->data->items);$i++){
            $requestedItem = $profile->data->items[$i];
            if (!isset($requestedItem->itemid, $requestedItem->qty) ||
                !is_numeric($requestedItem->itemid) || !is_numeric($requestedItem->qty)) {
                $res->SetError("Invalid product or quantity.");
                return;
            }
            $quantity = (int)$requestedItem->qty;
            if ($quantity < 1) {
                $res->SetError("Quantity must be at least one.");
                return;
            }

            $productResult = SOSSData::Query("products", urlencode("itemid:".(int)$requestedItem->itemid));
            if (!$productResult->success || count($productResult->result) === 0) {
                $res->SetError("A product in the cart is no longer available.");
                return;
            }
            $item = $productResult->result[0];
            if (!isset($item->storeid) || (string)$item->storeid !== (string)$profile->data->storeid) {
                $res->SetError("Cart products must belong to the selected seller.");
                return;
            }
            if (isset($item->qty) && strtolower(isset($item->invType)?$item->invType:"inventry") === "inventry" &&
                $quantity > (int)$item->qty) {
                $res->SetError("Insufficient stock for ".(isset($item->name)?$item->name:"product"));
                return;
            }
            $basePrice = isset($item->price) && is_numeric($item->price) ? (float)$item->price : 0;
            $discount = isset($item->discountper) && is_numeric($item->discountper) ? (float)$item->discountper : 0;
            $discount = max(0, min(100, $discount));
            if ($basePrice < 0) {
                $res->SetError("Invalid product price.");
                return;
            }
            
            $detail = new stdClass();
            $detail->itemid = $item->itemid;
            $detail->productid = $item->itemid;
            $detail->invType= isset($item->invType)?$item->invType:"inventry";
            $detail->tid = isset($item->tid)?$item->tid:null;
            $detail->tenant = HOST_NAME;
            $profile->order->tenant = HOST_NAME;
            $detail->name = $item->name;
            $detail->uom = isset($item->uom)?$item->uom:null;
            $detail->qty = $quantity;
            $detail->price = $basePrice-($basePrice*($discount/100));
            $detail->total = $quantity * $detail->price;
            if ($profile->order->currencycode === "") {
                $profile->order->currencycode = isset($item->currencycode)?$item->currencycode:"gbp";
            }
            $profile->order->total += ($detail->total);
            $profile->order->subtotal += ($detail->total);
            array_push($profile->order->InvoiceItems, $detail);
        }
        unset ($profile->data);
        try{
            $order= $handler->InvoiceSave($profile->order,$res);
            CacheData::clearObjects("products");
            $result = SOSSData::Insert ("orderheader_pending", $order);
            $result = SOSSData::Insert ("orderdetails_pending", $order->InvoiceItems);
            return $order;
        }catch(Exception $e){
            $res->SetError($e->getMessage());
            return;
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
                
                
                $escape = function($value){
                    return htmlspecialchars(urldecode((string)$value), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
                };
                $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
                $itemId = (int)$_GET["q"];
                $redirect = "/#/app/stelup_shop/xplo?id=".$itemId;
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8" />
                    <meta name="description" content="'.$escape(strip_tags(isset($data->caption)?$data->caption:"")).'">
                    <meta name="tags" content="'.$escape(isset($data->tags)?$data->tags:"").'">
                    <meta property="og:type" content="product">
                    <meta name="og:title" content="'.$escape(isset($data->name)?$data->name:"").'">
                    <meta property="og:category" content="'.$escape(isset($data->cat)?$data->cat:"").'" />
                    <meta name="og:description" content="'.$escape(strip_tags(isset($data->caption)?$data->caption:"")).'">
                    <meta name="og:tags" content="'.$escape(isset($data->keywords)?$data->keywords:"").'">
                    <meta name="og:image" content="'.$scheme.'://'.$escape($_SERVER["HTTP_HOST"]).'/components/dock/soss-uploader/service/get/products/'.$itemId.'-'.$escape(isset($data->imgurl)?$data->imgurl:"").'">
                    <meta property="og:price:amount" content="'.$escape(isset($data->price)?$data->price:0).'">
                    <meta property="og:price:currency" content="'.$escape(isset($data->currencycode)?$data->currencycode:"").'">
                    <meta property="og:availability" content="instock" />
                    <title>'.$escape(isset($data->name)?$data->name:"").'</title>
                    
                </head>
                <body>
                    loading.....
                    <script type="text/javascript">
                        setTimeout(function(){ window.location = '.json_encode($redirect).'; }, 1000);
                        
                    </script>    
                </body>
                </html>';
                exit();      

            }
        }
    }
}

?>
