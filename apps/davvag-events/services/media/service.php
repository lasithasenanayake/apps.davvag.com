<?php
namespace davvag_events;

require_once dirname(__DIR__,2).'/lib/bootstrap.php';

final class MediaService extends Service {
    public function getQueue($req,$res) { return $this->run($req,$res,function($in){$e=Store::load($in['eventId']??'');Identity::authorize($e,Identity::actor(),'media');return ['media'=>array_map(function($m){unset($m['staging'],$m['sessionUrl'],$m['driveId']);return $m;},$e['media']),'revision'=>$e['revision']];}); }
    public function postUpload($req,$res) {
        return $this->multipart($res,function($in){$id=$in['eventId']??'';Store::limit($id,'upload',10);$e=Store::load($id);$cap=Domain::capability($e,'rsvp');$rev=$e['revision'];$existingIds=array_column($e['media'],'id');$file=$_FILES['file']??null;$result=Media::ingest($e,$cap['reference'],$file,$in);
            try{Store::save($e,$rev,'party:'.$cap['reference'],'upload_queue');}catch(\Throwable $err){if(!in_array($result['id'],$existingIds,true))foreach($e['media'] as $m)if($m['id']===$result['id']){$p=Media::path($m['staging']);if(is_file($p))unlink($p);}throw $err;}return $result;});
    }
    public function postCancel($req,$res) { return $this->run($req,$res,function($in){$e=Store::load($in['eventId']??'');$cap=Domain::capability($e,'rsvp');$rev=$e['revision'];foreach($e['media'] as &$m)if($m['id']===($in['uploadId']??'')&&$m['partyId']===$cap['reference']){if($m['status']==='uploaded')Support::fail('UPLOADED','Ask the host to remove an uploaded file.',409);$m['status']='cancelled';unset($m);Store::save($e,$rev,'party:'.$cap['reference'],'upload_cancel');return ['cancelled'=>true];}Support::fail('UPLOAD','Upload not found.',404);},true); }
}
