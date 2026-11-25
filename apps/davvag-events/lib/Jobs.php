<?php
namespace davvag_events;

final class Jobs {
    public static function enqueue(&$e,$type,$reference,$key,$payload=[],$runAt=null) {
        foreach($e['jobs'] as $j)if($j['key']===$key)return $j['id'];
        $id=Support::id();$e['jobs'][]=['id'=>$id,'type'=>$type,'reference'=>$reference,'key'=>$key,'payload'=>$payload,'status'=>'queued','attempts'=>0,'runAt'=>$runAt??Support::now(),'lease'=>null,'leaseUntil'=>null,'reason'=>''];return $id;
    }
    public static function claim($eventId) {
        for($try=0;$try<3;$try++){
            $e=Store::load($eventId);$rev=$e['revision'];foreach($e['jobs'] as $i=>$j){
                if(($j['status']==='queued'&&$j['runAt']<=Support::now())||($j['status']==='running'&&($j['leaseUntil']??'')<Support::now())){
                    if($j['type']==='email'&&$j['status']==='running'){$e['jobs'][$i]['status']='uncertain';$e['jobs'][$i]['reason']='Delivery may have succeeded before the worker stopped. Review before rotating and resending.';Store::save($e,$rev,'worker','delivery_uncertain');return null;}
                    $e['jobs'][$i]['status']='running';$e['jobs'][$i]['lease']=Support::secret();$e['jobs'][$i]['leaseUntil']=gmdate('Y-m-d\TH:i:s\Z',time()+180);$e['jobs'][$i]['attempts']++;
                    try{Store::save($e,$rev,'worker','job_claim',$j['id']);return ['event'=>$e,'index'=>$i,'lease'=>$e['jobs'][$i]['lease']];}catch(Fault $f){if($f->reason!=='CONFLICT')throw $f;break;}
                }
            }if($e['revision']===$rev)return null;
        }return null;
    }
    private static function persist(&$e,$index,$lease,$modify) {
        $latest=Store::load($e['id']);$j=$latest['jobs'][$index]??null;
        if(!$j||$j['status']!=='running'||!hash_equals($j['lease']??'',$lease)||$j['leaseUntil']<Support::now())Support::fail('JOB_LEASE','Worker lease changed. A later worker will reconcile.',409);
        $rev=$latest['revision'];$modify($latest);Store::save($latest,$rev,'worker','job_progress',$j['id']);$e=$latest;
    }
    public static function execute($claim) {
        $e=$claim['event'];$i=$claim['index'];$lease=$claim['lease'];$job=$e['jobs'][$i];$done=true;
        try{
            if($job['type']==='drive'){
                $mi=null;foreach($e['media'] as $n=>$m)if($m['id']===$job['reference'])$mi=$n;if($mi===null)Support::fail('MEDIA','Upload metadata not found.',404);
                $persist=function(&$working)use($i,$lease,$mi){$media=$working['media'][$mi];self::persist($working,$i,$lease,function(&$latest)use($mi,$media){if($latest['media'][$mi]['status']==='cancelled')Support::fail('CANCELLED','Upload was cancelled.',410);$latest['media'][$mi]=$media;});};
                $done=Drive::transfer($e,$mi,$persist);
            }elseif($job['type']==='payment'){
                $oi=null;foreach($e['orders'] as $n=>$o)if($o['id']===$job['reference'])$oi=$n;if($oi===null)Support::fail('ORDER','Order not found.',404);$o=$e['orders'][$oi];
                $account=Stripe::request('GET','/account');if(($account['id']??'')!==getenv('DAVVAG_EVENTS_STRIPE_ACCOUNT'))Support::fail('MERCHANT','Stripe merchant does not match configuration.',503);
                if(empty($o['sessionId']))Support::fail('PAYMENT_PENDING','Checkout creation is still pending.',503);
                $session=Stripe::request('GET','/checkout/sessions/'.rawurlencode($o['sessionId']));
                if(Stripe::verify($session,$e,$o))self::persist($e,$i,$lease,function(&$latest)use($oi,$session){Ticketing::fulfill($latest,$oi,$session['payment_intent']);});
                elseif(($session['status']??'')==='expired')self::persist($e,$i,$lease,function(&$latest)use($oi){if($latest['orders'][$oi]['status']!=='issued')$latest['orders'][$oi]['status']='expired';});
                else Support::fail('PAYMENT_PENDING','Payment is awaiting provider confirmation.',503);
            }elseif($job['type']==='refund'){
                $o=null;foreach($e['orders'] as $row)if($row['id']===$job['reference'])$o=$row;if(!$o||!$o['paymentId']||$o['paymentId']==='free')Support::fail('REFUND','No paid transaction to refund.',409);
                $r=Stripe::request('POST','/refunds',['payment_intent'=>$o['paymentId']],'refund-'.$o['id']);
                if(($r['status']??'')!=='succeeded')Support::fail('REFUND_PENDING','Refund is pending at the provider.',503);
                self::persist($e,$i,$lease,function(&$latest)use($o){Ticketing::revoke($latest,$o['id']);});
            }elseif($job['type']==='email'){
                if(in_array($e['status'],['cancelled','archived'],true))Support::fail('CANCELLED','Event communication was cancelled.',410);
                $p=$e['parties'][Domain::partyIndex($e,$job['reference'])];if(!$p['emailConsent']||!$p['email'])Support::fail('CONSENT','Email consent or address is missing.',409);
                $payload=Support::decrypt($job['payload']['delivery']);
                require_once PLUGIN_PATH.'/notify/notify.php';
                $data=(object)['title'=>htmlspecialchars($e['draft']['title'],ENT_QUOTES,'UTF-8'),'url'=>htmlspecialchars($payload['url'],ENT_QUOTES,'UTF-8')];
                if(!\Notify::sendEmailMessage(htmlspecialchars($p['label'],ENT_QUOTES,'UTF-8'),$p['email'],'davvag-events-invitation',$data))Support::fail('EMAIL_UNCERTAIN','SMTP did not confirm delivery. Review before rotating and resending.',409);
                self::persist($e,$i,$lease,function(&$latest)use($p){$latest['parties'][Domain::partyIndex($latest,$p['id'])]['delivery']='sent';});
            }elseif($job['type']==='cleanup'){
                foreach($e['media'] as $m)if(isset($m['staging'])&&(in_array($m['status'],['uploaded','cancelled'],true)||strtotime($m['createdAt'])<time()-86400)){$path=Media::path($m['staging']);if(is_file($path)&&!unlink($path))Support::fail('CLEANUP','Unable to remove a staged upload.',503);}
                if(!empty($e['deletedAt']))self::persist($e,$i,$lease,function(&$latest){$latest['media']=[];$latest['members']=[];$latest['groups']=[];$latest['tables']=[];$latest['ticketTypes']=[];$latest['draft']['title']='Deleted event';$latest['draft']['hosts']='';$latest['draft']['venue']='';$latest['draft']['address']='';});
            }else Support::fail('JOB','Unknown job type.',409);
            self::persist($e,$i,$lease,function(&$latest)use($i,$done){$latest['jobs'][$i]['status']=$done?'done':'queued';$latest['jobs'][$i]['runAt']=Support::now();$latest['jobs'][$i]['lease']=null;$latest['jobs'][$i]['leaseUntil']=null;$latest['jobs'][$i]['reason']='';if(!$done)$latest['jobs'][$i]['attempts']=max(0,$latest['jobs'][$i]['attempts']-1);});
            return 'processed';
        }catch(Fault $f){
            try{self::persist($e,$i,$lease,function(&$latest)use($i,$f,$job){$j=&$latest['jobs'][$i];$j['status']=$f->reason==='EMAIL_UNCERTAIN'?'uncertain':($j['attempts']>=6||in_array($f->status,[404,409,410],true)?'failed':'queued');$j['reason']=$f->getMessage();$j['runAt']=gmdate('Y-m-d\TH:i:s\Z',time()+min(3600,15*(2**min(8,$j['attempts']))));$j['lease']=null;$j['leaseUntil']=null;if($job['type']==='drive')foreach($latest['media'] as &$m)if($m['id']===$job['reference']){$m['status']=$j['status']==='failed'?'failed':'queued';$m['reason']=$f->getMessage();}unset($m);});}catch(\Throwable $ignored){}
            return 'deferred';
        }
    }
    public static function sweep(&$e) {
        foreach($e['orders'] as &$o)if(in_array($o['status'],['holding','checkout'],true)&&$o['expires']<Support::now()){$o['status']='expired';if($o['amount']>0&&!empty($o['sessionId']))self::enqueue($e,'payment',$o['id'],'reconcile-'.$o['id']);}unset($o);
        foreach($e['media'] as &$m)if(!in_array($m['status'],['uploaded','cancelled'],true)&&strtotime($m['createdAt'])<time()-86400){$m['status']='cancelled';$m['reason']='Staging retention expired. Upload again.';}unset($m);
        self::enqueue($e,'cleanup',$e['id'],'cleanup-'.gmdate('Y-m-d'));
    }
}
