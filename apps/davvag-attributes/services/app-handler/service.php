<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");


class appService {

    function __construct(){
        
    } 
    public function getAtrribute($req,$res)
    {
        if(isset($_GET["id"])){
            $folder_attributes = TENANT_RESOURCE_LOCATION . "/schemas/attributes";
            $file=$_GET["id"];
            if(file_exists("$folder_attributes/$file.json")){
                return json_decode(file_get_contents("$folder_attributes/$file.json"));
            }else{
                return null;
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
        file_put_contents("$folder_attributes/$file.json",json_encode($data->atrributeFields));
        file_put_contents("$folder_schemas/$file.json",json_encode($data->schema));
        return $data; 
    }

    private function ConvertToSchemaFile($obj)
    {
        $schema_Class=new stdClass();
        $schema_Class->fields=[];
        foreach($obj->atrributeFields as $item){
            $field=new stdClass();
            $field->fieldName=$item->name;
            $field->dataType=$item->valuetype;
            $field->annotations=new stdClass();
            if(isset($item->primary))$field->annotations->isPrimary=true;
            if(isset($item->maxlen))$field->annotations->maxLen=$item->maxlen;
            if(isset($item->autoIncrement))$field->annotations->autoIncrement=$item->autoIncrement;
            if(isset($item->encoding))$field->annotations->encoding=$item->encoding;
            array_push($schema_Class->fields,$field);

        }

        return $schema_Class;
    }

    


}

?>