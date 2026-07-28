<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

$_SERVER["HTTP_HOST"] = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "localhost";
$_SERVER["SERVER_PROTOCOL"] = isset($_SERVER["SERVER_PROTOCOL"]) ? $_SERVER["SERVER_PROTOCOL"] : "HTTP/1.1";
$_SERVER["REMOTE_ADDR"] = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "127.0.0.1";
$_SERVER["HTTP_USER_AGENT"] = isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "DAVVAG permission installer";

$frameworkRoot = getcwd();
if (!file_exists($frameworkRoot . "/configloader.php")) {
    fwrite(STDERR, "Run this installer from the DAVVAG framework root.\n");
    exit(1);
}
require_once($frameworkRoot . "/configloader.php");

$manifest = json_decode(file_get_contents(__DIR__ . "/permissions.json"), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "Invalid permissions.json\n");
    exit(1);
}

$inserted = 0;
$existing = 0;
foreach ($manifest as $groupId => $operations) {
    foreach ($operations as $operation) {
        $query = "domain:" . AUTH_DOMAIN
            . ",groupid:" . $groupId
            . ",appCode:travel-destinations"
            . ",type:service"
            . ",code:api"
            . ",operation:" . $operation;
        $result = SOSSData::Query("usergroup_permission", $query, null, "asc", 1, 0, AUTH_DOMAIN, false);
        if ($result->success && count($result->result) > 0) {
            $existing++;
            continue;
        }
        $permission = new stdClass();
        $permission->groupid = $groupId;
        $permission->domain = AUTH_DOMAIN;
        $permission->appCode = "travel-destinations";
        $permission->type = "service";
        $permission->code = "api";
        $permission->operation = $operation;
        $permission->keyid = md5($groupId . "-" . AUTH_DOMAIN . "-travel-destinations--service-api-" . $operation);
        $save = SOSSData::Insert("usergroup_permission", $permission, AUTH_DOMAIN);
        if (!$save->success) {
            fwrite(STDERR, "Failed to add " . $groupId . "/" . $operation . "\n");
            exit(1);
        }
        $inserted++;
    }
}

$categories = array("Camping", "Hiking", "Stay", "Village", "Viewpoint", "Waterfall", "Beach", "Forest", "Mountain", "Lake or Reservoir", "Cultural Place", "Religious Place", "Wildlife or Nature Area");
$amenities = array("Drinking water", "Natural water source", "Toilet", "Shower", "Electricity", "Mobile signal", "Wi-Fi", "Parking", "Public transport", "Food nearby", "Shop nearby", "Medical help nearby", "Guide available", "Pet friendly", "Child friendly", "Wheelchair access", "Cooking area", "Campfire area", "Security", "Waste disposal", "Changing rooms", "Equipment rental");
$referenceInserted = 0;

function seedTravelReference($namespace, $names, $isCategory, &$referenceInserted) {
    foreach ($names as $index => $name) {
        $slug = strtolower(trim(preg_replace("/[^a-z0-9]+/", "-", strtolower($name)), "-"));
        $found = SOSSData::Query($namespace, "slug:" . $slug, null, "asc", 1, 0, AUTH_DOMAIN, false);
        if ($found->success && count($found->result) > 0) {
            continue;
        }
        $item = new stdClass();
        $item->name = $name;
        $item->slug = $slug;
        $item->description = "";
        $item->sort_order = $index + 1;
        $item->is_active = true;
        if ($isCategory) {
            $item->parent_id = 0;
            $item->marker_key = $slug;
        } else {
            $item->icon_key = $slug;
        }
        $saved = SOSSData::Insert($namespace, $item, AUTH_DOMAIN);
        if (!$saved->success) {
            fwrite(STDERR, "Failed to seed " . $namespace . "/" . $name . "\n");
            exit(1);
        }
        $referenceInserted++;
    }
}

seedTravelReference("travel_destination_category", $categories, true, $referenceInserted);
seedTravelReference("travel_destination_amenity", $amenities, false, $referenceInserted);

$mapSettingsInserted = 0;
$existingMapSettings = SOSSData::Query("travel_destination_map_settings", "provider:google_maps", null, "asc", 1, 0, AUTH_DOMAIN, false);
if ($existingMapSettings->success && count($existingMapSettings->result) === 0) {
    $mapSettings = new stdClass();
    $mapSettings->provider = "google_maps";
    $mapSettings->is_enabled = false;
    $mapSettings->map_id = "";
    $mapSettings->language = "en";
    $mapSettings->region = "LK";
    $mapSettings->default_latitude = 7.8731;
    $mapSettings->default_longitude = 80.7718;
    $mapSettings->default_zoom = 8;
    $mapSettings->enable_geocoding = false;
    $mapSettings->updated_by_profile_id = 0;
    $mapSettings->updated_at = date("Y-m-d H:i:s");
    $savedMapSettings = SOSSData::Insert("travel_destination_map_settings", $mapSettings, AUTH_DOMAIN);
    if (!$savedMapSettings->success) {
        fwrite(STDERR, "Failed to seed Google Maps settings.\n");
        exit(1);
    }
    $mapSettingsInserted = 1;
}

$weatherSettingsInserted = 0;
$existingWeatherSettings = SOSSData::Query("travel_destination_weather_settings", "provider:open_meteo", null, "asc", 1, 0, AUTH_DOMAIN, false);
if ($existingWeatherSettings->success && count($existingWeatherSettings->result) === 0) {
    $weatherSettings = new stdClass();
    $weatherSettings->provider = "open_meteo";
    $weatherSettings->is_enabled = false;
    $weatherSettings->forecast_days = 3;
    $weatherSettings->temperature_unit = "celsius";
    $weatherSettings->wind_speed_unit = "kmh";
    $weatherSettings->license_confirmed = false;
    $weatherSettings->updated_by_profile_id = 0;
    $weatherSettings->updated_at = date("Y-m-d H:i:s");
    $savedWeatherSettings = SOSSData::Insert("travel_destination_weather_settings", $weatherSettings, AUTH_DOMAIN);
    if (!$savedWeatherSettings->success) {
        fwrite(STDERR, "Failed to seed weather settings.\n");
        exit(1);
    }
    $weatherSettingsInserted = 1;
}

echo "Permission entries inserted: " . $inserted . PHP_EOL;
echo "Permission entries already present: " . $existing . PHP_EOL;
echo "Reference entries inserted: " . $referenceInserted . PHP_EOL;
echo "Map settings inserted: " . $mapSettingsInserted . PHP_EOL;
echo "Weather settings inserted: " . $weatherSettingsInserted . PHP_EOL;
?>
