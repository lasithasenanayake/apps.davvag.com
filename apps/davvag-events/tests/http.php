<?php
ob_start();
// Scoped local HTTP fixtures. No invitations, payments, or provider transfers are sent.
require dirname(__DIR__).'/bin/bootstrap.php';
use davvag_events\{Domain,Store,Support};
$base='http://localhost/davvag-core/';$ids=[];$profiles=[];$sessionIds=[];$checks=0;
function httpCall($path,$method='GET',$body=null,$cookie=''){
    global $base;$ch=curl_init($base.$path);$headers=['Accept: application/json'];if($body!==null)$headers[]='Content-Type: application/json';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,CURLOPT_COOKIE=>$cookie]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$ch = null;return ['status'=>$status,'body'=>json_decode($raw,true),'raw'=>$raw];
}
function httpCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException('HTTP FAILED: '.$label);$checks++;echo 'PASS '.$label."\n";}
function fixtureSession($profile,$group='web_user'){
    global $profiles,$sessionIds;$user='events-http-'.Support::id();$r=SOSSData::Insert('profile',(object)['id'=>$profile,'name'=>'Fictional event tester','linkeduserid'=>$user]);if(empty($r->success))throw new RuntimeException('Unable to install isolated HTTP profile fixture.');$profiles[]=$profile;
    $id=bin2hex(random_bytes(16));session_id($id);session_start();$_SESSION=['authData'=>(object)['userid'=>$user,'token'=>Support::secret(),'group'=>$group],'viewObjects'=>[0],'viewObjects_e'=>[0],'viewObjects_f'=>[0]];if($group==='anonymous')unset($_SESSION['authData']);session_write_close();$sessionIds[]=$id;return 'PHPSESSID='.$id;
}
$c=json_decode(file_get_contents(DB_CONFIG_FILE));$db=new mysqli($c->mysql_server,$c->mysql_username,$c->mysql_password,$c->init_db.str_replace('.','_',DATASTORE_DOMAIN));$db->set_charset('utf8mb4');
try{
    $owner=fixtureSession(random_int(1800000000,1850000000));$other=fixtureSession(random_int(1850000001,1890000000));$ownerProfile=(string)$profiles[0];
    $s=httpCall('components/davvag-events/events/service/Session','GET',null,$owner);httpCheck($s['status']===200&&!empty($s['body']['result']['actor']),'authenticated framework session resolves profile');$csrf=$s['body']['result']['csrf'];
    $data=['title'=>'Fictional HTTP Journey','occasion'=>'wedding','timezone'=>'UTC','start'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'end'=>gmdate('Y-m-d\TH:i:s\Z',time()+18000),'venue'=>'Fictional test hall','privacy'=>'private','csrf'=>$csrf];
    $r=httpCall('components/davvag-events/events/service/SaveEvent','POST',$data,$owner);httpCheck($r['status']===200&&!empty($r['body']['success']),'owner creates persistent event through dispatcher');$e=$r['body']['result'];$ids[]=$e['id'];$eventId=$e['id'];
    $bad=httpCall('components/davvag-events/events/service/Event?eventId='.$eventId,'GET',null,$other);httpCheck($bad['status']===403&&$bad['body']['success']===false,'another authenticated owner gets outer failure and 403');
    $bad=httpCall('components/davvag-events/events/service/SaveEvent','POST',['eventId'=>$eventId,'revision'=>$e['revision'],'title'=>'Spoof','csrf'=>'wrong'],$owner);httpCheck($bad['status']===403&&$bad['body']['success']===false,'CSRF failure propagates through framework envelope');
    $r=httpCall('components/davvag-events/guests/service/AddParty','POST',['eventId'=>$eventId,'names'=>["D'Arcy 李",'Alex Morgan'],'plusOnes'=>1,'email'=>'guest@example.invalid','csrf'=>$csrf],$owner);httpCheck($r['status']===200&&$r['body']['success'],'Unicode guest party added');$party=$r['body']['result']['party'];
    $r=httpCall('components/davvag-events/guests/service/RotateInvitation','POST',['eventId'=>$eventId,'partyId'=>$party['id'],'csrf'=>$csrf],$owner);httpCheck($r['status']===200&&!empty($r['body']['result']['credential']),'one-time invitation delivery material returned');$secret=$r['body']['result']['credential'];
    $fresh=httpCall('components/davvag-events/events/service/Event?eventId='.$eventId,'GET',null,$owner)['body']['result'];
    $r=httpCall('components/davvag-events/events/service/Transition','POST',['eventId'=>$eventId,'status'=>'published','revision'=>$fresh['revision'],'csrf'=>$csrf],$owner);httpCheck($r['status']===200&&$r['body']['success'],'owner publishes snapshot through service');
    $guestCookie=fixtureSession(random_int(1890000001,1900000000),'anonymous'); // A separate browser session; no authenticated identity is needed for the public methods.
    $gs=httpCall('components/davvag-events/events/service/Session','GET',null,$guestCookie);$gc=$gs['body']['result']['csrf'];
    $r=httpCall('components/davvag-events/public/service/Invitation?eventId='.$eventId,'GET',null,$guestCookie);httpCheck($r['status']===403,'private invitation refuses unexchanged capability');
    $ch=curl_init($base.'components/davvag-events/public/service/Exchange');$newCookie='';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['eventId'=>$eventId,'credential'=>$secret,'csrf'=>$gc]),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_COOKIE=>$guestCookie,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$newCookie){if(preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i',$line,$m))$newCookie=$m[1];return strlen($line);}]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$ch = null;$response=json_decode($raw,true);httpCheck($status===200&&$response['success'],'anonymous capability exchange succeeds');if($newCookie){$guestCookie=$newCookie;$sessionIds[]=substr($newCookie,10);}
    $r=httpCall('components/davvag-events/public/service/Invitation?eventId='.$eventId,'GET',null,$guestCookie);httpCheck($r['status']===200&&count($r['body']['result']['party']['attendees'])===2&&!isset($r['body']['result']['guests']),'invitation returns only the authenticated party');$att=$r['body']['result']['party']['attendees'];foreach($att as &$g)$g['rsvp']='Attending';unset($g);$att[]=['id'=>'','name'=>'Permitted Plus One','rsvp'=>'Attending'];$op=Support::id();
    $payload=['eventId'=>$eventId,'attendees'=>$att,'operation'=>$op,'csrf'=>$gc];$r=httpCall('components/davvag-events/public/service/Respond','POST',$payload,$guestCookie);$retry=httpCall('components/davvag-events/public/service/Respond','POST',$payload,$guestCookie);httpCheck($r['status']===200&&$retry['status']===200&&count($retry['body']['result']['attendees'])===3,'retried RSVP creates one plus-one only');
    $r=httpCall('components/davvag-events/greetings/service/Submit','POST',['eventId'=>$eventId,'displayName'=>'Fictional guest','message'=>'<script>alert(1)</script> Best wishes!','operation'=>Support::id(),'csrf'=>$gc],$guestCookie);httpCheck($r['status']===200&&$r['body']['result']['status']==='pending','greeting defaults to pending');
    $wall=httpCall('components/davvag-events/greetings/service/Wall?eventId='.$eventId,'GET',null,$guestCookie);httpCheck($wall['status']===200&&count($wall['body']['result']['greetings'])===0,'pending greeting absent from public wall response');
    $mod=httpCall('components/davvag-events/greetings/service/Moderation?eventId='.$eventId,'GET',null,$owner)['body']['result'];
    $r=httpCall('components/davvag-events/greetings/service/Moderate','POST',['eventId'=>$eventId,'greetingId'=>$mod['greetings'][0]['id'],'status'=>'approved','csrf'=>$csrf],$owner);$wall=httpCall('components/davvag-events/greetings/service/Wall?eventId='.$eventId,'GET',null,$guestCookie);httpCheck($wall['status']===200&&count($wall['body']['result']['greetings'])===1,'approved greeting appears in next public poll');
    foreach(['components/davvag-events/events/file/service.php','components/davvag-events/editor-view/file/%2e%2e/%2e%2e/lib/bootstrap.php','files/davvag-events/staged.stage','davvag-core/localhost/apps/davvag-events/lib/bootstrap.php'] as $path){$r=httpCall($path,'GET',null,$owner);httpCheck($r['status']>=400&&!str_contains($r['raw'],'class EventService'),'private source/staging retrieval denied: '.explode('?',$path)[0]);}
    $desc=httpCall('components/davvag-events/guests-view/object?object=desc','GET',null,$owner);httpCheck($desc['status']===200&&isset($desc['body']['result']['resources']['files']),'UI and service component identifiers do not collide');
    echo "\n$checks HTTP journey and public-path checks passed.\n";
}finally{
    foreach($ids as $id){$q=$db->prepare('DELETE FROM davvag_events_events WHERE id=?');$q->bind_param('s',$id);$q->execute();$q->close();}
    foreach($profiles as $id){$q=$db->prepare('DELETE FROM profile WHERE id=?');$q->bind_param('i',$id);$q->execute();$q->close();}
    foreach(array_unique($sessionIds) as $id){session_id($id);@session_start();@session_destroy();}
    echo "Scoped HTTP event/profile/session fixtures removed.\n";
}
