<?php
putenv('DAVVAG_LMP_TEST_DOMAIN=lmp_test_'.bin2hex(random_bytes(8)));
require __DIR__.'/integration_bootstrap.php';
use lesson_market_place\MarketplaceRules;
use lesson_market_place\MarketplaceSchema;
use lesson_market_place\MarketplaceCatalog;
use lesson_market_place\MarketplaceEnrolment;
use davvag_credit_points\CreditLedgerService;
$dbConfig=json_decode(file_get_contents(DB_CONFIG_FILE));$database=$dbConfig->init_db.DATASTORE_DOMAIN;
if(!preg_match('/^[A-Za-z0-9_]*lmp_test_[a-f0-9]{16}$/D',$database))throw new RuntimeException('Unsafe test database name.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$admin=new mysqli($dbConfig->mysql_server,$dbConfig->mysql_username,$dbConfig->mysql_password);
$statement=$admin->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME=?');$statement->bind_param('s',$database);$statement->execute();
if($statement->get_result()->num_rows)throw new RuntimeException('Refusing to reuse an existing database.');$statement->close();
$admin->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');$admin->select_db($database);
$checks=0;
function check($condition,$label){global $checks;$checks++;if(!$condition)throw new RuntimeException($label);}
function rejects($fn,$label){try{$fn();}catch(Throwable $error){check(true,$label);return;}check(false,$label);}
function parallel($arguments){$workers=[];foreach($arguments as $args){$cmd=array_merge([PHP_BINARY,'-d','xdebug.mode=off',__DIR__.'/concurrent_worker.php'],$args);$pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}$out=[];foreach($workers as [$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$out[]=[proc_close($process),$stdout,$stderr];}return $out;}
try {
scoped(function() {
    global $checks,$admin;
    $ledger=new CreditLedgerService();$db=$ledger->database();MarketplaceSchema::ensure($db);MarketplaceSchema::ensure($db);
    $catalog=new MarketplaceCatalog(99,true);$engine=new MarketplaceEnrolment($db,$catalog,$ledger);
    $course=$db->insert('course_manager_course',['title'=>'Isolated course']);$subject=$db->insert('course_manager_subject',['title'=>'Isolated subject','course_id'=>(int)$course,'teacher_id'=>99]);
    foreach([1,2,3] as $id)$db->insert('lesson_manager_lesson',['id'=>$id,'course_id'=>(int)$course,'subject_id'=>(int)$subject,'title'=>'Lesson '.$id,'status'=>'published','lesson_order'=>$id,'progression_enabled'=>$id===1?'true':'false','is_free'=>'false','required_credit_points'=>10]);
    $make=function($price,$approval,$lessonIds=[1,2])use($db,$course,$subject){static $seq=0;$seq++;$terms=(object)['name'=>'Package '.$seq,'slug'=>'package-'.$seq,'summary'=>'Terms','description'=>'<p>Safe terms</p>','outcomes'=>'Learn','audience'=>'All','prerequisites'=>'','cover_image'=>'','credit_price'=>$price,'pricing_mode'=>$price?'credits':'free','approval_required'=>$approval,'program_code'=>'CREDIT','lesson_ids'=>$lessonIds,'lessons'=>[]];foreach($lessonIds as $id)$terms->lessons[]=(object)['id'=>$id,'title'=>'Lesson '.$id,'course_id'=>(int)$course,'course_title'=>'Course','subject_id'=>(int)$subject,'subject_title'=>'Subject'];$package=$db->insert('lmp_package',['slug'=>$terms->slug,'product_id'=>$seq,'owner_profile_id'=>99,'status'=>'published','name'=>$terms->name,'draft_revision'=>1,'draft_json'=>json_encode($terms),'published_version_id'=>0]);$version=$db->insert('lmp_version',['package_id'=>(int)$package,'version_number'=>1,'snapshot_json'=>json_encode($terms),'snapshot_hash'=>hash('sha256',json_encode($terms))]);$db->updateById('lmp_package',$package,['published_version_id'=>(int)$version]);return [$package,$version,$terms];};
    [$free,$freeVersion]=$make(0,false);$first=$engine->request(1,$free,$freeVersion,'free-auto-request');check($first->status==='active','free enrolment works without a wallet balance');
    check($db->one("SELECT COUNT(*) n FROM davvag_credit_transaction WHERE source_app='lesson-market-place'")->n==0,'free enrolment has no financial transaction');
    $replay=$engine->request(1,$free,$freeVersion,'free-auto-request');check($replay->id==$first->id,'same request replay');
    $newKey=$engine->request(1,$free,$freeVersion,'different-request');check($newKey->id==$first->id,'new key cannot create second enrolment');
    rejects(function()use($engine,$free,$freeVersion){$engine->request(1,$free,$freeVersion+1,'free-auto-request');},'changed payload under same key');
    [$freeReview,$fv]=$make(0,true);$pending=$engine->request(1,$freeReview,$fv,'free-review-request');check($pending->status==='pending_approval','free approval pending');$active=$engine->decide(99,$pending->id,'approve','Welcome','private',function(){});check($active->status==='active'&&!isset($active->internal_note),'free approved access and private notes');rejects(function()use($engine,$pending){$engine->decide(99,$pending->id,'reject','','',function(){});},'conflicting decision rejected');
    [$paid,$pv]=$make(25,false);$pay=$engine->request(1,$paid,$pv,'paid-auto-request');check($pay->status==='awaiting_payment','paid auto waits for confirmation');rejects(function()use($engine,$pay,$pv){$engine->confirm(1,$pay->id,$pv);},'insufficient funds');check($db->one('SELECT COUNT(*) n FROM lmp_grant WHERE enrolment_id=?','i',[(int)$pay->enrolment_id])->n==0,'insufficient funds grant nothing');
    $ledger->credit(1,25,['sourceApp'=>'test','referenceType'=>'fixture','referenceId'=>'exact','idempotencyKey'=>'fixture-exact','description'=>'Exact balance']);
    $workers=parallel(array_fill(0,4,['confirm',(string)$pay->id,(string)$pv]));foreach($workers as $worker)check($worker[0]===0,'concurrent confirms return active: '.implode(' ',$worker));
    check($ledger->summary(1)->availableBalance===0,'exact balance debit');check($db->one("SELECT COUNT(*) n FROM davvag_credit_transaction WHERE source_app='lesson-market-place'")->n==1,'exactly one debit under concurrency');check($db->one('SELECT COUNT(*) n FROM lmp_grant WHERE enrolment_id=?','i',[(int)$pay->enrolment_id])->n==2,'all grants created once');check($engine->confirm(1,$pay->id,$pv)->id==$pay->id,'lost response recovers active result');
    [$approval,$av]=$make(10,true);$approvalRequest=$engine->request(1,$approval,$av,'paid-approval-request');check($ledger->summary(1)->reservedBalance===0,'approval reserves nothing');$approved=$engine->decide(99,$approvalRequest->id,'approve','','',function(){});check($approved->status==='awaiting_payment','paid approval makes payment available');check($ledger->summary(1)->availableBalance===0,'approval never debits');
    $cancel=$engine->decide(1,$approvalRequest->id,'cancel','','',function(){});check($cancel->status==='cancelled','cancel before activation');rejects(function()use($engine,$approval,$av){$engine->request(1,$approval,$av,'new-request-without-previous');},'new attempt must explicitly name prior attempt');$again=$engine->request(1,$approval,$av,'new-explicit-attempt',$approvalRequest->id);check($again->id!=$approvalRequest->id && $again->attempt_number==2,'reapplication preserves history');
    $decisions=parallel([['decide',(string)$again->id,'approve'],['decide',(string)$again->id,'reject']]);check(count(array_filter($decisions,function($w){return $w[0]===0;}))===1,'one concurrent staff decision wins');
    [$failure,$failVersion]=$make(10,false);$failAttempt=$engine->request(1,$failure,$failVersion,'atomic-failure-request');$ledger->credit(1,40,['sourceApp'=>'test','referenceType'=>'fixture','referenceId'=>'rollback','idempotencyKey'=>'fixture-rollback','description'=>'Rollback fixture']);
    $admin->query("CREATE TRIGGER lmp_injected_failure BEFORE INSERT ON lmp_grant FOR EACH ROW BEGIN IF NEW.lesson_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'; END IF; END");rejects(function()use($engine,$failAttempt,$failVersion){$engine->confirm(1,$failAttempt->id,$failVersion);},'injected grant failure');$admin->query('DROP TRIGGER lmp_injected_failure');check($ledger->summary(1)->availableBalance===40,'grant failure rolls back debit');check($db->one('SELECT COUNT(*) n FROM lmp_grant WHERE enrolment_id=?','i',[(int)$failAttempt->enrolment_id])->n==0,'grant failure rolls back earlier grants');
    $db->transaction(function($db){try{$db->transaction(function(){});throw new RuntimeException('Nested transaction accepted');}catch(\davvag_credit_points\CreditException $expected){}});check(true,'nested transaction explicitly rejected');
    $wallet=$ledger->wallet(1);$db->updateById('davvag_credit_wallet',$wallet->id,['status'=>'SUSPENDED']);rejects(function()use($engine,$failAttempt,$failVersion){$engine->confirm(1,$failAttempt->id,$failVersion);},'suspended wallet denied');$db->updateById('davvag_credit_wallet',$wallet->id,['status'=>'ACTIVE']);
    $db->updateById('lesson_manager_lesson',2,['status'=>'draft']);rejects(function()use($engine,$failAttempt,$failVersion){$engine->confirm(1,$failAttempt->id,$failVersion);},'changed lesson availability blocks payment');check($ledger->summary(1)->availableBalance===40,'unavailable content never charged');$db->updateById('lesson_manager_lesson',2,['status'=>'published']);
    [$missing,$mv]=$make(0,false,[2]);rejects(function()use($engine,$missing,$mv){$engine->request(1,$missing,$mv,'missing-prerequisite');},'missing prerequisite rejected');
    rejects(function()use($engine,$pay,$pv){$engine->confirm(2,$pay->id,$pv);},'foreign learner confirmation denied');
    require_once TENANT_RESOURCE_LOCATION.'/apps/lesson-manager/lib/LessonAccess.php';
    foreach(['course_manager_enrollment','course_manager_classgrade'] as $ns)SOSSData::WithServiceNamespaces([$ns],function()use($ns){return SOSSData::Query($ns,'',null,'asc',1,0,null,false);});
    $access=new \lesson_manager\LessonAccess();$included=$db->one('SELECT * FROM lesson_manager_lesson WHERE id=1');$other=$db->one('SELECT * FROM lesson_manager_lesson WHERE id=3');check($access->eligible($included,1)&&$access->financiallyCovered($included,1),'package covers paid lessons');check(!$access->eligible($other,1),'package does not expose unrelated lessons');check(in_array((int)$course,$access->courseIds(1),true),'package-only course discovery');check(!$access->broadCourse($course,1),'package creates no broad course assignment');
    $db->updateById('lmp_package',$free,['status'=>'archived']);check((new \lesson_manager\LessonAccess())->eligible($included,1),'archive preserves active access');
    $db->insert('course_manager_enrollment',['student_id'=>2,'course_id'=>(int)$course,'status'=>'active']);check((new \lesson_manager\LessonAccess())->eligible($other,2),'existing course enrolment preserved');$ledger->unlockLesson(1,3,10);check((new \lesson_manager\LessonAccess())->financiallyCovered($other,1),'standalone unlock preserved');
    require __DIR__.'/endpoint_checkout_cases.php';
    // Namespace capability must restore even when an authorized operation throws.
});
check(!SOSSData::Query('lmp_grant','')->success,'generic reads denied');check(!SOSSData::Insert('lmp_grant',(object)['profile_id'=>1])->success,'generic grant writes denied');
echo "Marketplace isolated database integration: $checks checks passed.\n";
} finally {
    // This invocation created this exact random database after proving it did not exist.
    $admin->query('DROP DATABASE `'.$database.'`');$admin->close();
}
