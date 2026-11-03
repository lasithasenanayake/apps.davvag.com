<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");

require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");

class seller_svr {

    function __construct(){
        
    } 

    private function dispatchOrder($order){
        $order->status="dispatched";
        $headerResult=SOSSData::Insert("orderheader_dispatched",$order);
        $detailsResult=SOSSData::Insert("orderdetails_dispatched",$order->details);
        $updateResult=SOSSData::Update("orderheader",$order);
        if (!$headerResult->success || !$detailsResult->success || !$updateResult->success) {
            throw new Exception("The order could not be dispatched.");
        }
        SOSSData::Delete("orderheader_accepted",array($order));
        SOSSData::Delete("orderdetails_accepted",$order->details);
        CacheData::clearObjects("orderheader");
        CacheData::clearObjects("orderdetails");
    }

    public function getOrderDetails($req,$res){
        $id = isset($_GET["id"]) ? trim((string)$_GET["id"]) : "";
        $userprofile = Profile::getUserProfile();
        if ($id === "" || !isset($userprofile->profile)) {
            $res->SetError("Permission Denied");
            return;
        }

        $orderHandler = new Davvag_Order();
        $order = $orderHandler->getOrder($id);
        if (!$order || !isset($order->supplier_profileId) ||
            (string)$order->supplier_profileId !== (string)$userprofile->profile->id) {
            $res->SetError("Order not found or access denied");
            return;
        }

        return isset($order->details) ? $order->details : array();
    }

    public function getUpdateOrder($req,$res){
        $order =new Davvag_Order();
        $status=isset($_GET["status"]) ? strtolower(trim((string)$_GET["status"])) : "";
        $id=isset($_GET["id"]) ? trim((string)$_GET["id"]) : "";
        $userprofile=Profile::getUserProfile();
        $data=new stdClass();
        
        if(isset($userprofile->profile) && $id !== ""){
            $existingOrder = $order->getOrder($id);
            if (!$existingOrder || !isset($existingOrder->supplier_profileId) ||
                (string)$existingOrder->supplier_profileId !== (string)$userprofile->profile->id) {
                $res->SetError("Order not found or access denied");
                return;
            }

            switch($status){
                case "dispatch":
                    if (!isset($existingOrder->status) || strtolower($existingOrder->status)!=="accepted") {
                        $data->success=false;
                        $data->result="Only accepted orders can be dispatched";
                    } else {
                        try {
                            $this->dispatchOrder($existingOrder);
                            $data->success=true;
                            $data->result="Order Dispatched";
                        } catch (Exception $e) {
                            $data->success=false;
                            $data->result=$e->getMessage();
                        }
                    }
                    break;
                default:
                    $data->success=false;
                    $data->result="Unsupported order transition";
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

    public function getOrders($req,$res){
        $type=isset($_GET["type"]) ? strtolower(trim((string)$_GET["type"])) : "";
        $userprofile=Profile::getUserProfile();
        $data=new stdClass();
        
        if(isset($userprofile->profile)){

            switch($type){
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
