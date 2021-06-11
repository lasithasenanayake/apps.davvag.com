<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class DirectPay_IPG {

    function __construct(){
        
    } 

    

    private function getBanks(){
      $id=0;
      $Store_profile= Profile::getProfile(0,0);
      if($Store_profile->profile){
        $id=$Store_profile->profile->id;
      }
      $q=SOSSData::Query("davvag_banking","id:".$id);
      if($q->success){
        return $q->result;
      }else{
        return [];
      }
    }

    public function postSaveBankDiposit($req,$res){
      $data=$req->Body(true);
      $id=isset($data->id)?$data->id:0;
      $q=SOSSData::Query("davvag_bank_disposits","id:".$id);
      if($q->success){
        if(count($q->result)>0){
          $r =SOSSData::Update("davvag_bank_disposits",$data);
          $data->RecStatus=$r;
          return $data;
        }else{
          $data->status="pending";
          $data->dipositDate=date_format(new DateTime(), 'm-d-Y H:i:s');
          $r=SOSSData::Insert("davvag_bank_disposits",$data);
          $data->RecStatus=$r;
          $data->id=$r->result->generatedId;
          return $data;
        }
      }else{
        $res->SetError("Internal Error please contact system Admin.");
        return $q;
      }
    }

   

    public function getExtPaymentRequest($req,$res){
      $handler=new Davvag_Order();
      $request=$handler->ExtPaymentRequest($_GET["id"]);
      if($request!=null){
        $r=SOSSData::Query("davvag_bank_disposits","refid:".$request->id.",Exttype:extern_reciept");
        if($r->success && count($r->result)>0){
          return $r->result[0];
        }else{
          $rForm=new stdClass();
          $rForm->email=isset($request->email)!=true?"null@null.com":$request->email;
          $rForm->contactno=isset($request->contactno)!=true?"":$request->contactno;
          $rForm->rProfileId=$request->donorId;
          $rForm->amount=$request->paymentAmount;
          $rForm->dipositAmount=$request->paymentAmount;
          $rForm->currencycode=isset($request->currencycode)!=true?"USD":$request->currencycode;
          $rForm->refId=$request->id;
          $rForm->name=$request->name;
          $rForm->ExtReq=$request;
          $rForm->ExtType='extern_reciept';
          $rForm->supplier_profileId = $request->supplier_profileId;
          $rForm->supplier_name = $request->supplier_name;
          //$rForm->supplier_contactno =$request->supplier_contactno; 
          $rForm->supplier_address =$request->supplier_address; 
          $rForm->supplier_city = $request->supplier_city;
          $rForm->supplier_country = $request->supplier_country;
          $rForm->supplier_email = $request->supplier_email;
          $rForm->profileId = $request->profileId;
          $rForm->name = $request->name;
          $rForm->contactno =$request->contactno; 
          $rForm->address =$request->address; 
          $rForm->city = $request->city;
          $rForm->country = $request->country;
          $rForm->email = $request->email;
          //$rForm->refID=
          //$rForm->appkey="ddde7400a6bce53296bfe8e41b2b18fd9691abc2585927d1e5a1501339ec6fd2";
          //$rForm->mainkey="OD04597";
          $rForm->banks=$this->getBanks();
          ///return $keys;
          return $rForm;
        }
      }else{
        return null;
      }
    }

    public function postPayment($req,$res){
      $body=$req->Body(true);
      $handler=new Davvag_Order();
      switch($body->ExtType){
        case "extern_reciept":
            $results=$body->ExtResults;
            //return $results;
            $payment= $body->ExtReq;
            $payment->paymentType="Onine Payment";
            $payment->OnlinePotal="Direct Pay";
            $payment->OnlineTranID=$results->data->transactionId;
            $payment->amount=$results->data->amount;
            $payment->detailsString=json_encode($results);
            
            $r=$handler->ConfirmExtPayment($payment);
            return $r;
        break;
        case "order":
            $order= $body->ExtReq;
            $results=$body->ExtResults;
            $rept=$handler->PayOrder($order->invoiceNo,$results->data->amount,"Payed via directpay.lk. tranid :[".$results->data->transactionId."]","DirectPay IPG",$results->data->transactionId);
            if($rept){
                $handler->AcceptOrder($order->invoiceNo);
            }
            return $rept;
            return;
        default:
            $res->SetError("Unautherized ");
        break;
      }
      return $body;

    }

    public function getOrder($req,$res){
      $handler=new Davvag_Order();
      $userprofile=Profile::getUserProfile();
      $order= $handler->getOrder($_GET["id"]);
      if($order==null || $order->profileId!=$userprofile->profile->id){
        $res->SetError ("Error Loading Order");
        return null;
      }else{
        $r=SOSSData::Query("davvag_bank_disposits","refid:".$order->invoiceNo.",Exttype:order");
        if($r->success && count($r->result)>0){
          return $r->result[0];
        }else{
          $rForm=new stdClass();
          $rForm->email=isset($order->email)!=true?"null@null.com":$order->email;
          $rForm->contactno=isset($order->contactno)!=true?"":$order->contactno;
          $rForm->rProfileId=$order->profileId;
          $rForm->amount=$order->balance;
          $rForm->dipositAmount=$order->balance;
          $rForm->currencycode=isset($order->currencycode)!=true?"USD":$order->currencycode;
          $rForm->refId=$order->invoiceNo;
          $rForm->name=$order->name;
          $rForm->ExtType='order';
          $rForm->ExtReq=$order;
          //$keys=$this->DirectPaykeys((isset($order->supplier_profileId)?$order->supplier_profileId:0));
          $rForm->ExtType='order';
          $rForm->supplier_profileId = $order->supplier_profileId;
          $rForm->supplier_name = $order->supplier_name;
          //$rForm->supplier_contactno =$order->supplier_contactno; 
          $rForm->supplier_address =$order->supplier_address; 
          $rForm->supplier_city = $order->supplier_city;
          $rForm->supplier_country = $order->supplier_country;
          $rForm->supplier_email = $order->supplier_email;
          $rForm->profileId = $order->profileId;
          $rForm->name = $order->name;
          $rForm->contactno =$order->contactno; 
          $rForm->address =$order->address; 
          $rForm->city = $order->city;
          $rForm->country = $order->country;
          $rForm->email = $order->email;
          $rForm->banks=$this->getBanks();
          return $rForm;
        }
        
        
      }
    }


}

?>