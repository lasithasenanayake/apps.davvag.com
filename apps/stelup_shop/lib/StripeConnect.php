<?php
class StelupStripeConnect {
    const PREFIX = "<?php http_response_code(404); exit; ?>\n";
    private $root, $transport, $paymentFactory;
    public function __construct($root=null,$transport=null,$paymentFactory=null) {
        $this->root=$root?:dirname(__DIR__).'/private';
        $this->transport=$transport;
        $this->paymentFactory=$paymentFactory;
        if(!is_dir($this->root)&&!mkdir($this->root,0700,true)) {
            throw new Exception('Private payment storage is unavailable.');
        }
    }
    private function path($key) {
        if(!preg_match('/^[a-zA-Z0-9_-]+$/D',$key)) {
            throw new Exception('Invalid payment reference.');
        }
        return $this->root.'/'.$key.'.php';
    }
    public function read($key,$default=array()) {
        $p=$this->path($key);
        if(!is_file($p)) {
            return $default;
        }
        $raw=file_get_contents($p);
        if(substr($raw,0,strlen(self::PREFIX))!==self::PREFIX) {
            throw new Exception('Invalid private payment data.');
        }
        $v=json_decode(substr($raw,strlen(self::PREFIX)),true);
        if(!is_array($v)) {
            throw new Exception('Invalid private payment data.');
        }
        return $v;
    }
    public function write($key,$value) {
        $p=$this->path($key);
        $tmp=$this->root.'/tmp-'.bin2hex(random_bytes(12)).'.php';
        if(file_put_contents($tmp,self::PREFIX.json_encode($value,JSON_THROW_ON_ERROR),LOCK_EX)===false) {
            throw new Exception('Unable to save payment data.');
        }
        chmod($tmp,0600);
        if(!rename($tmp,$p)) {
            @unlink($tmp);
            throw new Exception('Unable to save payment data.');
        }
    }
    public function locked($key,$fn) {
        $h=fopen($this->path($key).'.lock','c');
        if(!$h||!flock($h,LOCK_EX)) {
            throw new Exception('Payment is busy. Try again.');
        }
        try {
            return $fn();
        }
        finally {
            flock($h,LOCK_UN);
            fclose($h);
        }
    }
    public function settings() {
        return array_merge(array('enabled'=>false,'mode'=>'test','publishable_key'=>'','secret_key'=>'','client_id'=>'','webhook_secret'=>'','base_url'=>'','platform_account'=>'','commission_percent'=>0,'service_fee_percent'=>0),$this->read('settings'));
    }
    public static function admin() {
        if(!defined('GROUPID')||!in_array(strtolower(GROUPID),array('admin','sysadmin'),true)) {
            throw new Exception('Administrator access is required.');
        }
    }
    public static function csrf() {
        if(empty($_SESSION['stelup_stripe_csrf'])) {
            $_SESSION['stelup_stripe_csrf']=bin2hex(random_bytes(32));
        }
        return $_SESSION['stelup_stripe_csrf'];
    }
    public static function checkCsrf($b) {
        if(!is_object($b)||!isset($b->csrf)||!hash_equals(self::csrf(),(string)$b->csrf)) {
            throw new Exception('Reload this page before trying again.');
        }
    }
    public static function rate($v) {
        if(!is_numeric($v)||!is_finite((float)$v)||$v<0||$v>100||round((float)$v,2)!=(float)$v) {
            throw new Exception('Percentages must be 0 to 100 with at most two decimal places.');
        }
        return (float)$v;
    }
    public function publicSettings() {
        $s=$this->settings();
        return array_intersect_key($s,array_flip(array('enabled','commission_percent','service_fee_percent')));
    }
    public function adminSettings() {
        self::admin();
        $s=$this->settings();
        $s['has_secret_key']=$s['secret_key']!=='';
        $s['has_webhook_secret']=$s['webhook_secret']!=='';
        unset($s['secret_key'],$s['webhook_secret']);
        $s['csrf']=self::csrf();
        $s['callback_url']=$s['base_url'].'/components/stelup_shop/stripe-api/service/Callback';
        $s['webhook_url']=$s['base_url'].'/components/stelup_shop/stripe-api/service/Webhook';
        return $s;
    }
    public function saveSettings($b) {
        self::admin();
        self::checkCsrf($b);
        return $this->locked('settings',function()use($b) {
            $s=$this->settings();
            foreach(array('mode','publishable_key','client_id','base_url')as $k) {
                $s[$k]=trim((string)($b->$k??$s[$k]));
            }
            foreach(array('secret_key','webhook_secret')as $k) {
                if(!empty($b->$k)) {
                    $s[$k]=trim((string)$b->$k);
                }
            }
            $s['enabled']=!empty($b->enabled);
            foreach(array('commission_percent','service_fee_percent')as $k) {
                $s[$k]=self::rate($b->$k??0);
            }
            $s['base_url']=rtrim($s['base_url'],'/');
            $u=parse_url($s['base_url']);
            if(!$u||empty($u['host'])||isset($u['user'])||isset($u['query'])||isset($u['fragment'])||!(($u['scheme']??'')==='https'||(($u['scheme']??'')==='http'&&in_array($u['host'],array('localhost','127.0.0.1'),true)))) {
                throw new Exception('Enter your public HTTPS site URL. HTTP is allowed only for localhost.');
            }
            if(!in_array($s['mode'],array('test','live'),true)) {
                throw new Exception('Choose test or live mode.');
            }
            if($s['enabled']) {
                if(!preg_match('/^sk_'.$s['mode'].'_[a-zA-Z0-9]+$/D',$s['secret_key'])||!preg_match('/^pk_'.$s['mode'].'_[a-zA-Z0-9]+$/D',$s['publishable_key'])||!preg_match('/^ca_[a-zA-Z0-9]+$/D',$s['client_id'])||!preg_match('/^whsec_[a-zA-Z0-9]+$/D',$s['webhook_secret'])) {
                    throw new Exception('Provide matching platform keys, Connect client ID and webhook signing secret.');
                }
                $a=$this->api('GET','/v1/account',array(),'','',$s);
                $s['platform_account']=$a['id'];
            }
            $this->write('settings',$s);
            return $this->adminSettings();
        }
        );
    }
    public function api($method,$path,$data=array(),$account='',$idempotency='',$settings=null) {
        $s=$settings?:$this->settings();
        if($this->transport) {
            return call_user_func($this->transport,$method,$path,$data,$account,$idempotency,$s);
        }
        if(!function_exists('curl_init')) {
            throw new Exception('PHP cURL is required for Stripe.');
        }
        $url=$path==='/oauth/token'?'https://connect.stripe.com/oauth/token':'https://api.stripe.com'.$path;
        $headers=array('Authorization: Bearer '.$s['secret_key'],'Stripe-Version: 2024-06-20');
        if($account) {
            if(!preg_match('/^acct_[a-zA-Z0-9]+$/D',$account)) {
                throw new Exception('Invalid connected account.');
            }
            $headers[]='Stripe-Account: '.$account;
        }
        if($idempotency) {
            $headers[]='Idempotency-Key: '.$idempotency;
        }
        if($method==='GET'&&$data) {
            $url.='?'.http_build_query($data);
        }
        $c=curl_init($url);
        curl_setopt_array($c,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2));
        if($method==='POST') {
            curl_setopt($c,CURLOPT_POST,true);
            curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($data));
        }
        $raw=curl_exec($c);
        $status=curl_getinfo($c,CURLINFO_HTTP_CODE);
        curl_close($c);
        if($raw===false||$status<200||$status>=300) {
            throw new Exception('Stripe request failed. Check the platform configuration and connected account.');
        }
        $v=json_decode($raw,true);
        if(!is_array($v)) {
            throw new Exception('Stripe returned an invalid response.');
        }
        return $v;
    }
    public function readySettings() {
        $s=$this->settings();
        if(!$s['enabled']||!$s['secret_key']||!$s['platform_account']) {
            throw new Exception('Stripe payments have not been configured by the administrator.');
        }
        return $s;
    }
    private function accountKey($id) {
        $s=$this->readySettings();
        return 'seller-'.$s['mode'].'-'.$s['platform_account'].'-'.(int)$id;
    }
    public function account($id,$refresh=true) {
        $key=$this->accountKey($id);
        $d=$this->read($key);
        if(!empty($d['account_id'])&&$refresh) {
            $a=$this->api('GET','/v1/accounts/'.$d['account_id']);
            foreach(array('charges_enabled','payouts_enabled','details_submitted')as $k) {
                $d[$k]=!empty($a[$k]);
            }
            $this->write($key,$d);
        }
        return $d;
    }
    public function requireAccount($id) {
        $a=$this->account($id);
        if(empty($a['account_id'])||empty($a['charges_enabled'])||empty($a['payouts_enabled'])) {
            throw new Exception('This seller must finish connecting an active Stripe account before accepting payments.');
        }
        return $a;
    }
    public function authorize($p) {
        $s=$this->readySettings();
        $state=bin2hex(random_bytes(32));
        $_SESSION['stelup_connect']=array('state'=>$state,'profile_id'=>(int)$p->id,'expires'=>time()+900,'platform'=>$s['platform_account'],'mode'=>$s['mode']);
        return array('url'=>'https://connect.stripe.com/oauth/authorize?'.http_build_query(array('response_type'=>'code','scope'=>'read_write','client_id'=>$s['client_id'],'redirect_uri'=>$s['base_url'].'/components/stelup_shop/stripe-api/service/Callback','state'=>$state,'stripe_user'=>array('email'=>$p->email??''))));
    }
    public function callback($p,$q) {
        $s=$this->readySettings();
        $v=$_SESSION['stelup_connect']??array();
        unset($_SESSION['stelup_connect']);
        if(empty($v['state'])||!hash_equals($v['state'],(string)($q['state']??''))||$v['expires']<time()||$v['profile_id']!=(int)$p->id||$v['platform']!==$s['platform_account']||$v['mode']!==$s['mode']) {
            throw new Exception('Invalid or expired Stripe connection state.');
        }
        if(!empty($q['error'])||empty($q['code'])) {
            throw new Exception('Stripe connection was not completed.');
        }
        $t=$this->api('POST','/oauth/token',array('grant_type'=>'authorization_code','code'=>$q['code']));
        if(empty($t['stripe_user_id'])||(bool)($t['livemode']??false)!==($s['mode']==='live')) {
            throw new Exception('The Stripe account mode does not match the platform.');
        }
        $this->write($this->accountKey($p->id),array('account_id'=>$t['stripe_user_id'],'connected_at'=>gmdate('c')));
        return $this->account($p->id);
    }
    public static function factor($currency) {
        $c=strtolower($currency);
        if(in_array($c,array('bhd','jod','kwd','omr','tnd'),true)) {
            throw new Exception('Three-decimal currencies are not supported.');
        }
        return in_array($c,array('bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','vnd','vuv','xaf','xof','xpf'),true)?1:100;
    }
    public static function amounts($subtotal,$commission,$serviceFee,$currency='gbp') {
        $factor=self::factor($currency);
        $unit=in_array(strtolower($currency),array('isk','ugx'),true)?100:1;
        $fee=function($rate)use($subtotal,$unit) {
            return(int)(round(($subtotal*self::rate($rate)/100)/$unit,0,PHP_ROUND_HALF_UP)*$unit);
        }
        ;
        $c=$fee($commission);
        $f=$fee($serviceFee);
        return array('subtotal_minor'=>$subtotal,'commission_minor'=>$c,'service_fee_minor'=>$f,'total_minor'=>$subtotal+$f,'application_fee_minor'=>$c+$f,'factor'=>$factor,'subtotal'=>$subtotal/$factor,'commission'=>$c/$factor,'service_fee'=>$f/$factor,'total'=>($subtotal+$f)/$factor);
    }
    public function quote($cart,$reserved=array()) {
        $s=$this->readySettings();
        if(!is_object($cart)||empty($cart->storeid)||empty($cart->items)||!is_array($cart->items)||count($cart->items)>100) {
            throw new Exception('Select a seller and at most 100 cart items.');
        }
        $this->requireAccount($cart->storeid);
        $rows=array();
        $currency='';
        $subtotal=0;
        $seen=array();
        foreach($cart->items as $x) {
            if(!isset($x->itemid,$x->qty)||!ctype_digit((string)$x->itemid)||!is_numeric($x->qty)||(float)$x->qty!=(int)$x->qty||$x->qty<1||$x->qty>10000||isset($seen[$x->itemid])) {
                throw new Exception('Invalid product or quantity.');
            }
            $seen[$x->itemid]=true;
            $v=SOSSData::Query('products',urlencode('itemid:'.(int)$x->itemid));
            if(!$v->success||empty($v->result)) {
                throw new Exception('A product is no longer available.');
            }
            $item=$v->result[0];
            if((string)$item->storeid!==(string)$cart->storeid||(isset($item->showonstore)&&$item->showonstore==='N')) {
                throw new Exception('A product is not available from this seller.');
            }
            if(isset($item->qty)&&$x->qty>((int)$item->qty+(int)($reserved[(int)$item->itemid]??0))) {
                throw new Exception('Insufficient stock for '.$item->name);
            }
            $code=strtolower((string)($item->currencycode??'gbp'));
            if(!preg_match('/^[a-z]{3}$/D',$code)||($currency&&$currency!==$code)) {
                throw new Exception('A checkout must use one valid currency.');
            }
            $currency=$code;
            $factor=self::factor($code);
            $price=(float)($item->price??-1);
            $discount=(float)($item->discountper??0);
            if(!is_finite($price)||$price<0||!is_finite($discount)||$discount<0||$discount>100) {
                throw new Exception('Invalid product pricing.');
            }
            $unit=(int)round($price*(1-$discount/100)*$factor,0,PHP_ROUND_HALF_UP);
            if(in_array($code,array('isk','ugx'),true)&&$unit%100!==0) {
                throw new Exception('This currency requires whole-unit prices.');
            }
            $subtotal+=$unit*(int)$x->qty;
            $rows[]=array('itemid'=>(int)$item->itemid,'name'=>$item->name,'qty'=>(int)$x->qty,'unit_minor'=>$unit,'uom'=>$item->uom??null,'invType'=>$item->invType??'inventry','tid'=>$item->tid??null);
        }
        usort($rows,function($a,$b) {
            return $a['itemid']<=>$b['itemid'];
        }
        );
        if($subtotal<1||$subtotal>99999999) {
            throw new Exception('Checkout total is outside supported payment limits.');
        }
        return array_merge(self::amounts($subtotal,$s['commission_percent'],$s['service_fee_percent'],$currency),array('currency'=>$currency,'storeid'=>(int)$cart->storeid,'items'=>$rows,'service_fee_percent'=>$s['service_fee_percent'],'commission_percent'=>$s['commission_percent']));
    }
    private function quoteHash($q) {
        return hash('sha256',json_encode($q).json_encode($this->settings()));
    }
    private function matchesCart($cart,$quote) {
        if(!is_object($cart)||(int)($cart->storeid??0)!==$quote['storeid']||!is_array($cart->items??null)||count($cart->items)!==count($quote['items'])) {
            return false;
        }
        $rows=array();
        foreach($cart->items as $item) {
            if(!isset($item->itemid,$item->qty)||!is_numeric($item->qty)||(float)$item->qty!=(int)$item->qty) {
                return false;
            }
            $rows[(int)$item->itemid]=(int)$item->qty;
        }
        foreach($quote['items'] as $item) {
            if(($rows[$item['itemid']]??0)!==$item['qty']) {
                return false;
            }
        }
        return true;
    }
    private function reservedItems($record,$buyer,$cart) {
        if(!$record||$record['buyer']!=(int)$buyer->id||!empty($record['expired'])||!empty($record['completed'])||!$this->matchesCart($cart,$record['quote'])) {
            return array();
        }
        $reserved=array();
        foreach($record['quote']['items'] as $item) {
            $reserved[$item['itemid']]=$item['qty'];
        }
        return $reserved;
    }
    public function issueQuote($cart,$buyer) {
        $quotes=$_SESSION['stelup_quotes']??array();
        foreach($quotes as $token=>$saved) {
            if($saved['buyer']!=(int)$buyer->id) {
                continue;
            }
            $record=$this->read('checkout-'.$token);
            if(!$record||!empty($record['completed'])||!empty($record['expired'])||!$this->matchesCart($cart,$record['quote'])) {
                continue;
            }
            $q=$this->quote($cart,$this->reservedItems($record,$buyer,$cart));
            if(hash_equals($saved['hash'],$this->quoteHash($q))) {
                $_SESSION['stelup_quotes'][$token]['expires']=time()+900;
                $q['quote_token']=$token;
                return $q;
            }
        }
        $q=$this->quote($cart);
        $quotes=array_filter($quotes,function($v) {
            return $v['expires']>time();
        }
        );
        foreach($quotes as $token=>$saved) {
            if($saved['buyer']==(int)$buyer->id&&hash_equals($saved['hash'],$this->quoteHash($q))) {
                $record=$this->read('checkout-'.$token);
                if(!empty($record['completed'])||!empty($record['expired'])){continue;}
                $q['quote_token']=$token;
                return $q;
            }
        }
        if(count($quotes)>10) {
            $quotes=array_slice($quotes,-10,null,true);
        }
        $token=bin2hex(random_bytes(24));
        $quotes[$token]=array('hash'=>$this->quoteHash($q),'buyer'=>(int)$buyer->id,'expires'=>time()+900);
        $_SESSION['stelup_quotes']=$quotes;
        $q['quote_token']=$token;
        return $q;
    }
    public function createCheckout($body,$buyer,$res) {
        return $this->locked('inventory',function()use($body,$buyer,$res){
            return $this->createCheckoutLocked($body,$buyer,$res);
        });
    }
    private function createCheckoutLocked($body,$buyer,$res) {
        $cart=$body->data??null;
        $token=(string)($body->quote_token??'');
        if(!preg_match('/^[a-f0-9]{48}$/D',$token)) {
            throw new Exception('Review the checkout total before paying.');
        }
        $existing=$this->read('checkout-'.$token);
        if(!empty($existing['completed'])&&$existing['buyer']==(int)$buyer->id){$s=$this->readySettings();return array('checkout_url'=>$s['base_url'].'/#/app/stelup_shop/order-complete?checkout_token='.$token);}
        if(!empty($existing['expired'])) {
            throw new Exception('This checkout expired. Refresh the order summary.');
        }
        $q=$this->quote($cart,$this->reservedItems($existing,$buyer,$cart));
        $v=$_SESSION['stelup_quotes'][$token]??array();
        if(!$v||$v['buyer']!=(int)$buyer->id||$v['expires']<time()||!hash_equals($v['hash'],$this->quoteHash($q))) {
            throw new Exception('The cart or fees changed. Refresh the order summary before paying.');
        }
        return $this->locked('checkout-'.$token,function()use($body,$buyer,$res,$q,$token) {
            $s=$this->readySettings();
            $a=$this->requireAccount($q['storeid']);
            $r=$this->read('checkout-'.$token);
            if(!$r) {
                foreach(array('name','contactno','address','country')as $k) {
                    if(empty($body->$k)||!is_scalar($body->$k)) {
                        throw new Exception('Complete your name, contact number, address and country.');
                    }
                }
                $v=Profile::getProfile($q['storeid'],0);
                $seller=$v->profile??null;
                if(!$seller) {
                    throw new Exception('Seller not found.');
                }
                $o=new stdClass();
                $o->profileId=$buyer->id;
                $o->email=$buyer->email;
                foreach(array('name','contactno','address','city','country','remarks')as $k) {
                    $o->$k=substr(trim((string)($body->$k??'')),0,1000);
                }
                $o->supplier_profileId=$seller->id;
                foreach(array('name','contactno','address','city','country','email')as $k) {
                    $field='supplier_'.$k;
                    $o->$field=$seller->$k??'';
                }
                $o->tenant=defined('HOST_NAME')?HOST_NAME:'';
                $o->currencycode=$q['currency'];
                $o->paymenttype='Stripe Connect';
                $o->orderdate=$o->invoiceDate=$o->invoiceDueDate=date('m-d-Y H:i:s');
                $o->status='new';
                $o->total=$q['total'];
                $o->subtotal=$q['subtotal'];
                $o->taxamount=0;
                $o->paidamount=0;
                $o->balance=$q['total'];
                $o->InvoiceItems=array();
                foreach($q['items']as $row) {
                    $d=(object)$row;
                    $d->productid=$row['itemid'];
                    $d->tenant=$o->tenant;
                    $d->price=$row['unit_minor']/$q['factor'];
                    $d->total=$d->price*$d->qty;
                    unset($d->unit_minor);
                    $o->InvoiceItems[]=$d;
                }
                $h=new Davvag_Order();
                $o=$h->InvoiceSave($o,$res);
                if(empty($o->invoiceNo)) {
                    throw new Exception('Order could not be saved.');
                }
                $r=array('orderid'=>$o->invoiceNo,'buyer'=>(int)$buyer->id,'account'=>$a['account_id'],'mode'=>$s['mode'],'platform'=>$s['platform_account'],'quote'=>$q,'session'=>'','completed'=>false,'pending_header'=>$o,'pending_saved'=>false);
                $this->write('checkout-'.$token,$r);
            }
            if(empty($r['pending_saved'])) {
                $pending=(object)$r['pending_header'];
                $pending->InvoiceItems=array_map(function($item) {
                    return(object)$item;
                }
                ,(array)$pending->InvoiceItems);
                foreach(array('orderheader_pending','orderdetails_pending')as $table) {
                    $existing=SOSSData::Query($table,urlencode('invoiceno:'.$r['orderid']));
                    if(!$existing->success) {
                        throw new Exception('Unable to verify the pending order.');
                    }
                    if(empty($existing->result)) {
                        $saved=SOSSData::Insert($table,$table==='orderheader_pending'?$pending:$pending->InvoiceItems);
                        if(!$saved->success) {
                            throw new Exception('Unable to save pending order. Retry this checkout.');
                        }
                    }
                }
                $r['pending_saved']=true;
                unset($r['pending_header']);
                $this->write('checkout-'.$token,$r);
            }
            if($r['completed']) {
                return array('checkout_url'=>$s['base_url'].'/#/app/stelup_shop/order-complete?checkout_token='.$token);
            }
            $lines=array();
            foreach($q['items']as $row) {
                $lines[]=array('quantity'=>$row['qty'],'price_data'=>array('currency'=>$q['currency'],'unit_amount'=>$row['unit_minor'],'product_data'=>array('name'=>$row['name'])));
            }
            if($q['service_fee_minor']>0) {
                $lines[]=array('quantity'=>1,'price_data'=>array('currency'=>$q['currency'],'unit_amount'=>$q['service_fee_minor'],'product_data'=>array('name'=>'Service fee ('.$q['service_fee_percent'].'%)')));
            }
            $params=array('mode'=>'payment','payment_method_types'=>array('card'),'client_reference_id'=>(string)$r['orderid'],'customer_email'=>$buyer->email,'line_items'=>$lines,'metadata'=>array('stelup_checkout'=>$token),'payment_intent_data'=>array('application_fee_amount'=>$q['application_fee_minor']),'success_url'=>$s['base_url'].'/#/app/stelup_shop/order-complete?checkout_token='.$token,'cancel_url'=>$s['base_url'].'/#/app/stelup_shop/checkout?payment=cancelled');
            $session=$this->api('POST','/v1/checkout/sessions',$params,$r['account'],'stelup-'.$token);
            if(empty($session['id'])||empty($session['url'])) {
                throw new Exception('Stripe checkout could not be opened.');
            }
            $r['session']=$session['id'];
            $this->write('checkout-'.$token,$r);
            return array('invoiceNo'=>$r['orderid'],'checkout_url'=>$session['url']);
        }
        );
    }
    public static function verifySignature($raw,$header,$secret,$now=null) {
        $v=array();
        foreach(explode(',',$header)as $part) {
            $pair=explode('=',$part,2);
            if(count($pair)===2) {
                $v[trim($pair[0])][]=$pair[1];
            }
        }
        $t=$v['t'][0]??'';
        if(!ctype_digit((string)$t)||abs(($now??time())-(int)$t)>300) {
            throw new Exception('Invalid webhook signature.');
        }
        $expected=hash_hmac('sha256',$t.'.'.$raw,$secret);
        foreach($v['v1']??array()as $sig) {
            if(hash_equals($expected,$sig)) {
                return true;
            }
        }
        throw new Exception('Invalid webhook signature.');
    }
    public function settle($token,$session,$eventAccount=null,$eventLive=null) {
        return $this->locked('checkout-'.$token,function()use($token,$session,$eventAccount,$eventLive) {
            $r=$this->read('checkout-'.$token);
            $s=$this->settings();
            if(!$r||$r['platform']!==$s['platform_account']||$r['mode']!==$s['mode']||$r['session']!==($session['id']??'')||($eventAccount!==null&&$r['account']!==$eventAccount)||($eventLive!==null&&$eventLive!==($r['mode']==='live'))||($session['metadata']['stelup_checkout']??'')!==$token) {
                throw new Exception('Checkout verification failed.');
            }
            if(($session['payment_status']??'')!=='paid') {
                return $r;
            }
            if((int)($session['amount_total']??-1)!==$r['quote']['total_minor']||strtolower($session['currency']??'')!==$r['quote']['currency']) {
                throw new Exception('Payment amount does not match the order.');
            }
            if(!$r['completed']) {
                require_once __DIR__.'/StripeOrder.php';
                $h=$this->paymentFactory?call_user_func($this->paymentFactory):new StelupStripeOrder($this);
                $o=$h->getOrder($r['orderid']);
                if(!$o||(int)$o->profileId!==$r['buyer']||abs((float)$o->total-$r['quote']['total'])>0.00001) {
                    throw new Exception('Order verification failed.');
                }
                if((float)$o->balance>0) {
                    $receipt=$h->PayOrder($r['orderid'],$r['quote']['total'],'Stripe Connect payment','Stripe Connect',$session['payment_intent']??$session['id']);
                    if(!$receipt||(is_object($receipt)&&isset($receipt->success)&&!$receipt->success)) {
                        throw new Exception('Payment reconciliation is pending.');
                    }
                    $updated=$h->getOrder($r['orderid']);
                    if(!$updated||(float)$updated->balance>0.00001) {
                        throw new Exception('Payment reconciliation is pending.');
                    }
                }
                $h->AcceptOrder($r['orderid']);
                $r['completed']=true;
                $r['paid_at']=gmdate('c');
                $r['payment_intent']=$session['payment_intent']??'';
                $this->write('checkout-'.$token,$r);
                CacheData::clearObjects('products');
            }
            return $r;
        }
        );
    }
    private function expireOrder($token,$session,$account,$live) {
        $this->settle($token,$session,$account,$live);
        return $this->locked('inventory',function()use($token){
            return $this->locked('checkout-'.$token,function()use($token){
                $r=$this->read('checkout-'.$token);
                if(!empty($r['completed'])||!empty($r['expired'])){return;}
                require_once __DIR__.'/StripeOrder.php';
                $handler=$this->paymentFactory?call_user_func($this->paymentFactory):new StelupStripeOrder($this);
                $order=$handler->getOrder($r['orderid']);
                if($order&&(float)$order->balance>0){
                    $handler->DeleteOrder($r['orderid'],'Stripe Checkout expired',0,'Stripe Connect',$r['buyer']);
                    foreach(array('orderheader_pending','orderdetails_pending')as $table){
                        $pending=SOSSData::Query($table,urlencode('invoiceno:'.$r['orderid']),null,'DESC',1000,0,null,false);
                        if(!$pending->success){throw new Exception('Unable to reconcile expired order.');}
                        if(!empty($pending->result)){SOSSData::Delete($table,$pending->result);}
                    }
                }
                $r['expired']=true;$this->write('checkout-'.$token,$r);CacheData::clearObjects('products');
            });
        });
    }
    public function webhook($raw,$sig) {
        $s=$this->readySettings();
        self::verifySignature($raw,$sig,$s['webhook_secret']);
        $e=json_decode($raw,true);
        if(!is_array($e)) {
            throw new Exception('Invalid webhook payload.');
        }
        if(in_array($e['type']??'',array('checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'),true)) {
            $session=$e['data']['object']??array();
            $token=$session['metadata']['stelup_checkout']??'';
            if($token) {
                if(!preg_match('/^[a-f0-9]{48}$/D',$token)) {
                    throw new Exception('Invalid checkout reference.');
                }
                if($e['type']==='checkout.session.expired') {
                    $this->expireOrder($token,$session,$e['account']??'',(bool)($e['livemode']??false));
                }
                else {
                    $this->settle($token,$session,$e['account']??'',(bool)($e['livemode']??false));
                }
            }
        }
        return array('received'=>true);
    }
    public function paymentStatus($token,$buyer) {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token)) {
            throw new Exception('Invalid checkout reference.');
        }
        $r=$this->read('checkout-'.$token);
        $s=$this->readySettings();
        if(!$r||(int)$buyer->id!==$r['buyer']||$r['platform']!==$s['platform_account']||$r['mode']!==$s['mode']) {
            throw new Exception('Payment not found.');
        }
        if(!$r['completed']&&empty($r['expired'])) {
            $session=$this->api('GET','/v1/checkout/sessions/'.$r['session'],array(),$r['account']);
            $r=$this->settle($token,$session);
        }
        return array('paid'=>$r['completed'],'expired'=>!empty($r['expired']),'invoiceNo'=>$r['orderid'],'quote'=>$r['quote']);
    }
}
