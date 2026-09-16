<?php
require __DIR__.'/integration_bootstrap.php';
try {
    $result=scoped(function()use($argv){$ledger=new \davvag_credit_points\CreditLedgerService();$catalog=new \lesson_market_place\MarketplaceCatalog(1,true);$engine=new \lesson_market_place\MarketplaceEnrolment($ledger->database(),$catalog,$ledger);if(($argv[1] ?? '')==='confirm')return $engine->confirm(1,(int)$argv[2],(int)$argv[3]);return $engine->decide(99,(int)$argv[2],$argv[3],'review','',function(){});});
    echo $result->status;exit(0);
} catch(Throwable $error){echo get_class($error);exit(2);}
