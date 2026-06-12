<?php
namespace tax_master;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");

class TaxMasterService {
    private $store = "tax_master";

    private function seed(){
        $result = \SOSSData::Query($this->store, "");
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return;
        }
        $item = new \stdClass();
        $item->code = "NO_TAX";
        $item->name = "No Tax";
        $item->description = "Default zero tax mapping";
        $item->rate = 0;
        $item->taxType = "percentage";
        $item->applyTo = "invoice";
        $item->isDefault = "Y";
        $item->status = "active";
        $item->sortOrder = 1;
        \SOSSData::Insert($this->store, $item);
        \CacheData::clearObjects($this->store);
    }

    private function normalize($data){
        if(!isset($data->name) || trim($data->name) === ""){
            return null;
        }
        $data->name = trim($data->name);
        $data->code = isset($data->code) && trim($data->code) !== "" ? strtoupper(trim($data->code)) : strtoupper(preg_replace("/[^A-Za-z0-9]+/", "_", $data->name));
        $data->rate = isset($data->rate) ? floatval($data->rate) : 0;
        $data->taxType = "percentage";
        $data->applyTo = isset($data->applyTo) && $data->applyTo !== "" ? strtolower(trim($data->applyTo)) : "invoice";
        $data->isDefault = isset($data->isDefault) && strtoupper($data->isDefault) === "Y" ? "Y" : "N";
        $data->status = isset($data->status) && $data->status !== "" ? strtolower(trim($data->status)) : "active";
        $data->sortOrder = isset($data->sortOrder) ? intval($data->sortOrder) : 0;
        return $data;
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
            $res->SetError("Unable to load tax mappings.");
            return null;
        }
        return $this->sortItems($result->result);
    }

    public function getActive($req, $res){
        $query = $req->Query();
        $applyTo = isset($query->applyTo) && trim($query->applyTo) !== "" ? strtolower(trim($query->applyTo)) : "invoice";
        $items = $this->getList($req, $res);
        if(!is_array($items)){
            return array();
        }
        return array_values(array_filter($items, function($item) use ($applyTo){
            $statusOk = !isset($item->status) || strtolower($item->status) === "active";
            $target = isset($item->applyTo) ? strtolower($item->applyTo) : "invoice";
            return $statusOk && ($target === $applyTo || $target === "all");
        }));
    }

    public function postSave($req, $res){
        $data = $this->normalize($req->Body(true));
        if($data === null){
            $res->SetError("Tax name is required.");
            return null;
        }
        if($data->rate < 0){
            $res->SetError("Tax rate cannot be negative.");
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
            $res->SetError("Unable to save tax mapping.");
            return null;
        }
        return $data;
    }
}
?>
