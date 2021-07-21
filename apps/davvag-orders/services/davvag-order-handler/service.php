<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class BroadcastService {

    function __construct(){
        
    } 

    public function getAllPendingBids($req,$res){
        //if (isset($_GET["page"]) && isset($_GET["size"])){
            //require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();

            $resultObj = SOSSData::ExecuteRaw("product_bid_active", $mainObj);
            return $resultObj->result;
    }

    public function postRequestOrderCompletion($req,$res){
        $body=$req->Body(true);
        $update = new stdClass();
        $update->itemid=$body->itemid;
        $update->status="closed";
        $r=SOSSData::Update("attr_bi",$update);
        if($r->success){
            $profile=Profile::getProfile($body->profileId,0);
            //return $profile;
            if($profile->profile){
                $update->showonstore='n';
                SOSSData::Update("products",$update);
                $r=SOSSData::Insert("order_bid_approval_pending",$body);
                $body->id=$r->result->generatedId;
                Profile::AddNotify($body->profileId,"davvag-shop-v2-bid-confirmation",$body);
                Profile::Send_Notify();
                return $body;
            }else{
                $res->SetError("Profile Not Found.");
            }
        }else{
            $res->SetError($r);
        }

    }

    public function getallPendingOrders($req,$res){
        $allkeys=CacheData::getObjects("all","orderheader_pendings");
        if(isset($allkeys)){
            return $allkeys;
        }else{
            $r = SOSSData::Query ("orderheader_pending", null);
            if($r->success){
                return $r->result;
            }else{
                $res->SetError ($r->result);
                return $r->result; 
            }
        }
    }

    public function getPendingSchedulesBy($req,$res){
        if(isset($_GET["app"]) && isset($_GET["service"]) && isset($_GET["method"])){
            $app=$_GET["app"];
            $service=$_GET["service"];
            $method=$_GET["method"];
            $r = SOSSData::Query ("schedule_pending", "app:$app,service:$service,method:$method");
            if($r->success){
                return $r->result;
            }else{
                $res->SetError ($r->result);
                return $r->result; 
            }
        }else{
            $res->SetError ("Error Loading data");
        }
    }

    public function postAcceptOrder($req,$res){
        $body=$req->Body(true);
        $r = SOSSData::Query ("orderheader_pending", "invoiceNo:".$body->invoiceNo);
        if($r->success){
            if(count($r->result)==0){
                $res->SetError ("Pending Orders was not found.");
                return $r;
            }
            
            $r = SOSSData::Query ("orderheader", "invoiceNo:".$body->invoiceNo);
            if($r->success){
                if(count($r->result)>0){
                    $order =$r->result[0];
                    $order->status="accepted";
                    SOSSData::Update("orderheader",$order);
                    $rb=SOSSData::Query ("orderdetails_pending", "invoiceNo:".$body->invoiceNo); 
                    SOSSData::Insert("orderheader_accepted",$order);
                    SOSSData::Insert("orderdetails_accepted",$rb->result);
                    $rd=SOSSData::Delete("orderheader_pending",$order);
                    $r=SOSSData::Delete("orderdetails_pending",$rb->result);
                    return $order;
                }
            }else{
                $res->SetError ($rd->result);
                return $rd->result; 
            }
        }else{
            $res->SetError ($rd->result);
            return $rd->result; 
        }
    }

    public function postRejectOrder($req,$res){
        $body=$req->Body(true);
        $r = SOSSData::Query ("orderheader_pending", "invoiceNo:".$body->invoiceNo);
        if($r->success){
            if(count($r->result)==0){
                $res->SetError ("Pending Orders was not found.");
                return $r;
            }
            
            $r = SOSSData::Query ("orderheader", "invoiceNo:".$body->invoiceNo);
            if($r->success){
                if(count($r->result)>0){
                    $order =$r->result[0];
                    $order->status="rejected";
                    SOSSData::Update("orderheader",$order);
                    $rb=SOSSData::Query ("orderdetails_pending", "invoiceNo:".$body->invoiceNo); 
                    SOSSData::Insert("orderheader_rejected",$order);
                    SOSSData::Insert("orderdetails_rejected",$rb->result);
                    $rd=SOSSData::Delete("orderheader_pending",$order);
                    $r=SOSSData::Delete("orderdetails_pending",$rb->result);
                    return $order;
                }
            }else{
                $res->SetError ($rd->result);
                return $rd->result; 
            }
        }else{
            $res->SetError ($rd->result);
            return $rd->result; 
        }
    }

    


}

?>