<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");

require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");

class seller_svr {

    function __construct(){
        
    } 

    function getOrderDetails($req,$res){
        $id=$_GET["id"];
        $result=SOSSData::Query("orderdetails",urlencode("invoiceNo:".$id));
        if($result->success){
            return $result->result;
        }else{
            $res->SetError("Error Retrieving details Details");
        }
    }

    function getUpdateOrder(){
        $order =new Davvag_Order();
        $status=$_GET["status"];
        $id=$_GET["id"];
        $userprofile=Profile::getUserProfile();
        $data=new stdClass();
        
        if(isset($userprofile)){

            switch(strtolower($status)){
                case "dispatch":
                    $order->DispatchOrder($id);
                    $data->success=true;
                    $data->result="Order Dispatched";
                    break;
                case "cancel":
                    $order->DispatchOrder($id);
                    $data->success=true;
                    $data->result="Order Cancelled";
                    break;
                default:
                    $data->success=false;
                    $data->result=[];
                    break;
                
            }
            if($data->success){
                return $data->result;
            }else{
                $res->SetError($data);
                return 0;
            }
        }else{
            $res->SetError("Permission Denied");
        }
        
    }

    function getOrders($req,$res){
        $type=$_GET["type"];
        $userprofile=Profile::getUserProfile();
        $data=new stdClass();
        
        if(isset($userprofile)){

            switch(strtolower($type)){
                case "pending":
                    $result=SOSSData::Query("orderheader_pending",urlencode("supplier_profileId:".$userprofile->profile->id));
                    $data =$result;
                    break;
                case "accepted":
                    $result=SOSSData::Query("orderheader_accepted",urlencode("supplier_profileId:".$userprofile->profile->id));
                    $data =$result;
                   break;
                case "rejected":
                    $result=SOSSData::Query("orderheader_rejected",urlencode("supplier_profileId:".$userprofile->profile->id));
                    $data =$result;
                   break;
                case "deleted":
                    $result=SOSSData::Query("orderheader_deleted",urlencode("supplier_profileId:".$userprofile->profile->id));
                    $data =$result;
                    break;
                case "dispatched":
                        $result=SOSSData::Query("orderheader_dispatched",urlencode("supplier_profileId:".$userprofile->profile->id));
                        $data =$result;
                    break;
                case "cancelled":
                        $result=SOSSData::Query("orderheader_cancelled",urlencode("supplier_profileId:".$userprofile->profile->id));
                        $data =$result;
                    break;
                default:
                    $data->success=false;
                    $data->result=[];
                    break;
                
            }
            if($data->success){
                return $data->result;
            }else{
                $res->SetError($data);
                return 0;
            }
        }else{
            $res->SetError("Permission Denied");
        }
    }
    

    


}

?>