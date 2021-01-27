<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/stripe/init.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
use \Stripe\Stripe;
use \Stripe\Customer;
use \Stripe\ApiOperations\Create;
use \Stripe\Charge;

class Stripe_IPG {

    function __construct(){
        
    } 

    
    
    public function getOrder($req,$res){
      $handler=new Davvag_Order();
      $userprofile=Profile::getUserProfile();
      $order= $handler->getOrder($_GET["id"]);
      if($order==null || $order->profileId!=$userprofile->profile->id){
        $res->SetError ("Error Loading Order");
        return null;
      }else{
        //$this->Stripkeys($order->supplier_profileId);
        $keys=$this->Stripkeys($order->supplier_profileId);
        ///return $keys;
        if(isset($keys)){
          $order->stripeKey= $keys->stripeKey;
        }
        return $order;
      }
    }

    public function getPublicToken($req,$res)
    {
      # code...
      if($_GET["id"]){ 
        $keys=$this->Stripkeys($_GET["id"]);
        ///return $keys;
        if(isset($keys)){
          return $keys->stripeKey;
        }else{
          return null;
        }
      }
    }

    private function Stripkeys($id){
      $cache=CacheData::getObjects($id,"davvag_stripe");
      if($cache){
        return $cache;
      }
      $q=SOSSData::Query("davvag_stripe","id:".$id);
      if($q->success){
        if(count($q->result)>0){
          CacheData::setObjects($id,"davvag_stripe",$q->result[0]);
          return $q->result[0];
        }else{
          return null;
        }
      }else{
        return null;
      }

    }

    public function postChargeAmountFromCard($req,$res)
    {
        $cardDetails=$req->Body(true);
        $userprofile=Profile::getUserProfile();
        if($userprofile->profile){
            $handler=new Davvag_Order();
            $order= $handler->getOrder($cardDetails->id);
            //return $order;
            if($order==null || $order->profileId!=$userprofile->profile->id){
                $res->SetError ("Error Paying the given order");
                return null;
            }
            $keys=$this->Stripkeys($order->supplier_profileId);
            $apiKey = $keys->stripeSecretKey;
            $stripeService = new \Stripe\Stripe();
            $stripeService->setVerifySslCerts(false);
            $stripeService->setApiKey($apiKey);
            
            $customerDetailsAry = array(
                'email' => $userprofile->profile->email,
                'source' => $cardDetails->token
            );
            $customer = new Customer();
            $customerResult = $customer->create($customerDetailsAry);
            //return $customerResult;
            $charge = new Charge();
            $cardDetailsAry = array(
                'customer' => $customerResult->id,
                'amount' => $order->balance*100 ,
                'currency' => $order->currencycode,
                'description' => "Order No: ".$order->invoiceNo." Charged from Stelup.",
                'metadata' => array(
                    'order_id' => $order->invoiceNo
                )
            );
            //return $cardDetailsAry;
            try {
                $result = $charge->create($cardDetailsAry);
                $rept=$handler->PayOrder($order->invoiceNo,$order->balance,"Payed via Stripe. url :[".$result->receipt_url."]","Stripe IPG",$result->id);
                if($rept){
                    $handler->AcceptOrder($order->invoiceNo);
                }
                return $rept;
                //return $result;
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                 
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\RateLimitException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\InvalidRequestException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\AuthenticationException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\ApiConnectionException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\ApiErrorException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (Exception $e) {
                $res->SetError( $e);
                return null;
              }
            
        }
        
    }

    public function postTestChargeAmountFromCard($req,$res)
    {
        $cardDetails=$req->Body(true);
        $userprofile=Profile::getUserProfile();
        if($userprofile->profile){
            //$handler=new Davvag_Order();
            
            $apiKey = $cardDetails->stripeSecretKey;
            $stripeService = new \Stripe\Stripe();
            $stripeService->setVerifySslCerts(false);
            $stripeService->setApiKey($apiKey);
            //return $cardDetails;
            $customerDetailsAry = array(
                'email' => $userprofile->profile->email,
                'source' => $cardDetails->token
            );
            $customer = new Customer();
            $customerResult = $customer->create($customerDetailsAry);
            //return $customerResult;
            $charge = new Charge();
            $cardDetailsAry = array(
                'customer' => $customerResult->id,
                'amount' => $cardDetails->amount*100 ,
                'currency' => $cardDetails->courncycode,
                'description' => "Mapping Varification Charge.",
                'metadata' => array(
                    'order_id' => "0"
                )
            );
            //return $cardDetailsAry;
            try {
                $result = $charge->create($cardDetailsAry);
                $q=SOSSData::Query("davvag_stripe","id:".$cardDetails->id);
                if($q->success){
                  if(count($q->result)>0){
                    SOSSData::Update("davvag_stripe",$cardDetails);
                  }else{
                    SOSSData::Insert("davvag_stripe",$cardDetails);
                  }
                }
                
                return $result;
                //return $result;
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                 
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\RateLimitException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\InvalidRequestException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\AuthenticationException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\ApiConnectionException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (\Stripe\Exception\ApiErrorException $e) {
                $res->SetError( $e->getError()->message);
                return null;
              } catch (Exception $e) {
                $res->SetError( $e);
                return null;
              }
            
        }
        
    }


}

?>