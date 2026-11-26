<?php
namespace davvag_events;

final class Seating {
    public static function sections($event) { return $event['seatingSections']??[]; }
    private static function revision($event,$input) { if((int)($input['layoutRevision']??-1)!==$event['layoutRevision'])Support::fail('LAYOUT_STALE','The seating plan changed. Refresh it before saving.',409); }
    private static function section($event,$id) { if($id==='')return;foreach(self::sections($event) as $section)if($section['id']===$id)return;Support::fail('SECTION','Choose a section from this event.',422); }
    public static function number($table) { if(($table['shape']??'')==='theatre')return null;if(isset($table['number']))return (int)$table['number'];if(preg_match('/^(?:table\s*)?(\d+)$/i',$table['label']??'',$m))return (int)$m[1];return null; }
    public static function viewTables($event) { $tables=$event['tables'];$used=[];foreach($tables as $t)if(($n=self::number($t))!==null)$used[$n]=true;$next=1;foreach($tables as &$t)if(($t['shape']??'')!=='theatre'){if(($n=self::number($t))!==null){$t['number']=$n;continue;}while(isset($used[$next]))$next++;$t['number']=$next;$used[$next]=true;}unset($t);return $tables; }
    public static function nextNumber($event) { $next=1;foreach(self::viewTables($event) as $table)if(($n=self::number($table))!==null)$next=max($next,$n+1);return $next; }
    public static function title($table) { $n=self::number($table);if($n===null)return $table['label'];$base='Table '.$n;return preg_match('/^(?:table\s*)?'.preg_quote((string)$n,'/').'$/i',$table['label'])?$base:$base.' - '.$table['label']; }
    public static function change(&$event,$input) {
        self::revision($event,$input);
        switch($input['action']??'table'){
            case 'table': return self::saveTable($event,$input);
            case 'addTables': return self::addTables($event,$input);
            case 'addRows': return self::addRows($event,$input);
            case 'section': return self::saveSection($event,$input);
            case 'group': return self::saveGroup($event,$input);
            default: Support::fail('SEATING_ACTION','Choose a supported seating action.');
        }
    }
    public static function saveTable(&$e,$in) {
        self::revision($e,$in);$id=$in['tableId']??Support::id();$old=null;$index=null;
        foreach($e['tables'] as $i=>$table)if($table['id']===$id){$old=$table;$index=$i;break;}
        if(isset($in['tableId'])&&!$old)Support::fail('TABLE','This table or row is no longer available.',404);
        $shape=Support::choice($in['shape']??($old['shape']??'round'),['round','oval','square','rectangle','theatre']);$capacity=Support::integer($in['capacity']??($old['capacity']??8),1,100);
        $sectionId=Support::text($in['sectionId']??($old['sectionId']??''),40);self::section($e,$sectionId);
        $number=$shape==='theatre'?null:Support::integer($in['number']??($old?self::number($old):null)??self::nextNumber($e),1,9999);
        if($number!==null)foreach(self::viewTables($e) as $table)if($table['id']!==$id&&self::number($table)===$number)Support::fail('TABLE_NUMBER','That table number is already in use. Choose another number.',409);
        $label=Support::text($in['label']??($old['label']??''),80);if($label==='')$label=$shape==='theatre'?'Row A':'Table '.$number;
        $start=Support::integer($in['seatStart']??($old['seatStart']??1),1,99999);$prefix=Support::text($in['seatPrefix']??($old['seatPrefix']??''),12);
        $table=['id'=>$id,'number'=>$number,'label'=>$label,'shape'=>$shape,'sectionId'=>$sectionId,'x'=>Support::integer($in['x']??($old['x']??240),0,10000),'y'=>Support::integer($in['y']??($old['y']??240),0,10000),'rotation'=>Support::integer($in['rotation']??($old['rotation']??0),0,359),'capacity'=>$capacity,'vip'=>array_key_exists('vip',$in)?!empty($in['vip']):($old['vip']??false),'seatStart'=>$start,'seatPrefix'=>$prefix,'seats'=>[]];
        $accessible=$in['accessibleSeats']??($old?array_values(array_filter(array_map(function($s,$i){return !empty($s['accessible'])?$i+1:0;},$old['seats'],array_keys($old['seats'])))):[]);
        if(!is_array($accessible))Support::fail('SEAT','Choose valid accessible seat numbers.');foreach($accessible as $n)if(filter_var($n,FILTER_VALIDATE_INT)===false||(int)$n<1||(int)$n>$capacity)Support::fail('SEAT','Use accessible seat positions between 1 and this table capacity.');$accessible=array_map('intval',$accessible);
        for($i=0;$i<$capacity;$i++)$table['seats'][]=['id'=>$old['seats'][$i]['id']??Support::id(),'label'=>$prefix.($start+$i),'accessible'=>in_array($i+1,$accessible,true)];
        $remaining=array_column($table['seats'],'id');$removed=[];if($old)foreach($old['seats'] as $seat)if(!in_array($seat['id'],$remaining,true))$removed[]=$seat['id'];
        if(!empty($in['delete']))$removed=$old?array_column($old['seats'],'id'):[];
        foreach($e['assignments'] as $a)if(in_array($a['seatId'],$removed,true)&&empty($in['releaseOccupied']))Support::fail('OCCUPIED','These seats have guests. Confirm that their seats should be released first.',409);
        $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($removed){return !in_array($a['seatId'],$removed,true);}));
        if(!empty($in['delete'])){if($index!==null)array_splice($e['tables'],$index,1);}
        elseif($index===null){if(count($e['tables'])>=100)Support::fail('LIMIT','This event supports up to 100 tables or rows.',409);$e['tables'][]=$table;}else $e['tables'][$index]=$table;
        $e['layoutRevision']++;self::assertLayout($e);return $table;
    }
    private static function operation($e,$in,$kind) {
        $key=Support::text($in['operation']??'',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$key))Support::fail('OPERATION','Reopen this popup and try again.');$key='seating-'.$kind.'|'.$key;
        foreach($e['operations'] as $op)if($op['key']===$key)return ['key'=>$key,'result'=>$op['result']];return ['key'=>$key,'result'=>null];
    }
    public static function addTables(&$e,$in) {
        self::revision($e,$in);$op=self::operation($e,$in,'tables');if($op['result']!==null)return $op['result'];$count=Support::integer($in['count']??1,1,20);$number=Support::integer($in['number']??self::nextNumber($e),1,9999);$created=[];
        if(count($e['tables'])+$count>100)Support::fail('LIMIT','This would exceed 100 tables and rows.',409);
        for($i=0;$i<$count;$i++){$slot=count($e['tables']);$data=$in;$data['layoutRevision']=$e['layoutRevision'];$data['number']=$number+$i;if($count>1||empty($in['label']))$data['label']='Table '.($number+$i);$data['x']=240+($slot%5)*340;$data['y']=240+(int)floor($slot/5)*310;$created[]=self::saveTable($e,$data)['id'];}
        $result=['created'=>count($created),'ids'=>$created];$e['operations'][]=['key'=>$op['key'],'result'=>$result,'at'=>Support::now()];return $result;
    }
    public static function rowLabel($number) { $out='';for($n=$number+1;$n>0;$n=(int)floor(($n-1)/26))$out=chr(65+(($n-1)%26)).$out;return $out; }
    public static function addRows(&$e,$in) {
        self::revision($e,$in);$op=self::operation($e,$in,'rows');if($op['result']!==null)return $op['result'];$rows=Support::integer($in['rows']??4,1,30);$seats=Support::integer($in['seatsPerRow']??10,1,30);$start=Support::integer($in['startNumber']??1,1,99999);$first=strtoupper(Support::text($in['firstRow']??'A',2,true));if(!preg_match('/^[A-Z]{1,2}$/D',$first))Support::fail('ROW','Use letters such as A or AA for the first row.');$offset=0;foreach(str_split($first) as $letter)$offset=$offset*26+ord($letter)-64;$offset--; $numbering=Support::choice($in['numbering']??'perRow',['perRow','continuous']);$section=Support::text($in['sectionId']??'',40);self::section($e,$section);
        if(count($e['tables'])+$rows>100||$rows*$seats>1000)Support::fail('LIMIT','Choose a smaller seat plan. Up to 100 rows/tables and 1,000 new seats are supported.',409);
        $baseY=180;foreach($e['tables'] as $table)$baseY=max($baseY,$table['y']+280);$ids=[];
        for($r=0;$r<$rows;$r++){$letter=self::rowLabel($offset+$r);$label='Row '.$letter;foreach($e['tables'] as $table)if(($table['shape']??'')==='theatre'&&($table['sectionId']??'')===$section&&$table['label']===$label)Support::fail('ROW','This section already has '.$label.'. Choose a different first row.',409);
            $ids[]=self::saveTable($e,['layoutRevision'=>$e['layoutRevision'],'label'=>$label,'shape'=>'theatre','capacity'=>$seats,'sectionId'=>$section,'x'=>max(800,(int)($seats*48/2+100)),'y'=>$baseY+$r*160,'seatStart'=>$numbering==='continuous'?$start+$r*$seats:$start,'seatPrefix'=>$numbering==='perRow'?$letter:'','accessibleSeats'=>!empty($in['accessible'])?[1]:[]])['id'];
        }$result=['created'=>$rows,'seats'=>$rows*$seats,'ids'=>$ids];$e['operations'][]=['key'=>$op['key'],'result'=>$result,'at'=>Support::now()];return $result;
    }
    public static function saveSection(&$e,$in) {
        self::revision($e,$in);$sections=self::sections($e);$id=$in['sectionId']??Support::id();$index=null;foreach($sections as $i=>$section)if($section['id']===$id)$index=$i;
        if(isset($in['sectionId'])&&$index===null)Support::fail('SECTION','Section not found.',404);
        if(!empty($in['delete'])){if($index===null)Support::fail('SECTION','Choose an existing section to remove.',404);foreach($e['tables'] as $table)if(($table['sectionId']??'')===$id&&empty($in['moveToUnsectioned']))Support::fail('SECTION_OCCUPIED','Confirm moving this section\'s tables and rows to Unsectioned.',409);foreach($e['tables'] as &$table)if(($table['sectionId']??'')===$id)$table['sectionId']='';unset($table);foreach($e['groups'] as &$group)if(($group['sectionId']??'')===$id)$group['sectionId']='';unset($group);array_splice($sections,$index,1);}
        else{$label=Support::text($in['label']??'',80,true);$color=Support::text($in['color']??'#54866a',7);if(!preg_match('/^#[a-f0-9]{6}$/iD',$color))Support::fail('SECTION','Choose a valid section colour.');foreach($sections as $section)if($section['id']!==$id&&mb_strtolower($section['label'])===mb_strtolower($label))Support::fail('SECTION','That section name is already in use.',409);$value=['id'=>$id,'label'=>$label,'color'=>$color];if($index===null){if(count($sections)>=30)Support::fail('LIMIT','Up to 30 seating sections are supported.');$sections[]=$value;}else $sections[$index]=$value;}
        $e['seatingSections']=$sections;$e['layoutRevision']++;return ['saved'=>true];
    }
    public static function saveGroup(&$e,$in) {
        self::revision($e,$in);$id=$in['groupId']??Support::id();$index=null;$old=null;foreach($e['groups'] as $i=>$group)if($group['id']===$id){$index=$i;$old=$group;break;}
        if(isset($in['groupId'])&&$index===null)Support::fail('GROUP','Guest group not found.',404);$original=$old['label']??Support::text($in['originalLabel']??'',100);$label=Support::text($in['label']??'',100,true);$section=Support::text($in['sectionId']??'',40);self::section($e,$section);$members=$in['guestIds']??[];
        if(!is_array($members)||count($members)>1000||count(array_unique($members))!==count($members))Support::fail('GROUP','Choose valid attendees for this group.');foreach($members as $guest)Domain::guestIndex($e,$guest);
        foreach($e['groups'] as $group)if($group['id']!==$id&&mb_strtolower($group['label'])===mb_strtolower($label))Support::fail('GROUP','That group already exists. Open it to make changes.',409);
        if($original===''&&$index===null)foreach($e['guests'] as $guest)if(mb_strtolower($guest['group'])===mb_strtolower($label))Support::fail('GROUP','That group already exists. Open it to make changes.',409);
        $color=Support::text($in['color']??'#53765e',7);if(!preg_match('/^#[a-f0-9]{6}$/iD',$color))Support::fail('GROUP','Choose a valid group colour.');$deleting=!empty($in['delete']);
        foreach($e['guests'] as &$guest){if(!$deleting&&in_array($guest['id'],$members,true))$guest['group']=$label;elseif($original!==''&&mb_strtolower($guest['group'])===mb_strtolower($original))$guest['group']='';}unset($guest);
        foreach($e['parties'] as &$party)if($original!==''&&($party['group']??'')===$original)$party['group']=$deleting?'':$label;unset($party);
        if($deleting){if($index!==null)array_splice($e['groups'],$index,1);}
        else{$value=['id'=>$id,'label'=>$label,'color'=>$color,'sectionId'=>$section,'keepTogether'=>!empty($in['keepTogether'])];if($index===null){if(count($e['groups'])>=100)Support::fail('LIMIT','Up to 100 guest groups are supported.');$e['groups'][]=$value;}else $e['groups'][$index]=$value;}
        $e['layoutRevision']++;return ['saved'=>true];
    }
    public static function assertLayout($e) {
        $sections=[];$labels=[];foreach(self::sections($e) as $section){if(isset($sections[$section['id']])||isset($labels[mb_strtolower($section['label'])]))Support::fail('SECTION','Duplicate section.',409);$sections[$section['id']]=true;$labels[mb_strtolower($section['label'])]=true;}
        $numbers=[];foreach($e['tables'] as $table){$section=$table['sectionId']??'';if($section!==''&&!isset($sections[$section]))Support::fail('SECTION','A table refers to a missing section.',409);$n=self::number($table);if($n!==null){if(isset($numbers[$n]))Support::fail('TABLE_NUMBER','Table numbers must be unique.',409);$numbers[$n]=true;}}
        $groups=[];foreach($e['groups'] as $group){if(isset($groups[mb_strtolower($group['label'])]))Support::fail('GROUP','Duplicate guest group.',409);$groups[mb_strtolower($group['label'])]=true;if(($group['sectionId']??'')!==''&&!isset($sections[$group['sectionId']]))Support::fail('GROUP','A group refers to a missing seating section.',409);}
    }
    public static function assign(&$e,$in) {
        self::revision($e,$in);$guest=$e['guests'][Domain::guestIndex($e,$in['guestId']??'')];$seat=$in['seatId']??'';
        foreach($e['assignments'] as $a){if($a['guestId']===$guest['id']&&$a['locked']&&empty($in['unlock']))Support::fail('LOCKED','Unlock this guest\'s current seat before moving them.',409);if($a['seatId']===$seat&&$a['guestId']!==$guest['id'])Support::fail('SEAT_TAKEN','This seat is already assigned. Choose another seat.',409);}
        $e['assignments']=array_values(array_filter($e['assignments'],function($a)use($guest){return $a['guestId']!==$guest['id'];}));if($seat!=='')$e['assignments'][]=['seatId'=>$seat,'guestId'=>$guest['id'],'locked'=>!empty($in['locked'])];Domain::assertInvariants($e);return ['assigned'=>$seat!==''];
    }
    public static function plan($e,$in) {
        $globalSplit=!empty($in['allowSplit']);$groups=[];$occupied=[];$guestTable=[];$tableBySeat=[];$assigned=[];
        foreach($e['tables'] as $table)foreach($table['seats'] as $seat)$tableBySeat[$seat['id']]=$table['id'];foreach($e['assignments'] as $a){$occupied[$a['seatId']]=$a['guestId'];$assigned[$a['guestId']]=true;$guestTable[$a['guestId']]=$tableBySeat[$a['seatId']]??null;}
        $preferences=[];foreach($e['groups'] as $group)$preferences[mb_strtolower($group['label'])]=$group;
        foreach($e['guests'] as $guest){$key=$guest['group']!==''?'group:'.mb_strtolower($guest['group']):'party:'.$guest['partyId'];if($guest['rsvp']==='Attending'){$groups[$key]['all'][]=$guest;if(!isset($assigned[$guest['id']]))$groups[$key]['pending'][]=$guest;}}
        uasort($groups,function($a,$b){$av=max(array_map(function($g){return (int)$g['vip'];},$a['all']));$bv=max(array_map(function($g){return (int)$g['vip'];},$b['all']));return $bv<=>$av?:count($b['all'])<=>count($a['all'])?:strcmp($a['all'][0]['id'],$b['all'][0]['id']);});
        $tables=$e['tables'];usort($tables,function($a,$b){return (int)$b['vip']<=>(int)$a['vip']?:strcmp($a['id'],$b['id']);});$plan=[];$unsatisfied=[];
        foreach($groups as $group){$members=$group['pending']??[];if(!$members)continue;$label=$members[0]['group'];if($label===''){$label='Invitation party';foreach($e['parties'] as $party)if($party['id']===$members[0]['partyId'])$label=$party['label'];}$pref=$preferences[mb_strtolower($members[0]['group'])]??null;$split=$pref?!$pref['keepTogether']:$globalSplit;$section=$pref['sectionId']??'';
            $existing=[];foreach($group['all'] as $guest)if(isset($guestTable[$guest['id']]))$existing[$guestTable[$guest['id']]]=true;
            $candidates=array_values(array_filter($tables,function($t)use($section,$split,$existing){return ($section===''||($t['sectionId']??'')===$section)&&($split||!$existing||isset($existing[$t['id']]))&&($split||count($existing)<=1);}));
            usort($members,function($a,$b){return (int)$b['accessible']<=>(int)$a['accessible']?:strcmp($a['id'],$b['id']);});$placed=false;
            foreach($candidates as $table){$free=array_values(array_filter($table['seats'],function($s)use($occupied){return !isset($occupied[$s['id']]);}));if(count($free)<count($members))continue;usort($free,function($a,$b){return (int)$b['accessible']<=>(int)$a['accessible'];});$trial=[];
                foreach($members as $guest){$chosen=null;foreach($free as $i=>$seat)if(!$guest['accessible']||$seat['accessible']){$chosen=$seat;array_splice($free,$i,1);break;}if(!$chosen){$trial=[];break;}$trial[]=['guestId'=>$guest['id'],'seatId'=>$chosen['id'],'locked'=>false];}
                if(count($trial)===count($members)){foreach($trial as $a){$plan[]=$a;$occupied[$a['seatId']]=$a['guestId'];}$placed=true;break;}
            }
            if(!$placed&&$split){$trial=[];$local=$occupied;foreach($members as $guest){$found=false;foreach($candidates as $table)foreach($table['seats'] as $seat)if(!isset($local[$seat['id']])&&(!$guest['accessible']||$seat['accessible'])){$trial[]=['guestId'=>$guest['id'],'seatId'=>$seat['id'],'locked'=>false];$local[$seat['id']]=$guest['id'];$found=true;break 2;}if(!$found)break;}if(count($trial)===count($members)){$plan=array_merge($plan,$trial);$occupied=$local;$placed=true;}}
            if(!$placed)$unsatisfied[]=['group'=>$label,'count'=>count($members),'reason'=>$section!==''?'Not enough compatible seats in the chosen section.':'No compatible table can keep this group together.'];
        }
        return ['revision'=>$e['revision'],'layoutRevision'=>$e['layoutRevision'],'assignments'=>$plan,'unsatisfied'=>$unsatisfied,'heuristic'=>'VIP and larger groups first. Preserve current seats; keep marked groups together, respect their section and accessible-seat needs.'];
    }
    public static function lookup($e,$guestIds) {
        $out=[];foreach($e['assignments'] as $a)if(in_array($a['guestId'],$guestIds,true))foreach(self::viewTables($e) as $table)foreach($table['seats'] as $seat)if($seat['id']===$a['seatId']){$guest=$e['guests'][Domain::guestIndex($e,$a['guestId'])];$section='';foreach(self::sections($e) as $s)if($s['id']===($table['sectionId']??''))$section=$s['label'];$out[]=['name'=>$guest['name'],'table'=>self::title($table),'tableNumber'=>self::number($table),'section'=>$section,'seat'=>$seat['label'],'accessible'=>$seat['accessible']];}return $out;
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
