<?php
// App-only identity regression tests. No database, account, or core writes.
ob_start();
class Auth { public static $user; public static function Autendicate(){return self::$user;} }
class SOSSData {
    public static $profiles=[],$available=true,$queries=[];
    public static function Query($namespace,$query){
        self::$queries[]=$query;
        if(!self::$available)return (object)['success'=>false];
        if($namespace!=='profile')throw new RuntimeException('Unexpected namespace.');
        $rows=array_values(array_filter(self::$profiles,function($p)use($query){foreach($query['conditions'] as $c)if((string)($p[$c['column']]??'')!==(string)$c['value'])return false;return true;}));
        usort($rows,function($a,$b){return $a['id']<=>$b['id'];});
        return (object)['success'=>true,'result'=>array_map(function($p){return (object)$p;},array_slice($rows,0,$query['pageSize']))];
    }
}
define('TENANT_RESOURCE_LOCATION',dirname(__DIR__,3));
require dirname(__DIR__).'/services/events/service.php';
use davvag_events\{Identity,Fault,EventService};
$checks=0;
function expect($condition,$label){global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function fault($expected,$callback){try{$callback();}catch(Fault $e){expect($e->reason===$expected,$expected.' remains explicit');return;}throw new RuntimeException('Expected '.$expected);}
Auth::$user=(object)['userid'=>'account-a','token'=>'fictional-session','group'=>'web_user'];
SOSSData::$profiles=[['id'=>131,'linkeduserid'=>'account-a','mainprofileid'=>0],['id'=>1,'linkeduserid'=>'account-a','mainprofileid'=>0],['id'=>0,'linkeduserid'=>'account-b','mainprofileid'=>0]];
expect(Identity::actor()['id']==='1','multiple profiles select the oldest linked primary profile');
SOSSData::$profiles=array_reverse(SOSSData::$profiles);expect(Identity::actor()['id']==='1','primary selection is stable across row order');
SOSSData::$profiles=[['id'=>1,'linkeduserid'=>'account-a','mainprofileid'=>131],['id'=>131,'linkeduserid'=>'account-a','mainprofileid'=>0]];expect(Identity::actor()['id']==='131','primary profile is preferred over a child profile');
SOSSData::$profiles=[['id'=>7,'linkeduserid'=>'account-a','mainprofileid'=>null]];expect(Identity::actor()['id']==='7','legacy linked profile without primary metadata works');
expect(SOSSData::$queries[0]['conditions'][0]['column']==='linkeduserid'&&SOSSData::$queries[0]['pageSize']===1,'lookup remains authenticated-link scoped and bounded');
$_SESSION=[];
$req=new class {public function Query(){return new stdClass();}};
$res=new class {public $error;public function SetError($v){$this->error=$v;}};
SOSSData::$profiles=[['id'=>131,'linkeduserid'=>'account-a','mainprofileid'=>0],['id'=>1,'linkeduserid'=>'account-a','mainprofileid'=>0]];
$session=(new EventService())->getSession($req,$res);expect($session['actor']['id']==='1'&&$session['identityError']===null,'Session returns the signed-in actor with duplicate linked profiles');
SOSSData::$profiles=[['id'=>1,'linkeduserid'=>'account-b','mainprofileid'=>0]];fault('PROFILE_REQUIRED',function(){Identity::actor();});
$session=(new EventService())->getSession($req,$res);expect($session['actor']===null&&$session['identityError']['code']==='PROFILE_REQUIRED','missing profile is distinguished from being signed out');
Auth::$user=null;fault('AUTH_REQUIRED',function(){Identity::actor();});
$session=(new EventService())->getSession($req,$res);expect($session['actor']===null&&$session['identityError']['code']==='AUTH_REQUIRED','anonymous session gets the sign-in reason');
Auth::$user=(object)['userid'=>'account-a','token'=>'fictional-session','group'=>'web_user'];SOSSData::$available=false;fault('DATASTORE',function(){Identity::actor();});
echo $checks.' identity/session regression checks passed.'.PHP_EOL;
