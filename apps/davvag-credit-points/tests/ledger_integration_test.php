<?php
$_SERVER["HTTP_HOST"]="localhost";$_SERVER["SERVER_PROTOCOL"]="HTTP/1.1";$_SERVER["REQUEST_URI"]="/";
define("DATASTORE_DOMAIN","credit_points_test_20260728");
$bootstrap=getcwd().DIRECTORY_SEPARATOR."configloader.php";if(!file_exists($bootstrap))throw new \RuntimeException("Run this test from the DAVVAG framework root.");require$bootstrap;
$GLOBALS["ENGINE_CONFIG"]->DAVVAG_DATA->{DATASTORE_DOMAIN}=json_decode(json_encode($GLOBALS["ENGINE_CONFIG"]->DAVVAG_DATA->localhost));
require_once dirname(__DIR__)."/lib/CreditLedgerService.php";
require_once dirname(__DIR__)."/lib/CreditPaymentService.php";
use davvag_credit_points\CreditLedgerService;
$config=json_decode(file_get_contents(DB_CONFIG_FILE));$database=$config->init_db.str_replace(".","_",DATASTORE_DOMAIN);
if(strpos($database,"s_credit_points_test_")!==0)throw new \RuntimeException("Unsafe integration-test database name.");
$admin=new mysqli($config->mysql_server,$config->mysql_username,$config->mysql_password);
try{
 $ledger=new CreditLedgerService();$profile=1;
 $credit=$ledger->credit($profile,100,array("sourceApp"=>"credit-test","referenceType"=>"test","referenceId"=>"credit","idempotencyKey"=>"test-credit-1","description"=>"Test grant"));
 $replay=$ledger->credit($profile,100,array("sourceApp"=>"credit-test","referenceType"=>"test","referenceId"=>"credit","idempotencyKey"=>"test-credit-1","description"=>"Test grant"));
 if(!$replay->idempotentReplay)throw new \RuntimeException("Credit idempotency replay failed.");
 $reservation=$ledger->reserve($profile,30,array("sourceApp"=>"credit-test","referenceType"=>"test","referenceId"=>"reserve","idempotencyKey"=>"test-reserve-1","description"=>"Test reserve"));
 $ledger->releaseReservation($profile,$reservation->reservationId,"test-release-1");
 $spend=$ledger->debit($profile,25,array("sourceApp"=>"credit-test","referenceType"=>"test","referenceId"=>"spend","idempotencyKey"=>"test-debit-1","description"=>"Test spend"));$reversal=$ledger->reverse($spend->transactionId,array("sourceApp"=>"credit-test","idempotencyKey"=>"test-reversal-1","description"=>"Reverse test spend"));try{$ledger->reverse($spend->transactionId,array("sourceApp"=>"credit-test","idempotencyKey"=>"test-reversal-2","description"=>"Duplicate reversal"));throw new \RuntimeException("Duplicate reversal was accepted.");}catch(\davvag_credit_points\CreditException$expected){}
 $unlock=$ledger->unlockLesson($profile,99,10);$unlockReplay=$ledger->unlockLesson($profile,99,10);
 if(!$unlockReplay->alreadyUnlocked)throw new \RuntimeException("Lesson unlock idempotency failed.");
 $program=$ledger->program();$packageId=$ledger->database()->insert("davvag_credit_package",array("program_id"=>intval($program->id),"package_code"=>"TEST25","title"=>"Test package","description"=>"Integration test","credit_amount"=>20,"bonus_credit_amount"=>5,"price_minor"=>100,"currency"=>"LKR","payment_channel"=>"TEST","provider_product_id"=>"test","purchase_limit_per_profile"=>1,"first_purchase_only"=>"false","active_from"=>null,"active_until"=>null,"sort_order"=>1,"status"=>"ACTIVE"));
 $payment=new \davvag_credit_points\CreditPaymentService($ledger);$order=$payment->createOrder($profile,$packageId,"test-order-1");$credited=$payment->completeVerified("TEST","event-1",$order->order_reference,"provider-1",hash("sha256","test"));if($credited->order_status!=="CREDITED")throw new \RuntimeException("Verified purchase was not credited.");$paymentReplay=$payment->completeVerified("TEST","event-1",$order->order_reference,"provider-1",hash("sha256","test"));if(intval($paymentReplay->id)!==intval($order->id))throw new \RuntimeException("Payment event idempotency failed.");
 $ledger->database()->insert("currency_configuration",array("code"=>"USD","numericCode"=>"840","name"=>"Inactive test currency","symbol"=>"$","decimalPlaces"=>2,"exchangeRate"=>1,"isBase"=>"N","status"=>"inactive","sortOrder"=>2));$inactivePackage=$ledger->database()->insert("davvag_credit_package",array("program_id"=>intval($program->id),"package_code"=>"INACTIVEUSD","title"=>"Inactive currency package","description"=>"Must remain unavailable","credit_amount"=>10,"bonus_credit_amount"=>0,"price_minor"=>100,"currency"=>"USD","payment_channel"=>"TEST","provider_product_id"=>"inactive","purchase_limit_per_profile"=>0,"first_purchase_only"=>"false","active_from"=>null,"active_until"=>null,"sort_order"=>2,"status"=>"ACTIVE"));foreach($payment->packages()as$listed)if(intval($listed->id)===intval($inactivePackage))throw new \RuntimeException("Inactive currency package was listed.");try{$payment->createOrder($profile,$inactivePackage,"test-inactive-order");throw new \RuntimeException("Inactive currency purchase was accepted.");}catch(\davvag_credit_points\CreditException$expected){}
 $ledger->credit($profile,10,array("sourceApp"=>"credit-test","referenceType"=>"test","referenceId"=>"expired-promo","idempotencyKey"=>"test-promo-1","description"=>"Expired test promotion"),"DAILY_REWARD",date("Y-m-d H:i:s",time()-60));$expired=$ledger->expireDueLots();if($expired->processed!==1||$expired->lots[0]->amount!==10)throw new \RuntimeException("Promotional expiration failed.");
 $balance=$ledger->summary($profile);if($balance->availableBalance!==115||$balance->postedBalance!==115||$balance->reservedBalance!==0)throw new \RuntimeException("Final wallet balance is incorrect.");
 $check=$ledger->reconcile($balance->walletId);if(!$check->balanced)throw new \RuntimeException("Wallet reconciliation failed.");
 foreach(array($credit->transactionId,$unlock->transactionId)as$id){$tx=$ledger->transactionById($id);$sum=0;foreach($tx->entries as$entry)$sum+=$entry->direction==="CREDIT"?intval($entry->amount):-intval($entry->amount);if($sum!==0)throw new \RuntimeException("Transaction ".$id." is not balanced.");}
 echo"Ledger integration passed: atomic balance, reservation, idempotency, reversal, lesson unlock, configured-currency enforcement, purchase crediting, expiration, and double-entry reconciliation.".PHP_EOL;
}finally{$admin->query("DROP DATABASE IF EXISTS `".$database."`");$admin->close();}
?>
