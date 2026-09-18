<?php
namespace lesson_market_place;

require_once __DIR__ . '/MarketplaceRules.php';
require_once __DIR__ . '/MarketplaceData.php';

final class MarketplaceEnrolment
{
    private $data;
    private $ledger;
    private $catalog;

    /**
     * Application code passes MarketplaceData. A legacy CreditDatabase first
     * argument is accepted by the integration harness, but ordinary operations
     * still use SOSSData through MarketplaceData.
     */
    public function __construct($data, $catalog, $ledger = null)
    {
        $this->data = $data instanceof MarketplaceData ? $data : new MarketplaceData();
        $this->catalog = $catalog;
        $this->ledger = $ledger;
    }

    private function now() { return date('Y-m-d H:i:s'); }

    private function snapshot($attempt)
    {
        $snapshot=json_decode($attempt->snapshot_json);
        if (!$snapshot) throw new MarketplaceException('Stored package terms are unavailable.');
        return $snapshot;
    }

    public function request($profile,$packageId,$versionId,$key,$previousAttempt=0)
    {
        $profile=(int)$profile;
        $packageId=(int)$packageId;
        $versionId=(int)$versionId;
        $key=MarketplaceRules::key($key);
        $hash=hash('sha256',json_encode([$packageId,$versionId,(int)$previousAttempt]));

        $operation=$this->data->one('lmp_operation',[
            ['column'=>'profile_id','operator'=>'=','value'=>$profile],
            ['column'=>'operation_key','operator'=>'=','value'=>$key]
        ]);
        if ($operation) {
            if (!hash_equals($operation->payload_hash,$hash)) throw new MarketplaceException('This operation key was used with different terms.');
            return $this->safeAttempt($this->data->byId('lmp_attempt',(int)$operation->attempt_id));
        }

        $package=$this->data->byId('lmp_package',$packageId);
        if (!$package) throw new MarketplaceException('Package not found.');
        $enrolment=$this->data->one('lmp_enrolment',[
            ['column'=>'profile_id','operator'=>'=','value'=>$profile],
            ['column'=>'package_id','operator'=>'=','value'=>$packageId]
        ]);
        if ($enrolment && $enrolment->current_attempt_id) {
            $attempt=$this->data->byId('lmp_attempt',(int)$enrolment->current_attempt_id);
            if (!in_array($attempt->status,['rejected','cancelled'],true)) {
                if ($attempt->status!=='active' && (int)$attempt->version_id!==$versionId) throw new MarketplaceException('A request already exists for different terms. Cancel it before making a new request.');
                $this->recordOperation($profile,$key,$hash,(int)$attempt->id);
                return $this->safeAttempt($attempt);
            }
            if ((int)$previousAttempt!==(int)$attempt->id) throw new MarketplaceException('Choose Apply again to create an explicit new attempt.');
        } elseif ($previousAttempt) {
            throw new MarketplaceException('The previous attempt does not match this enrolment.');
        }

        if ($package->status!=='published' || (int)$package->published_version_id!==$versionId) throw new MarketplaceException('Package terms changed or enrolment is unavailable. Refresh the package first.');
        $version=$this->data->one('lmp_version',[
            ['column'=>'id','operator'=>'=','value'=>$versionId],
            ['column'=>'package_id','operator'=>'=','value'=>$packageId]
        ]);
        if (!$version) throw new MarketplaceException('Published package version not found.');
        $snapshot=json_decode($version->snapshot_json);
        $this->catalog->deliverable($snapshot);

        if (!$enrolment) {
            $enrolmentId=$this->data->insert('lmp_enrolment',[
                'package_id'=>$packageId,
                'profile_id'=>$profile,
                'current_attempt_id'=>0,
                'status'=>'processing',
                'created_at'=>$this->now()
            ]);
            $enrolment=$this->data->byId('lmp_enrolment',$enrolmentId);
        }

        $previous=$this->data->one('lmp_attempt',[
            ['column'=>'enrolment_id','operator'=>'=','value'=>(int)$enrolment->id]
        ],[
            ['column'=>'attempt_number','direction'=>'DESC']
        ]);
        $number=$previous ? (int)$previous->attempt_number+1 : 1;
        $state=MarketplaceRules::initialState((int)$snapshot->credit_price,(bool)$snapshot->approval_required);
        $attemptId=$this->data->insert('lmp_attempt',[
            'enrolment_id'=>(int)$enrolment->id,
            'attempt_number'=>$number,
            'version_id'=>$versionId,
            'snapshot_json'=>$version->snapshot_json,
            'status'=>$state,
            'transaction_id'=>0,
            'decision_by'=>0,
            'decision_reason'=>'',
            'internal_note'=>'',
            'created_at'=>$this->now(),
            'updated_at'=>$this->now()
        ]);
        $attempt=$this->data->byId('lmp_attempt',$attemptId);
        $this->data->update('lmp_enrolment',$enrolment,[
            'current_attempt_id'=>$attemptId,
            'status'=>$state
        ]);
        if ($state==='active') $this->grantWithSossData($enrolment,$attempt,$snapshot,0);
        $this->recordEventWithSossData($enrolment,$attempt,$profile,'',$state,'',[$profile,$package->owner_profile_id]);
        $this->recordOperation($profile,$key,$hash,$attemptId);
        return $this->safeAttempt($this->data->byId('lmp_attempt',$attemptId));
    }

    public function confirm($profile,$attemptId,$versionId)
    {
        $attempt=$this->ownedAttempt((int)$profile,(int)$attemptId);
        if ((int)$attempt->version_id!==(int)$versionId) throw new MarketplaceException('Confirm the recorded request version.');
        if ($attempt->status==='active') return $this->safeAttempt($attempt);
        $snapshot=$this->snapshot($attempt);
        MarketplaceRules::transition($attempt->status,'confirm',(int)$snapshot->credit_price);
        if (!$this->ledger || (int)$snapshot->credit_price<1) throw new MarketplaceException('Payment is unavailable.');

        $context=[
            'programCode'=>$snapshot->program_code,
            'sourceApp'=>'lesson-market-place',
            'referenceType'=>'enrolment',
            'referenceId'=>(string)$attempt->enrolment_id,
            'idempotencyKey'=>'lmp:enrolment:'.$attempt->enrolment_id.':purchase',
            'description'=>'Enrol in '.$snapshot->name,
            'actorProfileId'=>(int)$profile,
            'metadata'=>['attempt_id'=>(int)$attemptId,'version_id'=>(int)$versionId]
        ];

        // CreditLedgerService owns this transaction. Its callback is the sole
        // direct-database exception so debit and entitlement grants are atomic.
        $this->ledger->debit($profile,(int)$snapshot->credit_price,$context,function($db,$tx) use($profile,$attemptId,$versionId) {
            [$package,$enrolment,$locked]=$this->lockAttemptInCreditTransaction($db,$attemptId);
            if((int)$enrolment->profile_id!==(int)$profile)throw new MarketplaceException('Request not found.');
            if ((int)$locked->version_id!==(int)$versionId) throw new MarketplaceException('Request version changed.');
            MarketplaceRules::transition($locked->status,'confirm',(int)$this->snapshot($locked)->credit_price);
            if ($package->status!=='published' || (int)$enrolment->current_attempt_id!==(int)$locked->id) throw new MarketplaceException('Enrolment is no longer available. No credits were charged.');
            $snapshot=$this->snapshot($locked);
            $this->catalog->deliverableInCreditTransaction($snapshot,$db);
            $this->grantInCreditTransaction($db,$enrolment,$locked,$snapshot,(int)$tx->id);
            $this->auditInCreditTransaction($db,$enrolment,$locked,$profile,$locked->status,'active','');
        });

        $attempt=$this->ownedAttempt((int)$profile,(int)$attemptId);
        $enrolment=$this->data->byId('lmp_enrolment',(int)$attempt->enrolment_id);
        $package=$this->data->byId('lmp_package',(int)$enrolment->package_id);
        $this->notifyWithSossData($attempt,'active','',[(int)$profile,(int)$package->owner_profile_id]);
        return $this->safeAttempt($attempt);
    }

    public function decide($actor,$attemptId,$action,$reason,$note,$authorize)
    {
        $attempt=$this->data->byId('lmp_attempt',(int)$attemptId);
        if (!$attempt) throw new MarketplaceException('Request not found.');
        $enrolment=$this->data->byId('lmp_enrolment',(int)$attempt->enrolment_id);
        $package=$enrolment ? $this->data->byId('lmp_package',(int)$enrolment->package_id) : null;
        if (!$package || !$enrolment) throw new MarketplaceException('Request not found.');
        $authorize($package,$enrolment,$action);
        if ((int)$enrolment->current_attempt_id!==(int)$attemptId) throw new MarketplaceException('A newer request exists.');

        $snapshot=$this->snapshot($attempt);
        $previousState=$attempt->status;
        $next=MarketplaceRules::transition($attempt->status,$action,(int)$snapshot->credit_price);
        if ($action==='approve') {
            if ($package->status!=='published') throw new MarketplaceException('This package is unavailable for new enrolments.');
            $this->catalog->deliverable($snapshot);
        }
        $this->claimDecision((int)$attemptId,$previousState,$action);
        $updates=['status'=>$next,'updated_at'=>$this->now()];
        if ($action!=='cancel') {
            $updates+=['decision_by'=>(int)$actor,'decision_at'=>$this->now(),'decision_reason'=>$reason,'internal_note'=>$note];
        }
        if ($next==='active') {
            foreach ($updates as $field=>$value) $attempt->$field=$value;
            $this->data->update('lmp_attempt',$attempt,$updates);
            $this->grantWithSossData($enrolment,$attempt,$snapshot,0);
        } else {
            $this->data->update('lmp_attempt',$attempt,$updates);
            $this->data->update('lmp_enrolment',$enrolment,['status'=>$next]);
        }
        $this->recordEventWithSossData($enrolment,$attempt,$actor,$previousState,$next,$reason,[$enrolment->profile_id,$package->owner_profile_id]);
        return $this->safeAttempt($this->data->byId('lmp_attempt',(int)$attemptId));
    }

    private function claimDecision($attemptId,$previousState,$action)
    {
        $key='decision:'.(int)$attemptId.':'.$previousState;
        $hash=hash('sha256',(string)$action);
        $existing=$this->data->one('lmp_operation',[
            ['column'=>'profile_id','operator'=>'=','value'=>0],
            ['column'=>'operation_key','operator'=>'=','value'=>$key]
        ]);
        if ($existing) {
            if (!hash_equals($existing->payload_hash,$hash)) throw new MarketplaceException('Another decision has already been recorded for this request.');
            return;
        }
        // The unique (profile_id, operation_key) index is the connector-neutral
        // compare-and-set boundary for simultaneous staff decisions.
        $this->data->insert('lmp_operation',[
            'profile_id'=>0,
            'operation_key'=>$key,
            'payload_hash'=>$hash,
            'attempt_id'=>(int)$attemptId,
            'created_at'=>$this->now()
        ]);
    }

    private function ownedAttempt($profile,$id)
    {
        $attempt=$this->data->byId('lmp_attempt',(int)$id);
        $enrolment=$attempt ? $this->data->byId('lmp_enrolment',(int)$attempt->enrolment_id) : null;
        if (!$attempt || !$enrolment || (int)$enrolment->profile_id!==(int)$profile) throw new MarketplaceException('Request not found.');
        return $attempt;
    }

    private function recordOperation($profile,$key,$hash,$attempt)
    {
        $existing=$this->data->one('lmp_operation',[
            ['column'=>'profile_id','operator'=>'=','value'=>(int)$profile],
            ['column'=>'operation_key','operator'=>'=','value'=>$key]
        ]);
        if ($existing) {
            if (!hash_equals($existing->payload_hash,$hash) || (int)$existing->attempt_id!==(int)$attempt) throw new MarketplaceException('This operation key was used with different terms.');
            return;
        }
        $this->data->insert('lmp_operation',[
            'profile_id'=>(int)$profile,
            'operation_key'=>$key,
            'payload_hash'=>$hash,
            'attempt_id'=>(int)$attempt,
            'created_at'=>$this->now()
        ]);
    }

    private function grantWithSossData($enrolment,$attempt,$snapshot,$transactionId)
    {
        foreach ($snapshot->lessons as $member) {
            $existing=$this->data->one('lmp_grant',[
                ['column'=>'enrolment_id','operator'=>'=','value'=>(int)$enrolment->id],
                ['column'=>'lesson_id','operator'=>'=','value'=>(int)$member->id]
            ]);
            if (!$existing) {
                $this->data->insert('lmp_grant',[
                    'enrolment_id'=>(int)$enrolment->id,
                    'attempt_id'=>(int)$attempt->id,
                    'version_id'=>(int)$attempt->version_id,
                    'profile_id'=>(int)$enrolment->profile_id,
                    'lesson_id'=>(int)$member->id,
                    'course_id'=>(int)$member->course_id,
                    'subject_id'=>(int)$member->subject_id,
                    'status'=>'active',
                    'created_at'=>$this->now()
                ]);
            }
        }
        $this->syncCohortWithSossData($enrolment,$snapshot);
        $this->data->update('lmp_attempt',$attempt,['status'=>'active','transaction_id'=>(int)$transactionId,'updated_at'=>$this->now()]);
        $this->data->update('lmp_enrolment',$enrolment,['status'=>'active','activated_at'=>$this->now()]);
    }

    private function syncCohortWithSossData($enrolment,$snapshot)
    {
        $classGradeId=(int)($snapshot->class_grade_id ?? 0);
        $courseId=(int)($snapshot->course_id ?? 0);
        $cohort=$this->data->byId('course_manager_classgrade',$classGradeId,'id',false);
        if (!$cohort || strtolower($cohort->status ?? '')!=='active' || (int)$cohort->course_id!==$courseId) {
            throw new MarketplaceException('The selected cohort is no longer available. The enrolment was not activated.');
        }

        $rows=$this->data->rows('course_manager_enrollment',[
            ['column'=>'class_grade_id','operator'=>'=','value'=>$classGradeId]
        ],[],10000,0,false);
        $owned=null;
        $activeCount=0;
        foreach ($rows as $row) {
            if (strtolower($row->status ?? '')==='active') {
                $activeCount++;
                if ((int)$row->student_id===(int)$enrolment->profile_id) return;
            }
            if (($row->source_app ?? '')==='lesson-market-place' && (int)($row->source_ref_id ?? 0)===(int)$enrolment->id) $owned=$row;
        }
        $capacity=(int)($cohort->capacity ?? 0);
        if ($capacity>0 && $activeCount>=$capacity) throw new MarketplaceException('The selected cohort has reached capacity. The enrolment was not activated.');

        if ($owned) {
            $this->data->update('course_manager_enrollment',$owned,['status'=>'active','enrolled_at'=>$this->now()]);
            return;
        }
        $profile=$this->data->byId('profile',(int)$enrolment->profile_id,'id',false);
        $this->data->insert('course_manager_enrollment',[
            'class_grade_id'=>$classGradeId,
            'course_id'=>$courseId,
            'student_id'=>(int)$enrolment->profile_id,
            'student_name'=>$profile->name ?? '',
            'student_email'=>$profile->email ?? '',
            'enrolled_at'=>$this->now(),
            'status'=>'active',
            'source_app'=>'lesson-market-place',
            'source_ref_id'=>(int)$enrolment->id,
            'access_scope'=>'package_lessons'
        ]);
    }

    private function recordEventWithSossData($enrolment,$attempt,$actor,$from,$to,$reason,$recipients)
    {
        $this->data->insert('lmp_audit',[
            'enrolment_id'=>(int)$enrolment->id,
            'attempt_id'=>(int)$attempt->id,
            'actor_profile_id'=>(int)$actor,
            'previous_state'=>$from,
            'new_state'=>$to,
            'reason'=>$reason,
            'created_at'=>$this->now()
        ]);
        $this->notifyWithSossData($attempt,$to,$reason,$recipients);
    }

    private function notifyWithSossData($attempt,$state,$reason,$recipients)
    {
        foreach (array_unique(array_map('intval',$recipients)) as $id) {
            if ($id<1) continue;
            $this->data->insert('course_manager_notification',[
                'entity_type'=>'lmp_attempt',
                'entity_id'=>(int)$attempt->id,
                'profile_id'=>$id,
                'profile_name'=>'',
                'email'=>'',
                'event_type'=>'lmp-'.$state,
                'message'=>'Lesson package request: '.str_replace('_',' ',$state).($reason!==''?'. '.$reason:''),
                'status'=>'queued',
                'created_at'=>$this->now()
            ]);
        }
    }

    private function lockAttemptInCreditTransaction($db,$id)
    {
        $identity=$db->one('SELECT a.enrolment_id,e.package_id FROM lmp_attempt a JOIN lmp_enrolment e ON e.id=a.enrolment_id WHERE a.id=?','i',[(int)$id]);
        if(!$identity)throw new MarketplaceException('Request not found.');
        $package=$db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[(int)$identity->package_id]);
        $enrolment=$db->one('SELECT * FROM lmp_enrolment WHERE id=? FOR UPDATE','i',[(int)$identity->enrolment_id]);
        $attempt=$db->one('SELECT * FROM lmp_attempt WHERE id=? FOR UPDATE','i',[(int)$id]);
        if(!$package||!$enrolment||!$attempt)throw new MarketplaceException('Request not found.');
        return [$package,$enrolment,$attempt];
    }

    private function grantInCreditTransaction($db,$enrolment,$attempt,$snapshot,$transactionId)
    {
        foreach ($snapshot->lessons as $member) $db->insert('lmp_grant',[
            'enrolment_id'=>(int)$enrolment->id,
            'attempt_id'=>(int)$attempt->id,
            'version_id'=>(int)$attempt->version_id,
            'profile_id'=>(int)$enrolment->profile_id,
            'lesson_id'=>(int)$member->id,
            'course_id'=>(int)$member->course_id,
            'subject_id'=>(int)$member->subject_id,
            'status'=>'active',
            'created_at'=>$this->now()
        ]);
        $this->syncCohortInCreditTransaction($db,$enrolment,$snapshot);
        $db->updateById('lmp_attempt',$attempt->id,['status'=>'active','transaction_id'=>$transactionId,'updated_at'=>$this->now()]);
        $db->updateById('lmp_enrolment',$enrolment->id,['status'=>'active','activated_at'=>$this->now()]);
    }

    private function syncCohortInCreditTransaction($db,$enrolment,$snapshot)
    {
        $classGradeId=(int)($snapshot->class_grade_id ?? 0);
        $courseId=(int)($snapshot->course_id ?? 0);
        $cohort=$db->one('SELECT * FROM course_manager_classgrade WHERE id=? FOR UPDATE','i',[$classGradeId]);
        if (!$cohort || strtolower($cohort->status ?? '')!=='active' || (int)$cohort->course_id!==$courseId) {
            throw new MarketplaceException('The selected cohort is no longer available. No credits were charged.');
        }
        $existing=$db->one('SELECT * FROM course_manager_enrollment WHERE class_grade_id=? AND student_id=? AND status=? LIMIT 1 FOR UPDATE','iis',[$classGradeId,(int)$enrolment->profile_id,'active']);
        if ($existing) return;
        $owned=$db->one('SELECT * FROM course_manager_enrollment WHERE source_app=? AND source_ref_id=? LIMIT 1 FOR UPDATE','si',['lesson-market-place',(int)$enrolment->id]);
        $count=$db->one('SELECT COUNT(*) AS total FROM course_manager_enrollment WHERE class_grade_id=? AND status=?','is',[$classGradeId,'active']);
        $capacity=(int)($cohort->capacity ?? 0);
        if ($capacity>0 && (int)($count->total ?? 0)>=$capacity) throw new MarketplaceException('The selected cohort has reached capacity. No credits were charged.');
        if ($owned) {
            $db->updateById('course_manager_enrollment',$owned->id,['status'=>'active','enrolled_at'=>$this->now()]);
            return;
        }
        $profile=$db->one('SELECT name,email FROM profile WHERE id=?','i',[(int)$enrolment->profile_id]);
        $db->insert('course_manager_enrollment',[
            'class_grade_id'=>$classGradeId,
            'course_id'=>$courseId,
            'student_id'=>(int)$enrolment->profile_id,
            'student_name'=>$profile->name ?? '',
            'student_email'=>$profile->email ?? '',
            'enrolled_at'=>$this->now(),
            'status'=>'active',
            'source_app'=>'lesson-market-place',
            'source_ref_id'=>(int)$enrolment->id,
            'access_scope'=>'package_lessons'
        ]);
    }

    private function auditInCreditTransaction($db,$enrolment,$attempt,$actor,$from,$to,$reason)
    {
        $db->insert('lmp_audit',[
            'enrolment_id'=>(int)$enrolment->id,
            'attempt_id'=>(int)$attempt->id,
            'actor_profile_id'=>(int)$actor,
            'previous_state'=>$from,
            'new_state'=>$to,
            'reason'=>$reason,
            'created_at'=>$this->now()
        ]);
    }

    public function safeAttempt($attempt)
    {
        if (!$attempt) return null;
        $safe=clone $attempt;
        unset($safe->internal_note,$safe->sysviewobject,$safe->syscreatedby,$safe->syslastupdatedby);
        $safe->terms=$this->snapshot($attempt);
        unset($safe->snapshot_json);
        return $safe;
    }
}
