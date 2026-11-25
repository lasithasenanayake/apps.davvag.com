<?php
require __DIR__.'/bootstrap.php';
use davvag_events\Store;
use davvag_events\Support;
use davvag_events\Seating;
use davvag_events\Ticketing;
use davvag_events\Admission;
use davvag_events\Jobs;
use davvag_events\Fault;
$in=json_decode(trim(fgets(STDIN)),true);$result=[];
SOSSData::WithServiceNamespaces(eventsNamespaces(),function()use($in,&$result){
    $e=Store::load($in['eventId']);$rev=$e['revision'];echo "READY\n";fflush(STDOUT);fgets(STDIN);
    try{
        if($in['action']==='seat')Seating::assign($e,['guestId'=>$in['guestId'],'seatId'=>$in['seatId'],'layoutRevision'=>$e['layoutRevision']]);
        elseif($in['action']==='checkout')Ticketing::order($e,['operation'=>$in['operation'],'typeId'=>$in['typeId'],'attendees'=>['A fictional guest']]);
        elseif($in['action']==='checkin'){$result=Admission::checkin($e,$in['credential'],'201');}
        elseif($in['action']==='fulfill')Ticketing::fulfill($e,$in['orderIndex'],'pi_fixture');
        elseif($in['action']==='job'){$claim=Jobs::claim($e['id']);$result=['status'=>$claim?'claimed':'not_claimed'];return;}
        Store::save($e,$rev,'test-racer',$in['action']);if(!$result)$result=['status'=>'ok'];
    }catch(Fault $f){if($in['action']==='checkin'&&$f->reason==='CONFLICT'){$e=Store::load($in['eventId']);$result=Admission::checkin($e,$in['credential'],'201');}else $result=['status'=>$f->reason];}
});echo json_encode($result)."\n";
