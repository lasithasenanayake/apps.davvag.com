<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["SERVER_PROTOCOL"] = "HTTP/1.1";
$_SERVER["REQUEST_URI"] = "/";

$bootstrap = getcwd() . DIRECTORY_SEPARATOR . "configloader.php";
if (!file_exists($bootstrap)) {
    throw new RuntimeException("Run this test from the DAVVAG framework root.");
}
require $bootstrap;

require_once dirname(__DIR__) . "/services/credit-admin-api/service-v2.php";

$service = new \davvag_credit_points\CreditAdminApiServiceV2();
$databaseMethod = new ReflectionMethod($service, "database");
$databaseMethod->setAccessible(true);
$database = $databaseMethod->invoke($service);

$packages = $database->all("SELECT p.*,product.name mapped_product_name FROM davvag_credit_package p LEFT JOIN products product ON product.itemid=p.product_id WHERE COALESCE(p.status,'')<>'DELETED' ORDER BY p.sort_order,p.id");
$rewards = $database->all("SELECT * FROM davvag_credit_reward_rule WHERE COALESCE(status,'')<>'DELETED' ORDER BY id");
$campaigns = $database->all("SELECT * FROM davvag_credit_coupon_campaign WHERE COALESCE(status,'')<>'DELETED' ORDER BY id");
$catalog = $database->all("SELECT itemid,name,caption,keywords,price,currencycode,imgurl,catogory,uom,status FROM products ORDER BY itemid DESC LIMIT ?", "i", array(1));

if (!is_array($packages) || !is_array($rewards) || !is_array($campaigns) || !is_array($catalog) || count($catalog) > 1) {
    throw new RuntimeException("Credit admin datastore queries returned an invalid result.");
}

echo "Credit admin datastore passed with " . count($packages) . " packages, " . count($rewards) . " rewards, " . count($campaigns) . " campaigns, and " . count($catalog) . " sampled products." . PHP_EOL;
?>
