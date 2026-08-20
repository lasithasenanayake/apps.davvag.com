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

$destinationSchema = json_decode(file_get_contents($tenantRoot . "/schemas/travel_destination.json"));
$coordinateFields = array();
foreach ($destinationSchema->fields as $field) {
    $coordinateFields[$field->fieldName] = $field;
}
checkTravel(isset($coordinateFields["latitude"]) && $coordinateFields["latitude"]->dataType === "decimal" && $coordinateFields["latitude"]->annotations->decimalPoints === "9,7", "Latitude must use DECIMAL(9,7).");
checkTravel(isset($coordinateFields["longitude"]) && $coordinateFields["longitude"]->dataType === "decimal" && $coordinateFields["longitude"]->annotations->decimalPoints === "10,7", "Longitude must use DECIMAL(10,7).");

$frameworkRoot = getcwd();
$connectorPath = $frameworkRoot . "/plugins/sossdata/phpmysql/mysqlConnector.php";
checkTravel(file_exists($connectorPath), "The phpmysql connector was not found from the framework root.");
require_once($connectorPath);
$connectorReflection = new ReflectionClass("mysqlConnector");
$connector = $connectorReflection->newInstanceWithoutConstructor();
$decimalField = (object)array("dataType" => "decimal");
$dateField = (object)array("dataType" => "java.util.Date");
$readValue = $connectorReflection->getMethod("getValueToObject");
$readValue->setAccessible(true);
$writeValue = $connectorReflection->getMethod("getValue");
$writeValue->setAccessible(true);
checkTravel(abs($readValue->invoke($connector, $decimalField, "80.6337456") - 80.6337456) < 0.0000001, "SOSSData decimal reads are being converted as dates.");
checkTravel(abs($writeValue->invoke($connector, $decimalField, "80.6337456") - 80.6337456) < 0.0000001, "SOSSData decimal writes are being converted as dates.");
checkTravel($readValue->invoke($connector, $decimalField, null) === null && $writeValue->invoke($connector, $decimalField, null) === "NULL", "SOSSData decimal null handling is invalid.");
checkTravel($readValue->invoke($connector, $dateField, "2026-07-29 08:40:00") === "07-29-2026 08:40:00", "SOSSData date formatting changed while fixing decimals.");

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
$detailStyles = file_get_contents($appRoot . "/components/destination-detail/destination-detail.css");
checkTravel(strpos($detailScript, "function locked(key, factory, handleResponse, failureMessage)") !== false, "Detail actions do not use the single-callback DAVVAG request lock.");
checkTravel(!preg_match('/return\s+api\.services\.[A-Za-z0-9_]+\([^;]*\)\.then\(/', $detailScript), "A detail action attaches a response handler before the request lock and may be overwritten.");
checkTravel(strpos($detailView, "actionMessage") !== false, "Review and comment moderation feedback is not rendered.");
checkTravel(strpos($detailScript, "isTableSeparator") !== false && strpos($detailScript, "renderTable") !== false, "Destination Markdown tables are not rendered.");
checkTravel(strpos($detailView, "td-markdown") !== false, "Destination Markdown content has no dedicated formatting class.");
checkTravel(strpos($detailScript, "function externalHttpUrl") !== false && strpos($detailScript, 'target="_blank" rel="noopener noreferrer external"') !== false, "Destination Markdown links are not rendered as safe new-tab links.");
checkTravel(strpos($detailScript, "td-markdown-link") !== false && strpos($detailStyles, ".td-markdown-link") !== false, "Destination Markdown links have no visible link treatment.");
checkTravel(strpos($detailScript, "safeMediaReference") !== false && strpos($detailView, "td-detail-cover") !== false && strpos($detailStyles, ".td-detail-cover") !== false, "Approved destination media is not rendered as the detail cover.");
checkTravel(strpos($detailScript, 'openForm: ""') !== false && substr_count($detailView, 'v-show="openForm ===') >= 6, "Destination contribution forms are not collapsed behind explicit triggers.");
checkTravel(substr_count($detailView, 'aria-expanded="openForm ===') >= 6 && strpos($detailView, "td-collapsible-form") !== false, "Expandable destination forms are missing accessible state controls.");
checkTravel(strpos($detailStyles, "@media (max-width: 620px)") !== false && strpos($detailStyles, "overflow-x: hidden") !== false && strpos($detailStyles, ".td-detail-hero h1") !== false, "Destination detail has no narrow-screen overflow and typography treatment.");
checkTravel(strpos($detailStyles, ".td-collapsible-form.td-form-grid") !== false && strpos($detailStyles, ".td-inline-control") !== false, "Destination forms do not collapse to a single mobile column.");
checkTravel(strpos($detailScript, "Vue.nextTick") !== false && strpos($detailScript, "queueMapRender") !== false, "Destination detail does not wait for its map container.");

$mapRuntime = file_get_contents($appRoot . "/components/google-map-runtime/script.js");
$mapSettingsView = file_get_contents($appRoot . "/components/admin-map-settings/partial.html");
$formScript = file_get_contents($appRoot . "/components/destination-form/script.js");
$explorerScript = file_get_contents($appRoot . "/components/destination-explorer/script.js");
$travelStyles = file_get_contents($appRoot . "/components/travel-style/travel-destinations.css");
$apiDescriptor = json_decode(file_get_contents($appRoot . "/services/api/component.json"));
checkTravel(strpos($mapRuntime, "maps.googleapis.com/maps/api/js") !== false, "Google Maps runtime does not load the official Maps JavaScript API.");
checkTravel(strpos($mapRuntime, "AdvancedMarkerElement") !== false, "Google Maps runtime does not support advanced markers.");
checkTravel(strpos($mapRuntime, "DEMO_MAP_ID") !== false, "Google Maps runtime has no Advanced Marker Map ID fallback.");
checkTravel(strpos($mapRuntime, "new window.google.maps.Marker") === false, "Google Maps runtime still creates deprecated legacy markers.");
checkTravel(strpos($mapRuntime, "waitForContainer") !== false, "Google Maps runtime does not wait for a visible map container.");
checkTravel(strpos($mapRuntime, "gm_authFailure") !== false && strpos($mapRuntime, "timed out while loading") !== false, "Google Maps authentication and loading failures are not surfaced.");
checkTravel(strpos($mapRuntime, "PinElement") !== false && strpos($mapRuntime, "uniquePositionCount") !== false, "Map results do not use numbered Advanced Markers safely.");
checkTravel(strpos($mapSettingsView, "HTTP referrers") !== false, "Map settings do not explain browser API-key restrictions.");
checkTravel(strpos($formScript, "onPositionChanged") !== false && strpos($formScript, "onMapClick") !== false, "Destination form does not support draggable and click location selection.");
checkTravel(strpos($explorerScript, "GetMapConfiguration") !== false, "Explorer does not load the saved map configuration.");
checkTravel(strpos($explorerScript, 'pageSize: 20') !== false, "Explorer does not load destinations in twenty-place pages.");
checkTravel(strpos($explorerScript, "function hasMoreResults()") !== false, "Explorer load-more visibility does not fall back to the result total.");
checkTravel(substr_count($explorerScript, "api.services.SearchDestinations(requestFilters())") === 2, "List and map views do not share the same paginated search source.");
checkTravel(strpos($explorerScript, "function hasMapLocation(item)") !== false, "Explorer does not validate public map coordinates before plotting.");
$explorerView = file_get_contents($appRoot . "/components/destination-explorer/partial.html");
checkTravel(substr_count($explorerView, '@click="loadMore"') === 2, "Explorer must expose load-more controls in both list and map views.");
checkTravel(strpos($explorerView, "td-load-more-map") !== false, "Map view does not keep its load-more control with the mapped result list.");
checkTravel(strpos($explorerView, 'v-for="item in items"') !== false, "Map view hides matching destinations that lack public coordinates.");
checkTravel(strpos($explorerView, "Public map location unavailable") !== false, "Map view does not explain why a matching destination has no marker.");
checkTravel(strpos($formScript, "ResolveMapLocationUrl") !== false && strpos($formScript, "coordinatesFromMapUrl") !== false, "Destination form does not extract coordinates from Google Maps URLs.");
checkTravel(strpos($formScript, "prepareUploadNames") !== false && strpos($formScript, "file.uploadName") !== false && strpos($formScript, "file.status === true") !== false, "Destination photos do not follow the DAVVAG uploader result contract.");
checkTravel(strpos($formScript, "associateUploadedPhoto") !== false && strpos($formScript, "response && response.success && response.result") !== false, "Destination photo associations can report success after a failed service response.");
checkTravel(strpos($travelStyles, ".td-prose-table") !== false && strpos($travelStyles, "overflow-x:auto") !== false, "Markdown tables do not have responsive table styles.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetMapConfiguration), "Public map configuration service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetAdminMapSettings), "Admin map settings read service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->SaveMapSettings), "Admin map settings save service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->ResolveMapLocationUrl), "Map URL resolver service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetDestinationWeather), "Public destination weather service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetAdminWeatherSettings), "Admin weather settings read service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->SaveWeatherSettings), "Admin weather settings save service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetAiConfiguration), "Traveler AI configuration service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->EnrichDestination), "Destination AI enrichment service is not declared.");
checkTravel(isset($apiDescriptor->serviceHandler->methods->GetAdminAiSettings) && isset($apiDescriptor->serviceHandler->methods->SaveAiSettings), "Administrator AI settings services are not declared.");
checkTravel(in_array("travel_destination_map_settings", $app->dependencies->schemas, true), "Map settings schema dependency is missing.");
checkTravel(in_array("travel_destination_description_chunk", $app->dependencies->schemas, true), "Large description chunk schema dependency is missing.");
checkTravel(in_array("travel_destination_weather_settings", $app->dependencies->schemas, true), "Weather settings schema dependency is missing.");
checkTravel(in_array("travel_destination_ai_settings", $app->dependencies->schemas, true), "AI settings schema dependency is missing.");
checkTravel(strpos(file_get_contents($tenantRoot . "/schemas/travel_destination_ai_settings.json"), '"decimalPoints": "8,2"') !== false, "AI minimum confidence does not declare DECIMAL(8,2) precision.");
checkTravel(in_array("ai-agent-creator", $app->dependencies->apps, true), "AI Agent Creator app dependency is missing.");
checkTravel(strpos(file_get_contents($tenantRoot . "/schemas/travel_destination_description_chunk.json"), "content_utf8mb4") !== false, "Large descriptions must use utf8mb4-safe chunk storage.");
$formView = file_get_contents($appRoot . "/components/destination-form/partial.html");
checkTravel(strpos($formView, 'maxlength="250000"') !== false, "Destination description input is not configured for 250,000 characters.");
checkTravel(strpos($formScript, "savedMedia") !== false && strpos($formView, "td-photo-preview") !== false, "The destination form does not show media attached to the current destination.");
checkTravel(strpos($formScript, "EnrichDestination") !== false && strpos($formScript, "applyAiDestination") !== false && strpos($formView, "Autofill with AI") !== false, "The destination form does not expose guarded AI autofill.");
checkTravel(strpos($formView, 'capabilities.sysadmin === true') !== false, "The disabled AI settings notice is not restricted explicitly to sysadmin users.");
$apiServiceSource = file_get_contents($appRoot . "/services/api/service.php");
checkTravel(strpos($apiServiceSource, "syncDestinationDescription") !== false, "Large destination descriptions are not persisted in safe chunks.");
checkTravel(strpos($apiServiceSource, '$mapOnly && !TravelDestinationRules::validCoordinates') !== false, "Map pagination includes destinations without public coordinates.");
checkTravel(strpos($apiServiceSource, '$uploadPrefix = "components/dock/soss-uploader/service/get/travel_destination_media/"') !== false && strpos($apiServiceSource, "destinationMedia") !== false, "Destination media associations are not restricted and returned to their owner or administrator.");
checkTravel(strpos($apiServiceSource, "private function advancedRows") !== false && strpos($apiServiceSource, '\\SOSSData::Query($namespace, $query)') !== false, "Travel searches do not use the public SOSSData advanced-query contract.");
checkTravel(strpos($apiServiceSource, '$this->destinationSearchSorting($sort)') !== false && strpos($apiServiceSource, '$this->advancedCondition("status", "=", "Published")') !== false, "Destination search does not push validated conditions and sorting into AdvancedQuery.");
$weatherSettingsView = file_get_contents($appRoot . "/components/admin-weather-settings/partial.html");
checkTravel(strpos($weatherSettingsView, "CC BY 4.0") !== false && strpos($weatherSettingsView, "non-commercial") !== false, "Weather settings do not disclose provider licence constraints.");
checkTravel(strpos($detailScript, "GetDestinationWeather") !== false && strpos($detailView, "weather.provider.attributionUrl") !== false, "Destination details do not load and attribute optional weather data.");
$permissionManifest = json_decode(file_get_contents($appRoot . "/permissions.json"));
checkTravel(!in_array("ResolveMapLocationUrl", $permissionManifest->anonymous, true), "Anonymous users can access the map URL resolver.");
checkTravel(in_array("ResolveMapLocationUrl", $permissionManifest->web_user, true), "Authenticated travelers cannot access the map URL resolver.");
checkTravel(in_array("GetDestinationWeather", $permissionManifest->anonymous, true), "Anonymous users cannot access public destination weather.");
checkTravel(in_array("GetAdminWeatherSettings", $permissionManifest->sysadmin, true) && in_array("SaveWeatherSettings", $permissionManifest->sysadmin, true), "Weather settings are not restricted to the administrator permission manifest.");
checkTravel(!in_array("EnrichDestination", $permissionManifest->anonymous, true), "Anonymous users can invoke destination AI enrichment.");
checkTravel(in_array("GetAiConfiguration", $permissionManifest->web_user, true) && in_array("EnrichDestination", $permissionManifest->web_user, true), "Authenticated travelers cannot use destination AI enrichment.");
checkTravel(in_array("GetAdminAiSettings", $permissionManifest->sysadmin, true) && in_array("SaveAiSettings", $permissionManifest->sysadmin, true), "AI settings are not restricted to the administrator permission manifest.");
checkTravel(in_array("AssociateDestinationMedia", $permissionManifest->sysadmin, true), "Administrators cannot attach uploaded photos to destinations.");

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

$normalizeWeather = $apiReflection->getMethod("normalizeWeatherResponse");
$normalizeWeather->setAccessible(true);
$weatherPayload = json_decode('{"timezone":"Asia/Colombo","current":{"time":"2026-07-28T12:00","temperature_2m":27.4,"apparent_temperature":30.1,"precipitation":0.2,"weather_code":61,"wind_speed_10m":12.3,"wind_gusts_10m":24.6,"visibility":8000},"current_units":{"temperature_2m":"°C","precipitation":"mm","wind_speed_10m":"km/h","visibility":"m"},"daily":{"time":["2026-07-28","2026-07-29"],"weather_code":[61,2],"temperature_2m_max":[29.5,30.1],"temperature_2m_min":[23.2,22.8],"precipitation_sum":[4.5,0.2],"precipitation_probability_max":[80,20],"sunrise":["2026-07-28T06:01","2026-07-29T06:01"],"sunset":["2026-07-28T18:28","2026-07-29T18:28"],"wind_speed_10m_max":[18.2,14.0],"wind_gusts_10m_max":[31.0,25.0]},"daily_units":{"precipitation_probability_max":"%"}}');
$normalizedWeather = $normalizeWeather->invoke($apiService, $weatherPayload, array("forecast_days" => 2, "temperature_unit" => "celsius", "wind_speed_unit" => "kmh"));
checkTravel(is_array($normalizedWeather) && $normalizedWeather["available"] === true, "Valid weather provider payload was not normalized.");
checkTravel($normalizedWeather["current"]["summary"] === "Light rain", "Weather-code summary normalization failed.");
checkTravel(count($normalizedWeather["forecast"]) === 2 && $normalizedWeather["forecast"][0]["sunrise"] === "2026-07-28T06:01", "Daily forecast or sunrise normalization failed.");
checkTravel($normalizedWeather["provider"]["name"] === "Open-Meteo" && $normalizedWeather["provider"]["licence"] === "CC BY 4.0", "Weather provider attribution is incomplete.");

$decodeAiReply = $apiReflection->getMethod("decodeAiDestinationReply");
$decodeAiReply->setAccessible(true);
$sanitizeAiDestination = $apiReflection->getMethod("sanitizeAiDestination");
$sanitizeAiDestination->setAccessible(true);
$decodedAiReply = $decodeAiReply->invoke($apiService, "```json\n{\"known\":true,\"confidence\":0.91,\"destination\":{\"short_summary\":\"Known place\",\"latitude\":7.3,\"longitude\":80.6,\"category_names\":[\"Hiking\"],\"unexpected_admin_field\":\"unsafe\"}}\n```");
$safeAiDestination = $sanitizeAiDestination->invoke($apiService, $decodedAiReply, 0.75);
checkTravel($safeAiDestination["known"] === true && $safeAiDestination["destination"]["short_summary"] === "Known place", "A valid structured AI destination reply was not accepted.");
checkTravel(!isset($safeAiDestination["destination"]["unexpected_admin_field"]), "AI destination enrichment accepted a field outside its allowlist.");
$lowConfidenceAiDestination = $sanitizeAiDestination->invoke($apiService, array("known" => true, "confidence" => 0.4, "destination" => array("short_summary" => "Guess")), 0.75);
checkTravel($lowConfidenceAiDestination["known"] === false && count((array)$lowConfidenceAiDestination["destination"]) === 0, "Low-confidence AI destination data was accepted.");

$legacyEqualityConditions = $apiReflection->getMethod("legacyEqualityConditions");
$legacyEqualityConditions->setAccessible(true);
$advancedConditions = $legacyEqualityConditions->invoke($apiService, "destination_id:42,moderation_status:Approved,is_active:1");
checkTravel(count($advancedConditions) === 3 && $advancedConditions[1]["column"] === "moderation_status" && $advancedConditions[1]["value"] === "Approved", "Legacy equality filters were not converted to AdvancedQuery conditions.");
$destinationSearchSorting = $apiReflection->getMethod("destinationSearchSorting");
$destinationSearchSorting->setAccessible(true);
$ratingSorting = $destinationSearchSorting->invoke($apiService, "highest_rated");
checkTravel($ratingSorting[0]["column"] === "rating_average" && $ratingSorting[0]["direction"] === "DESC", "Destination sort was not converted to an AdvancedQuery sorting descriptor.");

$phaseTwoSchemas = array("travel_destination_route","travel_destination_list","travel_destination_list_item","travel_destination_visit","travel_destination_guide","travel_destination_guide_destination","travel_destination_availability","travel_destination_notification_preference","travel_destination_translation","travel_destination_collection","travel_destination_collection_item","travel_destination_trip","travel_destination_trip_item");
foreach ($phaseTwoSchemas as $namespace) {
    checkTravel(in_array($namespace, $app->dependencies->schemas, true), "Phase 2 schema dependency is missing: " . $namespace . ".");
}
$publicPhaseTwo = array("GetDestinationRoutes","GetOfflineDestinationBundle","GetDestinationVisitSummary","GetDestinationAvailability","GetDestinationGuides","GetSearchSuggestions","GetFeaturedCollections","GetCollection","GetDestinationTranslations");
$travelerPhaseTwo = array("GetRecommendations","SaveDestinationRoute","SubmitVerifiedVisit","GetMyVisits","SaveTravelList","DeleteTravelList","AddDestinationToList","RemoveDestinationFromList","GetMyTravelLists","SaveGuideProfile","GetMyGuideProfile","GetNotificationPreferences","SaveNotificationPreferences","SaveTrip","DeleteTrip","AddTripDestination","RemoveTripDestination","GetMyTrips");
$adminPhaseTwo = array("ModerateDestinationRoute","ModerateVerifiedVisit","VerifyGuideProfile","SaveDestinationAvailability","SaveDestinationTranslation","SaveFeaturedCollection","GetPhaseTwoAdminData");
foreach (array_merge($publicPhaseTwo,$travelerPhaseTwo,$adminPhaseTwo) as $method) {
    checkTravel(isset($apiDescriptor->serviceHandler->methods->{$method}), "Phase 2 service is not declared: " . $method . ".");
}
foreach ($publicPhaseTwo as $method) { checkTravel(in_array($method,$permissionManifest->anonymous,true), "Anonymous public Phase 2 permission is missing: " . $method . "."); }
foreach (array_merge($publicPhaseTwo,$travelerPhaseTwo) as $method) { checkTravel(in_array($method,$permissionManifest->web_user,true), "Traveler Phase 2 permission is missing: " . $method . "."); }
foreach ($adminPhaseTwo as $method) { checkTravel(in_array($method,$permissionManifest->sysadmin,true), "Administrator Phase 2 permission is missing: " . $method . "."); }
checkTravel(strpos($explorerScript,"GetSearchSuggestions") !== false && strpos($explorerView,"Featured collections") !== false, "Search suggestions or featured collections are missing from discovery.");
checkTravel(strpos($mapRuntime,"clusterPoints") !== false && strpos($mapRuntime,"addGeoJson") !== false, "Map clustering or GeoJSON rendering is missing.");
checkTravel(strpos($detailScript,"travel-destinations-offline-v1") !== false && strpos($detailView,"Save offline") !== false, "Offline destination bundles are not exposed.");
checkTravel(strpos($detailScript,"travel_destination_route") !== false && strpos($detailView,"GPX upload") !== false, "GPX route upload is not exposed.");
checkTravel(strpos($detailView,"verified visits") !== false && strpos($detailView,"Verified local guides") !== false, "Verified visits or guide profiles are not displayed.");
checkTravel(strpos($detailView,"Continue to provider") !== false && strpos($detailView,"does not duplicate provider payments") !== false, "Provider-neutral booking handoff is unclear.");
$workspaceScript = file_get_contents($appRoot . "/components/my-favorites/script.js");
$workspaceView = file_get_contents($appRoot . "/components/my-favorites/partial.html");
checkTravel(strpos($workspaceScript,"GetMyTravelLists") !== false && strpos($workspaceScript,"GetMyTrips") !== false, "Named lists or trips are missing from the travel workspace.");
checkTravel(strpos($workspaceScript,"GetRecommendations") !== false && strpos($workspaceView,"recommended") !== false, "Personal recommendations are missing from the workspace.");
checkTravel(strpos($workspaceScript,"SaveNotificationPreferences") !== false && strpos($workspaceView,"In-app notifications") !== false, "Notification preferences are missing from the workspace.");
$adminModerationSource = file_get_contents($appRoot . "/components/admin-moderation/script.js");
checkTravel(strpos($adminModerationSource,"ModerateDestinationRoute") !== false && strpos($adminModerationSource,"VerifyGuideProfile") !== false, "Phase 2 moderation queues are incomplete.");
checkTravel(file_exists($tenantRoot . "/global/templetes/app/travel_destination_update.jnx"), "In-app notification template is missing.");
$normalizeGeoJson = $apiReflection->getMethod("normalizeGeoJson");$normalizeGeoJson->setAccessible(true);
$validRoute = $normalizeGeoJson->invoke($apiService,'{"type":"LineString","coordinates":[[80.7,7.8],[80.8,7.9]]}');
$invalidRoute = $normalizeGeoJson->invoke($apiService,'{"type":"Point","coordinates":[80.7,7.8]}');
checkTravel(is_object($validRoute) && $validRoute->type === "FeatureCollection", "Valid GeoJSON route was not normalized.");
checkTravel($invalidRoute === null, "Unsupported GeoJSON geometry was accepted.");
$safeHttpUrl = $apiReflection->getMethod("safeHttpUrl");$safeHttpUrl->setAccessible(true);
checkTravel($safeHttpUrl->invoke($apiService,"https://booking.example/path") === "https://booking.example/path", "Valid booking URL was rejected.");
checkTravel($safeHttpUrl->invoke($apiService,"javascript:alert(1)") === "", "Unsafe booking URL was accepted.");

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
