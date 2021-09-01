<?php 
    
    
    require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/flow.php");
    class Davvag_Attributes{
        public static function Save($data){
            //require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/davvag-flow.php");
            $queryStr="";
            for ($i=0; $i < count($data->primary); $i++) { 
                $queryStr.=$data->primary[$i].":".$data->data->{$data->primary[$i]}.",";
            }
            if(strlen($queryStr)>1){
                $queryStr=substr($queryStr,0,-1);
            }
            $data->query=$queryStr;
            $result=SOSSData::Query($data->id,$queryStr);
            if($result->success){
                if(count($result->result)>0){
                    $data->status=SOSSData::Update($data->id,$data->data);
                }else{
                    $data->status=SOSSData::Insert($data->id,$data->data);
                }
                //sreturn $data->status;
                if($data->status->success){
                    //echo "im in";
                    if($data->postworkflow){
                        //echo "im in";
                        
                        $data->workflow_log= DavvagFlow::Execute("davvag-attributes",$data->postworkflow->name,self::getInputData($data));
                    }
                }
            }else{
                //$res->SetError($result);
                return null;
            }
            CacheData::clearObjects($data->id);
            return $data; 
        }

        private static function getInputData($data){
            $inputData=new stdClass();
            foreach ($data->postworkflow->inputData as $key => $value) {
                # code...
                if(isset($data->data->{$value->mappingfield})){
                    $inputData->{$value->name}=$data->data->{$value->mappingfield};
                }else{
                    $inputData->{$value->name}=null;
                }
            }
            return $inputData;
        }

        
    }

    
?>