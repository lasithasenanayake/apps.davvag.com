<?php
// Each descriptor must load only its own HTTP handler, with shared helpers in lib.
if(PHP_SAPI!=='cli')exit;
$app=dirname(__DIR__);$descriptors=[];$handlers=[];$checks=0;
foreach(glob($app.'/services/*/component.json') as $file){$desc=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$descriptors[]=[$file,$desc];$handlers[]=$desc['serviceHandler']['class'];}
function checkLoading($condition,$label){global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function isolatedLoad($file,$handlers){
    $code='require $argv[1]; $handlers=json_decode($argv[2],true);$loaded=array_values(array_filter($handlers,function($class){return class_exists($class,false);}));$files=[];foreach($loaded as $class)$files[$class]=(new ReflectionClass($class))->getFileName();echo json_encode(["loaded"=>$loaded,"files"=>$files]);';
    $pipes=[];$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-r',$code,$file,json_encode($handlers)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Could not start an isolated service loader.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);if($status!==0)throw new RuntimeException('Service load failed: '.$error);return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
$loaded=isolatedLoad($app.'/lib/bootstrap.php',$handlers);checkLoading($loaded['loaded']===[],'library bootstrap loads no HTTP handlers');
foreach($descriptors as [$file,$desc]){$class=$desc['serviceHandler']['class'];$implementation=dirname($file).'/'.$desc['serviceHandler']['file'];$result=isolatedLoad($implementation,$handlers);checkLoading($result['loaded']===[$class],basename(dirname($file)).' loads only its own handler');checkLoading(realpath($result['files'][$class])===realpath($implementation),$class.' is implemented in its descriptor service file');}
checkLoading(!file_exists($app.'/lib/Services.php'),'monolithic library service file is removed');
echo $checks.' service organization checks passed.'.PHP_EOL;
