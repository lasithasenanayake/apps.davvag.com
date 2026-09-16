<?php
namespace lesson_market_place;
require_once __DIR__ . '/MarketplaceRules.php';

/** All enrolment mutations use the shared CreditDatabase connection, including free grants. */
final class MarketplaceEnrolment
{
    private $db;
    private $ledger;
    private $catalog;
    public function __construct($db,$catalog,$ledger=null) { $this->db=$db; $this->catalog=$catalog; $this->ledger=$ledger; }
    private function now() { return date('Y-m-d H:i:s'); }
    private function snapshot($attempt) { $snapshot=json_decode($attempt->snapshot_json); if (!$snapshot) throw new MarketplaceException('Stored package terms are unavailable.'); return $snapshot; }

    public function request($profile,$packageId,$versionId,$key,$previousAttempt=0)
    {
        $key=MarketplaceRules::key($key);
        $hash=hash('sha256',json_encode([(int)$packageId,(int)$versionId,(int)$previousAttempt]));
        return $this->db->transaction(function($db) use($profile,$packageId,$versionId,$key,$previousAttempt,$hash) {
            $package=$db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[(int)$packageId]);
            if (!$package) throw new MarketplaceException('Package not found.');
            $operation=$db->one('SELECT * FROM lmp_operation WHERE profile_id=? AND operation_key=? FOR UPDATE','is',[(int)$profile,$key]);
            if ($operation) {
                if (!hash_equals($operation->payload_hash,$hash)) throw new MarketplaceException('This operation key was used with different terms.');
                return $this->safeAttempt($db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$operation->attempt_id]));
            }
            $enrolment=$db->one('SELECT * FROM lmp_enrolment WHERE profile_id=? AND package_id=? FOR UPDATE','ii',[(int)$profile,(int)$packageId]);
            if ($enrolment && $enrolment->current_attempt_id) {
                $attempt=$db->one('SELECT * FROM lmp_attempt WHERE id=? FOR UPDATE','i',[(int)$enrolment->current_attempt_id]);
                if (!in_array($attempt->status,['rejected','cancelled'],true)) {
                    if ($attempt->status!=='active' && (int)$attempt->version_id!==(int)$versionId) throw new MarketplaceException('A request already exists for different terms. Cancel it before making a new request.');
                    $this->operation($db,$profile,$key,$hash,$attempt->id);
                    return $this->safeAttempt($attempt);
                }
                if ((int)$previousAttempt!==(int)$attempt->id) throw new MarketplaceException('Choose Apply again to create an explicit new attempt.');
            } elseif ($previousAttempt) throw new MarketplaceException('The previous attempt does not match this enrolment.');
            if ($package->status!=='published' || (int)$package->published_version_id!==(int)$versionId) throw new MarketplaceException('Package terms changed or enrolment is unavailable. Refresh the package first.');
            $version=$db->one('SELECT * FROM lmp_version WHERE id=? AND package_id=?','ii',[(int)$versionId,(int)$packageId]);
            if (!$version) throw new MarketplaceException('Published package version not found.');
            $snapshot=json_decode($version->snapshot_json);
            $this->catalog->deliverable($snapshot,$db);
            if (!$enrolment) {
                $id=$db->insert('lmp_enrolment',['package_id'=>(int)$packageId,'profile_id'=>(int)$profile,'current_attempt_id'=>0,'status'=>'processing','created_at'=>$this->now()]);
                $enrolment=(object)['id'=>$id,'profile_id'=>(int)$profile];
            }
            $number=$db->one('SELECT COALESCE(MAX(attempt_number),0)+1 AS next_number FROM lmp_attempt WHERE enrolment_id=?','i',[(int)$enrolment->id]);
            $state=MarketplaceRules::initialState((int)$snapshot->credit_price,(bool)$snapshot->approval_required);
            $id=$db->insert('lmp_attempt',['enrolment_id'=>(int)$enrolment->id,'attempt_number'=>(int)$number->next_number,'version_id'=>(int)$versionId,'snapshot_json'=>$version->snapshot_json,'status'=>$state,'transaction_id'=>0,'decision_by'=>0,'decision_reason'=>'','internal_note'=>'','created_at'=>$this->now(),'updated_at'=>$this->now()]);
            $db->updateById('lmp_enrolment',$enrolment->id,['current_attempt_id'=>(int)$id,'status'=>$state]);
            $attempt=$db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$id]);
            $this->event($db,$enrolment,$attempt,$profile,'',$state,'',[$profile,$package->owner_profile_id]);
            if ($state==='active') $this->grant($db,$enrolment,$attempt,$snapshot,0);
            $this->operation($db,$profile,$key,$hash,$id);
            return $this->safeAttempt($attempt);
        });
    }

    public function confirm($profile,$attemptId,$versionId)
    {
        $attempt=$this->ownedAttempt($this->db,$profile,$attemptId);
        if ((int)$attempt->version_id!==(int)$versionId) throw new MarketplaceException('Confirm the recorded request version.');
        if ($attempt->status==='active') return $this->safeAttempt($attempt);
        $snapshot=$this->snapshot($attempt);
        MarketplaceRules::transition($attempt->status,'confirm',(int)$snapshot->credit_price);
        if (!$this->ledger || (int)$snapshot->credit_price<1) throw new MarketplaceException('Payment is unavailable.');
        // No transaction wrapper here: debit owns begin/commit/rollback. Its callback owns every grant.
        $context=['programCode'=>$snapshot->program_code,'sourceApp'=>'lesson-market-place','referenceType'=>'enrolment','referenceId'=>(string)$attempt->enrolment_id,'idempotencyKey'=>'lmp:enrolment:'.$attempt->enrolment_id.':purchase','description'=>'Enrol in '.$snapshot->name,'actorProfileId'=>(int)$profile,'metadata'=>['attempt_id'=>(int)$attemptId,'version_id'=>(int)$versionId]];
        $this->ledger->debit($profile,(int)$snapshot->credit_price,$context,function($db,$tx) use($profile,$attemptId,$versionId) {
            [$package,$enrolment,$locked]=$this->lockAttempt($db,$attemptId);
            if((int)$enrolment->profile_id!==(int)$profile)throw new MarketplaceException('Request not found.');
            if ((int)$locked->version_id!==(int)$versionId) throw new MarketplaceException('Request version changed.');
            MarketplaceRules::transition($locked->status,'confirm',(int)$this->snapshot($locked)->credit_price);
            // Existing agreed requests may settle an older version while the package remains open.
            if (!$package || $package->status!=='published' || (int)$enrolment->current_attempt_id!==(int)$locked->id) throw new MarketplaceException('Enrolment is no longer available. No credits were charged.');
            $snapshot=$this->snapshot($locked); $this->catalog->deliverable($snapshot,$db);
            $this->grant($db,$enrolment,$locked,$snapshot,(int)$tx->id);
            $this->event($db,$enrolment,$locked,$profile,$locked->status,'active','',[$profile,$package->owner_profile_id]);
        });
        return $this->safeAttempt($this->ownedAttempt($this->db,$profile,$attemptId));
    }

    public function decide($actor,$attemptId,$action,$reason,$note,$authorize)
    {
        return $this->db->transaction(function($db) use($actor,$attemptId,$action,$reason,$note,$authorize) {
            [$package,$enrolment,$attempt]=$this->lockAttempt($db,$attemptId);
            $authorize($package,$enrolment,$action);
            if ((int)$enrolment->current_attempt_id!==(int)$attemptId) throw new MarketplaceException('A newer request exists.');
            $snapshot=$this->snapshot($attempt);
            $next=MarketplaceRules::transition($attempt->status,$action,(int)$snapshot->credit_price);
            if ($action==='approve') {
                if ($package->status!=='published') throw new MarketplaceException('This package is unavailable for new enrolments.');
                $this->catalog->deliverable($snapshot,$db);
            }
            $updates=['status'=>$next,'updated_at'=>$this->now()];
            if ($action!=='cancel') $updates+=['decision_by'=>(int)$actor,'decision_at'=>$this->now(),'decision_reason'=>$reason,'internal_note'=>$note];
            $db->updateById('lmp_attempt',$attemptId,$updates);
            $db->updateById('lmp_enrolment',$enrolment->id,['status'=>$next]);
            if ($next==='active') $this->grant($db,$enrolment,$attempt,$snapshot,0);
            $this->event($db,$enrolment,$attempt,$actor,$attempt->status,$next,$reason,[$enrolment->profile_id,$package->owner_profile_id]);
            return $this->safeAttempt($db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$attemptId]));
        });
    }

    private function lockAttempt($db,$id)
    {
        $identity=$db->one('SELECT a.enrolment_id,e.package_id FROM lmp_attempt a JOIN lmp_enrolment e ON e.id=a.enrolment_id WHERE a.id=?','i',[(int)$id]);
        if(!$identity)throw new MarketplaceException('Request not found.');
        // Match request/publication order. Identity links are immutable.
        $package=$db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[(int)$identity->package_id]);
        $enrolment=$db->one('SELECT * FROM lmp_enrolment WHERE id=? FOR UPDATE','i',[(int)$identity->enrolment_id]);
        $attempt=$db->one('SELECT * FROM lmp_attempt WHERE id=? FOR UPDATE','i',[(int)$id]);
        if(!$package||!$enrolment||!$attempt)throw new MarketplaceException('Request not found.');
        return [$package,$enrolment,$attempt];
    }
    private function ownedAttempt($db,$profile,$id,$lock=false)
    {
        $attempt=$db->one('SELECT a.* FROM lmp_attempt a JOIN lmp_enrolment e ON e.id=a.enrolment_id WHERE a.id=? AND e.profile_id=?'.($lock?' FOR UPDATE':''),'ii',[(int)$id,(int)$profile]);
        if (!$attempt) throw new MarketplaceException('Request not found.');
        return $attempt;
    }
    private function operation($db,$profile,$key,$hash,$attempt)
    {
        $db->insert('lmp_operation',['profile_id'=>(int)$profile,'operation_key'=>$key,'payload_hash'=>$hash,'attempt_id'=>(int)$attempt,'created_at'=>$this->now()]);
    }
    private function grant($db,$enrolment,$attempt,$snapshot,$transactionId)
    {
        foreach ($snapshot->lessons as $member) $db->insert('lmp_grant',['enrolment_id'=>(int)$enrolment->id,'attempt_id'=>(int)$attempt->id,'version_id'=>(int)$attempt->version_id,'profile_id'=>(int)$enrolment->profile_id,'lesson_id'=>(int)$member->id,'course_id'=>(int)$member->course_id,'subject_id'=>(int)$member->subject_id,'status'=>'active','created_at'=>$this->now()]);
        $db->updateById('lmp_attempt',$attempt->id,['status'=>'active','transaction_id'=>$transactionId,'updated_at'=>$this->now()]);
        $db->updateById('lmp_enrolment',$enrolment->id,['status'=>'active','activated_at'=>$this->now()]);
    }
    private function event($db,$enrolment,$attempt,$actor,$from,$to,$reason,$recipients)
    {
        $db->insert('lmp_audit',['enrolment_id'=>(int)$enrolment->id,'attempt_id'=>(int)$attempt->id,'actor_profile_id'=>(int)$actor,'previous_state'=>$from,'new_state'=>$to,'reason'=>$reason,'created_at'=>$this->now()]);
        foreach (array_unique(array_map('intval',$recipients)) as $id) {
            if ($id<1) continue;
            $db->insert('course_manager_notification',['entity_type'=>'lmp_attempt','entity_id'=>(int)$attempt->id,'profile_id'=>$id,'profile_name'=>'','email'=>'','event_type'=>'lmp-'.$to,'message'=>'Lesson package request: '.str_replace('_',' ',$to).($reason!==''?'. '.$reason:''),'status'=>'queued','created_at'=>$this->now()]);
        }
    }
    public function safeAttempt($attempt)
    {
        if (!$attempt) return null;
        $safe=clone $attempt; unset($safe->internal_note,$safe->sysviewobject,$safe->syscreatedby,$safe->syslastupdatedby);
        $safe->terms=$this->snapshot($attempt); unset($safe->snapshot_json);
        return $safe;
    }
}
