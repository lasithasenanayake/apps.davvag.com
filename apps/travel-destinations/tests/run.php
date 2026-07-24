<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once(dirname(__DIR__) . "/services/api/service.php");

use travel_destinations\TravelDestinationRules;

$failures = array();
$checks = 0;

function checkTravel($condition, $message) {
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

checkTravel(TravelDestinationRules::validCoordinates(7.2906, 80.6337), "Valid coordinates were rejected.");
checkTravel(TravelDestinationRules::validCoordinates(-90, 180), "Coordinate boundaries were rejected.");
checkTravel(!TravelDestinationRules::validCoordinates(91, 80), "Invalid latitude was accepted.");
checkTravel(!TravelDestinationRules::validCoordinates(7, -181), "Invalid longitude was accepted.");
checkTravel(TravelDestinationRules::validRadius(25), "Allowed radius was rejected.");
checkTravel(!TravelDestinationRules::validRadius(7), "Arbitrary radius was accepted.");
checkTravel(TravelDestinationRules::slug("Knuckles Mountain Range!") === "knuckles-mountain-range", "Slug normalization failed.");
$safeMarkdown = TravelDestinationRules::plainMarkdown("<script>alert(1)</script> **safe**", 100);
checkTravel(strpos($safeMarkdown, "<script>") === false, "Unsafe HTML was retained.");
$distance = TravelDestinationRules::distanceKm(6.9271, 79.8612, 7.2906, 80.6337);
checkTravel($distance > 85 && $distance < 110, "Haversine distance was outside the expected Colombo-Kandy range.");
checkTravel(TravelDestinationRules::inBoundingBox(7.2906, 80.6337, 6.9271, 79.8612, 100), "A point inside the broad bounding box was rejected.");
checkTravel(!TravelDestinationRules::inBoundingBox(7.2906, 80.6337, 6.9271, 79.8612, 25), "A point outside the narrow bounding box was accepted.");
$urlCoordinates = TravelDestinationRules::coordinatesFromMapUrl("https://www.google.com/maps/place/Test/@7.2906123,80.6337456,16z");
checkTravel($urlCoordinates !== null && abs($urlCoordinates["latitude"] - 7.2906123) < 0.0000001 && abs($urlCoordinates["longitude"] - 80.6337456) < 0.0000001, "Google Maps @ coordinates were not extracted precisely.");
$dataCoordinates = TravelDestinationRules::coordinatesFromMapUrl("https://www.google.com/maps/place/Test/data=!3d6.927079!4d79.861244");
checkTravel($dataCoordinates !== null && abs($dataCoordinates["latitude"] - 6.927079) < 0.0000001 && abs($dataCoordinates["longitude"] - 79.861244) < 0.0000001, "Google Maps data coordinates were not extracted.");
$queryCoordinates = TravelDestinationRules::coordinatesFromMapUrl("https://maps.google.com/?q=6.053519,80.220977");
checkTravel($queryCoordinates !== null && abs($queryCoordinates["longitude"] - 80.220977) < 0.0000001, "Google Maps query coordinates were not extracted.");
checkTravel(TravelDestinationRules::coordinatesFromMapUrl("https://example.com/no-location") === null, "An unrelated URL produced coordinates.");

$appRoot = dirname(__DIR__);
$tenantRoot = dirname($appRoot, 2);
$app = json_decode(file_get_contents($appRoot . "/app.json"));
checkTravel(is_object($app), "app.json did not parse.");
checkTravel(isset($app->configuration->webdock->startupComponent), "Startup component is missing.");
checkTravel(isset($app->components->{$app->configuration->webdock->startupComponent}), "Startup component is not declared.");

foreach ($app->components as $name => $component) {
    $descriptorPath = $appRoot . "/" . $component->location . "/" . $name . "/component.json";
    checkTravel(file_exists($descriptorPath), "Missing descriptor for " . $name . ".");
    if (!file_exists($descriptorPath)) {
        continue;
    }
    $descriptor = json_decode(file_get_contents($descriptorPath));
    checkTravel(is_object($descriptor), "Invalid descriptor for " . $name . ".");
    if (isset($descriptor->resources)) {
        $resources = array_merge(isset($descriptor->resources->files) ? $descriptor->resources->files : array(), isset($descriptor->resources->css) ? $descriptor->resources->css : array());
        foreach ($resources as $resource) {
            checkTravel(file_exists(dirname($descriptorPath) . "/" . $resource->location), "Missing resource " . $name . "/" . $resource->location . ".");
        }
    }
}

$detailScript = file_get_contents($appRoot . "/components/destination-detail/script.js");
$detailView = file_get_contents($appRoot . "/components/destination-detail/partial.html");
checkTravel(strpos($detailScript, "function locked(key, factory, handleResponse, failureMessage)") !== false, "Detail actions do not use the single-callback DAVVAG request lock.");
checkTravel(!preg_match('/return\s+api\.services\.[A-Za-z0-9_]+\([^;]*\)\.then\(/', $detailScript), "A detail action attaches a response handler before the request lock and may be overwritten.");
checkTravel(strpos($detailView, "actionMessage") !== false, "Review and comment moderation feedback is not rendered.");

$mapRuntime = file_get_contents($appRoot . "/components/google-map-runtime/script.js");
$mapSettingsView = file_get_contents($appRoot . "/components/admin-map-settings/partial.html");
$formScript = file_get_contents($appRoot . "/components/destination-form/script.js");
$explorerScript = file_get_contents($appRoot . "/components/destination-explorer/script.js");
$apiDescriptor = json_decode(file_get_contents($appRoot . "/services/api/component.json"));
checkTravel(strpos($mapRuntime, "maps.googleapis.com/maps/api/js") !== false, "Google Maps runtime does not load the official Maps JavaScript API.");
checkTravel(strpos($mapRuntime, "AdvancedMarkerElement") !== false, "Google Maps runtime does not support advanced markers.");
checkTravel(strpos($mapRuntime, "DEMO_MAP_ID") !== false, "Google Maps runtime has no Advanced Marker Map ID fallback.");
checkTravel(strpos($mapRuntime, "new window.google.maps.Marker") === false, "Google Maps runtime still creates deprecated legacy markers.");
checkTravel(strpos($mapRuntime, "waitForContainer") !== false, "Google Maps runtime does not wait for a visible map container.");
checkTravel(strpos($mapRuntime, "PinElement") !== false && strpos($mapRuntime, "uniquePositionCount") !== false, "Map results do not use numbered Advanced Markers safely.");
checkTravel(strpos($mapSettingsView, "HTTP referrers") !== false, "Map settings do not explain browser API-key restrictions.");
checkTravel(strpos($formScript, "onPositionChanged") !== false && strpos($formScript, "onMapClick") !== false, "Destination form does not support draggable and click location selection.");
checkTravel(strpos($explorerScript, "GetMapConfiguration") !== false, "Explorer does not load the saved map configuration.");
checkTravel(strpos($formScript, "ResolveMapLocationUrl") !== false && strpos($formScript, "coordinatesFromMapUrl") !== false, "Destination form does not extract coordinates from Google Maps URLs.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetMapConfiguration), "Public map configuration service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetAdminMapSettings), "Admin map settings read service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->SaveMapSettings), "Admin map settings save service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->ResolveMapLocationUrl), "Map URL resolver service is not declared.");
checkTravel(in_array("travel_destination_map_settings", $app->dependencies->schemas, true), "Map settings schema dependency is missing.");
checkTravel(in_array("travel_destination_description_chunk", $app->dependencies->schemas, true), "Large description chunk schema dependency is missing.");
checkTravel(strpos(file_get_contents($tenantRoot . "/schemas/travel_destination_description_chunk.json"), "content_utf8mb4") !== false, "Large descriptions must use utf8mb4-safe chunk storage.");
$formView = file_get_contents($appRoot . "/components/destination-form/partial.html");
checkTravel(strpos($formView, 'maxlength="250000"') !== false, "Destination description input is not configured for 250,000 characters.");
checkTravel(strpos(file_get_contents($appRoot . "/services/api/service.php"), "syncDestinationDescription") !== false, "Large destination descriptions are not persisted in safe chunks.");
$permissionManifest = json_decode(file_get_contents($appRoot . "/permissions.json"));
checkTravel(!in_array("ResolveMapLocationUrl", $permissionManifest->anonymous, true), "Anonymous users can access the map URL resolver.");
checkTravel(in_array("ResolveMapLocationUrl", $permissionManifest->web_user, true), "Authenticated travelers cannot access the map URL resolver.");

putenv("DAVVAG_PROVIDER_SECRET=travel-destination-test-secret");
$apiService = new \travel_destinations\ApiService();
$apiReflection = new ReflectionClass($apiService);
$encryptSecret = $apiReflection->getMethod("encryptProviderSecret");
$encryptSecret->setAccessible(true);
$decryptSecret = $apiReflection->getMethod("providerValue");
$decryptSecret->setAccessible(true);
$encryptedKey = $encryptSecret->invoke($apiService, "AIza-test-browser-key-value");
$encryptedItem = new stdClass();
$encryptedItem->api_key_enc = $encryptedKey;
checkTravel($decryptSecret->invoke($apiService, $encryptedItem, "api_key_enc") === "AIza-test-browser-key-value", "Map API-key encryption round trip failed.");
checkTravel(strpos(json_encode($encryptedKey), "AIza-test-browser-key-value") === false, "Map API key was retained as plaintext in encrypted storage.");
putenv("DAVVAG_PROVIDER_SECRET");

$systemFields = array("sysversionid", "syscreated", "sysupdated", "sysviewobject", "syscreatedby", "syslastupdatedby");
foreach ($app->dependencies->schemas as $namespace) {
    $schemaPath = $tenantRoot . "/schemas/" . $namespace . ".json";
    checkTravel(file_exists($schemaPath), "Missing schema " . $namespace . ".");
    if (!file_exists($schemaPath)) {
        continue;
    }
    $schema = json_decode(file_get_contents($schemaPath));
    checkTravel(is_object($schema), "Invalid schema " . $namespace . ".");
    if (isset($schema->fields)) {
        foreach ($schema->fields as $field) {
            checkTravel(!in_array($field->fieldName, $systemFields, true), "Framework system field was manually declared in " . $namespace . ".");
        }
    }
}

foreach (array("tenant.json", "anonymous.json", "web_user.json", "sysadmin.json") as $groupFile) {
    $group = json_decode(file_get_contents($tenantRoot . "/" . $groupFile));
    checkTravel(isset($group->apps->{"travel-destinations"}), "App registration missing from " . $groupFile . ".");
}

echo "Checks: " . $checks . PHP_EOL;
echo "Failures: " . count($failures) . PHP_EOL;
foreach ($failures as $failure) {
    echo "- " . $failure . PHP_EOL;
}
exit(count($failures) === 0 ? 0 : 1);
?>
