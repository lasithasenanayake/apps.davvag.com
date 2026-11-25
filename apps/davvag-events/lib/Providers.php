<?php
namespace davvag_events;

final class Http {
    private static $testTransport;
    public static function testTransport($transport) { if(PHP_SAPI!=='cli'||!defined('DAVVAG_EVENTS_TESTING'))throw new \RuntimeException('Provider doubles are restricted to isolated CLI tests.');self::$testTransport=$transport; }
    public static function send($method,$url,$body=null,$headers=[],$length=null) {
        $u=parse_url($url);$host=strtolower($u['host']??'');
        if(($u['scheme']??'')!=='https'||!in_array($host,['oauth2.googleapis.com','accounts.google.com','www.googleapis.com','api.stripe.com'],true)||isset($u['user'])||isset($u['port']))Support::fail('PROVIDER_URL','Invalid provider endpoint.',503);
        if(self::$testTransport)return call_user_func(self::$testTransport,$method,$url,$body,$headers,$length);
        $ch=curl_init($url);$responseHeaders=[];
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>55,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$responseHeaders){$p=explode(':',$line,2);if(count($p)===2)$responseHeaders[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
        if(is_resource($body)){curl_setopt($ch,CURLOPT_UPLOAD,true);curl_setopt($ch,CURLOPT_INFILE,$body);curl_setopt($ch,CURLOPT_INFILESIZE,$length);}
        elseif($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$status===0)Support::fail('PROVIDER_NETWORK','Provider connection interrupted; retry is queued.',503);
        return ['status'=>$status,'headers'=>$responseHeaders,'data'=>json_decode($raw,true),'raw'=>$raw];
    }
    public static function json($method,$url,$body=null,$headers=[]) {
        $r=self::send($method,$url,$body,$headers);
        if($r['status']<200||$r['status']>=300)Support::fail(in_array($r['status'],[401,403],true)?'PROVIDER_AUTH':'PROVIDER_RETRY','The provider rejected the request. Check account access, storage, and quotas.',503);return $r['data']??[];
    }
}
final class Drive {
    const SCOPE='https://www.googleapis.com/auth/drive.file';
    public static function configured() { return getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_ID')&&getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_SECRET')&&getenv('DAVVAG_EVENTS_GOOGLE_REDIRECT_URI')&&getenv('DAVVAG_EVENTS_KEY_FILE'); }
    public static function connect($actor) {
        if(!self::configured())Support::fail('DRIVE_SETUP','Configure the Google web client, redirect URI, and private encryption key.',503);Support::key();
        $redirect=getenv('DAVVAG_EVENTS_GOOGLE_REDIRECT_URI');$expected=Support::baseUrl().'components/davvag-events/oauth/service/Callback';
        if($redirect!==$expected)Support::fail('DRIVE_REDIRECT','Register this exact OAuth redirect URI: '.$expected,503);
        $state=Support::secret();$_SESSION['davvag_events_oauth']=['hash'=>hash('sha256',$state),'owner'=>$actor['id'],'tenant'=>(string)DATASTORE_DOMAIN,'expires'=>time()+600];
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_ID'),'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>self::SCOPE,'access_type'=>'offline','prompt'=>'consent','state'=>$state]);
    }
    public static function callback($actor,$in) {
        $saved=$_SESSION['davvag_events_oauth']??null;unset($_SESSION['davvag_events_oauth']);
        if(!$saved||$saved['expires']<time()||$saved['owner']!==$actor['id']||$saved['tenant']!==(string)DATASTORE_DOMAIN||!hash_equals($saved['hash'],hash('sha256',$in['state']??'')))Support::fail('OAUTH_STATE','The Google connection request expired. Start again.',403);
        if(!empty($in['error']))Support::fail('OAUTH_DENIED','Google connection was not approved.',409);
        $data=Http::json('POST','https://oauth2.googleapis.com/token',http_build_query(['code'=>Support::text($in['code']??'',4096,true),'client_id'=>getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_ID'),'client_secret'=>getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_SECRET'),'redirect_uri'=>getenv('DAVVAG_EVENTS_GOOGLE_REDIRECT_URI'),'grant_type'=>'authorization_code']),['Content-Type: application/x-www-form-urlencoded']);
        $c=Store::connection($actor['id']);$old=isset($c['state']['credentials'])?Support::decrypt($c['state']['credentials']):[];
        if(empty($data['refresh_token']))$data['refresh_token']=$old['refresh_token']??null;
        if(empty($data['refresh_token'])||empty($data['access_token'])||!in_array(self::SCOPE,explode(' ',$data['scope']??''),true))Support::fail('OAUTH_SCOPE','Google did not grant offline Drive file access. Reconnect.',409);
        $data['expiresAt']=time()+(int)($data['expires_in']??3600)-60;$c['state']['credentials']=Support::encrypt($data);$c['state']['status']='connected';Store::saveConnection($c);return ['status'=>'connected'];
    }
    public static function access(&$c) {
        if(($c['state']['status']??'')!=='connected')Support::fail('DRIVE_RECONNECT','The host must reconnect Google Drive.',503);
        $data=Support::decrypt($c['state']['credentials']);
        if(($data['expiresAt']??0)<time()){
            $response=Http::send('POST','https://oauth2.googleapis.com/token',http_build_query(['client_id'=>getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_ID'),'client_secret'=>getenv('DAVVAG_EVENTS_GOOGLE_CLIENT_SECRET'),'refresh_token'=>$data['refresh_token'],'grant_type'=>'refresh_token']),['Content-Type: application/x-www-form-urlencoded']);
            if($response['status']<200||$response['status']>=300){if(in_array($response['data']['error']??'',['invalid_grant','invalid_client'],true)){$c['state']['status']='reconnect';Store::saveConnection($c);}Support::fail('PROVIDER_RETRY','Google token refresh failed. Retry, or reconnect if authorization expired.',503);}
            $next=$response['data'];if(empty($next['access_token']))Support::fail('PROVIDER_RETRY','Google returned an incomplete token response.',503);
            $data=array_merge($data,$next);$data['expiresAt']=time()+(int)($next['expires_in']??3600)-60;$c['state']['credentials']=Support::encrypt($data);Store::saveConnection($c);
        }return $data['access_token'];
    }
    public static function api($c,$method,$path,$body=null) { $token=self::access($c);return Http::json($method,'https://www.googleapis.com/drive/v3'.$path,$body===null?null:json_encode($body),['Authorization: Bearer '.$token,'Content-Type: application/json']); }
    public static function generatedId($c) { $r=self::api($c,'GET','/files/generateIds?count=1&space=drive&type=files');if(empty($r['ids'][0]))Support::fail('DRIVE','Unable to reserve a Drive file.',503);return $r['ids'][0]; }
    public static function folder(&$c,$key,$name,$parent=null) {
        if(empty($c['state']['folders'][$key])){$c['state']['folders'][$key]=self::generatedId($c);Store::saveConnection($c);}
        $id=$c['state']['folders'][$key];$token=self::access($c);$r=Http::send('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,trashed',null,['Authorization: Bearer '.$token]);
        if($r['status']===200){if(!empty($r['data']['trashed']))Support::fail('DRIVE_FOLDER','The host removed the upload folder. Restore it in Drive.',503);return $id;}
        if($r['status']!==404)Support::fail('DRIVE_FOLDER','The event folder is unavailable. Check host access and storage.',503);
        $body=['id'=>$id,'name'=>$name,'mimeType'=>'application/vnd.google-apps.folder'];if($parent)$body['parents']=[$parent];$r=Http::send('POST','https://www.googleapis.com/drive/v3/files',json_encode($body),['Authorization: Bearer '.$token,'Content-Type: application/json']);
        if(!in_array($r['status'],[200,201,409],true))Support::fail('DRIVE_FOLDER','Unable to create the event folder. Check storage and quotas.',503);return $id;
    }
    public static function folders(&$c,$e) { $root=self::folder($c,'root','Events');$event=self::folder($c,$e['id'].'-event',$e['draft']['title'].'_'.substr($e['draft']['start'],0,10),$root);return self::folder($c,$e['id'].'-uploads','Guest_Uploads',$event); }
    public static function disconnect($actor) {
        $c=Store::connection($actor['id']);if(isset($c['state']['credentials'])){$d=Support::decrypt($c['state']['credentials']);Http::json('POST','https://oauth2.googleapis.com/revoke',http_build_query(['token'=>$d['refresh_token']??$d['access_token']]),['Content-Type: application/x-www-form-urlencoded']);}
        unset($c['state']['credentials']);$c['state']['status']='disconnected';Store::saveConnection($c);return ['status'=>'disconnected'];
    }
    /** One bounded resumable chunk; reserved Drive ID makes an ambiguous success reconcilable. */
    public static function transfer(&$e,$index,$persist) {
        $m=&$e['media'][$index];if($m['status']==='cancelled')return true;
        $c=Store::connection($e['ownerProfile']);$folder=self::folders($c,$e);$token=self::access($c);
        if(empty($m['driveId'])){$m['driveId']=self::generatedId($c);$persist($e);$m=&$e['media'][$index];}
        $found=Http::send('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($m['driveId']).'?fields=id,size,md5Checksum,trashed',null,['Authorization: Bearer '.$token]);
        if($found['status']===200&&!empty($found['data']['size'])){
            if(!empty($found['data']['trashed'])||(int)$found['data']['size']!==$m['bytes']||($found['data']['md5Checksum']??'')!==$m['md5'])Support::fail('DRIVE_INTEGRITY','Uploaded file failed reconciliation.',503);
            $m['status']='uploaded';$m['uploadedAt']=Support::now();unset($m['sessionUrl']);$persist($e);return true;
        }
        $path=Media::path($m['staging']);if(!is_file($path))Support::fail('STAGING_EXPIRED','The staged file expired; ask the guest to upload again.',410);
        if(empty($m['sessionUrl'])){
            $meta=['id'=>$m['driveId'],'name'=>$m['originalName'],'parents'=>[$folder],'appProperties'=>['uploadId'=>$m['id'],'eventId'=>$e['id']]];
            $r=Http::send('POST','https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',json_encode($meta),['Authorization: Bearer '.$token,'Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: '.$m['mime'],'X-Upload-Content-Length: '.$m['bytes']]);
            if(!in_array($r['status'],[200,201],true)||empty($r['headers']['location']))Support::fail('DRIVE_RETRY','Drive could not start the transfer. Check quotas and account access.',503);
            $m['sessionUrl']=$r['headers']['location'];$m['offset']=0;$m['status']='uploading';$persist($e);$m=&$e['media'][$index];
        }
        $probe=Http::send('PUT',$m['sessionUrl'],'',['Authorization: Bearer '.$token,'Content-Length: 0','Content-Range: bytes */'.$m['bytes']]);
        if(in_array($probe['status'],[200,201],true)){unset($m['sessionUrl']);$persist($e);return false;}
        if(in_array($probe['status'],[404,410],true)){unset($m['sessionUrl']);$m['offset']=0;$persist($e);return false;}
        if($probe['status']!==308)Support::fail('DRIVE_RETRY','Drive paused the transfer. Retry or reconnect.',503);
        $offset=0;if(preg_match('/bytes=0-(\d+)/',$probe['headers']['range']??'',$match))$offset=(int)$match[1]+1;
        $size=min(8*1024*1024,$m['bytes']-$offset);if($size<=0)return false;$stream=fopen($path,'rb');fseek($stream,$offset);
        try{$r=Http::send('PUT',$m['sessionUrl'],$stream,['Authorization: Bearer '.$token,'Content-Type: '.$m['mime'],'Content-Length: '.$size,'Content-Range: bytes '.$offset.'-'.($offset+$size-1).'/'.$m['bytes']],$size);}finally{fclose($stream);}
        if(!in_array($r['status'],[200,201,308],true))Support::fail('DRIVE_RETRY','Drive paused the transfer. Check storage, access, and quota.',503);
        $m['offset']=$offset+$size;$persist($e);return false;
    }
}

final class Media {
    public static function root() {
        $dir=getenv('DAVVAG_EVENTS_STAGING_DIR');$real=$dir?realpath($dir):false;
        if(!$real||!is_dir($real)||!is_writable($real))Support::fail('UPLOAD_SETUP','Configure a writable private DAVVAG_EVENTS_STAGING_DIR.',503);
        foreach([$_SERVER['DOCUMENT_ROOT']??BASE_PATH,defined('MEDIA_FOLDER')?MEDIA_FOLDER:BASE_PATH] as $exposed){$public=realpath($exposed);$target=str_replace('\\','/',$real);$prefix=$public?rtrim(str_replace('\\','/',$public),'/'):'';if($prefix&&($target===$prefix||stripos($target,$prefix.'/')===0))Support::fail('UPLOAD_SETUP','Upload staging must be outside public web and framework media directories.',503);}return $real;
    }
    public static function path($name) { if(!preg_match('/^[a-f0-9]{32}\.stage$/D',$name))Support::fail('UPLOAD','Invalid staging reference.',400);return self::root().DIRECTORY_SEPARATOR.$name; }
    public static function ingest(&$e,$party,$file,$in) {
        Domain::window($e,'media');if(empty($in['consent']))Support::fail('CONSENT','Consent is required to share this media.');
        $c=Store::connection($e['ownerProfile']);if(($c['state']['status']??'')!=='connected')Support::fail('DRIVE_RECONNECT','The host needs to reconnect Google Drive.',503);
        $max=$e['published']['mediaMaxBytes'];if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))Support::fail('UPLOAD','Upload failed. Check the file and PHP upload limits.');
        $size=filesize($file['tmp_name']);if($size<1||$size>$max)Support::fail('FILE_SIZE','This file exceeds the event upload limit.');
        $operation=Support::text($in['operation']??'',80,true);if(!preg_match('/^[a-f0-9]{32}$/D',$operation))Support::fail('IDEMPOTENCY','Select this file again to start a valid upload.');
        $checksum=hash_file('sha256',$file['tmp_name']);
        foreach($e['media'] as $previous)if($previous['partyId']===$party&&($previous['operation']??'')===$operation){if($previous['sha256']!==$checksum)Support::fail('CONFLICT','This upload retry does not match the original file.',409);return ['id'=>$previous['id'],'status'=>$previous['status']];}
        $count=0;foreach($e['media'] as $m)if($m['partyId']===$party&&!in_array($m['status'],['cancelled'],true))$count++;
        if($count>=$e['published']['mediaMaxFiles'])Support::fail('UPLOAD_QUOTA','Your invitation has reached its upload limit.',409);
        $original=Support::text(basename(str_replace('\\','/',$file['name'])),200,true);$ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp'],'mp4'=>['video/mp4'],'mov'=>['video/quicktime'],'m4v'=>['video/mp4']];
        if(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true))Support::fail('FILE_TYPE','Use JPEG, PNG, WebP, MP4, or MOV. Convert HEIC/HEVC files if unsupported by your phone.');
        if(strpos($mime,'image/')===0&&!@getimagesize($file['tmp_name']))Support::fail('FILE_TYPE','The image signature is invalid.');
        $scan=getenv('DAVVAG_EVENTS_SCAN_HOOK');if($scan){if(!is_file($scan))Support::fail('SCAN','The media scan service is unavailable.',503);$checker=require $scan;if(!is_callable($checker)||!$checker($file['tmp_name'],$mime))Support::fail('SCAN','This file did not pass the media safety scan.');}
        $id=Support::id();$name=$id.'.stage';$path=self::path($name);if(!move_uploaded_file($file['tmp_name'],$path))Support::fail('UPLOAD','Unable to stage this file.',503);@chmod($path,0600);
        $m=['id'=>$id,'partyId'=>$party,'operation'=>$operation,'uploader'=>Support::text($in['uploader']??'',180,true),'consent'=>true,'originalName'=>$original,'mime'=>$mime,'bytes'=>$size,'sha256'=>$checksum,'md5'=>hash_file('md5',$path),'staging'=>$name,'status'=>'queued','createdAt'=>Support::now(),'offset'=>0,'driveId'=>null,'reason'=>''];$e['media'][]=$m;Jobs::enqueue($e,'drive',$id,'drive-'.$id);return ['id'=>$id,'status'=>'queued'];
    }
}
