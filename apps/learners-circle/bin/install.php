<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
$host=$argv[1]??'localhost';$framework=$argv[2]??getenv('DAVVAG_FRAMEWORK_ROOT');
if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host)||!$framework||!is_file(rtrim($framework,'/\\').'/configloader.php')){
    fwrite(STDERR,"Usage: php install.php <tenant-host> <framework-root>\n");exit(2);
}
$_SERVER['HTTP_HOST']=$host;$_SERVER['SERVER_PROTOCOL']='HTTP/1.1';$_SERVER['REQUEST_URI']='/';
require rtrim($framework,'/\\').'/configloader.php';
try{
    $manifest=json_decode(file_get_contents(dirname(__DIR__).'/permissions.json'),true,512,JSON_THROW_ON_ERROR);
    $groups=['web_user','sysuser','sysadmin','admin','staff','teacher','facebook_user','seller'];$installed=0;
    foreach($groups as $group){
        $path=TENANT_RESOURCE_LOCATION.'/'.$group.'.json';
        if(!is_file($path))continue;
        $config=json_decode(file_get_contents($path));
        if(!isset($config->apps->{'learners-circle'}))continue;
        $permissions=$manifest;
        $permissions['learners-circle']=['workspace'=>['View']];
        foreach($permissions as $app=>$components)foreach($components as $component=>$operations)foreach($operations as $operation){
            $type=$app==='learners-circle'?'component':'service';
            $conditions=[];
            foreach(['domain'=>AUTH_DOMAIN,'groupid'=>$group,'appCode'=>$app,'type'=>$type,'code'=>$component,'operation'=>$operation] as $field=>$value)$conditions[]=['column'=>$field,'operator'=>'=','value'=>$value];
            $query=['conditions'=>$conditions,'pageSize'=>1,'pageFrom'=>0];
            $found=SOSSData::Query('usergroup_permission',$query,null,'ASC',1,0,AUTH_DOMAIN,false);
            if(!$found||!$found->success)throw new RuntimeException('Learner permissions could not be inspected.');
            if($found->result)continue;
            $permission=(object)['keyid'=>md5($group.'-'.AUTH_DOMAIN.'-'.$app.'--'.$type.'-'.$component.'-'.$operation),'groupid'=>$group,'domain'=>AUTH_DOMAIN,'appCode'=>$app,'type'=>$type,'code'=>$component,'description'=>'Learners Circle learner access','uri'=>'','operation'=>$operation];
            $saved=SOSSData::Insert('usergroup_permission',$permission,AUTH_DOMAIN);
            if(!$saved||!$saved->success)throw new RuntimeException('Learner permissions could not be installed for '.$group.'.');
            $installed++;
        }
    }
    fwrite(STDOUT,"Learners Circle permissions installed: $installed new entries for ".AUTH_DOMAIN.".\n");
}catch(Throwable $error){fwrite(STDERR,"Learners Circle installation failed: ".$error->getMessage()."\n");exit(1);}
