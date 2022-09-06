<?php
class FReq {

	public function Params(){return $this->p;}
	public function Query(){return $this->qP;}
	public function Headers(){return $this->hP;}
	public function Body($json=false){ 
		return $this->o;
	}
	public function GetContentType(){return "";}

	function __construct($params, $m, $p,$qP=array(),$o=null){
		$this->method = $m;
		$this->template = $p;
		$this->p = $params;
		$this->qP = $this->aTo($qP);
        foreach ($qP as $k => $v) {
            $_GET[$k]=$v;
        };
		$this->hP = $this->aTo(getallheaders());
        $this->o=$o;
	}

	function aTo($a){
		$o = new stdClass();
		foreach ($a as $k => $v) {
            $o->$k = $v;
            //$_GET[$k]=$v;
        };
		return $o;
	}
}

class FRes {
	public function Get(){if (isset($this->o)) return $this->o;}
	public function Set($o){$this->o = $o;}
	public function SetContentType($c){$this->ct = $c;}
	public function GetContentType(){return $this->ct;}
	public function SetError($e){$this->e = $e;}
	public function GetError(){return isset($this->e) ? $this->e : null;}
	public function SetJSON($o,$s=true){$t = new stdClass(); $t->success = $s; $t->result = $o; $this->o = $t;}
}


class DavvagFlow {
    public static $AppObjects;
    public static function Log(&$logObject,$logtype,$message){
        if(!isset($logObject->excutionStack->{$logtype})){
            $logObject->excutionStack->{$logtype}=array();
        }
        $m=array(
            "date"=>date("Y-m-d h:i:sa"),
            "message"=>$message
        );
        array_push($logObject->excutionStack->{$logtype},$m);
    }
    
    public static function Execute($ns,$flowid,$inputData,$step=null,$excuteData=null,$workflow=null){
        if(!isset($ns)){
            $filename= TENANT_RESOURCE_LOCATION."/davvag-flow/".$flowid.".json";
        }else{
            $filename= TENANT_RESOURCE_LOCATION."/davvag-flow/".$ns."/".$flowid.".json";
        }
        //$excuteData=$scopData;
        if(!isset($excuteData)){
            $excuteData=new stdClass();
        }
        if(!isset($workflow)){
            if(file_exists($filename)){
                $data=file_get_contents($filename);
                $workflow =json_decode($data);
                $excuteData->excutionStack=isset($excuteData->excutionStack)?$excuteData->excutionStack:new stdClass();
                $excuteData->outData=isset($excuteData->outData)?$excuteData->outData:new stdClass();
                $excuteData->excutionStack->workFlowId=isset($excuteData->excutionStack->workFlowId)?$excuteData->excutionStack->workFlowId:uniqid();
                $inputData->workflowid=$excuteData->excutionStack->workFlowId;
                self::Log($excuteData,"debugLog","workflow Initaited id:".$excuteData->excutionStack->workFlowId);
            }else{
                throw new Exception('workflow not found.');
            }
        }
        if(!isset($step) || $step===""){
            $step=$workflow->start_up_node;
        }   

        if($workflow!=null){
                if(!isset($workflow->{$step})){
                    throw new Exception("[$step] This step is not configured");
                }
                $objNode=$workflow->{$step};
                self::Log($excuteData,"debugLog","Step exuction ".$step);
                try{
                    if($objNode->method->return){
                        self::Log($excuteData,"debugLog","invoke Method [".$objNode->method->name. "] with return value [".$objNode->method->returnobj ."] [Node]" );
                        $excuteData->outData->{$objNode->method->returnobj}= self::ExcuteNode($inputData,$excuteData,$objNode);
                    }else{
                        self::Log($excuteData,"debugLog","invoke Method [".$objNode->method->name. "] with out return value [Node]");

                        self::ExcuteNode($inputData,$excuteData,$objNode);
                    }
                    if(isset($objNode->success)){
                        $excuteData=self::Execute($ns,$flowid,$inputData,$objNode->success,$excuteData,$workflow);
                    }else{
                         //$excuteData;
                    }
                }catch(Exception $e){
                    if(isset($objNode->fail)){
                        self::Log($excuteData,"errorLog","invoke Method [".$objNode->method->name. "] failed Error [".$e->getMessage() ."] going to fail Step[".$objNode->fail);

                        $excuteData=self::Execute($ns,$flowid,$inputData,$objNode->fail,$excuteData,$workflow);
                    }else{
                        self::Log($excuteData,"errorLog","invoke Method [".$objNode->method->name. "] failed Error [".$e->getMessage() ."] Exit ");
                        throw $e;
                    }
                }

           
        }else{
            throw new Exception('Davvag Flow Decode failed.');
        }

        $excuteData->inputData=$inputData;
        return $excuteData;
    }

    public static function ExcuteNode($inputData,$scopData,$node){
        switch($node->urntype){
            case "class":
                if(file_exists(PLUGIN_PATH."/davvag-flow/lib/".$node->file)){  
                    require_once(PLUGIN_PATH."/davvag-flow/lib/".$node->file);
                    
                    try{
                        //var_dump($arr);
                        $methodArray=[];
                        $arr=self::getParameters($node,$inputData,$scopData);
                        foreach ($arr as $key => $value) {
                            array_push($methodArray,$value);
                        }
                        return call_user_func_array(array($node->class,$node->method->name),$methodArray);
                    }catch (Exception $e1){
                        throw $e1;
                    }
                }else{
                    throw new Exception('Activity Not Found.');
                }
            break;
            case "service":
                return self::HandleService($node,$inputData,$scopData);
                break;
            case "create_object":
                return self::createObject($node,$inputData,$scopData);
                break;
            default:
                throw new Exception('URN Type is not implemented');
                break;
        }
       
    }

    public static function createObject($node,$inputData,$scopData){
		$o = new stdClass();
		foreach ($node->variables as $v) {
            $type=isset($v->type)?$v->type:"native";
            if($type=="object")
                $o->{$v->name}=self::getValue($v->value,$inputData,$scopData);
            else
                $o->{$v->name}=$v->value;
            //$_GET[$k]=$v;
        };
		return $o;
	}

    public static function aToStd($a){
		$o = new stdClass();
		foreach ($a as $k => $v) {
            $o->$k = $v;
            //$_GET[$k]=$v;
        };
		return $o;
	}
    public static function getValue($variable,$inputData,$scopData){
        $values=explode(".",$variable);
        $mainvalue=null;
        for ($i=0; $i < count($values); $i++) { 
            # code...
            if($i==0){
                $mainvalue=${$values[$i]};
            }else{
                $mainvalue=$mainvalue->{$values[$i]};
            }
        }
        return $mainvalue;
    }
    public static function getParameters($node,$inputData,$scopData){
        $arr = array();
        foreach ($node->method->params as $para) {
            $type=isset($para->type)?$para->type:"native";
            switch($type){
                case "object":
                    $mainvalue=self::getValue($para->value,$inputData,$scopData);
                    $arr[$para->name]=$mainvalue;
                    break;
                default:
                    $arr[$para->name]=$para->value;
                    break;
            }/*
            if(isset($para->inputData)){
                if(isset($inputData->{$para->inputData})){
                    array_push($arr,$inputData->{$para->inputData});
                }else{
                    if($para->inputData===""){
                        array_push($arr,$inputData);
                    }else{
                        self::Log($scopData,"errorLog","invoke Method [".$node->method->name. "] input Parameter not found  [".$para->inputData ."]");
                        throw new Exception('Requested input Parameter not found {'.$para->inputData.'}');
                    }
                }
            }else if(isset($para->scopData)){
                if(isset($scopData->{$para->scopData})){
                    array_push($arr,$scopData->{$para->scopData});
                }else{
                    if($para->scopData===""){
                        array_push($arr,$scopData);
                    }else{
                        self::Log($scopData,"errorLog","invoke Method [".$node->method->name. "] input Parameter not found  [".$para->scopData ."]");
                        throw new Exception('Requested input Parameter not found {'.$para->scopData.'}');
                    }
                }
            }else{
                array_push($arr,$para);
            }*/
        }
        return $arr;
    }

    public static function HandleService($node,$inputData,$scopData){
        try{
            $obj=self::getServiceObject($node,$scopData);
            if(method_exists($obj, $node->method->type.$node->method->name)){
                if($node->method->type=="post"){
                    $PostData=self::getParameters($node,$inputData,$scopData);
                    $PostData=reset($PostData);
                    $params=[];
                }else{
                    $params=self::getParameters($node,$inputData,$scopData);
                    $PostData=null;
                }
                
                //==1?$PostData[0]:self::aToStd($PostData);
                $req=new FReq($node,$node->method->name,"",$params,$PostData);
                $res= new FRes();
                $outObj = $obj->{$node->method->type.$node->method->name}($req, $res);
                $errorObj = $res->GetError();
                if(isset($errorObj)){
                    throw new Exception($errorObj);
                }else{
                    return $outObj;
                }
            }else{
                throw new Exception("Method Not Found");
            }
        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
        
        
    }

    public static function getServiceObject($node,$scopData){
        
        if(isset(self::$AppObjects->{$node->appCode}->components->{$node->componentCode}->obj)){
            self::Log($scopData,"debugLog","retrieved Object from Memory  [".$node->appCode. "]  [".$node->componentCode ."] [Node]" );
            return self::$AppObjects->{$node->appCode}->{$node->componentCode}->obj;
        }
        if(!isset(self::$AppObjects)){
            self::$AppObjects=new stdClass();
        }
        $appFile = TENANT_RESOURCE_LOCATION . "/apps/{$node->appCode}/app.json";
        if (file_exists($appFile)){
            $appObj = json_decode(file_get_contents($appFile));
            if (isset($appObj)){
                self::Log($scopData,"debugLog","retrieved descriptor for [".$node->appCode. "]" );

                self::$AppObjects->{$node->appCode}=$appObj;
                if(isset(self::$AppObjects->{$node->appCode}->components->{$node->componentCode})){
                    self::Log($scopData,"debugLog","retrieving descriptor for  [".$node->appCode. "]  [".$node->componentCode ."] [Node]" );

                    $componentType = self::$AppObjects->{$node->appCode}->components->{$node->componentCode}->location;
                    $componentDescriptor = TENANT_RESOURCE_LOCATION . "/apps/{$node->appCode}/$componentType/$node->componentCode/component.json";
                    if (file_exists($componentDescriptor)){
                        self::Log($scopData,"debugLog","retrieved descriptor for  [".$node->appCode. "]  [".$node->componentCode ."] [Node]" );

                        $componentObj = json_decode(file_get_contents($componentDescriptor));
                        if(isset($componentObj)){
                            //$componentObj->serviceHandler->class  $node->class
                            $handlerFile= TENANT_RESOURCE_LOCATION . "/apps/{$node->appCode}/$componentType/$node->componentCode/{$componentObj->serviceHandler->file}";
                            if (file_exists($handlerFile)){
                                require_once($handlerFile);
                                $class = $componentObj->serviceHandler->class;
                                if (class_exists($class)){
                                    
                                    $obj = new $class(array());
                                    self::Log($scopData,"debugLog","creating object for   [".$node->appCode. "]  [".$node->componentCode ."] [".$class."]" );

                                    self::$AppObjects->{$node->appCode}->components->{$node->componentCode}->obj=$obj;
                                    return self::$AppObjects->{$node->appCode}->components->{$node->componentCode}->obj;
                                }else{
                                    throw new Exception("Class[$class] Not Found.");
                                }
                            }else{
                                throw new Exception("php file[$handlerFile] Not Found.");
                            }
                        }else{
                            throw new Exception("Service file [{$componentDescriptor}] to object cannot  be created.");
                        }
                    }else{
                        throw new Exception("Service file [$componentDescriptor] Not Found.");
                    }
                    
                }else{
                    throw new Exception("Service [$node->componentCode] Not Found.");
                }
                
            }else{
                throw new Exception("Application [$node->appCode] Not Found.");
            }
        }else{
            throw new Exception("Application [$node->appCode] Not Found.");
        }
    }



}
