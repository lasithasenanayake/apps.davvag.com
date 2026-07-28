<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/stripe/init.php");
require_once(TENANT_RESOURCE_LOCATION . "/apps/currency-configuration/services/currency-configuration-handler/service.php");

class Stripe_IPG {
    private function currencyCode($code){
        $currency = new \currency_configuration\CurrencyConfigurationService();
        return strtolower($currency->resolveCurrencyCode($code));
    }

    function __construct(){
        
    } 

    
    public function addCustomer($customerDetailsAry)
    {
        
        $customer = new Customer();
        
        $customerDetails = $customer->create($customerDetailsAry);
        
        return $customerDetails;
    }

    public function postchargeAmountFromCard($req,$res)
    {
        $userprofile=Profile::getUserProfile();
        if($userprofile->profile){
            $apiKey = "STRIPE_SECRET_KEY";
            $stripeService = new \Stripe\Stripe();
            $stripeService->setVerifySslCerts(false);
            $stripeService->setApiKey($apiKey);
            
            $customerDetailsAry = array(
                'email' => $userprofile->profile->email,
                'source' => $userprofile->profile->id
            );
            $customer = new Customer();
            $customerResult = $customer->create($customerDetailsAry);
            $charge = new Charge();
            $cardDetailsAry = array(
                'customer' => $customerResult->id,
                'amount' => $cardDetails['amount']*100 ,
                'currency' => $this->currencyCode(isset($cardDetails['currency_code']) ? $cardDetails['currency_code'] : null),
                'description' => $cardDetails['item_name'],
                'metadata' => array(
                    'order_id' => $cardDetails['item_number']
                )
            );
            $result = $charge->create($cardDetailsAry);

            return $result->jsonSerialize();
        }
        
    }


}

?>
