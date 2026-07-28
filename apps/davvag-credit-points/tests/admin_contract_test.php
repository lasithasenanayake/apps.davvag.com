<?php
$root = dirname(__DIR__);
$tenant = dirname(dirname($root));
$passed = 0;
$failed = 0;

function admin_check($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: " . $message . PHP_EOL;
    } else {
        $failed++;
        echo "FAIL: " . $message . PHP_EOL;
    }
}

require_once $root . "/services/credit-admin-api/service-v2.php";

$descriptor = json_decode(file_get_contents($root . "/services/credit-admin-api/component.json"));
$serviceClass = "\\davvag_credit_points\\CreditAdminApiServiceV2";
admin_check(class_exists($serviceClass), "admin service class loads");

$reflection = new ReflectionClass($serviceClass);
foreach ($descriptor->serviceHandler->methods as $name => $configuration) {
    admin_check($reflection->hasMethod("post" . $name), "service handler implements " . $name);
}

$app = json_decode(file_get_contents($root . "/app.json"));
$routes = $app->configuration->webdock->routes->partials;
admin_check(isset($routes->{"/admin/packages"}) && $routes->{"/admin/packages"} === "package-admin", "package admin route is registered");
admin_check(isset($routes->{"/admin/rewards"}) && $routes->{"/admin/rewards"} === "reward-admin", "reward admin route is registered");
admin_check(isset($routes->{"/admin/coupons"}) && $routes->{"/admin/coupons"} === "coupon-admin", "coupon admin route is registered");
admin_check(in_array("productapp", $app->dependencies->apps, true), "product application dependency is declared");
admin_check(in_array("products", $app->dependencies->schemas, true), "product schema dependency is declared");

$schema = json_decode(file_get_contents($tenant . "/schemas/davvag_credit_package.json"));
$fieldNames = array_map(function ($field) { return $field->fieldName; }, $schema->fields);
admin_check(in_array("product_id", $fieldNames, true), "package schema declares product mapping");
$productRelation = isset($schema->relations) && count($schema->relations) > 0 ? $schema->relations[0] : null;
admin_check(
    $productRelation && $productRelation->targetEntity === "products" && $productRelation->joinColumns[0]->sourceColumn === "product_id" && $productRelation->joinColumns[0]->targetColumn === "itemid",
    "package schema maps product_id to products.itemid"
);

echo "RESULT: " . $passed . " passed, " . $failed . " failed" . PHP_EOL;
exit($failed ? 1 : 0);
?>
