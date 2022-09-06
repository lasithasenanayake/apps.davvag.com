<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/davvag-flow.php");


class appService {

    function __construct(){
        
    } 

    public function postGetDataSource($req,$res){
        $data=$req->Body(true);
        $rs=SOSSData::Query($data->datasource,$data->query?$data->query:"");
        if($rs->success){
            return $rs->result;
        }else{
            $res->SetError($rs);
            return null;
        }
    }
    
    public function getAtrribute($req,$res)
    {
        if(isset($_GET["id"])){
            $folder_attributes = TENANT_RESOURCE_LOCATION . "/schemas/attributes";
            $file=$_GET["id"];
            $r=SOSSData::Query("d_attributes","id:$file"); 
            if($r->success){
                if(count($r->result)>0){
                    if(file_exists("$folder_attributes/$file.json")){
                        $tmpData=json_decode(file_get_contents("$folder_attributes/$file.json"));
                        $data=new stdClass();
                        if(is_array($tmpData)){
                            $data->Fields=$tmpData;
                        }else{
                            $data=$tmpData;
                        }
                        $data->id=$r->result[0]->id;
                        $data->main_node=$r->result[0]->main_node;
                        $data->name=$r->result[0]->name;
                        //$data->Fields=json_decode(file_get_contents("$folder_attributes/$file.json"));
                        
                        return $data;
                    }else{
                        return null;
                    }
                }else{
                    if(file_exists("$folder_attributes/$file.json")){
                        $tmpData=json_decode(file_get_contents("$folder_attributes/$file.json"));
                        $data=new stdClass();
                        if(is_array($tmpData)){
                            $data->Fields=$tmpData;
                        }else{
                            $data=$tmpData;
                        }
                        $data->id=$file;
                        $data->main_node=substr($file,0,strpos($file,"_"));
                        $data->name=substr($file,strpos($file,"_")+1,strlen($file)-strpos($file,"_"));
                        //$data->Fields=json_decode(file_get_contents("$folder_attributes/$file.json"));
                        
                        return $data;
                    }else{
                        return null;
                    }
                }
            }
            
        }
        # code...
    }
    public function postSave($req,$res){
        $data = $req->Body(true);
        $folder_attributes = TENANT_RESOURCE_LOCATION . "/schemas/attributes";
        $folder_schemas = TENANT_RESOURCE_LOCATION . "/schemas";
        $file=$data->main_node."_".$data->name;
        $file_date=date("YmdHis");
        $objData=new stdClass();
        $data->id=$file;
        
        $data->schema=$this->ConvertToSchemaFile($data);
        if(file_exists("$folder_attributes/$file.json")){
            if (!file_exists("$folder_attributes/backup"))
            mkdir("$folder_attributes/backup", 0777, true);
            file_put_contents ("$folder_attributes/backup/$file-$file_date.json",file_get_contents("$folder_attributes/$file.json"));
        }

        if(file_exists("$folder_schemas/$file.json")){
            if (!file_exists("$folder_schemas/backup"))
                mkdir("$folder_schemas/backup", 0777, true);

                file_put_contents("$folder_schemas/backup/$file-$file_date.json",file_get_contents("$folder_schemas/$file.json"));
        }
        file_put_contents("$folder_schemas/$file.json",json_encode($data->schema));
        unset($data->schema);
        file_put_contents("$folder_attributes/$file.json",json_encode($data));
        
        $r=SOSSData::Query("d_attributes","id:$file"); 
        if($r->success){
            if(count($r->result)==0){
                SOSSData::Insert("d_attributes",$data); 
            }else{
                SOSSData::Update("d_attributes",$data); 
            }
        }
        return $data; 
    }

    private function ConvertToSchemaFile($obj)
    {
        $schema_Class=new stdClass();
        $schema_Class->fields=[];
        foreach($obj->Fields as $item){
            $field=new stdClass();
            $field->fieldName=$item->name;
            $field->dataType=$item->valuetype;
            $field->annotations=new stdClass();
            if(isset($item->primary) && $item->primary==true)$field->annotations->isPrimary=true;
            if(isset($item->autoIncrement) && $item->autoIncrement==true)$field->annotations->autoIncrement=true;
            if(isset($item->maxlen))$field->annotations->maxLen=$item->maxlen;
            //if(isset($item->autoIncrement))$field->annotations->autoIncrement=$item->autoIncrement;
            if(isset($item->encoding))$field->annotations->encoding=$item->encoding;
            array_push($schema_Class->fields,$field);

        }

        return $schema_Class;
    }

    public function getWorkFlows(){
        $df=new Davvag_Flow_Controller();
        return $df->getFlows("davvag-attributes");
    }

    


}

?>