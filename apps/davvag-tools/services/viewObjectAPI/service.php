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

        $keySort = array();
        foreach ($rows as $row) {
            array_push($keySort, $row->item_type . "-" . $row->item_value . "-" . $row->item_permision);
        }
        sort($keySort);
        $keyValue = md5(implode("_", $keySort));

        $header = null;
        $existing = SOSSData::Query("user_object", "keyValue:" . $keyValue . ",owner:" . $user->userid, null, "asc", 1, 0, null, false);
        if ($existing->success && count($existing->result) > 0) {
            $header = $existing->result[0];
        }

        if ($header === null) {
            $header = new stdClass();
            $header->keyValue = $keyValue;
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
        $objects=array();
        $glob=new stdClass();
        $glob->tag="public";
        $glob->viewObjectID=0;
        $glob->keyvalue="";
        array_push($objects,$glob);
        if(isset($user->userid)){
            $objects=CacheData::getObjects($user->userid,"user_object");
            if(isset($objects)){
                return $objects;
            }else{

                $d =SOSSData::Query("user_object","owner:".$user->userid, null, "asc", 100, 0, null, false);
                if($d->success){
                    $keySort=array($user->userid."-full");
                    $keyValue=md5(implode("_",$keySort));
                    $hasOnlyMe=false;
                    $header=null;
                    foreach ($d->result as $key => $value) {
                        # code...
                        $value->tag="Custom";
                        if(isset($value->keyValue) && $value->keyValue==$keyValue){
                            $hasOnlyMe=true;
                            $header=$value;
                        }
                        array_push($objects,$value);
                    }
                    if(!$hasOnlyMe){
                        $header=new stdClass();
                        $header->keyvalue=$keyValue;
                        $header->owner=$user->userid;
                        $result =SOSSData::Insert("user_object",$header);
                        if($result->success){
                            $header->viewObjectID=$result->result->generatedId;
                            $header->tag="Only Me";
                            array_push($objects,$header);
                            $data=array();
                            $uprm =new stdClass();
                            $uprm->viewObjectID=$header->viewObjectID;
                            $uprm->item_type="user";
                            $uprm->item_permision="full";
                            $uprm->item_value=$user->userid;
                            $uprm->item_text=$user->email;
                            array_push($keySort,$user->userid."-full");
                            array_push($data,$uprm);
                            SOSSData::Insert("user_view_objects",$data);
                        }else{
                            $res->SetError($result);
                            return null;
                        }
                       
                        
                    }

                }else{
                    $res->SetError($d);
                    return null;
                }
            }
            

        }
        return $objects;
    }
    
    public function getPermisionValues($req,$res){
        $data_sent=array();
        switch ($req->Query()->item_type) {
            case 'group':
                # code...
                array_push($data_sent,array("val"=>"anonymous","text"=>"Anonymous / public visitors"));
                $data=SOSSData::Query("usergroups","", null, "asc", 1000, 0, null, false);
                
                foreach ($data->result as $key => $value) {
                    # code...
                    array_push($data_sent,array("val"=>$value->groupid,"text"=>$value->groupid));
                }

                break;
            case 'user':
                # code...
                array_push($data_sent,array("val"=>"*","text"=>"All signed-in users"));
                $data=SOSSData::Query("users","", null, "asc", 1000, 0, null, false);
                
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
