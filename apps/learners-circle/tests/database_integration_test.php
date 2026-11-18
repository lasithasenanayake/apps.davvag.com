<?php
// Uses the shared isolated-tenant bootstrap and a database created by this run only.
putenv('DAVVAG_LMP_TEST_DOMAIN=lmp_test_'.bin2hex(random_bytes(8)));
require dirname(__DIR__,2).'/lesson-market-place/tests/integration_bootstrap.php';
$config=json_decode(file_get_contents(DB_CONFIG_FILE));$name=$config->init_db.DATASTORE_DOMAIN;
if(!preg_match('/^[A-Za-z0-9_]*lmp_test_[a-f0-9]{16}$/D',$name))throw new RuntimeException('Unsafe test database name.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$admin=new mysqli($config->mysql_server,$config->mysql_username,$config->mysql_password);
$check=$admin->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME=?');$check->bind_param('s',$name);$check->execute();
if($check->get_result()->num_rows)throw new RuntimeException('Refusing to reuse an existing database.');$check->close();
$admin->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$admin->select_db($name);
$checks=0;
function check($condition,$message){global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
class CircleRequest{private $body;function __construct($b){$this->body=(object)$b;}function Body($decode){return $this->body;}}
class CircleResponse{public $error;function SetError($error){$this->error=$error;}}
function endpoint($class,$method,$body=[]){$response=new CircleResponse();$value=(new $class())->$method(new CircleRequest($body),$response);return[$value,$response->error];}
try{
scoped(function(){
    $ledger=new davvag_credit_points\CreditLedgerService();$db=$ledger->database();lesson_market_place\MarketplaceSchema::ensure($db);
    require TENANT_RESOURCE_LOCATION.'/apps/lesson-manager/services/api/service.php';
    require TENANT_RESOURCE_LOCATION.'/apps/lesson-market-place/services/marketplace-api/service.php';
    foreach(['lesson_manager_progress','course_manager_mark','lesson_manager_quiz'] as $ns)SOSSData::WithServiceNamespaces([$ns],function()use($ns){SOSSData::Query($ns,'',null,'asc',1,0,null,false);});
    $course=$db->insert('course_manager_course',['code'=>'TEST','title'=>'Test course','status'=>'active']);
    $subject=$db->insert('course_manager_subject',['course_id'=>(int)$course,'title'=>'Test subject','status'=>'active']);
    foreach([1,2,3] as $id)$db->insert('lesson_manager_lesson',['id'=>$id,'title'=>'Lesson '.$id,'course_id'=>(int)$course,'subject_id'=>(int)$subject,'lesson_order'=>$id,'status'=>'published','is_free'=>'true']);
    $packages=[];
    foreach(['available','active','pending_approval','awaiting_payment','cancelled','rejected','other-user'] as $index=>$state){
        $p=$db->insert('lmp_package',['name'=>'Package '.$state,'slug'=>'fixture-'.$state,'status'=>'published','owner_profile_id'=>99]);
        $json=json_encode(['name'=>'Package '.$state,'summary'=>'Summary','cover_image'=>'','credit_price'=>0,'approval_required'=>false,'lesson_ids'=>[1,2]]);
        $version=$db->insert('lmp_version',['package_id'=>(int)$p,'version_number'=>1,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json)]);
        $db->updateById('lmp_package',$p,['published_version_id'=>(int)$version]);$packages[$state]=(int)$p;
        if($state!=='available')$db->insert('lmp_enrolment',['package_id'=>(int)$p,'profile_id'=>$state==='other-user'?8:7,'status'=>$state==='other-user'?'active':$state]);
    }
    Auth::$role='web_user';Profile::$id=7;$api='lesson_market_place\\MarketplaceApi';
    [$all,$error]=endpoint($api,'postCatalog',['limit'=>100]);check(!$error&&$all['total']===7,'ordinary public catalog stays unchanged');
    [$discover,$error]=endpoint($api,'postCatalog',['limit'=>100,'exclude_enrolled'=>true,'profile_id'=>8]);
    check(!$error&&$discover['total']===4,'personalized catalog counts after excluding own current enrolments');
    $ids=array_map(function($p){return $p['id'];},$discover['items']);
    foreach(['active','pending_approval','awaiting_payment'] as $state)check(!in_array($packages[$state],$ids,true),'excludes '.$state.' packages');
    foreach(['available','cancelled','rejected','other-user'] as $state)check(in_array($packages[$state],$ids,true),'includes '.$state.' packages');
    [$page,$error]=endpoint($api,'postCatalog',['limit'=>1,'offset'=>3,'exclude_enrolled'=>true]);check(!$error&&count($page['items'])===1&&$page['total']===4,'filtering happens before pagination');
    [$empty,$error]=endpoint($api,'postCatalog',['limit'=>1,'offset'=>4,'exclude_enrolled'=>true]);check(!$error&&!$empty['items'],'end of personalized catalog is accurate');
    Auth::$role='anonymous';[, $error]=endpoint($api,'postCatalog',['exclude_enrolled'=>true]);check(!!$error,'anonymous requests cannot use a profile to personalize recommendations');
    Auth::$role='web_user';
    $enrolment=$db->one('SELECT id FROM lmp_enrolment WHERE package_id=?','i',[$packages['active']]);
    foreach([1,2] as $id)$db->insert('lmp_grant',['profile_id'=>7,'enrolment_id'=>(int)$enrolment->id,'lesson_id'=>$id,'course_id'=>(int)$course,'subject_id'=>(int)$subject,'status'=>'active']);
    $db->insert('lesson_manager_progress',['student_id'=>7,'course_id'=>(int)$course,'lesson_id'=>1,'lesson_completed'=>'true']);
    $db->insert('course_manager_mark',['student_id'=>7,'subject_id'=>(int)$subject,'marks'=>8,'max_mark'=>10]);
    [$overview,$error]=endpoint('lesson_manager\\ApiService','postLearnerOverview');
    check(!$error&&$overview['stats']['total_lessons']===2&&$overview['stats']['completed_lessons']===1,'real dashboard counts authorized published lessons and own progress');
    check($overview['stats']['marks_percentage']===80.0,'real dashboard uses recorded course marks');
    Auth::$role='sysadmin';Profile::$id=99;
    [$own,$error]=endpoint('lesson_manager\\ApiService','postLearnerOverview');check(!$error&&!$own['courses'],'administrators see personal learning rather than managed courses');
});
echo "Learners Circle database integration: $checks checks passed.\n";
}finally{
    // The exact random database above was proved absent and created by this invocation.
    $admin->query('DROP DATABASE `'.$name.'`');$admin->close();
}
