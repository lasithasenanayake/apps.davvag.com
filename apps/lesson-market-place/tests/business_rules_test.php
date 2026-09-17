<?php
require_once dirname(__DIR__).'/lib/MarketplaceRules.php';
require_once dirname(__DIR__,2).'/davvag-stripe/lib/CreditCheckoutAdapter.php';
use lesson_market_place\MarketplaceRules as Rules;
$checks=0;
function check($value,$message) { global $checks; $checks++; if(!$value)throw new RuntimeException($message); }
function rejects($fn,$message) { try{$fn();}catch(Throwable $e){check(true,$message);return;}check(false,$message); }
foreach([[0,false,'active'],[0,true,'pending_approval'],[25,false,'awaiting_payment'],[25,true,'pending_approval']] as $case) check(Rules::initialState($case[0],$case[1])===$case[2],'all pricing/approval combinations');
check(Rules::transition('pending_approval','approve',0)==='active','free approval grants access');
check(Rules::transition('pending_approval','approve',25)==='awaiting_payment','paid approval does not charge');
check(Rules::transition('awaiting_payment','confirm',25)==='active','learner confirmation activates');
foreach(['active','rejected','cancelled'] as $state)foreach(['confirm','approve','reject','cancel'] as $action)rejects(function()use($state,$action){Rules::transition($state,$action,25);},'terminal states reject transitions');
foreach([1.5,'01','-1','1e2',true,[],null] as $value)rejects(function()use($value){Rules::price('credits',$value);},'invalid prices rejected');
rejects(function(){Rules::price('free',1);},'free price must be zero');
rejects(function(){Rules::price('credits',0);},'paid price must be positive');
check(Rules::price('credits',1000000000)===1000000000,'maximum credits');
rejects(function(){Rules::lessonIds([1,1]);},'duplicate lessons');
rejects(function(){Rules::lessonIds([]);},'empty packages');
rejects(function(){Rules::lessonIds([1,'2 OR 1=1']);},'invalid lesson reference');
foreach(['https://evil.example/','#//evil.example','#/app/lesson-market-place/package?slug=x&return=https://evil.example',"#/app/lesson-market-place/package?slug=x\n"] as $route)rejects(function()use($route){Rules::returnRoute($route);},'external and malformed returns');
check(Rules::returnRoute('#/app/lesson-market-place/package?slug=math-1')==='#/app/lesson-market-place/package?slug=math-1','internal return accepted');
$safe=Rules::richText('<p onclick="alert(1)"><strong>Welcome</strong><img src=x onerror=alert(1)><script>alert(1)</script><a href="javascript:alert(1)">link</a><a href="https://example.com">safe</a></p>');
check(strpos($safe,'<strong>Welcome</strong>')!==false,'formatting retained');
foreach(['onclick','onerror','<script','javascript:','<img'] as $bad)check(stripos($safe,$bad)===false,'unsafe HTML stripped');
$lessons=[(object)['id'=>1,'lesson_order'=>1,'status'=>'published','progression_enabled'=>'true'],(object)['id'=>2,'lesson_order'=>2,'status'=>'published','progression_enabled'=>'true'],(object)['id'=>3,'lesson_order'=>3,'status'=>'published','progression_enabled'=>'false']];
check(Rules::prerequisites([3],$lessons)===[2],'preceding lesson required');
check(Rules::prerequisites([2,3],$lessons)===[1],'prerequisite chain required');
check(Rules::prerequisites([3,1,2],$lessons)===[],'display order does not affect subject order');
$order=(object)['id'=>9,'profile_id'=>7,'order_reference'=>'order9','price_minor_snapshot'=>500,'currency_snapshot'=>'USD'];
$session=(object)['id'=>'cs_test_fixture','mode'=>'payment','client_reference_id'=>'order9','amount_total'=>500,'currency'=>'usd','status'=>'complete','payment_status'=>'paid','metadata'=>(object)['credit_order_id'=>'9','profile_id'=>'7','tenant'=>'isolated']];
check(\davvag_stripe\CreditCheckoutAdapter::verify($session,$order,'cs_test_fixture','isolated'),'settled matching provider fixture accepted');
foreach(['amount_total'=>501,'currency'=>'eur','client_reference_id'=>'other','id'=>'cs_test_other','mode'=>'subscription'] as $field=>$value){$bad=clone $session;$bad->$field=$value;rejects(function()use($bad,$order){\davvag_stripe\CreditCheckoutAdapter::verify($bad,$order,'cs_test_fixture','isolated');},'provider mismatch rejected');}
rejects(function()use($session,$order){\davvag_stripe\CreditCheckoutAdapter::verify($session,$order,'cs_test_fixture','another-tenant');},'provider tenant mismatch rejected');
foreach([['open','unpaid'],['complete','unpaid'],['expired','unpaid']] as $state){$pending=clone $session;$pending->status=$state[0];$pending->payment_status=$state[1];check(!\davvag_stripe\CreditCheckoutAdapter::verify($pending,$order,'cs_test_fixture','isolated'),'unsettled provider fixture grants nothing');}
$service=file_get_contents(dirname(__DIR__).'/services/marketplace-api/service.php');
check(strpos($service,'new MarketplaceData()')!==false,'marketplace API initializes the SOSSData persistence boundary');
check(strpos($service,'MarketplaceSchema')===false,'marketplace API does not load or call the database schema migrator');
check(strpos($service,'WithServiceNamespaces')===false,'marketplace API relies on framework service access management');
foreach(['CreditDatabase','$this->db','->transaction(','SELECT ','INSERT ','UPDATE ','DELETE '] as $direct) check(strpos($service,$direct)===false,'marketplace API contains no direct database access: '.$direct);
$dataLayer=file_get_contents(dirname(__DIR__).'/lib/MarketplaceData.php');
foreach(['\\SOSSData::Query','\\SOSSData::Insert','\\SOSSData::Update'] as $facade) check(strpos($dataLayer,$facade)!==false,'marketplace data layer uses '.$facade);
check(strpos($dataLayer,'WithServiceNamespaces')===false,'marketplace data layer relies on framework service access management');
$descriptor=json_decode(file_get_contents(dirname(__DIR__).'/services/marketplace-api/component.json'));
check(in_array('lmp_package',$descriptor->serviceHandler->serviceNamespaces ?? [],true),'marketplace descriptor declares protected service namespaces');
echo "Marketplace business/provider rules: $checks checks passed.\n";
