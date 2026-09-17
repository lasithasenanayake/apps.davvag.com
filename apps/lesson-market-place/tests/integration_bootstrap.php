<?php
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI tests only.');
$framework=getenv('DAVVAG_FRAMEWORK_ROOT')?:getcwd();
if(!is_file($framework.'/config.json'))throw new RuntimeException('Run from the framework root or set DAVVAG_FRAMEWORK_ROOT.');
$config=json_decode(file_get_contents($framework.'/config.json'));
$domain=getenv('DAVVAG_LMP_TEST_DOMAIN');
if(!$domain || !preg_match('/^lmp_test_[a-f0-9]{16}$/D',$domain))throw new RuntimeException('An isolated random test domain is required.');
$tenant=dirname(__DIR__,3);
define('TENANT_RESOURCE_LOCATION',$tenant);define('SCHEMA_PATH',$tenant.'/schemas');define('PLUGIN_PATH',$framework.'/plugins');define('DATASTORE_DOMAIN',$domain);define('DB_CONFIG_FILE',$config->variables->DB_CONFIG_FILE);define('CURRENCY_CODE','LKR');define('MEDIA_FOLDER',$framework.'/.tmp/lmp-test-media');$_SERVER['HTTP_HOST']='localhost';
$GLOBALS['ENGINE_CONFIG']=$config;$GLOBALS['ENGINE_CONFIG']->DAVVAG_DATA->$domain=(object)['connector'=>'phpmysql'];
class Auth { public static $role='sysadmin';public static function GetAccess($group,$app,$type,$component,$operation){return (object)[];} public static function ViewObjects(){return [0];} public static function Autendicate(){return (object)['email'=>'test@example.invalid','userid'=>'isolated','group'=>self::$role];} }
class Profile { public static $id=1; public static function getUserProfile(){return (object)['id'=>self::$id,'name'=>'Fixture learner','email'=>'test@example.invalid'];} }
require_once $tenant.'/apps/davvag-credit-points/lib/CreditLedgerService.php';
require_once dirname(__DIR__).'/lib/MarketplaceSchema.php';
require_once dirname(__DIR__).'/lib/MarketplaceCatalog.php';
require_once dirname(__DIR__).'/lib/MarketplaceEnrolment.php';
function scoped($fn){$descriptor=json_decode(file_get_contents(dirname(__DIR__).'/services/marketplace-api/component.json'));return SOSSData::WithServiceNamespaces($descriptor->serviceHandler->serviceNamespaces,$fn);}
