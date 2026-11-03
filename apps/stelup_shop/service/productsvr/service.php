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
        require_once PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php";
        require_once PLUGIN_PATH_LOCAL . "/profile/profile.php";
        require_once dirname(__DIR__, 2) . "/lib/StripeConnect.php";
        try {
            $user = Profile::getUserProfile();
            if (empty($user->profile->id)) { throw new Exception("User Not Authenticated."); }
            $stripe = new StelupStripeConnect();
            return $stripe->createCheckout($req->Body(true), $user->profile, $res);
        } catch (Exception $error) {
            $res->SetError($error->getMessage());
            return null;
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
