<?php
namespace davvag_events;

final class Seating {
    public static function saveTable(&$e,$in) {
        if((int)($in['layoutRevision']??-1)!==$e['layoutRevision'])Support::fail('LAYOUT_STALE','The floor plan changed. Refresh first.',409);
        $id=$in['tableId']??Support::id();$old=null;$index=null;
        foreach($e['tables'] as $i=>$t)if($t['id']===$id){$old=$t;$index=$i;break;}
        $cap=Support::integer($in['capacity']??8,1,100);$shape=Support::choice($in['shape']??'round',['round','rectangle','theatre']);
        $t=['id'=>$id,'label'=>Support::text($in['label']??'',80,true),'shape'=>$shape,'x'=>Support::integer($in['x']??100,0,1800),'y'=>Support::integer($in['y']??100,0,1400),'rotation'=>Support::integer($in['rotation']??0,0,359),'capacity'=>$cap,'vip'=>!empty($in['vip']),'seats'=>[]];
        $accessible=$in['accessibleSeats']??[];if(!is_array($accessible))Support::fail('SEAT','Invalid accessible seats.');
        for($i=0;$i<$cap;$i++)$t['seats'][]=['id'=>$old['seats'][$i]['id']??Support::id(),'label'=>(string)($i+1),'accessible'=>in_array($i+1,array_map('intval',$accessible),true)];
        $remaining=array_column($t['seats'],'id');$removed=[];if($old)foreach($old['seats'] as $s)if(!in_array($s['id'],$remaining,true))$removed[]=$s['id'];
        if(!empty($in['delete'])){$removed=$old?array_column($old['seats'],'id'):[];}
        foreach($e['assignments'] as $a)if(in_array($a['seatId'],$removed,true)&&empty($in['releaseOccupied']))Support::fail('OCCUPIED','Confirm release of occupied seats before reducing or deleting this table.',409);
        $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($removed){return !in_array($a['seatId'],$removed,true);}));
        if(!empty($in['delete'])){if($index!==null)array_splice($e['tables'],$index,1);}
        elseif($index===null){if(count($e['tables'])>=100)Support::fail('LIMIT','At most 100 tables or rows are supported.');$e['tables'][]=$t;}else $e['tables'][$index]=$t;
        $e['layoutRevision']++;return $t;
    }
    public static function assign(&$e,$in) {
        if((int)($in['layoutRevision']??-1)!==$e['layoutRevision'])Support::fail('LAYOUT_STALE','The floor plan changed. Refresh first.',409);
        $guest=$e['guests'][Domain::guestIndex($e,$in['guestId']??'')];$seat=$in['seatId']??'';
        foreach($e['assignments'] as $a){if($a['guestId']===$guest['id']&&$a['locked']&&empty($in['unlock']))Support::fail('LOCKED','Unlock this assignment before moving it.',409);if($a['seatId']===$seat&&$a['guestId']!==$guest['id'])Support::fail('SEAT_TAKEN','This seat was assigned to another attendee.',409);}
        $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($guest){return $a['guestId']!==$guest['id'];}));
        if($seat!=='')$e['assignments'][]=['seatId'=>$seat,'guestId'=>$guest['id'],'locked'=>!empty($in['locked'])];Domain::assertInvariants($e);return ['assigned'=>$seat!==''];
    }
    public static function plan($e,$in) {
        $split=!empty($in['allowSplit']);$groups=[];$occupied=[];foreach($e['assignments'] as $a)$occupied[$a['seatId']]=$a['guestId'];
        $assigned=array_values($occupied);foreach($e['guests'] as $g)if($g['rsvp']==='Attending'&&!in_array($g['id'],$assigned,true))$groups[($g['group']?:$g['partyId'])][]=$g;
        uasort($groups,function($a,$b){$av=max(array_map(function($g){return (int)$g['vip'];},$a));$bv=max(array_map(function($g){return (int)$g['vip'];},$b));return $bv<=>$av ?: count($b)<=>count($a) ?: strcmp($a[0]['id'],$b[0]['id']);});
        $tables=$e['tables'];usort($tables,function($a,$b){return (int)$b['vip']<=>(int)$a['vip']?:strcmp($a['id'],$b['id']);});$plan=[];$unsatisfied=[];
        foreach($groups as $key=>$members){usort($members,function($a,$b){return (int)$b['accessible']<=>(int)$a['accessible']?:strcmp($a['id'],$b['id']);});$placed=false;
            foreach($tables as $t){$free=array_values(array_filter($t['seats'],function($s)use($occupied){return !isset($occupied[$s['id']]);}));if(count($free)<count($members))continue;$trial=[];
                foreach($members as $g){$chosen=null;foreach($free as $i=>$s)if(!$g['accessible']||$s['accessible']){$chosen=$s;array_splice($free,$i,1);break;}if(!$chosen){$trial=[];break;}$trial[]=['guestId'=>$g['id'],'seatId'=>$chosen['id'],'locked'=>false];}
                if(count($trial)===count($members)){foreach($trial as $a){$plan[]=$a;$occupied[$a['seatId']]=$a['guestId'];}$placed=true;break;}
            }
            if(!$placed&&$split){$trial=[];$local=$occupied;foreach($members as $g){$found=false;foreach($tables as $t){foreach($t['seats'] as $s)if(!isset($local[$s['id']])&&(!$g['accessible']||$s['accessible'])){$trial[]=['guestId'=>$g['id'],'seatId'=>$s['id'],'locked'=>false];$local[$s['id']]=$g['id'];$found=true;break 2;}}if(!$found)break;}if(count($trial)===count($members)){$plan=array_merge($plan,$trial);$occupied=$local;$placed=true;}}
            if(!$placed)$unsatisfied[]=['group'=>$key,'count'=>count($members),'reason'=>'No compatible table has enough seats.'];
        }
        return ['revision'=>$e['revision'],'layoutRevision'=>$e['layoutRevision'],'assignments'=>$plan,'unsatisfied'=>$unsatisfied,'heuristic'=>'VIP first, then largest group, stable IDs; preserve existing assignments; accessibility first; no group split unless enabled.'];
    }
    public static function lookup($e,$guestIds) {
        $out=[];foreach($e['assignments'] as $a)if(in_array($a['guestId'],$guestIds,true))foreach($e['tables'] as $t)foreach($t['seats'] as $s)if($s['id']===$a['seatId']){$g=$e['guests'][Domain::guestIndex($e,$a['guestId'])];$out[]=['name'=>$g['name'],'table'=>$t['label'],'seat'=>$s['label'],'accessible'=>$s['accessible']];}
        return $out;
    }
}

final class Csv {
    public static function preview($e,$csv,$policy) {
        Support::choice($policy,['reject','skip','allow']);if(!is_string($csv)||strlen($csv)>250000)Support::fail('CSV','CSV must be at most 250 KB.');
        $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);$header=fgetcsv($stream,0,',','"','');
        if($header!==['name','email','whatsapp','group','vip','plusOnes','emailConsent'])Support::fail('CSV','Use headers: name,email,whatsapp,group,vip,plusOnes,emailConsent');
        $rows=[];$seen=[];$existing=[];foreach($e['guests'] as $g)$existing[mb_strtolower($g['name'])]=true;$n=1;
        while(($r=fgetcsv($stream,0,',','"',''))!==false){$n++;if($n>501)Support::fail('CSV','Import at most 500 rows.');if($r===[null])continue;$errors=[];$data=null;$duplicate=false;
            try{if(count($r)!==7)Support::fail('CSV','Expected seven columns.');$name=Support::text($r[0],180,true);$email=Support::email($r[1]);$duplicate=isset($seen[mb_strtolower($name)])||isset($existing[mb_strtolower($name)]);$seen[mb_strtolower($name)]=true;$data=['names'=>[$name],'label'=>$name,'email'=>$email,'whatsapp'=>Support::text($r[2],40),'group'=>Support::text($r[3],100),'vip'=>Support::choice($r[4],['0','1'])==='1','plusOnes'=>Support::integer($r[5],0,10),'emailConsent'=>Support::choice($r[6],['0','1'])==='1'];if($duplicate&&$policy==='reject')$errors[]='Duplicate name: choose skip or allow as a separate person.';}catch(Fault $f){$errors[]=$f->getMessage();}
            $rows[]=['line'=>$n,'data'=>$data,'duplicate'=>$duplicate,'skip'=>$duplicate&&$policy==='skip','errors'=>$errors];
        }fclose($stream);return ['rows'=>$rows,'valid'=>!count(array_filter($rows,function($r){return count($r['errors'])>0;})),'hash'=>hash('sha256',$csv.'|'.$policy.'|'.$e['revision'])];
    }
    public static function export($e) {
        $s=fopen('php://temp','w+');fputcsv($s,['attendeeId','partyId','name','group','vip','rsvp','dietary','accessibility','email','whatsapp'],',','"','');
        foreach($e['guests'] as $g){$p=$e['parties'][Domain::partyIndex($e,$g['partyId'])];fputcsv($s,array_map([Support::class,'csvCell'],[$g['id'],$g['partyId'],$g['name'],$g['group'],$g['vip']?'1':'0',$g['rsvp'],$g['dietary'],$g['accessibility'],$p['email'],$p['whatsapp']]),',','"','');}rewind($s);$csv=stream_get_contents($s);fclose($s);return $csv;
    }
}
