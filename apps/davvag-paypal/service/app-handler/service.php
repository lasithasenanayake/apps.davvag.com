<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-order/davvag-order.php");
require_once(TENANT_RESOURCE_LOCATION . "/apps/currency-configuration/services/currency-configuration-handler/service.php");

class PayPal_IPG {
    private function currencyCode($code){
        $currency = new \currency_configuration\CurrencyConfigurationService();
        return $currency->resolveCurrencyCode($code);
    }

    public function getOrder($req, $res){
        $handler = new Davvag_Order();
        $userprofile = Profile::getUserProfile();
        $order = $handler->getOrder($_GET["id"]);
        if ($order == null || !$userprofile->profile || $order->profileId != $userprofile->profile->id){
            $res->SetError("Error Loading Order");
            return null;
        }

        $settings = $this->getPayPalSettings(isset($order->supplier_profileId) ? $order->supplier_profileId : 0);
        if (!isset($settings)){
            $res->SetError("There is no PayPal account mapped for this seller.");
            return null;
        }

        $payload = new stdClass();
        $payload->invoiceNo = $order->invoiceNo;
        $payload->email = isset($order->email) ? $order->email : "";
        $payload->name = isset($order->name) ? $order->name : "";
        $payload->balance = (float)$order->balance;
        $payload->currencycode = $this->currencyCode(isset($order->currencycode) ? $order->currencycode : (isset($settings->currencycode) ? $settings->currencycode : null));
        $payload->clientId = $settings->clientId;
        $payload->mode = isset($settings->mode) ? $settings->mode : "sandbox";
        $payload->brandName = isset($settings->brandName) ? $settings->brandName : "Davvag Store";
        $payload->supplier_profileId = isset($order->supplier_profileId) ? $order->supplier_profileId : 0;
        return $payload;
    }

    public function getPublicToken($req, $res){
        if (!isset($_GET["id"])){
            return null;
        }

        $settings = $this->getPayPalSettings($_GET["id"]);
        if (!isset($settings)){
            return null;
        }

        $payload = new stdClass();
        $payload->clientId = $settings->clientId;
        $payload->mode = isset($settings->mode) ? $settings->mode : "sandbox";
        $payload->currencycode = $this->currencyCode(isset($settings->currencycode) ? $settings->currencycode : null);
        $payload->brandName = isset($settings->brandName) ? $settings->brandName : "Davvag Store";
        return $payload;
    }

    public function postCreateOrder($req, $res){
        $body = $req->Body(true);
        $handler = new Davvag_Order();
        $userprofile = Profile::getUserProfile();
        $order = $handler->getOrder($body->id);

        if ($order == null || !$userprofile->profile || $order->profileId != $userprofile->profile->id){
            $res->SetError("Error Paying the given order");
            return null;
        }

        $settings = $this->getPayPalSettings(isset($order->supplier_profileId) ? $order->supplier_profileId : 0);
        if (!isset($settings)){
            $res->SetError("PayPal is not configured for this seller.");
            return null;
        }

        $requestBody = array(
            "intent" => "CAPTURE",
            "purchase_units" => array(
                array(
                    "invoice_id" => (string)$order->invoiceNo,
                    "description" => "Order No: " . $order->invoiceNo,
                    "amount" => array(
                        "currency_code" => $this->currencyCode(isset($order->currencycode) ? $order->currencycode : (isset($settings->currencycode) ? $settings->currencycode : null)),
                        "value" => number_format((float)$order->balance, 2, ".", "")
                    )
                )
            ),
            "application_context" => array(
                "brand_name" => isset($settings->brandName) ? $settings->brandName : "Davvag Store",
                "shipping_preference" => "NO_SHIPPING",
                "user_action" => "PAY_NOW"
            )
        );

        $result = $this->paypalRequest($settings, "/v2/checkout/orders", "POST", $requestBody);
        if (!$result->success){
            $res->SetError($result->message);
            return null;
        }

        return $result->body;
    }

    public function postCaptureOrder($req, $res){
        $body = $req->Body(true);
        $handler = new Davvag_Order();
        $userprofile = Profile::getUserProfile();
        $order = $handler->getOrder($body->id);

        if ($order == null || !$userprofile->profile || $order->profileId != $userprofile->profile->id){
            $res->SetError("Error Paying the given order");
            return null;
        }

        $settings = $this->getPayPalSettings(isset($order->supplier_profileId) ? $order->supplier_profileId : 0);
        if (!isset($settings)){
            $res->SetError("PayPal is not configured for this seller.");
            return null;
        }

        $capture = $this->paypalRequest($settings, "/v2/checkout/orders/" . rawurlencode($body->paypalOrderId) . "/capture", "POST", new stdClass());
        if (!$capture->success){
            $res->SetError($capture->message);
            return null;
        }

        $status = isset($capture->body->status) ? strtoupper($capture->body->status) : "";
        if ($status !== "COMPLETED"){
            $res->SetError("PayPal payment was not completed.");
            return null;
        }

        $captureId = "";
        if (isset($capture->body->purchase_units[0]->payments->captures[0]->id)){
            $captureId = $capture->body->purchase_units[0]->payments->captures[0]->id;
        }

        $receipt = $handler->PayOrder(
            $order->invoiceNo,
            $order->balance,
            "Paid via PayPal. order :[" . $body->paypalOrderId . "] capture :[" . $captureId . "]",
            "PayPal IPG",
            $captureId
        );

        if ($receipt){
            $handler->AcceptOrder($order->invoiceNo);
        }

        return $receipt;
    }

    private function getPayPalSettings($id){
        $cache = CacheData::getObjects($id, "davvag_paypal");
        if ($cache){
            return $cache;
        }

        $q = SOSSData::Query("davvag_paypal", "id:" . $id);
        if ($q->success && count($q->result) > 0){
            CacheData::setObjects($id, "davvag_paypal", $q->result[0]);
            return $q->result[0];
        }

        return null;
    }

    private function paypalRequest($settings, $path, $method, $payload = null){
        $token = $this->getAccessToken($settings);
        if (!$token->success){
            return $token;
        }

        return $this->httpRequest(
            $this->getBaseUrl($settings) . $path,
            $method,
            array(
                "Content-Type: application/json",
                "Authorization: Bearer " . $token->token,
                "PayPal-Request-Id: " . uniqid("davvag_", true)
            ),
            $payload === null ? null : json_encode($payload)
        );
    }

    private function getAccessToken($settings){
        $headers = array(
            "Accept: application/json",
            "Accept-Language: en_US",
            "Authorization: Basic " . base64_encode($settings->clientId . ":" . $settings->secret)
        );

        $result = $this->httpRequest(
            $this->getBaseUrl($settings) . "/v1/oauth2/token",
            "POST",
            $headers,
            "grant_type=client_credentials",
            "application/x-www-form-urlencoded"
        );

        if (!$result->success){
            return $result;
        }

        if (!isset($result->body->access_token)){
            $error = new stdClass();
            $error->success = false;
            $error->message = "Unable to authenticate with PayPal.";
            return $error;
        }

        $success = new stdClass();
        $success->success = true;
        $success->token = $result->body->access_token;
        return $success;
    }

    private function httpRequest($url, $method, $headers, $body = null, $contentType = null){
        $requestHeaders = $headers;
        if ($body !== null && $contentType !== null){
            $requestHeaders[] = "Content-Type: " . $contentType;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== null){
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = new stdClass();
        $result->success = false;
        $result->status = $status;
        $result->body = json_decode($response);
        $result->message = $this->resolvePayPalError($result->body, $error, $status);

        if ($error){
            return $result;
        }

        if ($status >= 200 && $status < 300){
            $result->success = true;
        }

        return $result;
    }

    private function resolvePayPalError($body, $curlError, $status){
        if ($curlError){
            return $curlError;
        }

        if (isset($body->details[0]->description)){
            return $body->details[0]->description;
        }

        if (isset($body->message)){
            return $body->message;
        }

        if ($status >= 200 && $status < 300){
            return "";
        }

        return "PayPal request failed.";
    }

    private function getBaseUrl($settings){
        $mode = isset($settings->mode) ? strtolower($settings->mode) : "sandbox";
        if ($mode === "live"){
            return "https://api-m.paypal.com";
        }
        return "https://api-m.sandbox.paypal.com";
    }
}
?>
