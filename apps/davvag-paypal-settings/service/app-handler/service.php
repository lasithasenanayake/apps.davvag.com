<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");

class PayPal_Settings_IPG {

    public function postSave($req, $res){
        require_once(PLUGIN_PATH_LOCAL . "/davvag-ipg/davvag-ipg.php");
        $data = $req->Body(true);
        $storeProfile = Profile::getProfile(0, 0);
        $data->id = $storeProfile->profile ? $storeProfile->profile->id : 0;

        if (!isset($data->mode) || $data->mode === ""){
            $data->mode = "sandbox";
        }
        if (!isset($data->currencycode) || $data->currencycode === ""){
            $data->currencycode = "USD";
        }
        if (!isset($data->brandName) || $data->brandName === ""){
            $data->brandName = "Davvag Store";
        }

        $q = SOSSData::Query("davvag_paypal", "id:" . $data->id);
        if (!$q->success){
            $res->SetError($q);
            return null;
        }

        if (count($q->result) === 0){
            SOSSData::Insert("davvag_paypal", $data);
        } else {
            SOSSData::Update("davvag_paypal", $data);
        }

        Davvag_IPG::SaveNewIPG(
            "davvag-paypal",
            $data->id,
            "PayPal",
            "PayPal online payments for store checkout",
            "https://www.paypal.com",
            "assets/davvag-paypal/paypal.svg"
        );
        CacheData::clearObjects("davvag_paypal");
        return $data;
    }

    public function getPublicToken($req, $res){
        $q = SOSSData::Query("davvag_paypal", "id:" . $_GET["id"]);
        if ($q->success && count($q->result) > 0){
            return $q->result[0];
        }
        return null;
    }
}
?>
