<?php
// Included by the isolated database runner before GROUPID is fixed for CMS tests.
Auth::$role='sysadmin'; Profile::$id=99;
[$extension,$originalVersion,$extensionTerms]=$make(0,false,[1]);
$members=[];
foreach(range(20,34) as $profileId) $members[$profileId]=$engine->request($profileId,$extension,$originalVersion,'extend-fixture-'.$profileId);
foreach([32=>'pending_approval',33=>'cancelled',34=>'awaiting_payment'] as $profileId=>$state) {
    $db->updateById('lmp_attempt',$members[$profileId]->id,['status'=>$state]);
    $db->updateById('lmp_enrolment',$members[$profileId]->enrolment_id,['status'=>$state]);
}
[$separate,$separateVersion]=$make(0,false,[1]);
$engine->request(35,$separate,$separateVersion,'separate-fixture');
$originalAttempt=$db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$members[20]->id]);
$progressId=$db->insert('lesson_manager_progress',['course_id'=>(int)$course,'lesson_id'=>1,'student_id'=>20,'lesson_completed'=>'true','reading_completed'=>'true']);
$beforeTransactions=(int)$db->one('SELECT COUNT(*) n FROM davvag_credit_transaction')->n;
$product=SOSSData::Insert('products',(object)['name'=>'Access extension fixture']);
check($product->success,'fixture product initialized through SOSSData');
$extensionTerms->product_id=(int)$product->result->generatedId;
$extensionTerms->lesson_ids=[1,2];
$extensionTerms->lessons[]=(object)['id'=>2,'title'=>'New lesson','course_id'=>(int)$course,'subject_id'=>(int)$subject];
$db->updateById('lmp_package',$extension,['draft_json'=>json_encode($extensionTerms)]);
[$published,$error]=endpoint($market,'postPublishPackage',['id'=>$extension,'revision'=>1]);
check(!$error && $published,'publishes new lessons using actual API: '.($error ?? ''));
$newVersion=(int)$published['version_id'];
check((int)$db->one('SELECT COUNT(*) n FROM lmp_grant WHERE lesson_id=2 AND profile_id>=20')->n===0,'publishing alone never extends existing access');

foreach(['anonymous','web_user'] as $role) {
    Auth::$role=$role; Profile::$id=20;
    [, $error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'preview'=>true]);
    check(!!$error,$role.' cannot review or extend package access');
    [, $error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'version_id'=>$newVersion,'through_id'=>999]);
    check(!!$error,$role.' cannot apply access extension');
}
Auth::$role='teacher'; Profile::$id=98;
[, $error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'preview'=>true]);
check(!!$error,'unrelated teacher cannot extend another owner package');
Auth::$role='teacher'; Profile::$id=99;
[, $error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'preview'=>true]);
check(!$error,'owning teacher can review owned subject lessons');
Auth::$role='sysadmin'; Profile::$id=99;
[$review,$error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'preview'=>true]);
check(!$error && $review['active_enrolments']===12 && count($review['lessons'])===2,'preview identifies all active recipients and published lessons');
$payload=['id'=>$extension,'version_id'=>$newVersion,'through_id'=>$review['through_id'],'after_id'=>0];
[, $error]=endpoint($market,'postExtendPackageAccess',array_merge($payload,['version_id'=>$originalVersion]));
check(!!$error,'stale reviewed publication is rejected');
[, $error]=endpoint($market,'postExtendPackageAccess',array_merge($payload,['lesson_ids'=>[3]]));
check(!!$error,'caller cannot inject unrelated lesson IDs');
$db->updateById('lesson_manager_lesson',2,['status'=>'draft']);
[, $error]=endpoint($market,'postExtendPackageAccess',$payload);
check(!!$error,'unpublished included lessons prevent extension');
$db->updateById('lesson_manager_lesson',2,['status'=>'published']);
[$firstBatch,$error]=endpoint($market,'postExtendPackageAccess',$payload);
check(!$error && $firstBatch['processed']===10 && $firstBatch['added_grants']===10 && $firstBatch['has_more'],'extension processes the first bounded batch');
[$replay,$error]=endpoint($market,'postExtendPackageAccess',$payload);
check(!$error && $replay['added_grants']===0 && $replay['after_id']===$firstBatch['after_id'],'lost-response retry preserves unique grants');
$payload['after_id']=$firstBatch['after_id'];
[$lastBatch,$error]=endpoint($market,'postExtendPackageAccess',$payload);
check(!$error && $lastBatch['processed']===2 && $lastBatch['added_grants']===2 && !$lastBatch['has_more'],'cursor reaches the remaining enrollees');
check((int)$db->one('SELECT COUNT(*) n FROM lmp_grant WHERE lesson_id=2 AND profile_id BETWEEN 20 AND 31')->n===12,'every active enrollee gains the newly published lesson');
check((int)$db->one('SELECT COUNT(*) n FROM lmp_grant WHERE lesson_id=2 AND profile_id>=32')->n===0,'pending cancelled unpaid and other-package learners gain nothing');
check((int)$db->one('SELECT COUNT(*) n FROM lmp_grant WHERE lesson_id=3 AND profile_id>=20')->n===0,'unselected course lessons stay outside package access');
check((int)$db->one('SELECT COUNT(*) n FROM lmp_audit WHERE reason LIKE ?','s',['%published package version #'.$newVersion.'.'])->n===12,'extensions record staff audit history per changed enrolment');
check((int)$db->one('SELECT COUNT(*) n FROM davvag_credit_transaction')->n===$beforeTransactions,'extending package lessons creates no wallet transaction');
$afterAttempt=$db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$members[20]->id]);
check($afterAttempt->snapshot_json===$originalAttempt->snapshot_json && $afterAttempt->version_id===$originalAttempt->version_id,'accepted terms and original version remain unchanged');
check($db->one('SELECT * FROM lesson_manager_progress WHERE id=?','i',[(int)$progressId])->lesson_completed==='true','existing learning completion is preserved');
Auth::$role='web_user'; Profile::$id=20;
[$learning,$error]=endpoint($lessonApi,'postLearningCourse',['course_id'=>$course]);
check(!$error && array_map(function($l){return (int)$l->id;},$learning['lessons'])===[1,2],'My Learning receives the additional package lesson');
check($learning['lessons'][1]->unlocked,'granted paid lesson opens when prerequisite completion is met');
Profile::$id=21;
[$learning,$error]=endpoint($lessonApi,'postLearningCourse',['course_id'=>$course]);
check(!$error && !$learning['lessons'][1]->unlocked,'extension retains normal lesson progression for incomplete learners');
Auth::$role='sysadmin'; Profile::$id=99;
$db->updateById('lmp_package',$extension,['status'=>'archived']);
[, $error]=endpoint($market,'postExtendPackageAccess',['id'=>$extension,'preview'=>true]);
check(!!$error,'archived packages cannot extend grants');
