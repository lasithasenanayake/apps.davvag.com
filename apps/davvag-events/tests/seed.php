<?php
// Representative fixtures in a dedicated test database only. Never seeds production bearer links.
if(isset($argv[1])&&str_starts_with($argv[1],'--cleanup='))putenv('DAVVAG_EVENTS_TEST_DOMAIN='.substr($argv[1],10));
require __DIR__.'/bootstrap.php';
use davvag_events\{Domain,Store,Seating,Ticketing,Admission,Support};
[$db,$name]=testConnection(false);
if(isset($argv[1])&&str_starts_with($argv[1],'--cleanup=')){$db->query('DROP DATABASE IF EXISTS `'.$name.'`');echo "Removed isolated fixture database.\n";exit;}
$db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db->select_db($name);
foreach(glob(SCHEMA_PATH.'/migrations/davvag-events/*.sql') as $f)foreach(explode("\n-- STATEMENT --\n",file_get_contents($f)) as $sql)if(trim($sql)!=='')$db->query($sql);
SOSSData::WithServiceNamespaces(eventsNamespaces(),function(){foreach(Domain::OCCASIONS as $occasion){
    $e=fixture($occasion);$e['fixtureOnly']=true;$party=Domain::addParty($e,['names'=>['Alex Morgan','Alex Morgan'],'plusOnes'=>1,'group'=>'Family','vip'=>true]);$declined=Domain::addParty($e,['names'=>["Zoë O'Connor"],'group'=>'Friends']);
    foreach($e['guests'] as &$g)$g['rsvp']=$g['partyId']===$declined['id']?'Declined':'Attending';unset($g);
    Seating::saveTable($e,['label'=>'Full family table','shape'=>'round','capacity'=>2,'layoutRevision'=>0,'accessibleSeats'=>[1],'vip'=>true]);foreach(array_slice($e['guests'],0,2) as $i=>$g)Seating::assign($e,['guestId'=>$g['id'],'seatId'=>$e['tables'][0]['seats'][$i]['id'],'layoutRevision'=>$e['layoutRevision'],'locked'=>$i===0]);
    $e['greetings'][]=['id'=>Support::id(),'operation'=>'fixture-pending','displayName'=>'A fictional guest','message'=>'Wishing you a beautiful day.','status'=>'pending','at'=>Support::now()];
    $e['media'][]=['id'=>Support::id(),'partyId'=>$party['id'],'uploader'=>'Fictional guest','consent'=>true,'originalName'=>'fictional-photo.jpg','mime'=>'image/jpeg','bytes'=>100,'sha256'=>'fixture','md5'=>'fixture','status'=>'failed','reason'=>'Test fixture: disconnected Drive account.','offset'=>0,'createdAt'=>Support::now()];
    if($occasion==='ticketed')Ticketing::type($e,['name'=>'Fixture admission','price'=>0,'currency'=>'USD','capacity'=>20,'limit'=>3,'start'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'end'=>$e['draft']['end']]);Domain::transition($e,'published');
    if($occasion==='ticketed')for($i=0;$i<3;$i++){$o=Ticketing::order($e,['operation'=>Support::id(),'typeId'=>$e['ticketTypes'][0]['id'],'attendees'=>['Fictional ticket holder '.($i+1)]]);if($i===1){$display=Ticketing::display($e,count($e['orders'])-1);Admission::checkin($e,$display['tickets'][0]['code'],'fixture-staff');}if($i===2)Ticketing::revoke($e,$o['id'],'refunded');}
    Store::save($e,0,'fixture','seed');echo $occasion.': '.$e['id']."\n";
}});
echo 'Fixture domain: '.DATASTORE_DOMAIN."\n";
echo 'Cleanup: php tests/seed.php --cleanup='.DATASTORE_DOMAIN."\n";
