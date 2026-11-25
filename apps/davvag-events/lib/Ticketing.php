<?php
namespace davvag_events;

final class Ticketing {
    public static function type(&$e,$in) {
        $id=$in['typeId']??Support::id();$currency=strtoupper(Support::text($in['currency']??'',3,true));if(!preg_match('/^[A-Z]{3}$/D',$currency))Support::fail('CURRENCY','Configure an ISO currency code.');
        $t=['id'=>$id,'name'=>Support::text($in['name']??'',120,true),'price'=>Support::integer($in['price']??0,0,99999999),'currency'=>$currency,'capacity'=>Support::integer($in['capacity']??1,1,1000),'limit'=>Support::integer($in['limit']??6,1,20),'start'=>Support::instant($in['start']??''),'end'=>Support::instant($in['end']??'')];
        if($t['end']<=$t['start'])Support::fail('DATE','Sale end must follow sale start.');
        if(self::typeUsed($e,$id)>$t['capacity'])Support::fail('CAPACITY','This type has more allocations than the new capacity.',409);
        foreach($e['ticketTypes'] as $i=>$old)if($old['id']===$id){$e['ticketTypes'][$i]=$t;return $t;}$e['ticketTypes'][]=$t;return $t;
    }
    public static function typeUsed($e,$type,$except=null) {
        $n=0;foreach($e['tickets'] as $t)if($t['typeId']===$type&&$t['status']==='issued')$n++;
        foreach($e['orders'] as $o)if($o['typeId']===$type&&$o['id']!==$except&&in_array($o['status'],['holding','checkout'],true)&&$o['expires']>Support::now())$n+=count($o['attendees']);return $n;
    }
    public static function order(&$e,$in,$partyId=null) {
        Domain::window($e,'sales');$operation=Support::text($in['operation']??'',80,true);
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$operation))Support::fail('IDEMPOTENCY','Supply a stable operation key.');
        foreach($e['orders'] as $o)if($o['operation']===$operation){$sessionHash=$_SESSION['davvag_events_orders'][$e['id']][$o['id']]??'';if(($partyId!==null&&$o['partyId']!==$partyId)||($partyId===null&&!hash_equals($o['retrievalHash'],$sessionHash)))Support::fail('ORDER','Order access denied.',403);return $o;}
        $type=null;foreach($e['ticketTypes'] as $t)if($t['id']===($in['typeId']??''))$type=$t;if(!$type)Support::fail('TYPE','Ticket type not found.',404);
        if(Support::now()<$type['start']||Support::now()>$type['end'])Support::fail('SALE_WINDOW','This ticket type is not on sale.',409);
        $names=$in['attendees']??[];if(!is_array($names)||count($names)<1||count($names)>$type['limit'])Support::fail('ORDER_LIMIT','Enter attendees within the ticket limit.');
        if(self::typeUsed($e,$type['id'])+count($names)>$type['capacity']||Domain::occupancy($e)+count($names)>$e['published']['capacity'])Support::fail('SOLD_OUT','These tickets are no longer available.',409);
        if($type['price']>0&&!Stripe::configured())Support::fail('PAYMENT_SETUP','Paid sales are disabled until the host configures an eligible Stripe merchant.',503);
        $email=Support::email($in['email']??'');$attendees=[];foreach($names as $name)$attendees[]=Support::text($name,180,true);
        if(!$partyId){$p=Domain::addParty($e,['names'=>$attendees,'email'=>$email,'emailConsent'=>!empty($in['emailConsent'])]);$partyId=$p['id'];$ids=array_column(Domain::partyView($e,$partyId)['attendees'],'id');}
        else {$party=Domain::partyView($e,$partyId);if(count($names)>$party['maxAttendees'])Support::fail('PARTY','This order exceeds your invitation size.');$ids=[];foreach($attendees as $i=>$name){if(isset($party['attendees'][$i]))$ids[]=$party['attendees'][$i]['id'];else{$p=$e['parties'][Domain::partyIndex($e,$partyId)];$g=Domain::guest($partyId,$name,$p['group']);$e['guests'][]=$g;$ids[]=$g['id'];}}}
        foreach($ids as $id){foreach($e['tickets'] as $ticket)if($ticket['guestId']===$id&&$ticket['status']==='issued')Support::fail('ALREADY_TICKETED','An attendee already has admission.',409);foreach($e['orders'] as $old)if(in_array($id,$old['attendees'],true)&&in_array($old['status'],['holding','checkout'],true)&&$old['expires']>Support::now())Support::fail('ORDER_PENDING','An attendee already has an active checkout.',409);}
        $retrieval=Support::secret();$o=['id'=>Support::id(),'operation'=>$operation,'partyId'=>$partyId,'typeId'=>$type['id'],'attendees'=>$ids,'email'=>$email,'currency'=>$type['currency'],'unitPrice'=>$type['price'],'amount'=>$type['price']*count($ids),'status'=>'holding','expires'=>gmdate('Y-m-d\TH:i:s\Z',time()+2100),'createdAt'=>Support::now(),'retrievalHash'=>hash('sha256',$retrieval),'sessionId'=>null,'paymentId'=>null,'checkoutUrl'=>null];
        $e['orders'][]=$o;$_SESSION['davvag_events_orders'][$e['id']][$o['id']]=$o['retrievalHash'];
        if($o['amount']===0){$idx=count($e['orders'])-1;self::fulfill($e,$idx,'free');$o=$e['orders'][$idx];}
        $o['retrieval']=$retrieval;return $o;
    }
    public static function fulfill(&$e,$index,$paymentId) {
        $o=$e['orders'][$index];if($o['status']==='issued')return;
        if(in_array($o['status'],['refunded','partially_refunded','refund_pending'],true))return;
        $type=null;foreach($e['ticketTypes'] as $t)if($t['id']===$o['typeId'])$type=$t;
        if(in_array($e['status'],['cancelled','archived'],true)||!$type||self::typeUsed($e,$o['typeId'],$o['id'])+count($o['attendees'])>$type['capacity']||Domain::occupancy($e,$o['id'])+count($o['attendees'])>$e['draft']['capacity']){
            $e['orders'][$index]['status']='refund_pending';$e['orders'][$index]['paymentId']=$paymentId;Jobs::enqueue($e,'refund',$o['id'],'refund-'.$o['id']);return;
        }
        foreach($o['attendees'] as $guestId){$e['guests'][Domain::guestIndex($e,$guestId)]['rsvp']='Attending';$id=Support::id();$e['tickets'][]=['id'=>$id,'orderId'=>$o['id'],'typeId'=>$o['typeId'],'guestId'=>$guestId,'status'=>'issued','issuedAt'=>Support::now()];Domain::issue($e,'admission',$id);}
        $e['orders'][$index]['status']='issued';$e['orders'][$index]['paymentId']=$paymentId;
    }
    public static function revoke(&$e,$orderId,$status='refunded') {
        $ids=[];$guests=[];foreach($e['tickets'] as &$t)if($t['orderId']===$orderId){$t['status']='revoked';$ids[]=$t['id'];$guests[]=$t['guestId'];}unset($t);
        foreach($e['tokens'] as &$t)if($t['scope']==='admission'&&in_array($t['reference'],$ids,true))$t['revoked']=true;unset($t);
        $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($guests){return !in_array($a['guestId'],$guests,true);}));
        foreach($e['orders'] as &$o)if($o['id']===$orderId)$o['status']=$status;unset($o);
    }
    public static function orderAccess($e,$id,$secret=null) {
        foreach($e['orders'] as $i=>$o)if($o['id']===$id){$hash=$secret?hash('sha256',$secret):($_SESSION['davvag_events_orders'][$e['id']][$id]??'');if(hash_equals($o['retrievalHash'],$hash))return $i;}
        Support::fail('ORDER','Open the private order confirmation in its original browser.',403);
    }
    public static function display(&$e,$orderIndex) {
        $o=$e['orders'][$orderIndex];$out=['id'=>$o['id'],'status'=>$o['status'],'amount'=>$o['amount'],'currency'=>$o['currency'],'checkoutUrl'=>$o['checkoutUrl'],'tickets'=>[]];
        if($o['status']!=='issued')return $out;
        foreach($e['tickets'] as $t)if($t['orderId']===$o['id']&&$t['status']==='issued'){
            foreach($e['tokens'] as &$token)if($token['scope']==='admission'&&$token['reference']===$t['id'])$token['revoked']=true;unset($token);
            $secret=Domain::issue($e,'admission',$t['id']);$g=$e['guests'][Domain::guestIndex($e,$t['guestId'])];$out['tickets'][]=['id'=>$t['id'],'name'=>$g['name'],'code'=>$secret,'url'=>Support::baseUrl().'#/app/davvag-events/tickets?eventId='.$e['id'].'&admission='.$secret];
        }return $out;
    }
}

final class Stripe {
    public static function configured() { return getenv('DAVVAG_EVENTS_STRIPE_ELIGIBILITY_CONFIRMED')==='yes'&&preg_match('/^(sk|rk)_(test|live)_/',getenv('DAVVAG_EVENTS_STRIPE_KEY')?:'')&&getenv('DAVVAG_EVENTS_STRIPE_WEBHOOK_SECRET')&&getenv('DAVVAG_EVENTS_STRIPE_ACCOUNT'); }
    public static function request($method,$path,$fields=[],$key='') {
        if(!self::configured())Support::fail('PAYMENT_SETUP','Stripe sales are not configured.',503);
        $headers=['Authorization: Bearer '.getenv('DAVVAG_EVENTS_STRIPE_KEY'),'Content-Type: application/x-www-form-urlencoded'];if($key)$headers[]='Idempotency-Key: '.hash('sha256',DATASTORE_DOMAIN.'|'.$key);
        return Http::json($method,'https://api.stripe.com/v1'.$path,$method==='POST'?http_build_query($fields):null,$headers);
    }
    public static function create($e,$o) {
        if(strtotime($o['expires'])<time()+1800)Support::fail('HOLD_EXPIRING','This payment hold is too close to expiry. Wait for it to expire before starting a new reservation.',409);
        $fields=['mode'=>'payment','client_reference_id'=>$o['id'],'metadata'=>['eventId'=>$e['id'],'orderId'=>$o['id'],'tenant'=>(string)DATASTORE_DOMAIN],'payment_intent_data'=>['metadata'=>['eventId'=>$e['id'],'orderId'=>$o['id'],'tenant'=>(string)DATASTORE_DOMAIN]],'line_items'=>[['quantity'=>count($o['attendees']),'price_data'=>['currency'=>strtolower($o['currency']),'unit_amount'=>$o['unitPrice'],'product_data'=>['name'=>$e['published']['title']]]]],'expires_at'=>strtotime($o['expires']),'success_url'=>Support::baseUrl().'#/app/davvag-events/tickets?eventId='.$e['id'].'&orderId='.$o['id'],'cancel_url'=>Support::baseUrl().'#/app/davvag-events/invitation?eventId='.$e['id']];
        $s=self::request('POST','/checkout/sessions',$fields,'checkout-'.$o['id']);
        if(!preg_match('~^https://checkout\.stripe\.com/[^\s]+$~D',$s['url']??'')||empty($s['id']))Support::fail('PROVIDER','Stripe returned an invalid checkout.',503);return $s;
    }
    public static function signature($raw,$header,$secret,$now=null) {
        $parts=[];foreach(explode(',',$header) as $p){$kv=explode('=',$p,2);if(count($kv)===2)$parts[$kv[0]][]=$kv[1];}$timestamp=$parts['t'][0]??'';
        if(!ctype_digit($timestamp)||abs(($now??time())-(int)$timestamp)>300)Support::fail('SIGNATURE','Invalid webhook signature.',400);
        $expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);foreach($parts['v1']??[] as $v)if(hash_equals($expected,$v))return true;Support::fail('SIGNATURE','Invalid webhook signature.',400);
    }
    public static function verify($session,$e,$o) {
        if(($session['id']??'')!==$o['sessionId']||($session['client_reference_id']??'')!==$o['id']||($session['mode']??'')!=='payment'||($session['metadata']['eventId']??'')!==$e['id']||($session['metadata']['orderId']??'')!==$o['id']||($session['metadata']['tenant']??'')!==(string)DATASTORE_DOMAIN||($session['amount_total']??null)!==$o['amount']||strtoupper($session['currency']??'')!==$o['currency'])Support::fail('PAYMENT_MISMATCH','Payment does not match the stored order.',409);
        return ($session['payment_status']??'')==='paid'&&($session['status']??'')==='complete';
    }
}

final class Admission {
    public static function resolve($e,$secret) {
        $token=Domain::capability($e,'admission',$secret);$id=$token['reference'];$guestId=null;
        foreach($e['tickets'] as $t)if($t['id']===$id){if($t['status']!=='issued')Support::fail('REVOKED','Admission has been revoked or refunded.',409);$guestId=$t['guestId'];}
        if(!$guestId){if(!empty($e['draft']['modules']['tickets']))Support::fail('INVALID_ADMISSION','A valid ticket is required.',403);$guestId=$id;}
        $g=$e['guests'][Domain::guestIndex($e,$guestId)];if($g['rsvp']!=='Attending')Support::fail('NOT_ATTENDING','This attendee is not confirmed.',409);
        return ['admission'=>$id,'guest'=>$g];
    }
    public static function checkin(&$e,$secret,$actor) {
        Domain::window($e,'checkin');$a=self::resolve($e,$secret);
        foreach($e['checkins'] as $c)if($c['admission']===$a['admission']&&empty($c['reversedAt']))return ['status'=>'already_used','name'=>$a['guest']['name'],'at'=>$c['at']];
        $c=['id'=>Support::id(),'admission'=>$a['admission'],'guestId'=>$a['guest']['id'],'actor'=>$actor,'at'=>Support::now(),'reversedAt'=>null];$e['checkins'][]=$c;return ['status'=>'valid','name'=>$a['guest']['name'],'at'=>$c['at']];
    }
}
