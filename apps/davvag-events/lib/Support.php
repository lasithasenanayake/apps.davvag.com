<?php
namespace davvag_events;

final class Fault extends \RuntimeException {
    public $status;
    public $reason;
    public function __construct($reason, $message, $status = 422) { parent::__construct($message); $this->reason = $reason; $this->status = $status; }
}
final class Support {
    public static function fail($code,$message,$status=422) { throw new Fault($code,$message,$status); }
    public static function id() { return bin2hex(random_bytes(16)); }
    public static function secret() { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
    public static function now() { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function text($v,$max=200,$required=false) {
        if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v))self::fail('VALIDATION','Enter valid text.');
        $v=trim($v); if(mb_strlen($v)>$max||($required&&$v===''))self::fail('VALIDATION','A required field is empty or too long.'); return $v;
    }
    public static function integer($v,$min,$max) { if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)self::fail('VALIDATION',"Enter a whole number between $min and $max.");return (int)$v; }
    public static function choice($v,$allowed) { if(!in_array($v,$allowed,true))self::fail('VALIDATION','Unsupported selection.');return $v; }
    public static function instant($v,$optional=false) {
        if($optional&&($v===''||$v===null))return null;
        if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(:\d\d)?(Z|[+-]\d\d:\d\d)$/D',$v))self::fail('DATE','Use an ISO date with an explicit UTC offset.');
        try{$d=new \DateTimeImmutable($v);if(\DateTimeImmutable::getLastErrors())self::fail('DATE','Invalid date.');return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}catch(\Exception $e){self::fail('DATE','Invalid date.');}
    }
    public static function localInstant($value,$timezone,$optional=false) {
        if($optional&&($value===''||$value===null))return null;
        if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D',$value))self::fail('DATE','Choose a valid date and time.');
        try{$zone=new \DateTimeZone($timezone);$d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$zone);if(!$d||\DateTimeImmutable::getLastErrors()||$d->format('Y-m-d\TH:i')!==$value)self::fail('DATE','This time does not exist in the selected timezone. Choose another time.');return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}catch(Fault $e){throw $e;}catch(\Throwable $e){self::fail('DATE','Choose a valid date, time and timezone.');}
    }
    public static function localValue($utc,$timezone) {if(!$utc)return '';return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d\TH:i');}
    public static function email($v) { $v=self::text($v??'',254);if($v!==''&&!filter_var($v,FILTER_VALIDATE_EMAIL))self::fail('EMAIL','Enter a valid email address.');return $v; }
    public static function maps($v) {
        $v=self::text($v??'',1000);if($v==='')return ''; $u=parse_url($v);
        if(($u['scheme']??'')!=='https'||!in_array(strtolower($u['host']??''),['maps.google.com','www.google.com','google.com','maps.app.goo.gl'],true)||isset($u['user'])||isset($u['port']))self::fail('MAPS','Use an HTTPS Google Maps link.');return $v;
    }
    public static function key() {
        $p=getenv('DAVVAG_EVENTS_KEY_FILE');if(!$p||!is_file($p))self::fail('SETUP','Configure DAVVAG_EVENTS_KEY_FILE outside the public web directory.',503);
        $real=str_replace('\\','/',realpath($p));$public=str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??BASE_PATH));
        if($public&&stripos($real,rtrim($public,'/').'/')===0)self::fail('SETUP','The encryption key must be outside the public web directory.',503);
        $key=base64_decode(trim(file_get_contents($p)),true);if($key===false||strlen($key)!==32)self::fail('SETUP','Use a base64 encoded 32 byte encryption key.',503);return $key;
    }
    public static function encrypt($v) { $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($v,JSON_THROW_ON_ERROR),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,(string)DATASTORE_DOMAIN);return base64_encode($iv.$tag.$cipher); }
    public static function decrypt($v) {
        $d=base64_decode($v,true);if($d===false||strlen($d)<29)self::fail('CREDENTIALS','Reconnect your provider account.',503);
        $p=openssl_decrypt(substr($d,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($d,0,12),substr($d,12,16),(string)DATASTORE_DOMAIN);
        if($p===false)self::fail('CREDENTIALS','Reconnect your provider account.',503);return json_decode($p,true,512,JSON_THROW_ON_ERROR);
    }
    public static function baseUrl() {
        $p=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'/components/index.php');$i=strpos($p,'/components/');$base=$i===false?rtrim(dirname($p),'/.'):substr($p,0,$i);$host=$_SERVER['HTTP_HOST']??HOST_NAME;
        if(!preg_match('/^[A-Za-z0-9.\-]+(:[0-9]+)?$/D',$host))self::fail('HOST','Invalid request host.',400);
        return ((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https://':'http://').$host.$base.'/';
    }
    public static function publicUrl($id,$secret='') { return self::baseUrl().'#/app/davvag-events/invitation?eventId='.rawurlencode($id).($secret!==''?'&credential='.rawurlencode($secret):''); }
    public static function csvCell($v) { $v=(string)$v;return preg_match('/^[\s]*[=+@\-\t\r]/u',$v)?"'".$v:$v; }
}
final class Identity {
    public static function user() { $u=\Auth::Autendicate();if(!$u||empty($u->userid)||empty($u->token))Support::fail('AUTH_REQUIRED','Sign in to DAVVAG to continue.',401);return $u; }
    public static function actor() {
        $u=self::user();
        // DAVVAG permits multiple profiles linked to one account. Use a stable primary
        // profile, retaining normal view-object filtering and the authenticated link.
        $conditions=[['column'=>'linkeduserid','operator'=>'=','value'=>(string)$u->userid]];
        $query=['conditions'=>array_merge($conditions,[['column'=>'mainprofileid','operator'=>'=','value'=>0]]),'sorting'=>[['column'=>'id','direction'=>'ASC']],'pageSize'=>1,'pageFrom'=>0];
        $r=\SOSSData::Query('profile',$query);
        if(!$r||empty($r->success))Support::fail('DATASTORE','Unable to resolve your profile.',503);
        if(empty($r->result)){$query['conditions']=$conditions;$r=\SOSSData::Query('profile',$query);}
        if(!$r||empty($r->success))Support::fail('DATASTORE','Unable to resolve your profile.',503);
        if(empty($r->result))Support::fail('PROFILE_REQUIRED','Your signed-in account has no accessible linked DAVVAG profile. Link a profile to this account to manage events.',403);
        return ['id'=>(string)$r->result[0]->id,'userId'=>(string)$u->userid,'admin'=>($u->group??'')==='sysadmin'];
    }
    public static function authorize($e,$a,$op) {
        if($e['ownerProfile']===$a['id'])return 'owner';if(!empty($a['admin']))return 'administrator';$role=null;$grants=[];
        foreach($e['members'] as $m)if($m['profileId']===$a['id']){$role=$m['role'];$grants=$m['grants'];break;}
        if(($op==='checkin'&&$role==='checkin')||($op==='moderate'&&$role==='moderator')||($op==='view'&&$role))return $role;
        if($role==='manager'&&in_array($op,$grants,true)&&!in_array($op,['owner','credentials','payments','members','delete'],true))return $role;
        Support::fail('FORBIDDEN','You do not have access to this event operation.',403);
    }
    public static function csrfToken() { if(empty($_SESSION['davvag_events_csrf']))$_SESSION['davvag_events_csrf']=Support::secret();return $_SESSION['davvag_events_csrf']; }
    public static function csrf($in) {
        $sent=$_SERVER['HTTP_X_DAVVAG_CSRF']??($in['csrf']??'');if(!is_string($sent)||!hash_equals(self::csrfToken(),$sent))Support::fail('CSRF','Your session changed. Refresh and try again.',403);
        $b=parse_url(Support::baseUrl());$origin=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
        if((isset($_SERVER['HTTP_ORIGIN'])&&rtrim($_SERVER['HTTP_ORIGIN'],'/')!==$origin)||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')Support::fail('ORIGIN','Use the original event website.',403);
    }
}
final class Store {
    public static function rows($ns,$conditions=[],$size=100,$offset=0) {
        $r=\SOSSData::Query($ns,['conditions'=>$conditions,'sorting'=>[['column'=>'id','direction'=>'ASC']],'pageSize'=>$size,'pageFrom'=>$offset]);
        if(!$r||empty($r->success))Support::fail('DATASTORE','Storage is unavailable. Run the event migrations and retry.',503);return $r->result??[];
    }
    public static function load($id) {
        if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))Support::fail('NOT_FOUND','Event not found.',404);
        $r=self::rows('davvag_events_events',[['column'=>'id','operator'=>'=','value'=>$id]],1);if(!$r)Support::fail('NOT_FOUND','Event not found.',404);
        $e=is_string($r[0]->state)?json_decode($r[0]->state,true):json_decode(json_encode($r[0]->state),true);$e['revision']=(int)$r[0]->revision;return $e;
    }
    public static function raw($ns,$params) {
        $r=\SOSSData::ExecuteRaw($ns,(object)['parameters'=>(object)$params]);if(!$r||empty($r->success)||empty($r->result))Support::fail('DATASTORE','The operation could not be saved. Check installation and retry.',503);$row=(array)$r->result[0];
        if(($row['status']??'')==='conflict')Support::fail('CONFLICT','Another request changed this event. Refresh and retry.',409);
        if(($row['status']??'')==='duplicate')Support::fail('CONFLICT','A unique event or allocation already exists.',409);
        if(($row['status']??'')!=='ok')Support::fail('DATASTORE','The operation was rejected by storage.',503);return $row;
    }
    public static function save(&$e,$expected,$actor,$action,$ref='') {
        Domain::assertInvariants($e);$e['audit'][]=['id'=>Support::id(),'actor'=>$actor,'action'=>$action,'reference'=>$ref,'at'=>Support::now()];$s=json_encode($e,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($s)>900000)Support::fail('EVENT_LIMIT','This event reached its storage limit. Export and archive old history.',409);
        $r=self::raw('davvag_events_commit',['id'=>$e['id'],'expected'=>$expected,'slug'=>$e['slug'],'owner'=>$e['ownerProfile'],'state'=>$s,'keys'=>json_encode(Domain::keys($e),JSON_THROW_ON_ERROR),'actor'=>$actor]);$e['revision']=(int)$r['revision'];return $e;
    }
    public static function mutate($id,$actor,$op,$callback,$expected=null) {
        $e=self::load($id);Identity::authorize($e,$actor,$op);if($expected!==null&&(int)$expected!==$e['revision'])Support::fail('CONFLICT','This view is stale. Refresh before saving.',409);
        $rev=$e['revision'];$value=$callback($e);self::save($e,$rev,$actor['id'],$op);return ['event'=>$e,'value'=>$value];
    }
    public static function limit($id,$action,$max=30) {
        $key=hash('sha256',DATASTORE_DOMAIN.'|'.$id.'|'.$action.'|'.($_SERVER['REMOTE_ADDR']??'cli'));
        $r=\SOSSData::ExecuteRaw('davvag_events_rate',(object)['parameters'=>(object)['key'=>$key,'max'=>$max,'window'=>60]]);
        if(!$r||empty($r->success)||empty($r->result))Support::fail('RATE_LIMIT','Please retry shortly.',503);
        if(($r->result[0]->status??'')!=='ok')Support::fail('RATE_LIMIT','Too many attempts. Try again in a minute.',429);
    }
    public static function connection($owner) {
        $id=hash('sha256',DATASTORE_DOMAIN.'|'.$owner);$rows=self::rows('davvag_events_drive_connections',[['column'=>'id','operator'=>'=','value'=>$id]],1);
        if(!$rows)return ['id'=>$id,'ownerProfile'=>$owner,'revision'=>0,'state'=>['status'=>'disconnected','folders'=>[]]];
        $r=(array)$rows[0];$r['state']=is_string($r['state'])?json_decode($r['state'],true):json_decode(json_encode($r['state']),true);return $r;
    }
    public static function saveConnection(&$c) { $r=self::raw('davvag_events_connection_commit',['id'=>$c['id'],'owner'=>$c['ownerProfile'],'expected'=>(int)$c['revision'],'state'=>json_encode($c['state'],JSON_THROW_ON_ERROR)]);$c['revision']=(int)$r['revision']; }
}
abstract class Service {
    protected function multipart($res,$callback) {
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
        try{Identity::csrf($_POST);return $callback($_POST);}
        catch(Fault $e){http_response_code($e->status);$res->SetError(['code'=>$e->reason,'message'=>$e->getMessage()]);return null;}
        catch(\Throwable $e){http_response_code(503);$res->SetError(['code'=>'UNAVAILABLE','message'=>'Upload is temporarily unavailable. Check server setup and retry.']);return null;}
    }
    protected function run($req,$res,$callback,$mutation=false) {
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
        try{$body=$mutation?$req->Body(true):$req->Query();$in=json_decode(json_encode($body??new \stdClass()),true);if(!is_array($in))Support::fail('BODY','Send a JSON object.',400);if($mutation)Identity::csrf($in);return $callback($in);}
        catch(Fault $e){http_response_code($e->status);$res->SetError(['code'=>$e->reason,'message'=>$e->getMessage()]);return null;}
        catch(\Throwable $e){http_response_code(503);$res->SetError(['code'=>'UNAVAILABLE','message'=>'The operation is temporarily unavailable. Check server setup and retry.']);return null;}
    }
}
