<?php
if(PHP_SAPI!=='cli')exit;
$testDomain=getenv('DAVVAG_EVENTS_TEST_DOMAIN')?:'davvag_events_test_'.bin2hex(random_bytes(6));
if(!preg_match('/^davvag_events_test_[a-f0-9]{12}$/D',$testDomain))throw new RuntimeException('Invalid isolated test domain.');
putenv('DAVVAG_EVENTS_TEST_DOMAIN='.$testDomain);define('DATASTORE_DOMAIN',$testDomain);
require dirname(__DIR__).'/bin/bootstrap.php';
$GLOBALS['ENGINE_CONFIG']->DAVVAG_DATA->{$testDomain}=(object)['connector'=>'phpmysql','phpmysql'=>new stdClass()];
define('DAVVAG_EVENTS_TESTING',true);
function testConnection($select=true){$c=json_decode(file_get_contents(DB_CONFIG_FILE));$name=$c->init_db.DATASTORE_DOMAIN;if(!preg_match('/^[A-Za-z0-9_]+davvag_events_test_[a-f0-9]{12}$/D',$name)&&!preg_match('/^davvag_events_test_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Unsafe test database.');$db=new mysqli($c->mysql_server,$c->mysql_username,$c->mysql_password,$select?$name:null);$db->set_charset('utf8mb4');return [$db,$name];}
function fixture($occasion='wedding',$owner='101'){
    return \davvag_events\Domain::create(['title'=>'Fictional '.ucfirst($occasion).' Gathering','occasion'=>$occasion,'timezone'=>'Europe/London','start'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'end'=>gmdate('Y-m-d\TH:i:s\Z',time()+21600),'venue'=>'The Example Conservatory','capacity'=>30,'privacy'=>'public'],$owner);
}
