<?php
$_SERVER["HTTP_HOST"]="localhost";
$_SERVER["SERVER_PROTOCOL"]="HTTP/1.1";
$_SERVER["REQUEST_URI"]="/";
$bootstrap=getcwd().DIRECTORY_SEPARATOR."configloader.php";
if(!file_exists($bootstrap))throw new \RuntimeException("Run this test from the DAVVAG framework root.");
require $bootstrap;
require_once dirname(__DIR__)."/lib/CreditLedgerService.php";
$ledger=new \davvag_credit_points\CreditLedgerService();
$program=$ledger->program();
if(!$program||$program->code!=="CREDIT")throw new \RuntimeException("Default credit program was not initialized.");
$rows=$ledger->database()->all("SELECT wallet_type FROM davvag_credit_wallet WHERE program_id=? AND owner_profile_id=0","i",array(intval($program->id)));
if(count($rows)!==7)throw new \RuntimeException("System wallets were not initialized.");
echo"Credit datastore bootstrap passed with ".count($rows)." system wallets.".PHP_EOL;
?>
