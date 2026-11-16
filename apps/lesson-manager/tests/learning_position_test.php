<?php
// Isolated service test; no application data or live learner records are used.
define('GROUPID', 'web_user');
define('SCHEMA_PATH', __DIR__ . '/../../../schemas');
class Profile { public static $id=7; public static function getUserProfile() { return (object)['id'=>self::$id, 'name'=>'Test learner']; } }
class SOSSData {
    public static $rows=[];
    public static function WithServiceNamespaces($ns,$fn) { return $fn(); }
    public static function Query($ns,$query) {
        $rows=self::$rows[$ns] ?? [];
        $conditions=is_array($query) ? $query['conditions'] : array_map(function($s){$x=explode(':',$s,2);return ['column'=>$x[0],'value'=>$x[1]];}, array_filter(explode(',',$query)));
        return (object)['success'=>true,'result'=>array_values(array_filter($rows,function($r)use($conditions){foreach($conditions as$c)if(strval($r->{$c['column']}??'')!==strval($c['value']))return false;return true;}))];
    }
    public static function Update($ns,$item) { foreach(self::$rows[$ns] as &$row)if($row->id===$item->id)$row=unserialize(serialize($item)); return (object)['success'=>true]; }
    public static function Insert($ns,$item) { $copy=unserialize(serialize($item));$copy->id=count(self::$rows[$ns]??[])+1;self::$rows[$ns][]=$copy;return(object)['success'=>true,'result'=>(object)['generatedId'=>$copy->id]]; }
}
require_once __DIR__.'/../services/api/service.php';
function check($value,$message) { if(!$value)throw new RuntimeException($message); }
function row($values) { return (object)$values; }
SOSSData::$rows=[
    'course_manager_enrollment'=>[row(['student_id'=>7,'course_id'=>4,'status'=>'active'])],
    'lesson_manager_lesson'=>[row(['id'=>56,'course_id'=>4,'subject_id'=>5,'lesson_order'=>1,'status'=>'published','is_free'=>'true','require_reading'=>'true','require_quiz'=>'true'])],
    'lesson_manager_content'=>[row(['id'=>1,'lesson_id'=>56,'status'=>'published'])],
    'lesson_manager_progress'=>[row(['id'=>1,'course_id'=>4,'lesson_id'=>56,'student_id'=>7,'reading_completed'=>'true','quiz_passed'=>'false','lesson_completed'=>'false'])]
];
function save($payload) {
    $request=new class($payload) {private $payload;function __construct($p){$this->payload=$p;}function Body($decode){return $this->payload;}};
    $response=new lesson_manager\LessonManagerMemoryResponse();
    $result=(new lesson_manager\ApiService())->postSaveLearningPosition($request,$response);
    return [$result,$response->GetError()];
}
$payload=row(['lesson_id'=>56,'section'=>'reading:1','drafts'=>row(['assignments'=>row(['6'=>row(['content'=>'Saved work'])])]),'completed_sections'=>['reading:1','summary'],'student_id'=>99,'quiz_passed'=>true,'lesson_completed'=>true]);
[$saved,$error]=save($payload);
check(!$error&&$saved->current_section==='reading:1','saves accessible section');
check($saved->student_id===7,'learner cannot choose another profile');
check($saved->reading_completed==='true'&&$saved->quiz_passed==='false'&&$saved->lesson_completed==='false','navigation preserves authoritative completion');
check($saved->completed_sections===['reading:1'],'only material/video section markers are accepted');
check($saved->learning_drafts->assignments->{'6'}->content==='Saved work','drafts round trip');
$payload->section='quiz:999';[, $error]=save($payload);check(!!$error,'rejects missing section');
$payload->section='summary';$payload->drafts=row(['text'=>str_repeat('a',200001)]);[, $error]=save($payload);check(!!$error,'rejects excessive drafts');
$payload->drafts=new stdClass();Profile::$id=8;[, $error]=save($payload);check(!!$error,'rejects unassigned learner');
Profile::$id=7;SOSSData::$rows['lesson_manager_lesson'][0]->is_free='false';[, $error]=save($payload);check(!!$error,'position save cannot purchase a locked lesson');
echo "Learning position service tests passed.\n";
