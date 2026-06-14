<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/davvag-flow.php");

class appService {

    function __construct(){

    }

    private function attributesFolder(){
        return TENANT_RESOURCE_LOCATION . "/schemas/attributes";
    }

    private function schemasFolder(){
        return TENANT_RESOURCE_LOCATION . "/schemas";
    }

    private function cleanKey($value, $fallback = ""){
        $value = isset($value) ? trim($value) : "";
        $value = preg_replace("/[^A-Za-z0-9_\\-]+/", "_", $value);
        $value = trim($value, "_-");
        return $value === "" ? $fallback : $value;
    }

    private function cleanId($value){
        $value = isset($value) ? basename($value) : "";
        $value = preg_replace("/\\.json$/i", "", $value);
        $value = preg_replace("/[^A-Za-z0-9_\\-]+/", "_", $value);
        return $value;
    }

    private function splitId($id){
        $parts = new stdClass();
        $pos = strpos($id, "_");
        if($pos === false){
            $parts->main_node = "";
            $parts->name = $id;
        }else{
            $parts->main_node = substr($id, 0, $pos);
            $parts->name = substr($id, $pos + 1);
        }
        return $parts;
    }

    private function ensureFolders(){
        $folder_attributes = $this->attributesFolder();
        $folder_schemas = $this->schemasFolder();
        if(!file_exists($folder_attributes)){
            mkdir($folder_attributes, 0777, true);
        }
        if(!file_exists($folder_schemas)){
            mkdir($folder_schemas, 0777, true);
        }
    }

    private function readAttributeFile($id){
        $file = $this->cleanId($id);
        if($file === ""){
            return null;
        }

        $path = $this->attributesFolder() . "/$file.json";
        if(!file_exists($path)){
            return null;
        }

        $tmpData = json_decode(file_get_contents($path));
        if($tmpData === null && json_last_error() !== JSON_ERROR_NONE){
            return null;
        }

        $data = new stdClass();
        if(is_array($tmpData)){
            $data->Fields = $tmpData;
        }else if(is_object($tmpData)){
            $data = $tmpData;
        }else{
            return null;
        }

        if(!isset($data->Fields) && isset($data->atrributeFields)){
            $data->Fields = $data->atrributeFields;
        }

        $parts = $this->splitId($file);
        if(!isset($data->id) || trim($data->id) === ""){
            $data->id = $file;
        }
        if(!isset($data->main_node)){
            $data->main_node = $parts->main_node;
        }
        if(!isset($data->name) || trim($data->name) === ""){
            $data->name = $parts->name;
        }
        if(!isset($data->Fields) || !is_array($data->Fields)){
            $data->Fields = array();
        }

        return $data;
    }

    private function loadAttribute($id){
        $file = $this->cleanId($id);
        if($file === ""){
            return null;
        }

        $data = $this->readAttributeFile($file);
        $r = SOSSData::Query("d_attributes", "id:$file");
        if($r->success && count($r->result) > 0){
            if($data === null){
                $data = new stdClass();
                $data->Fields = array();
            }
            $data->id = $r->result[0]->id;
            $data->main_node = $r->result[0]->main_node;
            $data->name = $r->result[0]->name;
        }

        return $data;
    }

    private function buildListItem($data, $source){
        $item = new stdClass();
        $item->id = isset($data->id) ? $data->id : "";
        $item->main_node = isset($data->main_node) ? $data->main_node : "";
        $item->name = isset($data->name) ? $data->name : $item->id;
        $item->fieldCount = isset($data->Fields) && is_array($data->Fields) ? count($data->Fields) : 0;
        $item->workflowName = isset($data->postworkflow) && is_object($data->postworkflow) && isset($data->postworkflow->name) ? $data->postworkflow->name : "";
        $item->source = $source;
        return $item;
    }

    private function backupFile($path, $backupFolder, $file, $file_date){
        if(!file_exists($path)){
            return;
        }
        if(!file_exists($backupFolder)){
            mkdir($backupFolder, 0777, true);
        }
        file_put_contents("$backupFolder/$file-$file_date.json", file_get_contents($path));
    }

    private function normalizeForSave($data, $res){
        if(!isset($data->main_node) || trim($data->main_node) === ""){
            $data->main_node = "attr";
        }
        $data->main_node = $this->cleanKey($data->main_node, "attr");

        if(!isset($data->name) || trim($data->name) === ""){
            $res->SetError("Attribute name is required.");
            return null;
        }
        $data->name = $this->cleanKey($data->name);
        if($data->name === ""){
            $res->SetError("Attribute name is required.");
            return null;
        }

        if(!isset($data->Fields) && isset($data->atrributeFields)){
            $data->Fields = $data->atrributeFields;
        }
        if(!isset($data->Fields) || !is_array($data->Fields)){
            $data->Fields = array();
        }

        $data->id = $data->main_node . "_" . $data->name;
        return $data;
    }

    public function postGetDataSource($req,$res){
        $data=$req->Body(true);
        $rs=SOSSData::Query($data->datasource, isset($data->query) && $data->query ? $data->query : "");
        if($rs->success){
            return $rs->result;
        }else{
            $res->SetError($rs);
            return null;
        }
    }

    public function getList($req, $res){
        $items = array();
        $seen = array();

        $r = SOSSData::Query("d_attributes", "");
        if($r->success && is_array($r->result)){
            foreach($r->result as $row){
                $id = isset($row->id) ? $this->cleanId($row->id) : "";
                if($id === "" && isset($row->main_node) && isset($row->name)){
                    $id = $this->cleanKey($row->main_node) . "_" . $this->cleanKey($row->name);
                }
                if($id === ""){
                    continue;
                }

                $data = $this->readAttributeFile($id);
                if($data === null){
                    $data = new stdClass();
                    $data->id = $id;
                    $data->main_node = isset($row->main_node) ? $row->main_node : "";
                    $data->name = isset($row->name) ? $row->name : $id;
                    $data->Fields = array();
                }

                $items[] = $this->buildListItem($data, "store");
                $seen[$id] = true;
            }
        }

        $folder_attributes = $this->attributesFolder();
        if(file_exists($folder_attributes)){
            foreach(glob($folder_attributes . "/*.json") as $path){
                $id = preg_replace("/\\.json$/i", "", basename($path));
                if(isset($seen[$id])){
                    continue;
                }
                $data = $this->readAttributeFile($id);
                if($data !== null){
                    $items[] = $this->buildListItem($data, "file");
                    $seen[$id] = true;
                }
            }
        }

        usort($items, function($a, $b){
            $am = isset($a->main_node) ? $a->main_node : "";
            $bm = isset($b->main_node) ? $b->main_node : "";
            if($am !== $bm){
                return strcmp($am, $bm);
            }
            return strcmp(isset($a->name) ? $a->name : "", isset($b->name) ? $b->name : "");
        });

        return $items;
    }

    public function getAtrribute($req,$res)
    {
        if(isset($_GET["id"])){
            return $this->loadAttribute($_GET["id"]);
        }
        return null;
    }

    public function getAttribute($req,$res)
    {
        return $this->getAtrribute($req, $res);
    }

    public function postSave($req,$res){
        $data = $this->normalizeForSave($req->Body(true), $res);
        if($data === null){
            return null;
        }

        $this->ensureFolders();
        $folder_attributes = $this->attributesFolder();
        $folder_schemas = $this->schemasFolder();
        $file = $data->id;
        $file_date = date("YmdHis");

        $data->schema = $this->ConvertToSchemaFile($data);
        $this->backupFile("$folder_attributes/$file.json", "$folder_attributes/backup", $file, $file_date);
        $this->backupFile("$folder_schemas/$file.json", "$folder_schemas/backup", $file, $file_date);

        if(file_put_contents("$folder_schemas/$file.json", json_encode($data->schema)) === false){
            $res->SetError("Unable to write schema file.");
            return null;
        }
        unset($data->schema);
        if(file_put_contents("$folder_attributes/$file.json", json_encode($data)) === false){
            $res->SetError("Unable to write attribute file.");
            return null;
        }

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
            if(!isset($item->name) || trim($item->name) === ""){
                continue;
            }
            $field=new stdClass();
            $field->fieldName=$item->name;
            $field->dataType=isset($item->valuetype) && $item->valuetype !== "" ? $item->valuetype : (isset($item->type) && $item->type === "date" ? "java.util.Date" : "java.lang.String");
            $field->annotations=new stdClass();
            if(isset($item->primary) && ($item->primary===true || $item->primary==="true" || $item->primary==="1" || $item->primary===1))$field->annotations->isPrimary=true;
            if(isset($item->autoIncrement) && ($item->autoIncrement===true || $item->autoIncrement==="true" || $item->autoIncrement==="1" || $item->autoIncrement===1))$field->annotations->autoIncrement=true;
            if(isset($item->maxlen) && $item->maxlen !== "")$field->annotations->maxLen=$item->maxlen;
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
