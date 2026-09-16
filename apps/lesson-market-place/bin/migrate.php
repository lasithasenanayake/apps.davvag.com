<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
$host=$argv[1] ?? 'localhost';
$framework=$argv[2] ?? getenv('DAVVAG_FRAMEWORK_ROOT');
if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host) || !$framework || !is_file(rtrim($framework,'/\\').'/configloader.php')) {
    fwrite(STDERR,"Usage: php migrate.php <tenant-host> <framework-root>\n"); exit(2);
}
$_SERVER['HTTP_HOST']=$host;$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REQUEST_URI']='/';
require rtrim($framework,'/\\').'/configloader.php';
require_once TENANT_RESOURCE_LOCATION.'/apps/davvag-credit-points/lib/CreditLedgerService.php';
require_once dirname(__DIR__).'/lib/MarketplaceSchema.php';
try {
    $ledger=new \davvag_credit_points\CreditLedgerService();
    \SOSSData::WithServiceNamespaces(\lesson_market_place\MarketplaceSchema::namespaces(),function()use($ledger){
        \lesson_market_place\MarketplaceSchema::ensure($ledger->database());
    });
    $permissions=json_decode(file_get_contents(dirname(__DIR__).'/permissions.json'),true);
    if(!is_array($permissions))throw new RuntimeException('Marketplace permission manifest is invalid.');
    foreach($permissions as $group=>$operations)foreach($operations as $operation) {
        $query='domain:'.AUTH_DOMAIN.',groupid:'.$group.',appCode:lesson-market-place,type:service,code:marketplace-api,operation:'.$operation;
        $found=\SOSSData::Query('usergroup_permission',$query,null,'asc',1,0,AUTH_DOMAIN,false);
        if(!$found || !$found->success)throw new RuntimeException('Marketplace permissions could not be inspected.');
        if($found->result)continue;
        $permission=(object)['keyid'=>md5($group.'-'.AUTH_DOMAIN.'-lesson-market-place--service-marketplace-api-'.$operation),'groupid'=>$group,'domain'=>AUTH_DOMAIN,'appCode'=>'lesson-market-place','type'=>'service','code'=>'marketplace-api','description'=>'Lesson Marketplace service access','uri'=>'','operation'=>$operation];
        $saved=\SOSSData::Insert('usergroup_permission',$permission,AUTH_DOMAIN);
        if(!$saved || !$saved->success)throw new RuntimeException('Marketplace permission installation failed for '.$group.'/'.$operation.'.');
    }
    fwrite(STDOUT,"Lesson Marketplace migrations are current for ".DATASTORE_DOMAIN.".\n");
} catch(Throwable $error) {
    fwrite(STDERR,"Migration failed safely: ".$error->getMessage()."\n"); exit(1);
}
