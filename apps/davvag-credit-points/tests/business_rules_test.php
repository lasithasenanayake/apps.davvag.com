<?php
require_once dirname(__DIR__)."/lib/CreditLedgerService.php";
use davvag_credit_points\CreditRules;
use davvag_credit_points\CreditException;
$passed=0;$failed=0;
function check($condition,$message){global$passed,$failed;if($condition){$passed++;echo"PASS: ".$message.PHP_EOL;}else{$failed++;echo"FAIL: ".$message.PHP_EOL;}}
function rejects($fn,$message){try{$fn();check(false,$message);}catch(CreditException$e){check(true,$message);}}
check(CreditRules::amount("25")===25,"whole positive credits are accepted");
rejects(function(){CreditRules::amount(0);},"zero credits are rejected");
rejects(function(){CreditRules::amount("1.5");},"fractional credits are rejected");
rejects(function(){CreditRules::amount(1000000001);},"amount safety ceiling is enforced");
check(CreditRules::normalizeCoupon(" dv-ab12-cd34 ")==="DVAB12CD34","coupon formatting is normalized");
check(CreditRules::idempotency("lesson-access:7:9")==="lesson-access:7:9","valid idempotency keys are preserved");
rejects(function(){CreditRules::idempotency("bad key with spaces");},"unsafe idempotency keys are rejected");
$a=CreditRules::requestHash(array("b"=>2,"a"=>1));$b=CreditRules::requestHash(array("a"=>1,"b"=>2));check(hash_equals($a,$b),"request hashing is canonical across object key order");
check(preg_match('/^\d{4}-\d{2}-\d{2}$/',CreditRules::periodKey("DAILY","Asia/Colombo"))===1,"daily reward periods are computed on the server");
check(CreditRules::truthy("true")===true&&CreditRules::truthy("false")===false,"boolean storage values are interpreted safely");
echo"RESULT: ".$passed." passed, ".$failed." failed".PHP_EOL;exit($failed?1:0);
?>
