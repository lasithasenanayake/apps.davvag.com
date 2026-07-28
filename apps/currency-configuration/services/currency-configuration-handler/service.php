<?php
namespace currency_configuration;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");

class CurrencyConfigurationService {
    private $store = "currency_configuration";

    private function seed(){
        $result = \SOSSData::Query($this->store, "", null, "asc", 1000, 0, null, false);
        if(isset($result->success) && $result->success && isset($result->result) && count($result->result) > 0){
            return;
        }
        $item = new \stdClass();
        $item->code = defined("CURRENCY_CODE") ? CURRENCY_CODE : "LKR";
        $item->numericCode = "144";
        $item->name = "Sri Lankan Rupee";
        $item->symbol = "Rs.";
        $item->decimalPlaces = 2;
        $item->exchangeRate = 1;
        $item->isBase = "Y";
        $item->status = "active";
        $item->sortOrder = 1;
        \SOSSData::Insert($this->store, $item);
        \CacheData::clearObjects($this->store);
    }

    private function normalize($data){
        if(!isset($data->code)){
            return null;
        }
        $data->code = strtoupper(preg_replace("/[^A-Z]/", "", $data->code));
        if(!preg_match("/^[A-Z]{3}$/", $data->code)){
            return null;
        }
        if(!isset($data->name) || trim($data->name) === ""){
            $data->name = $data->code;
        }else{
            $data->name = trim($data->name);
        }
        $data->numericCode = isset($data->numericCode) ? preg_replace("/[^0-9]/", "", $data->numericCode) : "";
        if(strlen($data->numericCode) > 3){
            $data->numericCode = substr($data->numericCode, 0, 3);
        }
        $data->decimalPlaces = isset($data->decimalPlaces) ? intval($data->decimalPlaces) : 2;
        $data->exchangeRate = isset($data->exchangeRate) ? floatval($data->exchangeRate) : 1;
        $data->isBase = isset($data->isBase) && strtoupper($data->isBase) === "Y" ? "Y" : "N";
        $data->status = isset($data->status) && $data->status !== "" ? strtolower(trim($data->status)) : "active";
        $data->sortOrder = isset($data->sortOrder) ? intval($data->sortOrder) : 0;
        return $data;
    }

    private function sortItems($items){
        usort($items, function($a, $b){
            $ao = isset($a->sortOrder) ? intval($a->sortOrder) : 0;
            $bo = isset($b->sortOrder) ? intval($b->sortOrder) : 0;
            if($ao === $bo){
                return strcmp(isset($a->code) ? $a->code : "", isset($b->code) ? $b->code : "");
            }
            return $ao < $bo ? -1 : 1;
        });
        return $items;
    }

    /**
     * Shared application contract. Monetary applications should call these
     * methods instead of defining their own USD/LKR defaults or code lists.
     */
    public function listCurrencies(){
        $this->seed();
        $result = \SOSSData::Query($this->store, "", null, "asc", 1000, 0, null, false);
        if(!isset($result->success) || !$result->success){
            throw new \RuntimeException("Unable to load currencies from Currency Configuration.");
        }
        return $this->sortItems(isset($result->result) ? $result->result : array());
    }

    public function activeCurrencies(){
        return array_values(array_filter($this->listCurrencies(), function($item){
            return !isset($item->status) || strtolower($item->status) === "active";
        }));
    }

    public function defaultCurrency(){
        $items = $this->activeCurrencies();
        foreach($items as $item){
            if(isset($item->isBase) && strtoupper($item->isBase) === "Y"){
                return $item;
            }
        }
        if(count($items) === 0){
            throw new \RuntimeException("No active currency is configured.");
        }
        return $items[0];
    }

    public function requireActiveCurrency($code){
        $code = strtoupper(trim(strval($code)));
        if(!preg_match("/^[A-Z]{3}$/", $code)){
            throw new \InvalidArgumentException("Select a valid configured currency.");
        }
        foreach($this->activeCurrencies() as $item){
            if(isset($item->code) && strtoupper($item->code) === $code){
                return $item;
            }
        }
        throw new \InvalidArgumentException("The selected currency is not active in Currency Configuration.");
    }

    public function resolveCurrencyCode($code = null){
        if($code === null || trim(strval($code)) === ""){
            return $this->defaultCurrency()->code;
        }
        return $this->requireActiveCurrency($code)->code;
    }

    public function formatAmount($amount, $code = null){
        $currency = ($code === null || trim(strval($code)) === "")
            ? $this->defaultCurrency()
            : $this->requireActiveCurrency($code);
        $places = isset($currency->decimalPlaces) ? intval($currency->decimalPlaces) : 2;
        $prefix = isset($currency->symbol) && trim($currency->symbol) !== "" ? $currency->symbol : $currency->code;
        return $prefix . " " . number_format(floatval($amount), $places, ".", ",");
    }

    public function getList($req, $res){
        try{
            return $this->listCurrencies();
        }catch(\Exception $error){
            $res->SetError("Unable to load currencies.");
            return null;
        }
    }

    public function getActive($req, $res){
        try{
            return $this->activeCurrencies();
        }catch(\Exception $error){
            if($res){
                $res->SetError($error->getMessage());
            }
            return array();
        }
    }

    public function getDefault($req, $res){
        try{
            return $this->defaultCurrency();
        }catch(\Exception $error){
            if($res){
                $res->SetError($error->getMessage());
            }
            return null;
        }
    }

    public function postSave($req, $res){
        $data = $this->normalize($req->Body(true));
        if($data === null){
            $res->SetError("Currency code must be a valid three-letter ISO 4217 code.");
            return null;
        }
        if($data->exchangeRate <= 0){
            $res->SetError("Exchange rate must be greater than zero.");
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
            $res->SetError("Unable to save currency.");
            return null;
        }
        return $data;
    }
}
?>
