<?php
namespace davvag_events;

require_once dirname(__DIR__,2).'/lib/bootstrap.php';

final class OAuthService extends Service {
    public function getStatus($req,$res) { return $this->run($req,$res,function($in){$a=Identity::actor();$e=Store::load($in['eventId']??'');Identity::authorize($e,$a,'credentials');$c=Store::connection($a['id']);return ['configured'=>(bool)Drive::configured(),'status'=>$c['state']['status'],'scope'=>Drive::SCOPE,'setup'=>'Use your Google confidential web client and register '.Support::baseUrl().'components/davvag-events/oauth/service/Callback'];}); }
    public function postConnect($req,$res) { return $this->run($req,$res,function($in){$a=Identity::actor();$e=Store::load($in['eventId']??'');if($e['ownerProfile']!==$a['id'])Support::fail('OWNER','Only the event owner can connect their Drive.',403);return ['url'=>Drive::connect($a)];},true); }
    public function getCallback($req,$res) { return $this->run($req,$res,function($in){$r=Drive::callback(Identity::actor(),$in);header('Location: '.Support::baseUrl().'#/app/davvag-events/dashboard',true,303);return $r;}); }
    public function postDisconnect($req,$res) { return $this->run($req,$res,function($in){$a=Identity::actor();$e=Store::load($in['eventId']??'');if($e['ownerProfile']!==$a['id'])Support::fail('OWNER','Only the owner can disconnect their Drive.',403);return Drive::disconnect($a);},true); }
}
