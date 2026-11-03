<?php
$root = dirname(__DIR__);
$failures = array();

function expectTrue($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$app = json_decode(file_get_contents($root . "/app.json"));
expectTrue(isset($app->components->{"stelup-theme"}), "Shared theme component is not registered.");
expectTrue(in_array("stelup-theme", $app->configuration->webdock->onLoad, true), "Shared theme is not loaded at startup.");
expectTrue(in_array("orderdetails_dispatched", $app->dependencies->schemas, true), "Dispatched order detail schema is missing.");

$sellerService = file_get_contents($root . "/service/seller_svr/service.php");
expectTrue(strpos($sellerService, 'orderdetails_dispatched') !== false, "Dispatch does not persist dispatched order details.");
expectTrue(strpos($sellerService, 'case "cancel"') === false, "Unsafe cancel-to-dispatch branch is still present.");

$profileService = file_get_contents($root . "/service/p_svr/service.php");
expectTrue(strpos($profileService, '$res->SetError ($result)') === false, "Undefined profile-service error variable remains.");

$productService = file_get_contents($root . "/service/productsvr/service.php");
expectTrue(strpos($productService, '$requestedItem->price') === false, "Checkout still trusts a client-provided price.");
expectTrue(strpos($productService, '$req->SetError') === false, "Checkout errors are still sent through the request object.");

$loginManifest = json_decode(file_get_contents($root . "/service/login-handler/component.json"));
expectTrue($loginManifest->serviceHandler->methods->login->method === "POST", "Login still sends credentials in a GET request.");
expectTrue($loginManifest->serviceHandler->methods->resetPassword->method === "POST", "Password reset still sends credentials in a GET request.");

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . "/apps"));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === "html") {
        expectTrue(strpos(file_get_contents($file->getPathname()), "v-html") === false, "Unsafe v-html remains in " . $file->getPathname());
    }
}

if (count($failures) > 0) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Static regression checks passed." . PHP_EOL;
