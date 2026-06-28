<?php
if (defined("PLUGIN_PATH")) {
    require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    require_once(PLUGIN_PATH . "/phpcache/cache.php");
    require_once(PLUGIN_PATH . "/auth/auth.php");
}

class ViewObjectApi {

    function __construct(){
        
    } 

    

    public function postSave($req,$res){
        $data = $req->Body(true);
        if (!is_array($data)) {
            $res->SetError("Permission list is required.");
            return null;
        }

        $user = Auth::Autendicate();
        if (!isset($user->userid)) {
            $res->SetError("You must be logged in to save permissions.");
            return null;
        }

        $rows = $this->normalizeRows($data);
        if (count($rows) === 0) {
            $res->SetError("Add at least one permission row.");
            return null;
        }

        $keyValue = $this->keyForRows($rows);

        $header = $this->findHeaderByKey($keyValue, $user->userid);

        if ($header === null) {
            $header = new stdClass();
            $header->keyValue = $keyValue;
            $header->keyvalue = $keyValue;
            $header->owner = $user->userid;
            $result = SOSSData::Insert("user_object", $header);
            if (!$result->success) {
                $res->SetError($result);
                return null;
            }
            $header->viewObjectID = $result->result->generatedId;

            foreach ($rows as $row) {
                $row->viewObjectID = $header->viewObjectID;
            }
            $insert = SOSSData::Insert("user_view_objects", $rows);
            if (!$insert->success) {
                $res->SetError($insert);
                return null;
            }
        } else {
            foreach ($rows as $row) {
                $row->viewObjectID = $header->viewObjectID;
            }
            if (count($this->findRows($header->viewObjectID)) === 0) {
                $insert = SOSSData::Insert("user_view_objects", $rows);
                if (!$insert->success) {
                    $res->SetError($insert);
                    return null;
                }
            }
        }

        $this->clearPermissionCaches();
        return $this->findRows($header->viewObjectID);
    }

    public function getFindObject($req,$res){
        $query = $req->Query();
        if (!isset($query->objectID) || intval($query->objectID) === 0) {
            return array();
        }
        return $this->findRows($query->objectID);
    }

    public function getUserVieObjects($req,$res){
        $user=Auth::Autendicate();
        $objects=$this->publicObjects();
        if(!isset($user->userid)){
            return $objects;
        }

        $cached=CacheData::getObjects($user->userid,"user_object");
        if(isset($cached) && is_array($cached) && count($cached) > 0){
            return $this->withPublicObject($cached);
        }

        $d =SOSSData::Query("user_object","owner:".$user->userid, null, "asc", 100, 0, null, false);
        if(!$d->success){
            $res->SetError($d);
            return null;
        }

        $onlyMeKeyValue=$this->keyForRows(array($this->onlyMeRow($user, 0)));
        $legacyOnlyMeKeyValue=md5($user->userid."-full");
        $hasOnlyMe=false;
        foreach ($d->result as $key => $value) {
            $value->tag="Custom";
            $valueKey=$this->objectKeyValue($value);
            if($valueKey==$onlyMeKeyValue || $valueKey==$legacyOnlyMeKeyValue){
                $hasOnlyMe=true;
                $value->tag="Only Me";
            }
            array_push($objects,$value);
        }

        if(!$hasOnlyMe){
            $header=new stdClass();
            $header->keyValue=$onlyMeKeyValue;
            $header->keyvalue=$onlyMeKeyValue;
            $header->owner=$user->userid;
            $result =SOSSData::Insert("user_object",$header);
            if(!$result->success){
                $res->SetError($result);
                return null;
            }

            $header->viewObjectID=$result->result->generatedId;
            $header->tag="Only Me";
            array_push($objects,$header);

            $data=array($this->onlyMeRow($user, $header->viewObjectID));
            $insert=SOSSData::Insert("user_view_objects",$data);
            if(!$insert->success){
                $res->SetError($insert);
                return null;
            }
            $this->clearPermissionCaches();
        }

        return $objects;
    }

    public function getUserViewObjects($req,$res){
        return $this->getUserVieObjects($req,$res);
    }
    
    public function getPermisionValues($req,$res){
        $data_sent=array();
        $query=$req->Query();
        $itemType=isset($query->item_type) ? $query->item_type : "";
        switch ($itemType) {
            case 'group':
                # code...
                array_push($data_sent,array("val"=>"anonymous","text"=>"Anonymous / public visitors"));
                $data=SOSSData::Query("usergroups","", null, "asc", 1000, 0, null, false);
                if(!$data->success){
                    $res->SetError($data);
                    return null;
                }
                
                foreach ($data->result as $key => $value) {
                    # code...
                    array_push($data_sent,array("val"=>$value->groupid,"text"=>$value->groupid));
                }

                break;
            case 'user':
                # code...
                array_push($data_sent,array("val"=>"*","text"=>"All signed-in users"));
                $data=SOSSData::Query("users","", null, "asc", 1000, 0, null, false);
                if(!$data->success){
                    $res->SetError($data);
                    return null;
                }
                
                foreach ($data->result as $key => $value) {
                    # code...
                    array_push($data_sent,array("val"=>$value->userid,"text"=>$value->email));
                }
                break;
            
            default:
                # code...
                return array();
                break;
        }
        return $data_sent;
    }

    public function getPermissionValues($req,$res){
        return $this->getPermisionValues($req,$res);
    }

    private function findHeaderByKey($keyValue, $owner) {
        $queries = array(
            "keyValue:" . $keyValue . ",owner:" . $owner,
            "keyvalue:" . $keyValue . ",owner:" . $owner
        );
        foreach ($queries as $query) {
            $existing = SOSSData::Query("user_object", $query, null, "asc", 1, 0, null, false);
            if ($existing->success && count($existing->result) > 0) {
                $header = $existing->result[0];
                if (!isset($header->keyValue)) {
                    $header->keyValue = $this->objectKeyValue($header);
                }
                if (!isset($header->keyvalue)) {
                    $header->keyvalue = $this->objectKeyValue($header);
                }
                return $header;
            }
        }
        return null;
    }

    private function publicObjects() {
        $glob=new stdClass();
        $glob->tag="Public";
        $glob->viewObjectID=0;
        $glob->keyValue="";
        $glob->keyvalue="";
        return array($glob);
    }

    private function withPublicObject($objects) {
        $out=$this->publicObjects();
        foreach ($objects as $object) {
            if (isset($object->viewObjectID) && intval($object->viewObjectID) === 0) {
                continue;
            }
            if (!isset($object->tag) || $object->tag === "") {
                $object->tag="Custom";
            }
            array_push($out, $object);
        }
        return $out;
    }

    private function onlyMeRow($user, $viewObjectID) {
        $uprm =new stdClass();
        $uprm->viewObjectID=$viewObjectID;
        $uprm->item_type="user";
        $uprm->item_permision="Full";
        $uprm->item_value=$user->userid;
        $uprm->item_text=isset($user->email) ? $user->email : $user->userid;
        return $uprm;
    }

    private function objectKeyValue($object) {
        if (isset($object->keyValue)) {
            return $object->keyValue;
        }
        if (isset($object->keyvalue)) {
            return $object->keyvalue;
        }
        return "";
    }

    private function keyForRows($rows) {
        $keySort = array();
        foreach ($rows as $row) {
            array_push($keySort, $row->item_type . "-" . $row->item_value . "-" . $row->item_permision);
        }
        sort($keySort);
        return md5(implode("_", $keySort));
    }

    private function findRows($objectId) {
        $r = SOSSData::Query("user_view_objects", "viewObjectID:" . $objectId, null, "asc", 1000, 0, null, false);
        return $r->success ? $r->result : array();
    }

    private function normalizeRows($data) {
        $rows = array();
        $seen = array();
        foreach ($data as $item) {
            if (!isset($item->item_type) || !isset($item->item_value) || !isset($item->item_permision)) {
                continue;
            }
            $type = $item->item_type === "group" ? "group" : "user";
            $value = trim((string)$item->item_value);
            if ($value === "") {
                continue;
            }
            $permission = $this->normalizePermission($item->item_permision);
            $key = $type . ":" . $value;
            if (isset($seen[$key])) {
                $rows[$seen[$key]]->item_permision = $permission;
                $rows[$seen[$key]]->item_text = isset($item->item_text) && trim($item->item_text) !== "" ? trim($item->item_text) : $value;
                continue;
            }
            $row = new stdClass();
            $row->item_type = $type;
            $row->item_value = $value;
            $row->item_permision = $permission;
            $row->item_text = isset($item->item_text) && trim($item->item_text) !== "" ? trim($item->item_text) : $value;
            $seen[$key] = count($rows);
            array_push($rows, $row);
        }
        return $rows;
    }

    private function normalizePermission($permission) {
        $permission = strtolower(trim((string)$permission));
        if ($permission === "full") {
            return "Full";
        }
        if ($permission === "edit") {
            return "Edit";
        }
        return "View";
    }

    private function clearPermissionCaches() {
        CacheData::clearObjects("user_object");
        CacheData::clearObjects("viewObjects");
    }
    


}

?>
