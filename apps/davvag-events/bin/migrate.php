<?php
require __DIR__.'/bootstrap.php';
// Direct MySQLi is installation-only; runtime writes go through SOSSData ExecuteRaw.
$config=json_decode(file_get_contents(DB_CONFIG_FILE));$database=$config->init_db.str_replace('.','_',DATASTORE_DOMAIN);
if(!preg_match('/^[A-Za-z0-9_]+$/D',$database))throw new RuntimeException('Unsafe database identifier.');
$db=new mysqli($config->mysql_server,$config->mysql_username,$config->mysql_password,$database);$db->set_charset('utf8mb4');
$db->query('CREATE TABLE IF NOT EXISTS davvag_events_migrations (version INT PRIMARY KEY, checksum CHAR(64) NOT NULL, installedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
foreach(glob(SCHEMA_PATH.'/migrations/davvag-events/*.sql') as $file){
    $version=(int)basename($file);$sql=file_get_contents($file);$hash=hash('sha256',$sql);$q=$db->prepare('SELECT checksum FROM davvag_events_migrations WHERE version=?');$q->bind_param('i',$version);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();
    if($old){if(!hash_equals($old['checksum'],$hash))throw new RuntimeException('An installed migration was modified. Add a new version.');echo "Migration $version already installed.\n";continue;}
    foreach(explode("\n-- STATEMENT --\n",$sql) as $statement)if(trim($statement)!=='')$db->query($statement);
    $q=$db->prepare('INSERT INTO davvag_events_migrations(version,checksum) VALUES (?,?)');$q->bind_param('is',$version,$hash);$q->execute();$q->close();echo "Installed migration $version.\n";
}
// Explicit runtime method permissions: phpauth checks these records, not group JSON alone.
$public=['events'=>['Session'],'public'=>['Exchange','Invitation','Unlock','Respond','Seat','Calendar'],'greetings'=>['Wall','Submit'],'media'=>['Upload','Cancel'],'sales'=>['Types','Checkout','DisplayTickets','Admission'],'webhook'=>['Stripe']];
foreach(['anonymous','web_user'] as $group)foreach(glob(dirname(__DIR__).'/services/*/component.json') as $file){$desc=json_decode(file_get_contents($file));$service=basename(dirname($file));foreach($desc->serviceHandler->methods as $method=>$definition){if($group==='anonymous'&&!in_array($method,$public[$service]??[],true))continue;$id=md5($group.'-'.AUTH_DOMAIN.'-davvag-events--service-'.$service.'-'.$method);$old=SOSSData::Query('usergroup_permission',['conditions'=>[['column'=>'keyid','operator'=>'=','value'=>$id]],'pageSize'=>1,'pageFrom'=>0],null,'ASC',1,0,AUTH_DOMAIN,false);if(!empty($old->success)&&count($old->result)>0)continue;$object=(object)['keyid'=>$id,'groupid'=>$group,'domain'=>AUTH_DOMAIN,'appCode'=>'davvag-events','type'=>'service','code'=>$service,'operation'=>$method,'description'=>'DAVVAG Events method access'];$r=SOSSData::Insert('usergroup_permission',$object,AUTH_DOMAIN);if(empty($r->success))throw new RuntimeException('Unable to install event method permissions.');}}
echo "Event method permissions installed.\n";
$template=TENANT_RESOURCE_LOCATION.'/global/templetes/email/davvag-events-invitation.tmp';
if(!is_file($template)){if(!is_dir(dirname($template)))mkdir(dirname($template),0750,true);if(!copy(dirname(__DIR__).'/templates/invitation.tmp',$template))throw new RuntimeException('Unable to install the event email template.');}
