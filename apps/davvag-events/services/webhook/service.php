<?php
namespace davvag_events;

require_once dirname(__DIR__,2).'/lib/bootstrap.php';

final class WebhookService {
    public function postStripe($req,$res) {
        header('Cache-Control: no-store');
        try{
            $secret=getenv('DAVVAG_EVENTS_STRIPE_WEBHOOK_SECRET');if(!$secret)Support::fail('SETUP','Payment webhooks are not configured.',503);
            $raw=$req->Body(false);if(!is_string($raw)||strlen($raw)>262144)Support::fail('BODY','Invalid webhook payload.',400);
            Stripe::signature($raw,$_SERVER['HTTP_STRIPE_SIGNATURE']??'',$secret);$p=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!in_array($p['type']??'',['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','checkout.session.expired','charge.refunded'],true))return ['accepted'=>true];
            if(isset($p['account'])&&$p['account']!==getenv('DAVVAG_EVENTS_STRIPE_ACCOUNT'))Support::fail('MERCHANT','Wrong merchant account.',400);
            $object=$p['data']['object']??[];
            if($p['type']==='charge.refunded'&&empty($object['metadata']['tenant'])&&preg_match('/^pi_[A-Za-z0-9]+$/D',$object['payment_intent']??'')){$intent=Stripe::request('GET','/payment_intents/'.rawurlencode($object['payment_intent']));$object['metadata']=$intent['metadata']??[];}
            if(($object['metadata']['tenant']??'')!==(string)DATASTORE_DOMAIN)Support::fail('TENANT','Wrong tenant.',400);
            $id=$object['metadata']['eventId']??'';$orderId=$object['metadata']['orderId']??'';
            for($attempt=0;$attempt<4;$attempt++){$e=Store::load($id);$rev=$e['revision'];foreach($e['providerEvents'] as $old)if($old['id']===($p['id']??''))return ['accepted'=>true,'duplicate'=>true];$o=null;foreach($e['orders'] as $row)if($row['id']===$orderId)$o=$row;if(!$o)Support::fail('ORDER','Unknown order.',400);
                $eventId=Support::text($p['id']??'',100,true);$e['providerEvents'][]=['id'=>$eventId,'type'=>$p['type'],'at'=>Support::now()];
                if($p['type']==='charge.refunded'){
                    if(($object['payment_intent']??'')!==$o['paymentId']||($object['currency']??'')!==strtolower($o['currency'])||($object['amount']??null)!==$o['amount'])Support::fail('PAYMENT_MISMATCH','Refund does not match order.',400);
                    if(($object['amount_refunded']??0)>0)Ticketing::revoke($e,$o['id'],($object['refunded']??false)?'refunded':'partially_refunded');
                }else Jobs::enqueue($e,'payment',$orderId,'payment-event-'.$eventId);
                try{Store::save($e,$rev,'stripe','webhook_accepted',$eventId);return ['accepted'=>true];}catch(Fault $f){if($f->reason!=='CONFLICT')throw $f;}
            }Support::fail('CONFLICT','Retry webhook delivery.',503);
        }catch(Fault $f){http_response_code($f->status);$res->SetError(['code'=>$f->reason,'message'=>$f->getMessage()]);return null;}catch(\Throwable $f){http_response_code(503);$res->SetError(['code'=>'WEBHOOK_RETRY','message'=>'Webhook processing is temporarily unavailable.']);return null;}
    }
}
