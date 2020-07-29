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
            $mainObj->parameters->cat = isset($_GET["cat"])?$_GET["cat"]:'0';

            $resultObj = SOSSData::ExecuteRaw("nearproducts", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }
}

?>