<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");

class Bank_IPG {

    function __construct(){
        
    } 


    public function getPendingdipositsRequests($req,$res){
      
      $q=SOSSData::Query("davvag_bank_disposits","status:pending");
      if($q->success){
        return $q->result;
      }else{
        return [];
      }

      
    }

    public function getallBanks($req,$res){
      return $this->getBanks();
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

    public function postSave($req,$res){
      require_once(PLUGIN_PATH_LOCAL . "/davvag-ipg/davvag-ipg.php");
      $data=$req->Body(true);
      $Store_profile= Profile::getProfile(0,0);
      if($Store_profile->profile){
        $data->id=$Store_profile->profile->id;
      }else{
        $data->id=0;
      }
      
      $q=SOSSData::Query("davvag_banking","id:".$data->id.",bank_code:".$data->bank_code);
      if($q->success){
        if(count($q->result)==0){
            SOSSData::Insert("davvag_banking",$data);
            Davvag_IPG::SaveNewIPG("davvag-banking",$data->id,"Bank Tranfer","This feature is to get tranfers to the bank Accounts","http://www.davvag.com","assets/davvag-banking/bankdiposit.png");
            CacheData::clearObjects("davvag_banking");
            return $data;
        }else{
            SOSSData::Update("davvag_banking",$data);
            Davvag_IPG::SaveNewIPG("davvag-banking",$data->id,"Bank Tranfer","This feature is to get tranfers to the bank Accounts","http://www.davvag.com","assets/davvag-banking/bankdiposit.png");
            CacheData::clearObjects("davvag_banking");
            return $data;
        }
      }else{
        $res->SetError($q);
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
      $e=$req->Body(true);
      //return $body;
      $q=SOSSData::Query("davvag_bank_disposits","id:".$e->id);
      if($q->success && count($q->result)>0){
        $body=$q->result[0];
        $body->clearedAmount=$e->clearedAmount;
        $body->cleared_ref=$e->cleared_ref;
        //return $body;
        //$body->cleared_ref=$e->cleared_ref;
        $handler=new Davvag_Order();
        switch($body->ExtType){
          case "extern_reciept":
              $results=$handler->ExtPaymentRequest($body->refId);
              //return $results;
              if(!isset($results)){
                $res->SetError("Error finding external payment request");
                return;
              }
              //sreturn $results;
              //return $results;
              $payment= $results;
              $payment->paymentType="Bank Diposit";
              $payment->OnlinePotal="Payment Potal";
              $payment->OnlineTranID=$body->cleared_ref;
              $payment->amount=$body->clearedAmount;
              $payment->remarks=isset($payment->remarks)?$payment->remarks:""." Diposited to Bank ".$body->bank_name." Account No:".$body->bank_accountno;
              $payment->detailsString=json_encode($results);
              //return $payment;
              $r=$handler->ConfirmExtPayment($payment);
              //sreturn $r;
              if($r){
                $body->status="Confirm";
                $body->recieptid=$r->receiptNo;
                $body->payment=$r;
                SOSSData::Delete("davvag_bank_disposits",$body);
                SOSSData::Insert("davvag_bank_disposits_h",$body);
                return $body;
              }else{
                $res->SetError("Error Processing");
              }
              
          break;
          case "order":
              $rept=$handler->PayOrder($body->refId,$body->clearedAmount," Diposited to Bank ".$body->bank_name." Account No:".$body->bank_accountno,"Bank Diposit",$body->cleared_ref);
              
              if($rept){
                $body->status="Confirm";
                $body->recieptid=$rept->receiptNo;
                $body->payment=$rept;
                $handler->AcceptOrder($body->refId);
                SOSSData::Delete("davvag_bank_disposits",$body);
                SOSSData::Insert("davvag_bank_disposits_h",$body);
                return $body;
              }else{
                $res->SetError("Error Processing");
              }
              //return $body;
              //return;
          default:
              $res->SetError("Unautherized ");
          break;
        }
        return $body;
      }else{
        $res->SetError("Error finding Bank slip");
      }
      

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

    public function getBank($req,$res){
      $id=0;
      $Store_profile= Profile::getProfile(0,0);
      if($Store_profile->profile){
        $id=$Store_profile->profile->id;
      }
      $q=SOSSData::Query("davvag_banking","id:".$id.",bank_code:".$_GET["bank_code"]);
      if($q->success){
        return count($q->result)>0?$q->result[0]:null;
      }else{
        return null;
      }
    }

    public function getPublicToken($req,$res)
    {
      # code...
      if(isset($_GET["id"])){ 
        $keys=$this->DirectPaykeys($_GET["id"]);
        //return $keys;
        if(isset($keys)){
          return $keys;
        }else{
          return null;
        }
      }else{
        return "dddd";
      }
    }

    private function DirectPaykeys($id){
      $cache=CacheData::getObjects($id,"davvag_directpay_lk");
      if(isset($cache)){
        return $cache;
      }
      $q=SOSSData::Query("davvag_directpay_lk","id:".$id);
      //return $q;
      if($q->success){
        if(count($q->result)>0){
          CacheData::setObjects($id,"davvag_directpay_lk",$q->result[0]);
          return $q->result[0];
        }else{
          return null;
        }
      }else{
        return null;
      }

    }

}

?>