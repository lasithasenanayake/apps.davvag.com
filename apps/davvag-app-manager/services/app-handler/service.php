<?php

class BroadcastService {

    public function getApplicationLaunchers($req,$res){
        $app=$_GET["app"];
        $sub=$_GET["subapp"];
        $r=SOSSData::Query("davvag_launchers","bid:".(isset($data->bid)?$data->bid:0));
        
    }

    public function postSaveLauncherUserPerm($req,$res){
        $data=$req->Body(true);
        $alreadySaved= SOSSData::Query("davvag_launchers_perm","bid:".(isset($data->bid)?$data->bid:0));
        if(count($alreadySaved->result)>0){
            SOSSData::Delete("davvag_launchers_perm",$alreadySaved->result);
        }
        foreach ($data->UserGroups as $key => $value) {
            # code...
            if((isset($value->selected)?$value->selected:"N")=="Y"){
                $item=new stdClass();
                $item->bid=$data->bid;
                $item->groupid=$value->groupid;
                SOSSData::Insert("davvag_launchers_perm",$item);

            }
        }
        CacheData::clearObjects("davvag_launchers_perm");
        CacheData::clearObjects("davvag_launchers");

        return $data;

    }

    public function getUserGroupsByLauncher($req,$res){
        $pid=$_GET["p_appid"];
        if($pid==0){
            //$data=SOSSData::Query("");
            return Auth::getUserGroups();
        }else{
            $alreadySaved= SOSSData::Query("davvag_launchers_perm","bid:".$pid);
            return $alreadySaved->result;
        }
    }

    public function getUserGroupsLancherAccess($req,$res){
        $pid=$_GET["appid"];
        $alreadySaved= SOSSData::Query("davvag_launchers_perm","bid:".$pid);
        return $alreadySaved->result;
    }
    
    public function postSaveLauncher($req,$res){
        $data=$req->Body(true);
            $r=SOSSData::Query("davvag_launchers","bid:".(isset($data->bid)?$data->bid:0));
            
            if($r->success && count($r->result)>0){
                $data->result= SOSSData::Update("davvag_launchers",$data);
            }else{
                $data->result= SOSSData::Insert("davvag_launchers",$data);
                $data->bid=isset($data->result->result->generatedId)?$data->result->result->generatedId:0;
            }
            CacheData::clearObjects("davvag_launchers_perm");
            CacheData::clearObjects("davvag_launchers");
            if($data->result->success){
                return $data;
            }else{
                $res->SetError($data->result);
                return null;
            }
    }

    public function getLauncherParentApp($req,$res){
        $bid =$_GET["bid"];
        $r=SOSSData::Query("davvag_launchers","bid:".$bid);
        if($r->success && count($r->result)>0){
            $pid=isset($r->result[0]->pid)?$r->result[0]->pid:0;
            $data=$r->result[0];
            //$subappcode=$r->result[0]->subappcode;
            while($pid!=0){
                $r2=SOSSData::Query("davvag_launchers","bid:".$pid);
                if($r2->success && count($r2->result)>0){
                    $pid=isset($r2->result[0]->pid)?(int)$r2->result[0]->pid:0;
                    $data=$r2->result[0];
                }else{
                    $res->SetError($r2);
                    $pid=0;
                    return 0;
                }
            }
            return $data;
        }else{
            //$res->SetError($r);
            $pid=0;
            return [];
        }
    }

    public function getApps($req,$res){
        $tenantFile = TENANT_RESOURCE_LOCATION . "/tenant.json";
        $fileToServe;$errorMsg;
        
        $apps=array();
        if (file_exists($tenantFile)){
            $jsonContents = file_get_contents($tenantFile);
            $tenantObj = json_decode($jsonContents);
            if (isset($tenantObj)){
                foreach ($tenantObj->apps as $appCode => $appData) {
                    
                    
                    $appLocation = TENANT_RESOURCE_LOCATION_APPS . "/$appCode/app.json" ;
                    if (file_exists($appLocation)){
                        $jsonObj = json_decode(file_get_contents($appLocation));
                        //return $tenantObj->apps;
                        $app=new stdClass();
                        $app->appCode=$appCode;
                        $app->Name=$jsonObj->description->title;
                        $app->Icon=$jsonObj->description->icon;
                        $app->tags=isset($jsonObj->tags)?$jsonObj->tags:[];
                        $app->Services=array();
                        $app->Apps=array();
                        $app->Schemas=array();
                        $app->UnknownApps=array();
                        foreach ($jsonObj->components as $Code => $Data){
                            $a=new stdClass();
                            $a->Code=$Code;
                            $aLocation = TENANT_RESOURCE_LOCATION_APPS . "/$appCode/$Data->location/$Code/component.json";
                            if (file_exists($aLocation)){
                                $aObj = json_decode(file_get_contents($aLocation));
                                
                                $a->Name=$aObj->name;
                                $a->Description=$aObj->description;
                                $a->author=$aObj->author;
                                $a->version=$aObj->version;
                                $a->outputData=isset($aObj->outputData)?$aObj->outputData:null;
                                $a->inputData=isset($aObj->inputData)?$aObj->inputData:null;
                                if(isset($jsonObj->configuration->webdock->routes->partials)){
                                    foreach($jsonObj->configuration->webdock->routes->partials as $pk=>$p){
                                        if($p==$a->Code){$a->path=$pk;}
                                    }
                                }
                                //if(!isset($a->path)){
                                    switch($Data->type){
                                        case "partial":
                                            array_push($app->Apps,$a);
                                        break;
                                        case "component":
                                            array_push($app->Apps,$a);
                                        break;
                                        
                                        default:
                                            array_push($app->UnknownApps,$a);
                                        break;
                                    }
                                //}
                            }else{
                                $app->Error= "This Location '$aLocation' not found.";
                            }
                        }
                        array_push($apps,$app);
                    }
                    
                }
            }
        }
        return $apps;

    }

    public function getallApplications($req,$res){
        
        $tenantFile = TENANT_RESOURCE_LOCATION . "/tenant.json";
        $fileToServe;$errorMsg;
        $apps=array();
        //var_dump($req->Params());
        if(!isset($_GET["Group"])){
            $res->SetError("Not a Valied Request");
            return null;
        }
        $Group=$_GET["Group"];
        $allkeys=CacheData::getObjects($Group,"domain_permision_e");
        if($allkeys)
            return $allkeys;
        
        if (file_exists($tenantFile)){
            $jsonContents = file_get_contents($tenantFile);
            $tenantObj = json_decode($jsonContents);
            //return $tenantObj;
            if (isset($tenantObj)){
                foreach ($tenantObj->apps as $appCode => $appData) {
                    
                    
                    $appLocation = TENANT_RESOURCE_LOCATION_APPS . "/$appCode/app.json" ;
                    if (file_exists($appLocation)){
                        $jsonObj = json_decode(file_get_contents($appLocation));
                        //return $tenantObj->apps;
                        $app=new stdClass();
                        $app->appCode=$appCode;
                        $app->Name=$jsonObj->description->title;
                        $app->Icon=$jsonObj->description->icon;
                        $app->Services=array();
                        $app->Apps=array();
                        $app->Schemas=array();
                        if(isset($jsonObj->schemas)){
                            foreach($jsonObj->schemas as $Code => $Data){
                                $a=new stdClass();
                                $a->Name =$Code;
                                $a->FileName=$Data;
                                $a->selected=$this->Permistion($Group,$app->appCode,"schema",$Code,"");
                                array_push($app->Schemas,$a);
                            }
                        }
                        foreach ($jsonObj->components as $Code => $Data){
                            $a=new stdClass();
                            $a->Code=$Code;
                            $aLocation = TENANT_RESOURCE_LOCATION_APPS . "/$appCode/$Data->location/$Code/component.json";
                            if (file_exists($aLocation)){
                                $aObj = json_decode(file_get_contents($aLocation));
                                
                                $a->Name=$aObj->name;
                                $a->Description=$aObj->description;
                                $a->author=$aObj->author;
                                $a->version=$aObj->version;
                                switch($Data->type){
                                    case "partial":
                                        $a->selected=$this->Permistion($Group,$app->appCode,"app",$Code,"");
                                        array_push($app->Apps,$a);
                                    break;
                                    case "shell":
                                        if(isset($aObj->serviceHandler->methods)){
                                            $a->methods=array();
                                                
                                                foreach ($aObj->serviceHandler->methods as $m=>$md){
                                                    $method=new stdClass();
                                                    $method->name=$m;
                                                    $method->otherdata=$md;
                                                    if(isset($md->route)){
                                                       $x= explode("/",$md->route);
                                                       foreach($x as $xm){
                                                            
                                                            if($xm!="" && is_bool(strpos($xm,"@"))){
                                                                $method->name=$xm;
                                                            }
                                                       }
                                                       
                                                    }
                                                    $method->selected=$this->Permistion($Group,$app->appCode,"service",$Code,$m);
                                                    array_push($a->methods,$method);
    
                                                }
                                            }else{
                                                $a->methods=array();
                                                $method=new stdClass();
                                                $method->name="all";
                                                $method->selected=$this->Permistion($Group,$app->appCode,"service",$Code,"all");
                                                array_push($a->methods,$method);
                                            }
                                            array_push($app->Services,$a);
                                    break;
                                    case "service":
                                        if(isset($aObj->serviceHandler->methods)){
                                        $a->methods=array();
                                            
                                            foreach ($aObj->serviceHandler->methods as $m=>$md){
                                                $method=new stdClass();
                                                $method->name=$m;
                                                $method->otherdata=$md;
                                                $method->selected=$this->Permistion($Group,$app->appCode,"service",$Code,$m);
                                                array_push($a->methods,$method);

                                            }
                                        }else{
                                            $a->methods=array();
                                            $method=new stdClass();
                                            $method->name="all";
                                            $method->selected=$this->Permistion($Group,$app->appCode,"service",$Code,"all");
                                            array_push($a->methods,$method);
                                        }
                                        array_push($app->Services,$a);
                                    break;
                                    case "component":
                                        $a->selected=$this->Permistion($Group,$app->appCode,"app",$Code,"");
                                        array_push($app->Apps,$a);
                                    break;
                                }
                            }else{
                                $app->Error= "This Location '$aLocation' not found.";
                            }
                        }
                        array_push($apps,$app);
                    }
                    
                }
            }
        }
        CacheData::setObjects($Group,"domain_permision_e",$apps);
        return $apps;
    }

    private function Permistion($Group,$App,$Type,$Code,$Method){
        $obj=Auth::GetAccess($Group,$App,$Type,$Code,$Method);
        ///var_dump($obj);
        if(isset($obj->keyid)){
            return true;
        }else{
            return false;
        }
    }

    public function getUserGroups($req,$res){
        return Auth::GetUserGroups();
    }
    
    public function postSetAccess($req,$res){
        $bodyAccess= $req->Body(true);
        $assdata=array();
        $groupid=$bodyAccess->groupid;
        $descObj =null;
        $descriptorLocation = TENANT_RESOURCE_LOCATION . "/tenant.json" ;
        if (file_exists($descriptorLocation)){
            $jsonFile = file_get_contents($descriptorLocation);
            $descObj = json_decode($jsonFile);
        }else{
            $res->SetError("Not Configured tenant.json missing");
            return null;
        }      
        $tenatjson=new stdClass();
        $tenatjson->apps=new stdClass();
        foreach($bodyAccess->data as $item){
            $appcode=$item->appCode;
            foreach($item->Services as $sitem){
                $code=$sitem->Code;
                $type="service";
                ///var_dump($sitem->methods);
                if(isset($sitem->methods)){
                    foreach($sitem->methods as $ops){
                        if($ops->selected){
                            if(!isset($tenatjson->{$appcode})){
                                $tenatjson->apps->{$appcode}=$descObj->apps->{$appcode};
                            }
                            array_push($assdata,array("groupid"=>$groupid,"appCode"=>$appcode,"type"=>$type,"code"=>$code,"operation"=>$ops->name));
                        }
                    }
                }
            }

            foreach($item->Apps as $sitem){
                $code=$sitem->Code;
                $type="app";
                if($sitem->selected){
                    if(!isset($tenatjson->{$appcode})){
                        $tenatjson->apps->{$appcode}=$descObj->apps->{$appcode};
                    }
                    array_push($assdata,array("groupid"=>$groupid,"appCode"=>$appcode,"type"=>$type,"code"=>$code,"operation"=>""));
                }
            }

            foreach($item->Schemas as $sitem){
                $code=$sitem->Name;
                $type="schema";
                if($sitem->selected){
                    if(!isset($tenatjson->{$appcode})){
                        $tenatjson->apps->{$appcode}=$descObj->apps->{$appcode};
                    }
                    array_push($assdata,array("groupid"=>$groupid,"appCode"=>$appcode,"type"=>$type,"code"=>$code,"operation"=>""));
                }
            }
        }
        CacheData::clearObjects("domain_permision_e");
        CacheData::clearObjects("sys_access");
        $tenatjson->webdock=$descObj->webdock;
        file_put_contents(TENANT_RESOURCE_LOCATION ."/$groupid.json",json_encode($tenatjson));
        
        //Auth::SetAccess($assdata);
        return  Auth::SetAccess($assdata);

    }


    private function changeGroup($userid,$groupid){
        $r = SOSSData::Query ("domain_permision", "userid:$userid");
        //var_dump($r);
        if(count($r->result)>0){
            $save=$r->result[0];
            $save->groupid=$groupid;
            $result = SOSSData::Update("domain_permision", $save);
            //echo json_encode($result);
            return $save;
        }else{
           return null;
        }

    }


    public function postDeleteItem($req,$res){
        $body=$req->Body(true);
        $rd=SOSSData::Delete("schedule_pending", $body);
        if($rd->success){
            return $rd->result;
        }else{
            $res->SetError ($rd->result);
            return $rd->result; 
        }
    }


}

?>