<?php
namespace davvag_credit_points;
require_once __DIR__.'/CreditPaymentService.php';
require_once TENANT_RESOURCE_LOCATION.'/apps/davvag-stripe/lib/CreditCheckoutAdapter.php';

final class CreditCheckoutService
{
    private $ledger;
    private $payment;
    private $provider;
    public function __construct($ledger=null,$provider=null) { $this->ledger=$ledger?:new CreditLedgerService();$this->payment=new CreditPaymentService($this->ledger);$this->provider=$provider?:new \davvag_stripe\CreditCheckoutAdapter();$this->schema(); }
    private function scope($fn) { return \SOSSData::WithServiceNamespaces(['davvag_credit_checkout'],$fn); }
    private function schema()
    {
        $this->scope(function(){ $r=\SOSSData::Query('davvag_credit_checkout','',null,'asc',1,0,null,false);if(!$r || !$r->success)throw new CreditException('Credit checkout schema is unavailable.'); });
        $db=$this->ledger->database();
        foreach(['uq_credit_checkout_order'=>'order_id','uq_credit_checkout_session'=>'session_id'] as $name=>$column) {
            if(!$db->one('SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?','ss',['davvag_credit_checkout',$name]))$db->run('CREATE UNIQUE INDEX `'.$name.'` ON davvag_credit_checkout (`'.$column.'`)');
        }
    }
    public static function returnRoute($route)
    {
        if($route==='')return '#/app/davvag-credit-points/';
        if(!is_string($route) || strlen($route)>600 || !preg_match('~^#/app/(?:lesson-market-place/(?:package\?slug=[a-z0-9-]+|my-enrolments)|davvag-credit-points/)$~D',$route))throw new CreditException('Only an internal package or wallet return is accepted.');
        return $route;
    }
    public function start($profile,$orderId,$returnRoute)
    {
        $returnRoute=self::returnRoute($returnRoute);$order=$this->payment->orderForProfile($orderId,$profile);
        if(!$order)throw new CreditException('Purchase order was not found.');
        if(strtoupper($order->payment_provider)!=='STRIPE')throw new CreditException('This credit package needs a configured STRIPE checkout channel.');
        $db=$this->ledger->database();$existing=$db->one('SELECT * FROM davvag_credit_checkout WHERE order_id=?','i',[(int)$orderId]);
        if($existing) { if($existing->return_route!==$returnRoute)throw new CreditException('This order has a different return destination. Use the original checkout.');return $this->safe($existing,$order); }
        if($order->order_status!=='PAYMENT_PENDING' || strtotime($order->expires_at)<=time()+60)throw new CreditException('This order is unavailable or expired. Create a new order.');
        $base=rtrim(trim((string)getenv('DAVVAG_CREDIT_CHECKOUT_BASE_URL')),'/');
        if(!preg_match('~^https://[^\s?#]+$~D',$base) && !preg_match('~^http://(?:localhost|127\.0\.0\.1)(?::[0-9]+)?(?:/[^\s?#]*)?$~D',$base))throw new CreditException('Set DAVVAG_CREDIT_CHECKOUT_BASE_URL to the configured application URL.');
        $statusUrl=$base.'/#/app/davvag-credit-points/purchase-status?order='.(int)$orderId;
        try{$session=$this->provider->create($order,$statusUrl);}catch(\Throwable $error){throw new CreditException('Provider checkout could not start. Verify Stripe configuration and retry the same order.');}
        $checkout=$db->transaction(function($txdb) use($order,$session,$returnRoute) {
            $locked=$txdb->one('SELECT id FROM davvag_credit_purchase_order WHERE id=? FOR UPDATE','i',[(int)$order->id]);
            $found=$txdb->one('SELECT * FROM davvag_credit_checkout WHERE order_id=?','i',[(int)$order->id]);if($found)return $found;
            $id=$txdb->insert('davvag_credit_checkout',['order_id'=>(int)$order->id,'session_id'=>$session->id,'checkout_url'=>$session->url,'return_route'=>$returnRoute,'created_at'=>date('Y-m-d H:i:s')]);
            return $txdb->one('SELECT * FROM davvag_credit_checkout WHERE id=?','i',[(int)$id]);
        });
        return $this->safe($checkout,$order);
    }
    private function safe($checkout,$order) { return ['order_id'=>(int)$order->id,'checkout_url'=>$order->order_status==='PAYMENT_PENDING' && $order->provider_status!=='EXPIRED'?$checkout->checkout_url:null,'return_to'=>self::returnRoute($checkout->return_route),'order_status'=>$order->order_status]; }
    public function reconcile($orderId,$profile=null)
    {
        $order=$profile===null?$this->payment->orderById($orderId):$this->payment->orderForProfile($orderId,$profile);
        if(!$order)throw new CreditException('Purchase order was not found.');
        $checkout=$this->ledger->database()->one('SELECT * FROM davvag_credit_checkout WHERE order_id=?','i',[(int)$orderId]);
        if(!$checkout)return $order;
        if(strtoupper($order->payment_provider)!=='STRIPE')throw new CreditException('The stored checkout provider does not match the order.');
        if($order->order_status!=='CREDITED') {
            try {
                $session=$this->provider->retrieve($checkout->session_id);
                $paid=\davvag_stripe\CreditCheckoutAdapter::verify($session,$order,$checkout->session_id,DATASTORE_DOMAIN);
            } catch(\Throwable $error) { throw new CreditException('Provider verification failed. The wallet has not been credited.'); }
            if($paid) {
                $evidence=['provider'=>'STRIPE','order_reference'=>$order->order_reference,'provider_reference'=>$checkout->session_id,'amount_minor'=>(int)$order->price_minor_snapshot,'currency'=>strtoupper($order->currency_snapshot),'settled'=>true];
                $order=$this->payment->completeVerified('STRIPE','checkout:'.$checkout->session_id,$order->order_reference,$checkout->session_id,hash('sha256',json_encode($evidence)));
            } elseif(($session->status ?? '')==='expired') { $this->ledger->database()->run("UPDATE davvag_credit_purchase_order SET provider_status='EXPIRED' WHERE id=? AND order_status='PAYMENT_PENDING'",'i',[(int)$orderId]);$order=$this->payment->orderById($orderId); }
        }
        $order->return_to=self::returnRoute($checkout->return_route);
        return $order;
    }
    public function reconcilePending($limit=50)
    {
        $rows=$this->ledger->database()->all("SELECT o.id FROM davvag_credit_purchase_order o JOIN davvag_credit_checkout c ON c.order_id=o.id WHERE o.order_status='PAYMENT_PENDING' AND o.provider_status<>'EXPIRED' ORDER BY o.id LIMIT ?",'i',[max(1,min(100,(int)$limit))]);
        $results=[];foreach($rows as $row) { try { $order=$this->reconcile($row->id);$results[]=['id'=>(int)$row->id,'status'=>$order->order_status]; } catch(\Throwable $error) { $results[]=['id'=>(int)$row->id,'status'=>'verification_failed']; } }return $results;
    }
}
