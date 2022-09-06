<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-ipg/davvag-ipg.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");

class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
             
        return $data; 
    }

    public function getIPGs($req,$res){
        switch($_GET["type"]){
            case "store":
                $sk=new stdClass();
                $sk->ipgs=Davvag_IPG::getIPGs($_GET["id"]);
                return $sk;
            break;
            case "order":
                $handler=new Davvag_Order();
                $order= $handler->getOrder($_GET["id"]);
                if($order==null){
                    $res->SetError ("Error Loading Order");
                    return null;
                }else{
                    $order->ipgs=Davvag_IPG::getIPGs(isset($order->supplier_profileId)?$order->supplier_profileId:0);
                    return $order;
                }
            break;
            case "ExPayment":
                $handler=new Davvag_Order();
                $payment =$handler->ExtPaymentRequest($_GET["id"]);
                //return $payment;
                if($payment!=null){
                    $payment->ipgs=Davvag_IPG::getIPGs(isset($payment->supplier_profileId)?$payment->supplier_profileId:0);
                    return $payment;
                }else{
                    $res->SetError ("Error Loading Payment Request");
                    return null;
                }
            break;
        }
        
    }

    


}

?>