<?php
namespace profile_catogory_creator;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");

class ProfileCatogoryService {
    private $store = "profile_catogory";

    private function defaults(){
        return array("Customer","Vender","Company","Guest","Staff","Student","Student-MDiv","Student-Diploma","Student-BTH","Student-Digree","Student-HCD","Visiting","Church","Pastor");
    }

    private function normalize($data){
        if(!isset($data->name) || trim($data->name) === ""){
            return null;
        }
        $data->name = trim($data->name);
        $data->code = isset($data->code) && trim($data->code) !== "" ? strtoupper(trim($data->code)) : strtoupper(preg_replace("/[^A-Za-z0-9]+/", "_", $data->name));
        $data->status = isset($data->status) && $data->status !== "" ? strtolower(trim($data->status)) : "active";
        $data->isDefault = isset($data->isDefault) && strtoupper($data->isDefault) === "Y" ? "Y" : "N";
        $data->sortOrder = isset($data->sortOrder) ? intval($data->sortOrder) : 0;
        return $data;
    }

    private function seed(){
        $result = \SOSSData::Query($this->store, "");
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return;
        }
        $items = array();
        foreach($this->defaults() as $index => $name){
            $item = new \stdClass();
            $item->name = $name;
            $item->code = strtoupper(preg_replace("/[^A-Za-z0-9]+/", "_", $name));
            $item->status = "active";
            $item->isDefault = $index === 0 ? "Y" : "N";
            $item->sortOrder = $index + 1;
            $items[] = $item;
        }
        \SOSSData::Insert($this->store, $items);
        \CacheData::clearObjects($this->store);
    }

    private function sortItems($items){
        usort($items, function($a, $b){
            $ao = isset($a->sortOrder) ? intval($a->sortOrder) : 0;
            $bo = isset($b->sortOrder) ? intval($b->sortOrder) : 0;
            if($ao === $bo){
                return strcmp(isset($a->name) ? $a->name : "", isset($b->name) ? $b->name : "");
            }
            return $ao < $bo ? -1 : 1;
        });
        return $items;
    }

    public function getList($req, $res){
        $this->seed();
        $result = \SOSSData::Query($this->store, "");
        if(!$result->success){
            $res->SetError("Unable to load profile catogories.");
            return null;
        }
        return $this->sortItems($result->result);
    }

    public function getActive($req, $res){
        $items = $this->getList($req, $res);
        if(!is_array($items)){
            return array();
        }
        return array_values(array_filter($items, function($item){
            return !isset($item->status) || strtolower($item->status) === "active";
        }));
    }

    public function postSave($req, $res){
        $data = $this->normalize($req->Body(true));
        if($data === null){
            $res->SetError("Catogory name is required.");
            return null;
        }
        if(isset($data->id) && intval($data->id) > 0){
            $result = \SOSSData::Update($this->store, $data);
        }else{
            unset($data->id);
            $result = \SOSSData::Insert($this->store, $data);
        }
        \CacheData::clearObjects($this->store);
        if(!$result->success){
            $res->SetError("Unable to save profile catogory.");
            return null;
        }
        return $data;
    }
}
?>
