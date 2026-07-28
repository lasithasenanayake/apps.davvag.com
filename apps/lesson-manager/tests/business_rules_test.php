<?php
require_once(__DIR__ . "/../services/api/service.php");
use lesson_manager\LessonRules;
use lesson_manager\ApiService;
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
check(LessonRules::validCreditRequirement(true,0),"free lessons do not require credit points");
check(LessonRules::validCreditRequirement(false,25),"non-free lessons accept a positive whole credit requirement");
check(!LessonRules::validCreditRequirement(false,0)&&!LessonRules::validCreditRequirement(false,"1.5"),"non-free lessons reject missing or fractional credit requirements");
$service=new ApiService();$reflection=new ReflectionClass($service);
$sanitize=$reflection->getMethod("sanitizeRichText");$sanitize->setAccessible(true);
$safe=$sanitize->invoke($service,'<p onclick="bad()"><strong>Safe</strong><script>alert(1)</script><a href="javascript:bad">bad</a></p>');
check(strpos($safe,"<strong>Safe</strong>")!==false,"rich text keeps allowed formatting");
check(stripos($safe,"script")===false&&stripos($safe,"onclick")===false&&stripos($safe,"javascript:")===false,"rich text removes executable markup");
$safeLocal=$sanitize->invoke($service,'<img src="components/dock/soss-uploader/service/get/lesson_content_image/example.png"><img src="javascript:bad">');
check(strpos($safeLocal,"lesson_content_image/example.png")!==false&&stripos($safeLocal,"javascript:")===false,"rich text keeps approved uploader images and rejects executable URLs");
$videoId=$reflection->getMethod("youtubeVideoId");$videoId->setAccessible(true);
check($videoId->invoke($service,"https://www.youtube.com/watch?v=dQw4w9WgXcQ")==="dQw4w9WgXcQ","YouTube URLs are normalized to video IDs");
putenv("DAVVAG_PROVIDER_SECRET=lesson-manager-test-secret");
$encrypt=$reflection->getMethod("encryptProviderSecret");$encrypt->setAccessible(true);$decrypt=$reflection->getMethod("providerValue");$decrypt->setAccessible(true);
$encrypted=$encrypt->invoke($service,"round-trip");$secretRow=obj(array("value"=>$encrypted));
check($decrypt->invoke($service,$secretRow,"value")==="round-trip","provider credentials encrypt and decrypt with the server secret");
putenv("DAVVAG_PROVIDER_SECRET");
$_SERVER["HTTP_HOST"]="localhost";$_SERVER["REQUEST_URI"]="/davvag-core/components/lesson-manager/api/service/StartProviderOAuth";
$baseUrl=$reflection->getMethod("appBaseUrl");$baseUrl->setAccessible(true);
check($baseUrl->invoke($service)==="http://localhost/davvag-core","OAuth callback URLs preserve a subdirectory installation path");
$decode=$reflection->getMethod("decodeAgentJson");$decode->setAccessible(true);$draft=$decode->invoke($service,"```json\n{\"title\":\"Draft\",\"questions\":[]}\n```");
check($draft&&$draft->title==="Draft","saved-agent JSON responses are decoded from fenced output");
$component=json_decode(file_get_contents(__DIR__."/../services/api/component.json"),true);foreach($component["serviceHandler"]["methods"] as $method=>$settings){$prefix=strtoupper(isset($settings["method"])?$settings["method"]:"POST")==="GET"?"get":"post";check($reflection->hasMethod($prefix.$method),"service descriptor method ".$method." has a PHP handler");}
if($failures===0)echo "LessonRules tests passed.".PHP_EOL;
exit($failures?1:0);
?>
