<?php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=getenv('DAVVAG_EVENTS_FRAMEWORK_ROOT')?:getcwd();
while(!is_file($root.'/configloader.php')||!is_dir($root.'/components')){$parent=dirname($root);if($parent===$root)throw new RuntimeException('Set DAVVAG_EVENTS_FRAMEWORK_ROOT to the DAVVAG engine checkout.');$root=$parent;}
$_SERVER['HTTP_HOST']=getenv('DAVVAG_EVENTS_HOST')?:'localhost';
$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['SCRIPT_NAME']='/davvag-core/components/index.php';$_SERVER['DOCUMENT_ROOT']=dirname($root);
require_once $root.'/configloader.php';
require_once PLUGIN_PATH.'/sossdata/SOSSData.php';
require_once PLUGIN_PATH.'/auth/auth.php';
require_once PLUGIN_PATH.'/phpcache/cache.php';
require_once dirname(__DIR__).'/lib/bootstrap.php';
$_SESSION['viewObjects']=[0];$_SESSION['viewObjects_e']=[0];$_SESSION['viewObjects_f']=[0];
function eventsNamespaces() { return ['davvag_events_events','davvag_events_commit','davvag_events_keys','davvag_events_limits','davvag_events_rate','davvag_events_drive_connections','davvag_events_connection_commit']; }
