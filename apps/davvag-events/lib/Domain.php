<?php
namespace davvag_events;
require_once __DIR__.'/InvitationTemplates.php';

final class Domain {
    const OCCASIONS=['wedding','birthday','business','corporate','ticketed'];
    public static function templates() {
        $defs=[
            ['wedding','Together, with you','Join us as we begin our next chapter.','#743d60','#fff6fa','serif'],
            ['birthday','A little older. A lot to celebrate.','Good friends, good food, and another wonderful year.','#9a3c18','#fff8ed','rounded'],
            ['business','An evening of good company','Connect, share ideas, and celebrate what comes next.','#17645d','#f0faf7','sans'],
            ['corporate','Great things happen together','An inspiring gathering of people, perspectives, and possibility.','#2445a1','#f3f6ff','sans'],
            ['ticketed','Your next unforgettable experience','Discover the programme, reserve your place, and be part of it.','#6840a8','#f8f3ff','sans']
        ];$out=[];
        foreach($defs as $d)$out[]=['id'=>$d[0].'-v1','occasion'=>$d[0],'version'=>1,'heading'=>$d[1],'message'=>$d[2],'design'=>['accent'=>$d[3],'background'=>$d[4],'font'=>$d[5],'cover'=>'','sections'=>['message','details','agenda','rsvp','seating','greetings','media','tickets']]];
        return InvitationTemplates::enrich($out);
    }
    public static function create($in,$owner) {
        $occasion=Support::choice($in['occasion']??'',self::OCCASIONS);$t=self::templates()[array_search($occasion,self::OCCASIONS,true)];
        $id=Support::id();$e=['id'=>$id,'slug'=>'event-'.substr($id,0,12),'ownerProfile'=>$owner,'revision'=>0,'status'=>'draft','createdAt'=>Support::now(),'draftRevision'=>1,'publishedRevision'=>0,'published'=>null,'layoutRevision'=>0,
            'draft'=>['title'=>'','occasion'=>$occasion,'timezone'=>'UTC','start'=>'','end'=>'','venue'=>'','address'=>'','maps'=>'','message'=>$t['message'],'heading'=>$t['heading'],'hosts'=>'','rsvpDeadline'=>null,'privacy'=>'private','capacity'=>100,'modules'=>['rsvp'=>true,'seating'=>true,'greetings'=>true,'media'=>false,'tickets'=>$occasion==='ticketed'],'windows'=>['rsvp'=>true,'checkin'=>true,'greetings'=>true,'media'=>false,'sales'=>true],'moderation'=>true,'nameLookup'=>false,'gateHash'=>'','wallSeconds'=>10,'mediaMaxFiles'=>10,'mediaMaxBytes'=>52428800,'templateId'=>$t['id'],'templateVersion'=>1,'templateSnapshot'=>InvitationTemplates::snapshot($t),'design'=>$t['design'],'agenda'=>[]],
            'members'=>[],'groups'=>[],'parties'=>[],'guests'=>[],'tokens'=>[],'seatingSections'=>[],'tables'=>[],'assignments'=>[],'greetings'=>[],'media'=>[],'ticketTypes'=>[],'orders'=>[],'tickets'=>[],'checkins'=>[],'jobs'=>[],'providerEvents'=>[],'operations'=>[],'audit'=>[]];
        self::edit($e,$in);return $e;
    }
    public static function edit(&$e,$in) {
        if(in_array($e['status'],['cancelled','archived'],true))Support::fail('LIFECYCLE','Archived or cancelled events cannot be edited.',409);
        $d=$e['draft'];
        foreach(['title'=>180,'venue'=>240,'address'=>600,'message'=>4000,'heading'=>240,'hosts'=>240] as $k=>$max)if(array_key_exists($k,$in))$d[$k]=Support::text($in[$k],$max,$k==='title');
        if(isset($in['timezone'])){$tz=Support::text($in['timezone'],80);if(!in_array($tz,\DateTimeZone::listIdentifiers(),true)&&$tz!=='UTC')Support::fail('TIMEZONE','Choose an IANA timezone.');$d['timezone']=$tz;}
        foreach(['start','end','rsvpDeadline'] as $k)if(array_key_exists($k,$in))$d[$k]=Support::instant($in[$k],$k==='rsvpDeadline');
        foreach(['localStart'=>'start','localEnd'=>'end','localRsvpDeadline'=>'rsvpDeadline'] as $local=>$utc)if(array_key_exists($local,$in)){
            $value=$in[$local];
            // Preserve the exact instant when an existing local value is unchanged,
            // including the repeated hour at the end of daylight saving time.
            if(!$d[$utc]||Support::localValue($d[$utc],$d['timezone'])!==$value)$d[$utc]=Support::localInstant($value,$d['timezone'],$utc==='rsvpDeadline');
        }
        if(isset($in['templateId'])){
            $id=Support::text($in['templateId'],80,true);$version=Support::integer($in['templateVersion']??1,1,100000);
            if(($d['templateSnapshot']['id']??'')!==$id||($d['templateSnapshot']['version']??0)!==$version){$template=InvitationTemplates::find($id,$version);if(!empty($template['custom'])&&$template['occasion']!=='all'&&$template['occasion']!==$d['occasion'])Support::fail('TEMPLATE','Choose a template for this occasion.');$d['templateSnapshot']=InvitationTemplates::snapshot($template);}
            $d['templateId']=$id;$d['templateVersion']=$version;
        }
        if($d['start']&&$d['end']&&$d['end']<=$d['start'])Support::fail('DATE','The event must end after it starts.');
        if(isset($in['maps']))$d['maps']=Support::maps($in['maps']);
        if(isset($in['capacity']))$d['capacity']=Support::integer($in['capacity'],1,1000);
        if(isset($in['privacy']))$d['privacy']=Support::choice($in['privacy'],['private','public','gated']);
        foreach(['moderation','nameLookup'] as $k)if(isset($in[$k]))$d[$k]=(bool)$in[$k];
        foreach(['modules','windows'] as $k)if(isset($in[$k]))foreach($d[$k] as $key=>$v)if(array_key_exists($key,$in[$k]))$d[$k][$key]=(bool)$in[$k][$key];
        if(isset($in['wallSeconds']))$d['wallSeconds']=Support::integer($in['wallSeconds'],5,15);
        if(isset($in['mediaMaxFiles']))$d['mediaMaxFiles']=Support::integer($in['mediaMaxFiles'],1,100);
        if(isset($in['mediaMaxBytes']))$d['mediaMaxBytes']=Support::integer($in['mediaMaxBytes'],1024,536870912);
        if(!empty($in['gateCode']))$d['gateHash']=hash('sha256',Support::text($in['gateCode'],100,true));
        if(isset($in['slug'])){$slug=strtolower(Support::text($in['slug'],100,true));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug))Support::fail('SLUG','Use lowercase letters, numbers, and hyphens.');$e['slug']=$slug;}
        if(isset($in['design'])){
            $x=$in['design'];foreach(['accent','background'] as $k)if(isset($x[$k])){if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$x[$k]))Support::fail('DESIGN','Use a six digit hex color.');$d['design'][$k]=$x[$k];}
            if(isset($x['font']))$d['design']['font']=Support::choice($x['font'],['serif','sans','rounded']);
            if(isset($x['cover'])){ $cover=Support::text($x['cover'],1000);if($cover!==''&&!preg_match('~^assets/davvag-events/[a-zA-Z0-9/_\-.]+\.(png|jpe?g|webp)$~D',$cover))Support::fail('COVER','Choose a local event cover asset.');$d['design']['cover']=$cover; }
            if(isset($x['sections'])){$allowed=['message','details','agenda','rsvp','seating','greetings','media','tickets'];if(!is_array($x['sections'])||count($x['sections'])>8||count(array_unique($x['sections']))!==count($x['sections']))Support::fail('DESIGN','Invalid sections.');foreach($x['sections'] as $v)Support::choice($v,$allowed);$d['design']['sections']=array_values($x['sections']);}
        }
        if(isset($in['agenda'])){if(!is_array($in['agenda'])||count($in['agenda'])>40)Support::fail('AGENDA','Use at most 40 agenda items.');$d['agenda']=[];foreach($in['agenda'] as $i=>$a)$d['agenda'][]=['id'=>Support::text($a['id']??Support::id(),50),'order'=>$i,'time'=>Support::text($a['time']??'',50),'title'=>Support::text($a['title']??'',180,true),'description'=>Support::text($a['description']??'',1000)];}
        if(self::occupancy($e)>$d['capacity'])Support::fail('CAPACITY','Capacity is below existing RSVP or ticket allocations.',409);
        $e['draft']=$d;$e['draftRevision']++;
    }
    public static function transition(&$e,$to) {
        $allowed=['draft'=>['published','cancelled','archived'],'published'=>['published','closed','cancelled'],'closed'=>['published','cancelled','archived'],'cancelled'=>['archived'],'archived'=>[]];
        if(!in_array($to,$allowed[$e['status']]??[],true))Support::fail('LIFECYCLE','That event transition is not available.',409);
        if($to==='published'){
            $d=$e['draft'];if(!$d['title']||!$d['start']||!$d['end']||!$d['venue'])Support::fail('PUBLISH','Add title, dates, and venue before publishing.');
            if($d['privacy']==='gated'&&!$d['gateHash'])Support::fail('PUBLISH','Set an event access code.');
            if($d['occasion']==='ticketed'&&$d['modules']['tickets']&&!$e['ticketTypes'])Support::fail('PUBLISH','Add at least one ticket type.');
            $e['published']=$d;$e['publishedRevision']=$e['draftRevision'];$e['publishedAt']=Support::now();
        }
        $e['status']=$to;
        if(in_array($to,['cancelled','archived'],true)){
            foreach($e['tokens'] as &$t)$t['revoked']=true;unset($t);
            foreach($e['tickets'] as &$t)$t['status']='revoked';unset($t);
            foreach($e['orders'] as &$o)if(in_array($o['status'],['holding','checkout'],true))$o['status']='cancelled';unset($o);
            foreach($e['media'] as &$m)if(!in_array($m['status'],['uploaded','cancelled'],true))$m['status']='cancelled';unset($m);
        }
    }
    public static function window($e,$module) {
        $d=$e['published'];if(!in_array($e['status'],['published','closed'],true)||!$d)Support::fail('EVENT_CLOSED','This event is unavailable.',410);
        if(($module!=='checkin'&&empty($d['modules'][$module==='sales'?'tickets':$module]))||empty($d['windows'][$module]))Support::fail('WINDOW_CLOSED','This activity is closed.',409);
        if($module==='rsvp'&&($e['status']==='closed'||($d['rsvpDeadline']&&Support::now()>$d['rsvpDeadline'])))Support::fail('DEADLINE','Responses are now closed.',409);
        if(in_array($module,['checkin','media','greetings'],true)&&strtotime($d['end'])+86400<time())Support::fail('WINDOW_CLOSED','This activity has ended.',410);
        if($module==='sales'&&($e['status']==='closed'||Support::now()>$d['end']))Support::fail('WINDOW_CLOSED','Ticket sales are closed.',409);
    }
    public static function issue(&$e,$scope,$reference,$expires=null) {
        $secret=Support::secret();$e['tokens'][]=['hash'=>hash('sha256',$secret),'scope'=>$scope,'reference'=>$reference,'expires'=>$expires??gmdate('Y-m-d\TH:i:s\Z',max(time()+86400,strtotime($e['draft']['end']?:'+30 days')+86400)),'revoked'=>false];return $secret;
    }
    public static function capability($e,$scope,$secret=null) {
        if(in_array($e['status'],['cancelled','archived'],true))Support::fail('EVENT_CLOSED','This event has been cancelled or archived.',410);
        $hash=$secret!==null?hash('sha256',Support::text($secret,200,true)):($_SESSION['davvag_events_caps'][$e['id']][$scope]['hash']??'');
        $until=$_SESSION['davvag_events_caps'][$e['id']][$scope]['until']??0;
        if($secret===null&&$until<time())Support::fail('CREDENTIAL','Open your private invitation link again.',401);
        foreach($e['tokens'] as $t)if($t['scope']===$scope&&hash_equals($t['hash'],$hash)){
            if($t['revoked']||$t['expires']<Support::now())Support::fail('CREDENTIAL','This link has expired or been revoked.',410);return $t;
        }
        Support::fail('CREDENTIAL','This private link is invalid for this event.',403);
    }
    public static function partyIndex($e,$id) { foreach($e['parties'] as $i=>$p)if($p['id']===$id)return $i;Support::fail('PARTY','Invitation party not found.',404); }
    public static function guestIndex($e,$id) { foreach($e['guests'] as $i=>$g)if($g['id']===$id)return $i;Support::fail('GUEST','Attendee not found in this event.',404); }
    public static function addParty(&$e,$in) {
        if(count($e['parties'])>=500||count($e['guests'])>=1000)Support::fail('LIMIT','This event has reached its guest limit.',409);
        $names=$in['names']??[];if(!is_array($names)||!count($names)||count($names)>20)Support::fail('PARTY','Enter one to twenty invited attendees.');
        $extra=Support::integer($in['plusOnes']??0,0,10);$group=Support::text($in['group']??'',100);
        $p=['id'=>Support::id(),'label'=>Support::text($in['label']??$names[0],180,true),'email'=>Support::email($in['email']??''),'whatsapp'=>Support::text($in['whatsapp']??'',40),'plusOnes'=>$extra,'maxAttendees'=>count($names)+$extra,'group'=>$group,'emailConsent'=>!empty($in['emailConsent']),'delivery'=>'not_sent'];
        $e['parties'][]=$p;
        foreach($names as $name)$e['guests'][]=self::guest($p['id'],Support::text($name,180,true),$group,!empty($in['vip']));
        return $p;
    }
    public static function guest($party,$name,$group='',$vip=false) { return ['id'=>Support::id(),'partyId'=>$party,'name'=>$name,'group'=>$group,'vip'=>$vip,'rsvp'=>'Pending','dietary'=>'','accessibility'=>'','accessible'=>false,'respondedAt'=>null,'history'=>[]]; }
    public static function occupancy($e,$exceptOrder=null) {
        $ids=[];foreach($e['guests'] as $g)if($g['rsvp']==='Attending'&&empty($e['draft']['modules']['tickets']))$ids[$g['id']]=true;
        foreach($e['tickets'] as $t)if($t['status']==='issued')$ids[$t['guestId']]=true;
        $n=count($ids);foreach($e['orders'] as $o)if($o['id']!==$exceptOrder&&in_array($o['status'],['holding','checkout'],true)&&$o['expires']>Support::now())$n+=count($o['attendees']);return $n;
    }
    public static function rsvp(&$e,$partyId,$in,$manual=false) {
        if(!$manual)self::window($e,'rsvp');
        $pi=self::partyIndex($e,$partyId);$p=$e['parties'][$pi];$rows=$in['attendees']??[];
        if(!is_array($rows)||count($rows)>$p['maxAttendees'])Support::fail('PLUS_ONES','This response exceeds your invitation size.');
        $existing=[];foreach($e['guests'] as $g)if($g['partyId']===$partyId)$existing[$g['id']]=$g;
        $seen=[];$changes=[];$extra=0;
        foreach($rows as $r){$id=$r['id']??'';$status=Support::choice($r['rsvp']??'', ['Pending','Attending','Declined']);
            if($id===''){$extra++;$g=self::guest($partyId,Support::text($r['name']??'',180,true),$p['group']);}
            else {if(!isset($existing[$id])||isset($seen[$id]))Support::fail('GUEST','Invalid or duplicate attendee.');$seen[$id]=true;$g=$existing[$id];}
            $g['name']=Support::text($r['name']??$g['name'],180,true);$g['dietary']=Support::text($r['dietary']??'',600);$g['accessibility']=Support::text($r['accessibility']??'',600);$g['accessible']=!empty($r['accessible']);
            if($g['rsvp']!==$status){$g['history'][]=['from'=>$g['rsvp'],'to'=>$status,'at'=>Support::now()];$g['respondedAt']=Support::now();}$g['rsvp']=$status;$changes[]=$g;
        }
        if(count($existing)+$extra>$p['maxAttendees'])Support::fail('PLUS_ONES','All permitted places in your invitation are already named.');
        foreach($changes as $g){$found=false;foreach($e['guests'] as $i=>$old)if($old['id']===$g['id']){$e['guests'][$i]=$g;$found=true;break;}if(!$found)$e['guests'][]=$g;
            if($g['rsvp']==='Declined'){
                foreach($e['tickets'] as $t)if($t['guestId']===$g['id']&&$t['status']==='issued'&&!empty($t['orderId']))Support::fail('PAID_ADMISSION','Contact the host to cancel or refund your admission first.',409);
                $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($g){return $a['guestId']!==$g['id'];}));
                foreach($e['tokens'] as &$t)if($t['scope']==='admission'&&$t['reference']===$g['id'])$t['revoked']=true;unset($t);
            }
        }
        if(self::occupancy($e)>($e['published']['capacity']??$e['draft']['capacity']))Support::fail('FULL','This event has reached capacity.',409);
        return self::partyView($e,$partyId);
    }
    public static function partyView($e,$id) { $p=$e['parties'][self::partyIndex($e,$id)];return ['id'=>$p['id'],'label'=>$p['label'],'maxAttendees'=>$p['maxAttendees'],'attendees'=>array_values(array_filter($e['guests'],function($g)use($id){return $g['partyId']===$id;}))]; }
    public static function publicView($e) {
        $d=$e['published'];if(!$d||!in_array($e['status'],['published','closed'],true))Support::fail('NOT_FOUND','This invitation is unavailable.',404);
        unset($d['gateHash']);return ['id'=>$e['id'],'status'=>$e['status'],'invitation'=>$d,'revision'=>$e['publishedRevision'],'remaining'=>max(0,$d['capacity']-self::occupancy($e))];
    }
    public static function gate($e,$input=[]) {
        if(!$e['published'])Support::fail('NOT_FOUND','Invitation not found.',404);$d=$e['published'];
        if($d['privacy']==='public')return;
        try{self::capability($e,'rsvp');return;}catch(Fault $f){}
        if($d['privacy']==='gated'){
            if(($_SESSION['davvag_events_gate'][$e['id']]??0)>time())return;
            if(!empty($input['gateCode'])&&hash_equals($d['gateHash'],hash('sha256',Support::text($input['gateCode'],100,true)))){$_SESSION['davvag_events_gate'][$e['id']]=time()+1800;return;}
        }
        Support::fail('PRIVATE','Open your personal invitation or enter the event access code.',403);
    }
    public static function summary($e,$role) {
        $counts=['Pending'=>0,'Attending'=>0,'Declined'=>0];foreach($e['guests'] as $g)$counts[$g['rsvp']]++;
        return ['id'=>$e['id'],'title'=>$e['draft']['title'],'occasion'=>$e['draft']['occasion'],'status'=>$e['status'],'start'=>$e['draft']['start'],'timezone'=>$e['draft']['timezone'],'venue'=>$e['draft']['venue'],'role'=>$role,'revision'=>$e['revision'],'parties'=>count($e['parties']),'attendees'=>count($e['guests']),'rsvp'=>$counts,'seated'=>count($e['assignments']),'pendingGreetings'=>count(array_filter($e['greetings'],function($g){return $g['status']==='pending';})),'uploads'=>count($e['media']),'tickets'=>count(array_filter($e['tickets'],function($t){return $t['status']==='issued';})),'checkins'=>count(array_filter($e['checkins'],function($c){return empty($c['reversedAt']);})),'remaining'=>max(0,$e['draft']['capacity']-self::occupancy($e))];
    }
    public static function adminView($e,$actor) {
        $role=Identity::authorize($e,$actor,'view');$summary=self::summary($e,$role);
        if(in_array($role,['checkin','moderator'],true))return ['summary'=>$summary,'id'=>$e['id'],'revision'=>$e['revision'],'role'=>$role];
        $v=$e;unset($v['tokens'],$v['operations'],$v['providerEvents'],$v['audit']);
        foreach($v['jobs'] as &$j)unset($j['payload'],$j['lease']);unset($j);
        foreach($v['media'] as &$m)unset($m['staging'],$m['sessionUrl'],$m['driveId']);unset($m);
        foreach($v['orders'] as &$o)unset($o['retrievalHash']);unset($o);
        unset($v['draft']['gateHash'],$v['published']['gateHash']);
        if($role==='manager'){
            $grants=[];foreach($e['members'] as $m)if($m['profileId']===$actor['id'])$grants=$m['grants'];
            unset($v['orders'],$v['tickets'],$v['providerEvents'],$v['members']);
            if(!in_array('guests',$grants,true))unset($v['parties'],$v['guests']);
            if(!in_array('media',$grants,true))unset($v['media']);
            if(!in_array('moderate',$grants,true))unset($v['greetings']);
            if(!in_array('reports',$grants,true))unset($v['jobs']);
            if(!in_array('edit',$grants,true))unset($v['draft'],$v['published']);
        }
        $v['summary']=$summary;$v['role']=$role;return $v;
    }
    public static function keys($e) {
        $out=[];$add=function($kind,$key,$global=false)use(&$out,$e){$out[]=['kind'=>$kind,'scope'=>$global?'tenant':$e['id'],'value'=>(string)$key];};
        foreach(Seating::sections($e) as $section){$add('section',$section['id']);$add('section_label',hash('sha256',mb_strtolower($section['label'])));}
        foreach($e['tables'] as $table)if(($number=Seating::number($table))!==null)$add('table_number',$number);
        foreach($e['groups'] as $group)$add('guest_group',hash('sha256',mb_strtolower($group['label'])));
        foreach($e['members'] as $m)$add('member',$m['profileId']);
        foreach($e['tokens'] as $t)$add('credential',$t['hash'],true);
        foreach($e['assignments'] as $a){$add('seat',$a['seatId']);$add('seated_guest',$a['guestId']);}
        foreach($e['tickets'] as $t){$add('ticket',$t['id']);if($t['status']==='issued')$add('issued_guest',$t['guestId']);}
        foreach($e['checkins'] as $c)if(empty($c['reversedAt']))$add('checkin',$c['admission']);
        foreach($e['providerEvents'] as $p)$add('provider_event',$p['id'],true);
        foreach($e['orders'] as $o){$add('order_operation',$o['operation']);if(!empty($o['paymentId'])&&$o['paymentId']!=='free')$add('payment',$o['paymentId'],true);}
        foreach($e['jobs'] as $j)$add('job',$j['key']);return $out;
    }
    public static function assertInvariants($e) {
        Seating::assertLayout($e);
        if(count($e['guests'])>1000)Support::fail('LIMIT','At most 1,000 attendees are supported per event.',409);
        $keys=[];foreach(self::keys($e) as $k){$key=$k['kind'].'|'.$k['scope'].'|'.$k['value'];if(isset($keys[$key]))Support::fail('CONFLICT','An attendee, seat, credential, or operation is duplicated.',409);$keys[$key]=true;}
        $guests=[];foreach($e['guests'] as $g){if(isset($guests[$g['id']]))Support::fail('CONFLICT','Duplicate attendee.',409);$guests[$g['id']]=$g;self::partyIndex($e,$g['partyId']);}
        $seats=[];foreach($e['tables'] as $t){if(count($t['seats'])>$t['capacity'])Support::fail('CAPACITY','Table capacity exceeded.',409);foreach($t['seats'] as $s){if(isset($seats[$s['id']]))Support::fail('CONFLICT','Duplicate seat.',409);$seats[$s['id']]=$s;}}
        foreach($e['assignments'] as $a){$g=$guests[$a['guestId']]??null;$s=$seats[$a['seatId']]??null;if(!$g||!$s||$g['rsvp']!=='Attending'||($g['accessible']&&!$s['accessible']))Support::fail('ASSIGNMENT','An assignment is invalid or requires an accessible seat.',409);}
        foreach($e['parties'] as $p){$n=0;foreach($e['guests'] as $g)if($g['partyId']===$p['id'])$n++;if($n>$p['maxAttendees'])Support::fail('PLUS_ONES','Party capacity exceeded.',409);}
        foreach($e['tickets'] as $t)if(!isset($guests[$t['guestId']]))Support::fail('TICKET','Ticket attendee is missing.',409);
        if(self::occupancy($e)>$e['draft']['capacity'])Support::fail('FULL','Event capacity exceeded.',409);
    }
}
