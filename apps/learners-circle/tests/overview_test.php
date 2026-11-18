<?php
// In-memory service fixtures: no tenant records or learner results are changed.
define('SCHEMA_PATH',dirname(__DIR__,3).'/schemas');
class Auth {public static $role='web_user';public static function Autendicate(){return(object)['group'=>self::$role];}}
class Profile {public static $id=7;public static function getUserProfile(){return(object)['id'=>self::$id,'name'=>'Test learner'];}}
class SOSSData {
    public static $rows=[];
    public static function WithServiceNamespaces($namespaces,$callback){return $callback();}
    public static function Query($namespace,$query,$version=null,$sorting='desc'){
        $conditions=is_array($query)?$query['conditions']:array_map(function($part){$v=explode(':',$part,2);return['column'=>$v[0],'value'=>$v[1]];},array_filter(explode(',',$query)));
        $rows=array_values(array_filter(self::$rows[$namespace]??[],function($row)use($conditions){foreach($conditions as $c)if(strval($row->{$c['column']}??'')!==strval($c['value']))return false;return true;}));
        usort($rows,function($a,$b)use($sorting){return(strtolower($sorting)==='desc'?-1:1)*(($a->id??0)<=>($b->id??0));});
        return(object)['success'=>true,'result'=>$rows];
    }
}
require dirname(__DIR__,2).'/lesson-manager/services/api/service.php';
function row($value){return(object)$value;}
function check($value,$message){global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function overview($body=[]){$req=new class($body){private $body;function __construct($b){$this->body=(object)$b;}function Body($decode){return $this->body;}};$res=new lesson_manager\LessonManagerMemoryResponse();$result=(new lesson_manager\ApiService())->postLearnerOverview($req,$res);return[$result,$res->GetError()];}
$checks=0;
SOSSData::$rows=[
    'course_manager_course'=>[row(['id'=>4,'code'=>'BIBLE','title'=>'Bible study']),row(['id'=>8,'title'=>'Unassigned course'])],
    'course_manager_subject'=>[row(['id'=>5,'course_id'=>4,'teacher_id'=>9]),row(['id'=>8,'course_id'=>8])],
    'profile'=>[row(['id'=>9,'name'=>'Teacher'])],
    'lmp_enrolment'=>[row(['id'=>1,'profile_id'=>7,'status'=>'active'])],
    'lmp_grant'=>[row(['id'=>1,'enrolment_id'=>1,'profile_id'=>7,'lesson_id'=>56,'status'=>'active']),row(['id'=>2,'enrolment_id'=>1,'profile_id'=>7,'lesson_id'=>57,'status'=>'active'])],
    'lesson_manager_lesson'=>array_map(function($id){return row(['id'=>$id,'course_id'=>4,'subject_id'=>5,'lesson_order'=>$id-55,'title'=>'Lesson '.$id,'status'=>$id===99?'draft':'published']);},[56,57,58,99]),
    'lesson_manager_progress'=>[row(['id'=>1,'course_id'=>4,'student_id'=>7,'lesson_id'=>56,'lesson_completed'=>'true']),row(['id'=>2,'course_id'=>4,'student_id'=>99,'lesson_id'=>57,'lesson_completed'=>'true'])],
    'lesson_manager_quiz'=>[row(['id'=>1,'lesson_id'=>56,'assessment_id'=>1]),row(['id'=>2,'lesson_id'=>57,'assessment_id'=>3])],
    'course_manager_assignment'=>[row(['id'=>2,'subject_id'=>5])],
    'course_manager_mark'=>[row(['id'=>1,'student_id'=>7,'assessment_id'=>1,'marks'=>3,'max_mark'=>10]),row(['id'=>2,'student_id'=>7,'assessment_id'=>1,'marks'=>7,'max_mark'=>10]),row(['id'=>3,'student_id'=>7,'assignment_id'=>2,'marks'=>18,'max_mark'=>20]),row(['id'=>4,'student_id'=>99,'assessment_id'=>3,'marks'=>100,'max_mark'=>100]),row(['id'=>5,'student_id'=>7,'subject_id'=>8,'marks'=>15,'max_mark'=>15])]
];
[$result,$error]=overview();
check(!$error,'own dashboard loads');
check(count($result['courses'])===1&&$result['courses'][0]['id']===4,'only entitled courses are listed');
check($result['stats']['total_lessons']===2&&$result['stats']['completed_lessons']===1,'only granted published lessons and own progress are counted');
check($result['stats']['completion_percentage']===50.0,'lesson progress uses correct denominator');
check($result['stats']['earned_marks']===25.0&&$result['stats']['maximum_marks']===30.0,'latest quiz and assignment marks count once');
check($result['stats']['marks_percentage']===83.3,'performance is weighted by maximum marks');
check($result['courses'][0]['current_lesson']['id']===57,'next incomplete entitled lesson is returned');
check($result['courses'][0]['teacher_names']===['Teacher'],'teacher names use shared profiles');
[, $error]=overview(['student_id'=>99]);check(!!$error,'caller cannot impersonate another learner');
[, $error]=overview(['course_id'=>8]);check(!!$error,'caller cannot request an unrelated course');
Auth::$role='anonymous';[, $error]=overview();check(!!$error,'anonymous profile is insufficient for dashboard access');
Auth::$role='teacher';[$result,$error]=overview();check(!$error&&count($result['courses'])===1&&$result['stats']['total_lessons']===2,'staff dashboard keeps personal package entitlements');
Auth::$role='web_user';
SOSSData::$rows['lmp_grant'][]=row(['id'=>3,'enrolment_id'=>1,'profile_id'=>7,'lesson_id'=>58,'status'=>'active']);
[$result,$error]=overview();check($result['stats']['total_lessons']===3&&$result['stats']['completion_percentage']===33.3,'later package access extensions update the dashboard');
SOSSData::$rows['course_manager_mark']=[];
[$result,$error]=overview();check($result['stats']['marks_percentage']===null,'unmarked work is not presented as a zero score');
Profile::$id=18;[$result,$error]=overview();check(!$error&&!count($result['courses'])&&$result['stats']['total_lessons']===0&&$result['stats']['marks_percentage']===null,'new learners receive an honest empty state');
echo "Learners Circle overview: $checks checks passed.\n";
