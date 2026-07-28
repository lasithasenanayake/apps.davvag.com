<?php
namespace davvag_credit_points;
require_once __DIR__."/CreditLedgerService.php";
class CreditCurrencyMemoryResponse {private$error=null;public function SetError($error){$this->error=$error;}public function error(){return$this->error;}}
class CreditCurrencyAdapter {
    private function service(){if(!defined("TENANT_RESOURCE_LOCATION"))throw new CreditException("The active tenant is unavailable.");$file=TENANT_RESOURCE_LOCATION."/apps/currency-configuration/services/currency-configuration-handler/service.php";if(!file_exists($file))throw new CreditException("Currency Configuration is not installed.");require_once$file;if(!class_exists("\\currency_configuration\\CurrencyConfigurationService"))throw new CreditException("Currency Configuration could not be loaded.");return new \currency_configuration\CurrencyConfigurationService();}
    private function translate($callback){try{return $callback();}catch(\Exception $error){throw new CreditException($error->getMessage());}}
    public function active(){return $this->translate(function(){return $this->service()->activeCurrencies();});}
    public function defaultCurrency(){return $this->translate(function(){return $this->service()->defaultCurrency();});}
    public function requireActive($code){return $this->translate(function()use($code){return $this->service()->requireActiveCurrency($code);});}
}
?>
