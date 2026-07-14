<?php
require_once(__DIR__ . "/../services/api/service.php");
use lesson_manager\LessonRules;
$failures=0;
function check($value,$message){global $failures;if(!$value){$failures++;echo "FAIL: ".$message.PHP_EOL;}}
function obj($data){$o=new stdClass();foreach($data as $k=>$v)$o->{$k}=$v;return $o;}
$lesson=obj(array("require_reading"=>"true","require_video"=>"false","require_quiz"=>"true","require_assignment"=>"false","require_teacher_approval"=>"false"));
$progress=obj(array("reading_completed"=>"true","quiz_passed"=>"true"));
check(LessonRules::requirementsMet($lesson,$progress),"required reading and quiz complete lesson");
$progress->quiz_passed="false";check(!LessonRules::requirementsMet($lesson,$progress),"failed quiz keeps lesson incomplete");
$progress->override_unlocked="true";check(LessonRules::requirementsMet($lesson,$progress),"teacher override completes lock requirements");
$question=obj(array("marks"=>2,"negative_marks"=>0.5,"correct_answer"=>array("A","C"),"requires_manual_marking"=>"false","question_type"=>"multiple_answer"));
check(LessonRules::scoreQuestion($question,array("C","A"),true)===2.0,"multiple answers are order independent");
check(LessonRules::scoreQuestion($question,array("A"),true)===-0.5,"negative mark is applied");
check(LessonRules::completionPercent(3,4)===75.0,"completion percentage is calculated");
if($failures===0)echo "LessonRules tests passed.".PHP_EOL;
exit($failures?1:0);
?>
