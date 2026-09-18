<?php
namespace lesson_market_place;
require_once __DIR__ . '/MarketplaceRules.php';
require_once __DIR__ . '/MarketplaceData.php';

/** Catalog validation keeps SOSSData schema and view-object controls in force. */
final class MarketplaceCatalog
{
    private $actor;
    private $admin;
    private $data;
    public function __construct($actor, $admin, $data = null) { $this->actor=(int)$actor; $this->admin=(bool)$admin; $this->data=$data ?: new MarketplaceData(); }

    public function rows($namespace, $conditions = [], $limit = 100, $offset = 0)
    {
        $query = [];
        foreach ($conditions as $key=>$value) $query[]=['column'=>$key,'operator'=>'=','value'=>$value];
        return $this->data->query($namespace,$query,[],$limit,$offset);
    }
    public function one($namespace,$id,$key='id') { $rows=$this->rows($namespace,[$key=>(int)$id],1)->result; return $rows ? $rows[0] : null; }

    public function validateDraft($body)
    {
        $draft = new \stdClass();
        foreach (['name'=>200,'summary'=>2000,'outcomes'=>6000,'audience'=>4000,'prerequisites'=>6000] as $field=>$length) $draft->$field=MarketplaceRules::text($body->$field ?? '',ucfirst($field),$length,$field==='name');
        $draft->description=MarketplaceRules::richText($body->description ?? '');
        $draft->cover_image=MarketplaceRules::safeUrl($body->cover_image ?? '');
        $draft->pricing_mode=$body->pricing_mode ?? '';
        $draft->credit_price=MarketplaceRules::price($draft->pricing_mode,$body->credit_price ?? 0);
        $draft->approval_required=MarketplaceRules::boolean($body->approval_required ?? false);
        $draft->product_id=MarketplaceRules::integer($body->product_id ?? 0,'Product ID');
        $product=$this->one('products',$draft->product_id,'itemid');
        if (!$product || strtolower($product->status ?? '')==='deleted') throw new MarketplaceException('Select an existing product visible in your product management scope.');
        // Productapp delegates record scope to Auth::ViewObjects; retain that filter above.
        $draft->class_grade_id=MarketplaceRules::integer($body->class_grade_id ?? 0,'Cohort');
        $cohort=$this->one('course_manager_classgrade',$draft->class_grade_id);
        if (!$cohort || strtolower($cohort->status ?? '')!=='active') throw new MarketplaceException('Select an active Course Manager cohort.');
        $draft->course_id=(int)($cohort->course_id ?? 0);
        $draft->cohort_name=$cohort->name ?? ('Cohort #'.$draft->class_grade_id);
        if ($draft->course_id<1) throw new MarketplaceException('The selected cohort is not assigned to a course.');
        $draft->lesson_ids=MarketplaceRules::lessonIds($body->lesson_ids ?? []);
        $draft->lessons=[];
        foreach ($draft->lesson_ids as $id) {
            $lesson=$this->one('lesson_manager_lesson',$id);
            $subject=$lesson ? $this->one('course_manager_subject',$lesson->subject_id) : null;
            $course=$subject ? $this->one('course_manager_course',$subject->course_id) : null;
            if (!$lesson || !$subject || !$course || strtolower($lesson->status ?? '')==='deleted'
                || (int)$lesson->course_id!==(int)$subject->course_id || (!$this->admin && (int)($subject->teacher_id ?? 0)!==$this->actor)) throw new MarketplaceException('Every lesson must be active, visible, and assigned to a subject you manage.');
            if ((int)$subject->course_id!==$draft->course_id) throw new MarketplaceException('Every package lesson must belong to the selected cohort course.');
            $draft->lessons[]=(object)['id'=>$id,'title'=>$lesson->title,'course_id'=>(int)$subject->course_id,'course_title'=>$course->title ?? $course->name ?? 'Course','subject_id'=>(int)$subject->id,'subject_title'=>$subject->title ?? $subject->name ?? 'Subject'];
        }
        return $draft;
    }

    public function deliverable($snapshot)
    {
        $cohort=$this->one('course_manager_classgrade',(int)($snapshot->class_grade_id ?? 0));
        if (!$cohort || strtolower($cohort->status ?? '')!=='active' || (int)$cohort->course_id!==(int)($snapshot->course_id ?? 0)) {
            throw new MarketplaceException('The package cohort is unavailable or its course changed. No credits were charged. Ask staff to update the package.');
        }
        $ids=MarketplaceRules::lessonIds($snapshot->lesson_ids ?? []);
        $subjects=[];
        foreach ($snapshot->lessons as $member) {
            $lesson=$this->one('lesson_manager_lesson',(int)$member->id);
            $subject=$this->one('course_manager_subject',(int)$member->subject_id);
            $course=$this->one('course_manager_course',(int)$member->course_id);
            if (!$lesson || !$subject || !$course || strtolower($lesson->status ?? '')!=='published'
                || strtolower($subject->status ?? '')==='deleted' || strtolower($course->status ?? '')==='deleted'
                || (int)$lesson->subject_id!==(int)$member->subject_id || (int)$lesson->course_id!==(int)$member->course_id
                || (int)$member->course_id!==(int)$cohort->course_id
                || (int)$subject->course_id!==(int)$member->course_id || (!empty($lesson->available_at) && strtotime($lesson->available_at)>time())) {
                throw new MarketplaceException('An included lesson is unavailable or its course/subject changed. No credits were charged. Ask staff to update the package and submit a new request.');
            }
            $subjects[(int)$member->subject_id]=true;
        }
        foreach (array_keys($subjects) as $subjectId) {
            $lessons=$this->data->rows('lesson_manager_lesson',[
                ['column'=>'subject_id','operator'=>'=','value'=>(int)$subjectId]
            ],[
                ['column'=>'lesson_order','direction'=>'ASC'],
                ['column'=>'id','direction'=>'ASC']
            ],10000);
            $missing=MarketplaceRules::prerequisites($ids,$lessons);
            if ($missing) throw new MarketplaceException('Package is missing required preceding lesson IDs: '.implode(', ',$missing).'. Update the package before publication or enrolment.');
        }
    }

    /**
     * Recheck delivery inside CreditLedgerService's transaction. This is the
     * documented narrow exception: SOSSData has no public transaction handle,
     * and the debit plus grants must use the same connection.
     */
    public function deliverableInCreditTransaction($snapshot,$db)
    {
        $cohort=$db->one('SELECT * FROM course_manager_classgrade WHERE id=? FOR UPDATE','i',[(int)($snapshot->class_grade_id ?? 0)]);
        if (!$cohort || strtolower($cohort->status ?? '')!=='active' || (int)$cohort->course_id!==(int)($snapshot->course_id ?? 0)) {
            throw new MarketplaceException('The package cohort is unavailable or its course changed. No credits were charged. Ask staff to update the package.');
        }
        $ids=MarketplaceRules::lessonIds($snapshot->lesson_ids ?? []);
        $subjects=[];
        foreach ($snapshot->lessons as $member) {
            $lesson=$db->one('SELECT * FROM lesson_manager_lesson WHERE id=? FOR UPDATE','i',[(int)$member->id]);
            $subject=$db->one('SELECT * FROM course_manager_subject WHERE id=? FOR UPDATE','i',[(int)$member->subject_id]);
            $course=$db->one('SELECT * FROM course_manager_course WHERE id=? FOR UPDATE','i',[(int)$member->course_id]);
            if (!$lesson || !$subject || !$course || strtolower($lesson->status ?? '')!=='published'
                || strtolower($subject->status ?? '')==='deleted' || strtolower($course->status ?? '')==='deleted'
                || (int)$lesson->subject_id!==(int)$member->subject_id || (int)$lesson->course_id!==(int)$member->course_id
                || (int)$member->course_id!==(int)$cohort->course_id
                || (int)$subject->course_id!==(int)$member->course_id || (!empty($lesson->available_at) && strtotime($lesson->available_at)>time())) {
                throw new MarketplaceException('An included lesson is unavailable or its course/subject changed. No credits were charged. Ask staff to update the package and submit a new request.');
            }
            $subjects[(int)$member->subject_id]=true;
        }
        foreach (array_keys($subjects) as $subjectId) {
            $lessons=$db->all('SELECT * FROM lesson_manager_lesson WHERE subject_id=? ORDER BY lesson_order,id FOR UPDATE','i',[$subjectId]);
            $missing=MarketplaceRules::prerequisites($ids,$lessons);
            if ($missing) throw new MarketplaceException('Package is missing required preceding lesson IDs: '.implode(', ',$missing).'. Update the package before publication or enrolment.');
        }
    }
}
