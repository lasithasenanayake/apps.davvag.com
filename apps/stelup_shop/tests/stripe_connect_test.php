<?php
require_once dirname(__DIR__).'/lib/StripeConnect.php';
function check($value,$message){if(!$value){throw new Exception($message);}}
function rejects($fn,$message){try{$fn();}catch(Exception $e){return;}throw new Exception($message);}
class CacheData{public static function clearObjects($name){}}
class SOSSData {
    public static $products=array();
    public static function Query($table,$query){if($table!=='products'){return(object)array('success'=>true,'result'=>array());}parse_str('q='.$query,$v);$id=(int)substr($v['q'],strpos($v['q'],':')+1);return (object)array('success'=>true,'result'=>isset(self::$products[$id])?array(self::$products[$id]):array());}
    public static function Delete($table,$record){return(object)array("success"=>true);}
    public static function Insert($table,$record){return (object)array('success'=>true);}
}
class Profile{public static function getProfile($id,$viewer){return(object)array('profile'=>(object)array('id'=>$id,'name'=>'Shop','email'=>'shop@example.test'));}}
class Davvag_Order {
    public static $orders=array(),$paid=0;
    public function InvoiceSave($o,$res){$o->invoiceNo=count(self::$orders)+1;self::$orders[$o->invoiceNo]=clone $o;foreach($o->InvoiceItems as $item){SOSSData::$products[$item->itemid]->qty-=$item->qty;}return $o;}
    public function getOrder($id){return self::$orders[$id]??null;}
    public function PayOrder($id,$amount,$remarks,$type,$reference){self::$paid++;self::$orders[$id]->balance=0;return(object)array('success'=>true);}
    public function DeleteOrder($id,$remarks,$actor,$name,$buyer){foreach(self::$orders[$id]->InvoiceItems as $item){SOSSData::$products[$item->itemid]->qty+=$item->qty;}self::$orders[$id]->status='deleted';}
    public function AcceptOrder($id){self::$orders[$id]->status='accepted';}
}
$_SESSION=array();
rejects(function(){StelupStripeConnect::admin();},'Unauthenticated settings access must fail');
define('GROUPID','sysadmin');
$root=sys_get_temp_dir().'/stelup-stripe-unit-'.bin2hex(random_bytes(8));$calls=array();
$transport=function($method,$path,$data,$account,$key,$settings)use(&$calls){$calls[]=compact('method','path','data','account','key');if($path==='/oauth/token'){return array('stripe_user_id'=>'acct_seller','livemode'=>false,'access_token'=>'not-stored','refresh_token'=>'not-stored');}if($path==='/v1/account'){return array('id'=>'acct_platform');}if(strpos($path,'/v1/accounts/')===0){return array('charges_enabled'=>true,'payouts_enabled'=>true,'details_submitted'=>true);}if($path==='/v1/checkout/sessions'){return array('id'=>'cs_test_session','url'=>'https://checkout.stripe.com/test');}throw new Exception('Unexpected mocked request');};
try{
$c=new StelupStripeConnect($root,$transport,function(){return new Davvag_Order();});
check($c->publicSettings()['enabled']===false,'Payments must default to disabled');
$settings=(object)array('csrf'=>StelupStripeConnect::csrf(),'enabled'=>true,'mode'=>'test','publishable_key'=>'pk_test_example','secret_key'=>'sk_test_example','client_id'=>'ca_example','webhook_secret'=>'whsec_example','base_url'=>'https://shop.example.test','commission_percent'=>10,'service_fee_percent'=>5);
rejects(function()use($c,$settings){$b=clone $settings;$b->csrf='wrong';$c->saveSettings($b);},'Settings CSRF must fail');
$public=$c->saveSettings($settings);check(!array_key_exists('secret_key',$public)&&!array_key_exists('webhook_secret',$public),'Secret keys must never be returned');
check(strpos(file_get_contents($root.'/settings.php'),StelupStripeConnect::PREFIX)===0,'Private files must deny direct PHP requests');
check(!isset($c->publicSettings()['secret_key']),'Public settings must not contain keys');
rejects(function(){StelupStripeConnect::rate(-1);},'Negative rate must fail');rejects(function(){StelupStripeConnect::rate(100.01);},'Rate above 100 must fail');
$amount=StelupStripeConnect::amounts(10000,10,5);check($amount['total_minor']===10500&&$amount['application_fee_minor']===1500,'Buyer fee and seller commission must be separate');
check(StelupStripeConnect::amounts(101,0,0)['service_fee_minor']===0,'Disabled fee must be zero');
check(StelupStripeConnect::amounts(101,0,1)['service_fee_minor']===1,'Minor-unit fee rounding must be deterministic');
check(StelupStripeConnect::factor('jpy')===1&&StelupStripeConnect::factor('ugx')===100,'Currency factors must match Stripe');
$seller=(object)array('id'=>5,'email'=>'seller@example.test');$link=$c->authorize($seller);parse_str(parse_url($link['url'],PHP_URL_QUERY),$params);
rejects(function()use($c,$seller){$c->callback($seller,array('state'=>'wrong','code'=>'code'));},'Invalid OAuth state must fail');
$c->authorize($seller);$state=$_SESSION['stelup_connect']['state'];$c->callback($seller,array('state'=>$state,'code'=>'code'));
rejects(function()use($c,$seller,$state){$c->callback($seller,array('state'=>$state,'code'=>'code'));},'OAuth state replay must fail');
$account=$c->read('seller-test-acct_platform-5');check(!isset($account['access_token'],$account['refresh_token']),'OAuth tokens must not be stored');
SOSSData::$products[1]=(object)array('itemid'=>1,'storeid'=>5,'name'=>'Product','price'=>10.25,'discountper'=>10,'qty'=>2,'currencycode'=>'gbp');
$cart=(object)array('storeid'=>5,'items'=>array((object)array('itemid'=>1,'qty'=>2,'price'=>0.01)));
$buyer=(object)array('id'=>99,'email'=>'buyer@example.test');$q=$c->issueQuote($cart,$buyer);
check($q['subtotal_minor']===1846&&$q['service_fee_minor']===92&&$q['commission_minor']===185&&$q['total_minor']===1938,'Prices must come from DB and round per unit');
check($c->issueQuote($cart,$buyer)['quote_token']===$q['quote_token'],'Same cart quote must be reused for retries');
$body=(object)array('data'=>$cart,'quote_token'=>$q['quote_token'],'name'=>'Buyer','contactno'=>'123','address'=>'Address','country'=>'UK');
$wrong=clone $body;$wrong->data=clone $cart;$wrong->data->items=array((object)array('itemid'=>1,'qty'=>3));rejects(function()use($c,$wrong,$buyer){$c->createCheckout($wrong,$buyer,null);},'Changed quantity must invalidate a quote');
$c->createCheckout($body,$buyer,null);check($c->issueQuote($cart,$buyer)['quote_token']===$q['quote_token'],'Reserved cart must resume the same checkout');$c->createCheckout($body,$buyer,null);check(count(Davvag_Order::$orders)===1,'Checkout retries must not create duplicate invoices');
$sent=array_values(array_filter($calls,function($v){return $v['path']==='/v1/checkout/sessions';}));$request=$sent[0];check($request['account']==='acct_seller','Charge must target connected account');check($request['data']['payment_intent_data']['application_fee_amount']===277,'Application fee must combine commission and service fee');check($request['data']['line_items'][1]['price_data']['unit_amount']===92,'Stripe Checkout must display service fee');check($sent[0]['key']===$sent[1]['key'],'Checkout retries must use the same Stripe idempotency key');
$token=$q['quote_token'];$session=array('id'=>'cs_test_session','payment_status'=>'paid','amount_total'=>1938,'currency'=>'gbp','metadata'=>array('stelup_checkout'=>$token),'payment_intent'=>'pi_test');
rejects(function()use($c,$token,$session){$c->settle($token,$session,'acct_wrong',false);},'Wrong connected account must fail');$bad=$session;$bad['amount_total']=1;rejects(function()use($c,$token,$bad){$c->settle($token,$bad,'acct_seller',false);},'Wrong amount must fail');
$event=json_encode(array('type'=>'checkout.session.completed','account'=>'acct_seller','livemode'=>false,'data'=>array('object'=>$session)));$time=time();$signature='t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$event,'whsec_example');
rejects(function()use($c,$event){$c->webhook($event,'t=1,v1=invalid');},'Invalid webhook signature must fail');
$c->webhook($event,$signature);$c->webhook($event,$signature);check(Davvag_Order::$paid===1,'Webhook replay must not duplicate receipts');check($c->read('checkout-'.$token)['completed']===true,'Verified payment must complete order');
rejects(function()use($c,$token){$c->paymentStatus($token,(object)array('id'=>100));},'Buyer must not access another payment');
check($c->paymentStatus($token,$buyer)['paid']===true,'Buyer must receive verified payment status');
SOSSData::$products[1]->qty=5;check($c->issueQuote($cart,$buyer)['quote_token']!==$token,'A later purchase must not reuse a paid checkout');
// An expired checkout releases its reservation exactly once.
SOSSData::$products[2]=(object)array('itemid'=>2,'storeid'=>5,'name'=>'Second product','price'=>5,'qty'=>2,'currencycode'=>'gbp');
$cart2=(object)array('storeid'=>5,'items'=>array((object)array('itemid'=>2,'qty'=>2)));$q2=$c->issueQuote($cart2,$buyer);$body2=clone $body;$body2->data=$cart2;$body2->quote_token=$q2['quote_token'];$c->createCheckout($body2,$buyer,null);check(SOSSData::$products[2]->qty===0,'Pending order must reserve stock');
$expired=$session;$expired['metadata']['stelup_checkout']=$q2['quote_token'];$expired['payment_status']='unpaid';$expired['status']='expired';
$event2=json_encode(array('type'=>'checkout.session.expired','account'=>'acct_seller','livemode'=>false,'data'=>array('object'=>$expired)));$sig2='t='.$time.',v1='.hash_hmac('sha256',$time.'.'.$event2,'whsec_example');$c->webhook($event2,$sig2);$c->webhook($event2,$sig2);check(SOSSData::$products[2]->qty===2,'Expired session must release stock once');
echo "Stripe Connect tests passed: permissions, CSRF, OAuth, fees, authoritative pricing, idempotency and webhook verification.\n";
}finally{if(is_dir($root)){foreach(glob($root.'/*')as $file){if(is_file($file)){unlink($file);}}rmdir($root);}}
