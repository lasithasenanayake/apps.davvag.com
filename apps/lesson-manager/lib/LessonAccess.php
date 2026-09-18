<?php
namespace lesson_manager;

/** Source-aware entitlement contract. It does not bypass publication or learning progression. */
final class LessonAccess
{
    private $cache=[];
    private function rows($ns,$conditions)
    {
        $query=['conditions'=>[],'pageSize'=>10000,'pageFrom'=>0];
        foreach($conditions as $field=>$value)$query['conditions'][]=['column'=>$field,'operator'=>'=','value'=>$value];
        $read=function() use($ns,$query) { $r=\SOSSData::Query($ns,$query); if(!$r || !$r->success) throw new \RuntimeException('Lesson access could not be verified.'); return $r->result; };
        return \SOSSData::WithServiceNamespaces([$ns],$read);
    }
    public function sources($profile)
    {
        $profile=(int)$profile;
        if($profile<1)return ['courses'=>[],'standalone'=>[],'packages'=>[]];
        if(isset($this->cache[$profile]))return $this->cache[$profile];
        $out=['courses'=>[],'standalone'=>[],'packages'=>[]];
        foreach($this->rows('course_manager_enrollment',['student_id'=>$profile]) as $row) {
            if(isset($row->status)&&strtolower($row->status)!=='active')continue;
            // Marketplace cohort membership is used by Course Manager rosters,
            // timetables, and assignments. Lesson access remains limited to the
            // immutable lmp_grant rows from the purchased package.
            if(strtolower($row->access_scope ?? '')==='package_lessons')continue;
            $course=(int)($row->course_id ?? 0);
            if(!$course && !empty($row->class_grade_id)) { $classes=$this->rows('course_manager_classgrade',['id'=>(int)$row->class_grade_id]);$course=(int)($classes[0]->course_id ?? 0); }
            if($course)$out['courses'][$course]=true;
        }
        // Legacy purchases intentionally retain the existing permanent-unlock interpretation.
        if(is_file(SCHEMA_PATH.'/davvag_credit_lesson_unlock.json')) foreach($this->rows('davvag_credit_lesson_unlock',['profile_id'=>$profile]) as $unlock)$out['standalone'][(int)$unlock->lesson_id]=true;
        if(is_file(SCHEMA_PATH.'/lmp_grant.json')) {
            $active=[];foreach($this->rows('lmp_enrolment',['profile_id'=>$profile,'status'=>'active']) as $e)$active[(int)$e->id]=true;
            foreach($this->rows('lmp_grant',['profile_id'=>$profile,'status'=>'active']) as $grant)if(isset($active[(int)$grant->enrolment_id]))$out['packages'][(int)$grant->lesson_id][]=(int)$grant->enrolment_id;
        }
        return $this->cache[$profile]=$out;
    }
    public function broadCourse($course,$profile) { return isset($this->sources($profile)['courses'][(int)$course]); }
    public function eligible($lesson,$profile)
    {
        if(!$lesson || (int)$profile<1)return false;
        $s=$this->sources($profile);
        return isset($s['courses'][(int)$lesson->course_id]) || isset($s['standalone'][(int)$lesson->id]) || isset($s['packages'][(int)$lesson->id]);
    }
    public function financiallyCovered($lesson,$profile)
    {
        if(!$lesson || !$this->eligible($lesson,$profile))return false;
        $s=$this->sources($profile);
        return !isset($lesson->is_free) || in_array($lesson->is_free,[true,1,'1','true'],true)
            || isset($s['standalone'][(int)$lesson->id]) || isset($s['packages'][(int)$lesson->id]);
    }
    public function courseIds($profile)
    {
        $s=$this->sources($profile);$ids=array_keys($s['courses']);
        foreach(array_unique(array_merge(array_keys($s['standalone']),array_keys($s['packages']))) as $id) {
            $rows=$this->rows('lesson_manager_lesson',['id'=>(int)$id]); if($rows && strtolower($rows[0]->status ?? '')==='published')$ids[]=(int)$rows[0]->course_id;
        }
        return array_values(array_unique($ids));
    }
}
