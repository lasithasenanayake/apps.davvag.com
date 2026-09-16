<?php
namespace davvag_stripe;

/** Stripe hosted Checkout adapter. The injectable transport is for PHP tests only. */
final class CreditCheckoutAdapter
{
    private $transport;
    public function __construct($transport=null) { $this->transport=$transport; }
    private function request($method,$path,$fields=[],$key='')
    {
        if ($this->transport) return call_user_func($this->transport,$method,$path,$fields,$key);
        $secret=trim((string)getenv('DAVVAG_CREDIT_STRIPE_SECRET_KEY'));
        if ($secret==='') {
            $account=(int)getenv('DAVVAG_CREDIT_STRIPE_ACCOUNT_ID');
            if ($account>0) {
                $r=\SOSSData::Query('davvag_stripe',['conditions'=>[['column'=>'id','operator'=>'=','value'=>$account]],'pageSize'=>1,'pageFrom'=>0]);
                if ($r && $r->success && $r->result) $secret=trim($r->result[0]->stripeSecretKey ?? '');
            }
        }
        if (!preg_match('/^(sk|rk)_(test|live)_/', $secret)) throw new \RuntimeException('Stripe credit checkout is not configured.');
        if (!extension_loaded('curl')) throw new \RuntimeException('The curl PHP extension is required.');
        $curl=curl_init('https://api.stripe.com/v1'.$path);
        $headers=['Authorization: Bearer '.$secret,'Content-Type: application/x-www-form-urlencoded'];
        if($key!=='')$headers[]='Idempotency-Key: '.$key;
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method]);
        if($method==='POST')curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($fields));
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $data=is_string($body)?json_decode($body):null;
        if($status<200 || $status>=300 || !$data)throw new \RuntimeException('Stripe could not verify checkout. Retry the same order.');
        return $data;
    }
    public function create($order,$statusUrl)
    {
        $fields=['mode'=>'payment','client_reference_id'=>$order->order_reference,'metadata'=>['credit_order_id'=>(string)$order->id,'profile_id'=>(string)$order->profile_id,'tenant'=>(string)DATASTORE_DOMAIN],
            'payment_method_types'=>['card'],'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($order->currency_snapshot),'unit_amount'=>(int)$order->price_minor_snapshot,'product_data'=>['name'=>'Credits: '.$order->package_code_snapshot]]]],
            'success_url'=>$statusUrl,'cancel_url'=>$statusUrl];
        $session=$this->request('POST','/checkout/sessions',$fields,'davvag-credit-'.hash('sha256',DATASTORE_DOMAIN.':'.$order->order_reference));
        if(!preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/D',$session->id ?? '') || !preg_match('~^https://checkout\.stripe\.com/[^\s]+$~D',$session->url ?? ''))throw new \RuntimeException('Stripe returned an invalid checkout session.');
        return $session;
    }
    public function retrieve($sessionId)
    {
        if(!preg_match('/^cs_(test_|live_)?[A-Za-z0-9]+$/D',$sessionId))throw new \RuntimeException('Invalid stored Stripe checkout reference.');
        return $this->request('GET','/checkout/sessions/'.rawurlencode($sessionId));
    }
    public static function verify($session,$order,$sessionId,$tenant)
    {
        if(($session->id ?? '')!==$sessionId || ($session->mode ?? '')!=='payment' || ($session->client_reference_id ?? '')!==$order->order_reference
            || (string)($session->metadata->credit_order_id ?? '')!==(string)$order->id || (string)($session->metadata->profile_id ?? '')!==(string)$order->profile_id
            || ($session->metadata->tenant ?? '')!==$tenant || !is_int($session->amount_total ?? null) || $session->amount_total!==(int)$order->price_minor_snapshot
            || strtoupper($session->currency ?? '')!==strtoupper($order->currency_snapshot)) throw new \RuntimeException('Provider checkout does not match the stored credit order.');
        return ($session->status ?? '')==='complete' && ($session->payment_status ?? '')==='paid';
    }
}
