<?php
namespace travel_destinations;

if (defined("PLUGIN_PATH")) {
    require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    require_once(PLUGIN_PATH . "/phpcache/cache.php");
    require_once(PLUGIN_PATH . "/auth/auth.php");
}
if (defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
    require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
}

class TravelDestinationRules {
    public static function validCoordinates($latitude, $longitude) {
        return is_numeric($latitude) && is_numeric($longitude)
            && floatval($latitude) >= -90 && floatval($latitude) <= 90
            && floatval($longitude) >= -180 && floatval($longitude) <= 180;
    }

    public static function validRadius($radius) {
        return in_array(intval($radius), array(1, 5, 10, 25, 50, 100), true);
    }

    public static function slug($value) {
        $value = strtolower(trim(strval($value)));
        $value = preg_replace("/[^a-z0-9]+/", "-", $value);
        return trim($value, "-");
    }

    public static function plainMarkdown($value, $maxLength) {
        $value = str_replace("\0", "", strval($value));
        $value = strip_tags($value);
        return mb_substr(trim($value), 0, $maxLength);
    }

    public static function distanceKm($latitudeA, $longitudeA, $latitudeB, $longitudeB) {
        $earthRadius = 6371.0088;
        $latDelta = deg2rad(floatval($latitudeB) - floatval($latitudeA));
        $lngDelta = deg2rad(floatval($longitudeB) - floatval($longitudeA));
        $a = sin($latDelta / 2) * sin($latDelta / 2)
            + cos(deg2rad(floatval($latitudeA))) * cos(deg2rad(floatval($latitudeB)))
            * sin($lngDelta / 2) * sin($lngDelta / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function inBoundingBox($itemLatitude, $itemLongitude, $latitude, $longitude, $radiusKm) {
        $latitudeDelta = floatval($radiusKm) / 111.32;
        $cosine = max(0.01, cos(deg2rad(floatval($latitude))));
        $longitudeDelta = floatval($radiusKm) / (111.32 * $cosine);
        return floatval($itemLatitude) >= floatval($latitude) - $latitudeDelta
            && floatval($itemLatitude) <= floatval($latitude) + $latitudeDelta
            && floatval($itemLongitude) >= floatval($longitude) - $longitudeDelta
            && floatval($itemLongitude) <= floatval($longitude) + $longitudeDelta;
    }

    public static function coordinatesFromMapUrl($value) {
        $value = trim(strval($value));
        if ($value === "" || strlen($value) > 5000) {
            return null;
        }
        $candidates = array($value, urldecode($value), urldecode(urldecode($value)));
        $patterns = array(
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i',
            '/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)(?:[,\/]|$)/i',
            '/[?&](?:q|query|ll|center|destination|daddr)=(-?\d{1,2}(?:\.\d+)?)(?:%2C|,|\s+)(-?\d{1,3}(?:\.\d+)?)/i',
            '/(?:^|[^0-9.-])(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)(?:[^0-9.]|$)/'
        );
        foreach ($candidates as $candidate) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $candidate, $match)
                    && TravelDestinationRules::validCoordinates($match[1], $match[2])) {
                    return array("latitude" => floatval($match[1]), "longitude" => floatval($match[2]));
                }
            }
        }
        return null;
    }
}

class ApiService {
    private $destinationNamespace = "travel_destination";
    private $categoryNamespace = "travel_destination_category";
    private $categoryLinkNamespace = "travel_destination_category_link";
    private $amenityNamespace = "travel_destination_amenity";
    private $amenityLinkNamespace = "travel_destination_amenity_link";
    private $mediaNamespace = "travel_destination_media";
    private $reviewNamespace = "travel_destination_review";
    private $helpfulNamespace = "travel_destination_review_helpful";
    private $commentNamespace = "travel_destination_comment";
    private $favoriteNamespace = "travel_destination_favorite";
    private $submissionLogNamespace = "travel_destination_submission_log";
    private $conditionNamespace = "travel_destination_condition";
    private $reportNamespace = "travel_destination_report";
    private $mapSettingsNamespace = "travel_destination_map_settings";
    private $descriptionChunkNamespace = "travel_destination_description_chunk";
    private $weatherSettingsNamespace = "travel_destination_weather_settings";
    private $routeNamespace = "travel_destination_route";
    private $listNamespace = "travel_destination_list";
    private $listItemNamespace = "travel_destination_list_item";
    private $visitNamespace = "travel_destination_visit";
    private $guideNamespace = "travel_destination_guide";
    private $guideDestinationNamespace = "travel_destination_guide_destination";
    private $availabilityNamespace = "travel_destination_availability";
    private $notificationPreferenceNamespace = "travel_destination_notification_preference";
    private $translationNamespace = "travel_destination_translation";
    private $collectionNamespace = "travel_destination_collection";
    private $collectionItemNamespace = "travel_destination_collection_item";
    private $tripNamespace = "travel_destination_trip";
    private $tripItemNamespace = "travel_destination_trip_item";

    private $destinationStatuses = array("Draft", "Pending Review", "Returned for Changes", "Approved", "Rejected", "Published", "Archived");
    private $privacyModes = array("exact_public", "approximate_public", "hidden_sensitive", "approved_only");
    private $sortValues = array("nearest", "highest_rated", "most_reviewed", "recently_added", "recently_verified", "most_viewed", "featured", "name");
    private $reviewStatuses = array("Pending", "Approved", "Rejected", "Hidden");
    private $reportReasons = array("incorrect_location", "duplicate_destination", "closed_destination", "private_property", "unsafe_destination", "misleading_description", "inappropriate_image", "inappropriate_review", "inappropriate_comment", "environmental_concern", "spam");
    private $conditionTypes = array("road_blocked", "trail_closed", "heavy_rain", "flooding", "landslide", "strong_wind", "fire_risk", "unsafe_water", "construction", "entrance_closed", "permit_change", "high_crowd_level", "mobile_signal_unavailable", "campsite_unavailable", "general_update");
    private $routeTypes = array("loop", "out_and_back", "point_to_point");
    private $visitStatuses = array("Pending", "Verified", "Rejected");

    public function getCapabilities($req, $res) {
        $profileId = $this->currentProfileId();
        $mapSettings = $this->mapSettings();
        $googleMapsEnabled = $mapSettings !== null
            && $this->booleanValue($mapSettings, "is_enabled")
            && $this->providerValue($mapSettings, "api_key_enc") !== "";
        return array(
            "authenticated" => $profileId !== null,
            "profileId" => $profileId,
            "administrator" => $this->isAdmin(),
            "radii" => array(1, 5, 10, 25, 50, 100),
            "sorts" => $this->sortValues,
            "map" => array(
                "provider" => $googleMapsEnabled ? "google" : "provider-neutral",
                "directionsUrl" => $googleMapsEnabled ? "https://www.google.com/maps/dir/" : "https://www.openstreetmap.org/directions"
            )
        );
    }

    public function getGetCategories($req, $res) {
        return $this->activeReferenceRows($this->categoryNamespace);
    }

    public function getGetAmenities($req, $res) {
        return $this->activeReferenceRows($this->amenityNamespace);
    }

    public function getGetMapConfiguration($req, $res) {
        $settings = $this->mapSettings();
        $apiKey = $this->providerValue($settings, "api_key_enc");
        $enabled = $settings !== null && $this->booleanValue($settings, "is_enabled") && $apiKey !== "";
        $result = $this->mapSettingsDefaults();
        $result["enabled"] = $enabled;
        $result["provider"] = $enabled ? "google" : "provider-neutral";
        $result["apiKey"] = $enabled ? $apiKey : "";
        $result["mapId"] = $settings && isset($settings->map_id) ? trim(strval($settings->map_id)) : "";
        $result["language"] = $settings && isset($settings->language) ? trim(strval($settings->language)) : "en";
        $result["region"] = $settings && isset($settings->region) ? strtoupper(trim(strval($settings->region))) : "LK";
        $latitude = $settings && isset($settings->default_latitude) ? floatval($settings->default_latitude) : 7.8731;
        $longitude = $settings && isset($settings->default_longitude) ? floatval($settings->default_longitude) : 80.7718;
        if (!TravelDestinationRules::validCoordinates($latitude, $longitude)) {
            $latitude = 7.8731;
            $longitude = 80.7718;
        }
        $result["defaultCenter"] = array("lat" => $latitude, "lng" => $longitude);
        $result["defaultZoom"] = $settings && isset($settings->default_zoom) ? min(20, max(2, intval($settings->default_zoom))) : 8;
        $result["geocodingEnabled"] = $enabled && $this->booleanValue($settings, "enable_geocoding");
        return $result;
    }

    public function getGetAdminMapSettings($req, $res) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        return $this->safeAdminMapSettings($this->mapSettings());
    }

    public function getGetAdminWeatherSettings($req, $res) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        return $this->safeAdminWeatherSettings($this->weatherSettings());
    }

    public function postSaveWeatherSettings($req, $res) {
        $profileId = $this->requireAdmin($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $forecastDays = isset($body->forecast_days) ? intval($body->forecast_days) : 3;
        $temperatureUnit = $this->allowedText($body, "temperature_unit", array("celsius", "fahrenheit"), "celsius");
        $windSpeedUnit = $this->allowedText($body, "wind_speed_unit", array("kmh", "ms", "mph", "kn"), "kmh");
        $enabled = $this->bodyBoolean($body, "enabled");
        $licenseConfirmed = $this->bodyBoolean($body, "license_confirmed");
        if ($forecastDays < 1 || $forecastDays > 7) {
            return $this->fail($res, "Forecast days must be between 1 and 7.");
        }
        if ($enabled && !$licenseConfirmed) {
            return $this->fail($res, "Confirm the weather provider licence before enabling forecasts.");
        }
        $settings = $this->weatherSettings();
        if ($settings === null) {
            $settings = new \stdClass();
            $settings->provider = "open_meteo";
        }
        $settings->is_enabled = $enabled;
        $settings->forecast_days = $forecastDays;
        $settings->temperature_unit = $temperatureUnit;
        $settings->wind_speed_unit = $windSpeedUnit;
        $settings->license_confirmed = $licenseConfirmed;
        $settings->updated_by_profile_id = intval($profileId);
        $settings->updated_at = date("Y-m-d H:i:s");
        $saved = $this->saveObject($this->weatherSettingsNamespace, "id", $settings, $res);
        return $saved === null ? null : $this->safeAdminWeatherSettings($saved);
    }

    public function postResolveMapLocationUrl($req, $res) {
        if ($this->requireProfile($res) === null) {
            return null;
        }
        $body = $this->body($req);
        $url = $this->text($body, "url", 5000);
        $coordinates = TravelDestinationRules::coordinatesFromMapUrl($url);
        if ($coordinates !== null) {
            return $coordinates;
        }
        if (!$this->allowedGoogleMapUrl($url)) {
            return $this->fail($res, "Paste a valid Google Maps location URL.");
        }
        if (!function_exists("curl_init")) {
            return $this->fail($res, "Short Google Maps links cannot be resolved on this server.");
        }
        $currentUrl = $url;
        for ($redirect = 0; $redirect < 6; $redirect++) {
            if (!$this->allowedGoogleMapUrl($currentUrl)) {
                return $this->fail($res, "The map link redirected outside an approved Google Maps host.");
            }
            $coordinates = TravelDestinationRules::coordinatesFromMapUrl($currentUrl);
            if ($coordinates !== null) {
                return $coordinates;
            }
            $nextUrl = $this->mapRedirectLocation($currentUrl);
            if ($nextUrl === "") {
                break;
            }
            $currentUrl = $this->absoluteRedirectUrl($currentUrl, $nextUrl);
        }
        $coordinates = TravelDestinationRules::coordinatesFromMapUrl($currentUrl);
        return $coordinates !== null ? $coordinates : $this->fail($res, "Coordinates were not found in that Google Maps URL. Open the place in Google Maps and copy its full browser URL.");
    }

    public function postSaveMapSettings($req, $res) {
        $profileId = $this->requireAdmin($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $settings = $this->mapSettings();
        $apiKey = $this->text($body, "api_key", 255);
        $hasSavedKey = $this->providerValue($settings, "api_key_enc") !== "";
        $enabled = $this->bodyBoolean($body, "enabled");
        if ($apiKey !== "" && !preg_match('/^[A-Za-z0-9_-]{20,255}$/', $apiKey)) {
            return $this->fail($res, "Enter a valid Google Maps browser API key without spaces.");
        }
        if ($apiKey !== "" && $this->providerSecret() === "") {
            return $this->fail($res, "Set DAVVAG_PROVIDER_SECRET on the server before saving the map API key.");
        }
        if ($enabled && $apiKey === "" && !$hasSavedKey) {
            return $this->fail($res, "Add a Google Maps API key before enabling the provider.");
        }
        $latitude = isset($body->default_latitude) ? $body->default_latitude : 7.8731;
        $longitude = isset($body->default_longitude) ? $body->default_longitude : 80.7718;
        if (!TravelDestinationRules::validCoordinates($latitude, $longitude)) {
            return $this->fail($res, "Default latitude and longitude are outside the valid range.");
        }
        $zoom = isset($body->default_zoom) ? intval($body->default_zoom) : 8;
        if ($zoom < 2 || $zoom > 20) {
            return $this->fail($res, "Default zoom must be between 2 and 20.");
        }
        $mapId = $this->text($body, "map_id", 128);
        if ($mapId !== "" && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $mapId)) {
            return $this->fail($res, "Map ID contains unsupported characters.");
        }
        $language = $this->text($body, "language", 20);
        $region = strtoupper($this->text($body, "region", 3));
        if ($language !== "" && !preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})?$/', $language)) {
            return $this->fail($res, "Language must be a valid language code, such as en or en-GB.");
        }
        if ($region !== "" && !preg_match('/^[A-Z]{2,3}$/', $region)) {
            return $this->fail($res, "Region must be a two or three letter region code.");
        }
        if ($settings === null) {
            $settings = new \stdClass();
            $settings->provider = "google_maps";
        }
        $settings->is_enabled = $enabled;
        $settings->map_id = $mapId;
        $settings->language = $language === "" ? "en" : $language;
        $settings->region = $region === "" ? "LK" : $region;
        $settings->default_latitude = floatval($latitude);
        $settings->default_longitude = floatval($longitude);
        $settings->default_zoom = $zoom;
        $settings->enable_geocoding = $this->bodyBoolean($body, "enable_geocoding");
        $settings->updated_by_profile_id = intval($profileId);
        $settings->updated_at = date("Y-m-d H:i:s");
        if ($apiKey !== "") {
            $settings->api_key_enc = $this->encryptProviderSecret($apiKey);
        }
        $saved = $this->saveObject($this->mapSettingsNamespace, "id", $settings, $res);
        return $saved === null ? null : $this->safeAdminMapSettings($saved);
    }

    public function postSearchDestinations($req, $res) {
        return $this->search($this->body($req), false, $res);
    }

    public function postGetMapResults($req, $res) {
        $body = $this->body($req);
        $body->pageSize = isset($body->pageSize) ? min(250, intval($body->pageSize)) : 150;
        $result = $this->search($body, true, $res);
        if ($result === null) {
            return null;
        }
        foreach ($result["items"] as $item) {
            $this->applyCoordinatePrivacy($item, false);
        }
        return $result;
    }

    public function postGetNearbyDestinations($req, $res) {
        $body = $this->body($req);
        if (!isset($body->latitude) || !isset($body->longitude) || !TravelDestinationRules::validCoordinates($body->latitude, $body->longitude)) {
            return $this->fail($res, "Valid latitude and longitude are required.");
        }
        $radius = isset($body->radius) ? intval($body->radius) : 10;
        if (!TravelDestinationRules::validRadius($radius)) {
            return $this->fail($res, "Radius must be one of 1, 5, 10, 25, 50 or 100 km.");
        }
        $body->sort = "nearest";
        $body->radius = $radius;
        return $this->search($body, false, $res);
    }

    public function postGetDestination($req, $res) {
        $body = $this->body($req);
        $destination = null;
        if (isset($body->id) && intval($body->id) > 0) {
            $destination = $this->findOne($this->destinationNamespace, "id:" . intval($body->id));
        } elseif (isset($body->slug) && TravelDestinationRules::slug($body->slug) !== "") {
            $destination = $this->findOne($this->destinationNamespace, "slug:" . TravelDestinationRules::slug($body->slug));
        }
        if ($destination === null || !$this->canReadDestination($destination)) {
            return $this->fail($res, "Destination was not found.");
        }
        $destination->categories = $this->linkedReferenceRows($destination->id, $this->categoryLinkNamespace, "category_id", $this->categoryNamespace);
        $destination->amenities = $this->linkedReferenceRows($destination->id, $this->amenityLinkNamespace, "amenity_id", $this->amenityNamespace);
        $destination->media = $this->approvedMedia($destination->id);
        $destination->description_markdown = $this->fullDestinationDescription($destination);
        $destination->available_languages = array($destination->primary_language);
        foreach ($this->rows($this->translationNamespace, "destination_id:" . intval($destination->id) . ",moderation_status:Approved", "asc", 50, 0) as $translation) {
            if (!in_array($translation->language_code, $destination->available_languages, true)) { $destination->available_languages[] = $translation->language_code; }
        }
        $requestedLanguage = isset($body->language) ? strtolower(trim(strval($body->language))) : "";
        if ($requestedLanguage !== "" && $requestedLanguage !== strtolower(strval($destination->primary_language))) {
            $translation = $this->findOne($this->translationNamespace, "destination_id:" . intval($destination->id) . ",language_code:" . $requestedLanguage . ",moderation_status:Approved");
            if ($translation !== null) {
                $destination->original_language = $destination->primary_language; $destination->content_language = $requestedLanguage;
                $destination->name = $translation->name; $destination->short_summary = $translation->short_summary; $destination->description_markdown = $translation->description_markdown;
            }
        }
        $this->applyCoordinatePrivacy($destination, $this->canSeeExactCoordinates($destination));
        $destination->directions_url = $this->directionsUrl($destination);
        return $destination;
    }

    public function postGetDestinationWeather($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null) {
            return null;
        }
        $destination = $this->findOne($this->destinationNamespace, "id:" . $destinationId);
        if ($destination === null || !$this->canReadDestination($destination)) {
            return $this->fail($res, "Destination was not found.");
        }
        $settings = $this->weatherSettings();
        if ($settings === null || !$this->booleanValue($settings, "is_enabled") || !$this->booleanValue($settings, "license_confirmed")) {
            return array("available" => false, "reason" => "disabled", "message" => "Weather forecasts are not configured for this site.");
        }
        $publicLocation = clone $destination;
        $this->applyCoordinatePrivacy($publicLocation, $this->canSeeExactCoordinates($destination));
        if (!isset($publicLocation->latitude) || !isset($publicLocation->longitude)
            || !TravelDestinationRules::validCoordinates($publicLocation->latitude, $publicLocation->longitude)) {
            return array("available" => false, "reason" => "location_restricted", "message" => "Weather is unavailable because this destination's coordinates are restricted.");
        }
        $safeSettings = $this->safeAdminWeatherSettings($settings);
        $cacheKey = md5(implode("|", array(
            round(floatval($publicLocation->latitude), 4),
            round(floatval($publicLocation->longitude), 4),
            $safeSettings["forecast_days"],
            $safeSettings["temperature_unit"],
            $safeSettings["wind_speed_unit"]
        )));
        if (class_exists("\\CacheData")) {
            $cached = \CacheData::getObjects($cacheKey, "travel_destination_weather");
            if ($cached !== null) {
                $cached = json_decode(json_encode($cached), true);
                $cached["cached"] = true;
                return $cached;
            }
        }
        $payload = $this->fetchOpenMeteoForecast(
            floatval($publicLocation->latitude),
            floatval($publicLocation->longitude),
            $safeSettings
        );
        if ($payload === null) {
            return array("available" => false, "reason" => "provider_unavailable", "message" => "Weather is temporarily unavailable. Destination information is still available.");
        }
        $forecast = $this->normalizeWeatherResponse($payload, $safeSettings);
        if ($forecast === null) {
            return array("available" => false, "reason" => "invalid_provider_response", "message" => "Weather is temporarily unavailable. Destination information is still available.");
        }
        if (class_exists("\\CacheData")) {
            \CacheData::setObjects($cacheKey, "travel_destination_weather", $forecast);
        }
        return $forecast;
    }

    public function postGetDestinationReviews($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null) {
            return null;
        }
        return $this->pagedRows($this->reviewNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved,is_active:1", $body, 20, 50);
    }

    public function postGetDestinationComments($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null) {
            return null;
        }
        return $this->pagedRows($this->commentNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved,is_active:1", $body, 30, 100);
    }

    public function postGetDestinationConditions($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null) {
            return null;
        }
        $rows = $this->rows($this->conditionNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved", "desc", 100, 0);
        $now = time();
        $rows = array_values(array_filter($rows, function ($item) use ($now) {
            return empty($item->expires_at) || strtotime($item->expires_at) >= $now;
        }));
        return $this->paginateArray($rows, $body, 20, 50);
    }

    public function postSaveDestinationDraft($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $existing = isset($body->id) ? $this->findOne($this->destinationNamespace, "id:" . intval($body->id)) : null;
        if ($existing !== null && !$this->ownsDestination($existing, $profileId)) {
            return $this->fail($res, "You may edit only your own submission.");
        }
        if ($existing !== null && !in_array($existing->status, array("Draft", "Returned for Changes"), true)) {
            return $this->fail($res, "This submission is locked while it is under review.");
        }
        $destination = $this->validatedDestination($body, $res, false);
        if ($destination === null) {
            return null;
        }
        $destination->status = "Draft";
        $destination->created_by_profile_id = $profileId;
        $saved = $this->saveObject($this->destinationNamespace, "id", $destination, $res);
        if ($saved !== null) {
            $this->syncDestinationLinks($saved, $body, $res);
        }
        return $saved;
    }

    public function postUpdateOwnSubmission($req, $res) {
        return $this->postSaveDestinationDraft($req, $res);
    }

    public function postSubmitDestination($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $destination = isset($body->id) ? $this->findOne($this->destinationNamespace, "id:" . intval($body->id)) : null;
        if ($destination === null || !$this->ownsDestination($destination, $profileId)) {
            return $this->fail($res, "Save your own draft before submitting it.");
        }
        if (!in_array($destination->status, array("Draft", "Returned for Changes"), true)) {
            return $this->fail($res, "Only Draft or Returned for Changes submissions can be submitted.");
        }
        $validated = $this->validatedDestination($body, $res, true);
        if ($validated === null) {
            return null;
        }
        $validated->id = $destination->id;
        $validated->created_by_profile_id = $profileId;
        $fromStatus = $destination->status;
        $validated->status = "Pending Review";
        $saved = $this->saveObject($this->destinationNamespace, "id", $validated, $res);
        if ($saved !== null) {
            $this->syncDestinationLinks($saved, $body, $res);
            $this->logTransition($saved->id, $profileId, $fromStatus, "Pending Review", "Submitted for moderation.");
        }
        return $saved;
    }

    public function postAssociateDestinationMedia($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        if ($destinationId === null) {
            return null;
        }
        $destination = $this->findOne($this->destinationNamespace, "id:" . $destinationId);
        if ($destination === null || (!$this->isAdmin() && !$this->ownsDestination($destination, $profileId))) {
            return $this->fail($res, "You cannot add media to this destination.");
        }
        $reference = isset($body->media_reference) ? trim(strval($body->media_reference)) : "";
        $size = isset($body->file_size) ? intval($body->file_size) : 0;
        $extension = strtolower(pathinfo(parse_url($reference, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($reference === "" || preg_match('/(^|[\\\\\\/])\\.\\.([\\\\\\/]|$)/', $reference) || !in_array($extension, array("jpg", "jpeg", "png", "webp"), true)) {
            return $this->fail($res, "Only valid JPG, PNG or WebP upload references are accepted.");
        }
        if ($size < 0 || $size > 10485760) {
            return $this->fail($res, "Images must not exceed 10 MB.");
        }
        $media = new \stdClass();
        $media->destination_id = $destinationId;
        $media->media_reference = mb_substr($reference, 0, 500);
        $media->media_type = "image";
        $media->caption = $this->text($body, "caption", 1000);
        $media->alternative_text = $this->text($body, "alternative_text", 500);
        $media->credit = $this->text($body, "credit", 255);
        $media->display_order = isset($body->display_order) ? max(0, intval($body->display_order)) : 0;
        $media->is_cover = !empty($body->is_cover);
        $media->uploaded_by_profile_id = $profileId;
        $media->moderation_status = $this->isAdmin() ? "Approved" : "Pending";
        return $this->saveObject($this->mediaNamespace, "id", $media, $res);
    }

    public function postSaveReview($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        $rating = isset($body->overall_rating) ? intval($body->overall_rating) : 0;
        if ($destinationId === null || $rating < 1 || $rating > 5) {
            return $this->fail($res, "A destination and rating from 1 to 5 are required.");
        }
        if (!$this->isPublishedDestination($destinationId)) {
            return $this->fail($res, "Reviews can be added only to published destinations.");
        }
        $existing = $this->findOne($this->reviewNamespace, "destination_id:" . $destinationId . ",reviewer_profile_id:" . $profileId . ",is_active:1");
        if (isset($body->id) && intval($body->id) > 0) {
            if ($existing === null || intval($existing->id) !== intval($body->id)) {
                return $this->fail($res, "You may update only your own active review.");
            }
        } elseif ($existing !== null) {
            return $this->fail($res, "You already have an active review for this destination.");
        }
        $review = $existing !== null ? $existing : new \stdClass();
        $review->destination_id = $destinationId;
        $review->reviewer_profile_id = $profileId;
        $review->overall_rating = $rating;
        $review->review_title = $this->text($body, "review_title", 255);
        $review->review_markdown = TravelDestinationRules::plainMarkdown(isset($body->review_markdown) ? $body->review_markdown : "", 6000);
        if (isset($body->visit_date) && trim(strval($body->visit_date)) !== "" && strtotime($body->visit_date) === false) {
            return $this->fail($res, "Visit date is invalid.");
        }
        $review->visit_date = $this->validDateValue(isset($body->visit_date) ? $body->visit_date : null);
        $review->traveler_type = $this->allowedText($body, "traveler_type", array("Solo", "Couple", "Family", "Friends", "Group", "Business", "Other"), "Other");
        $review->would_visit_again = !empty($body->would_visit_again);
        $review->condition_at_visit = $this->text($body, "condition_at_visit", 1000);
        $review->category_ratings = isset($body->category_ratings) && is_object($body->category_ratings) ? $body->category_ratings : new \stdClass();
        $review->moderation_status = "Pending";
        $review->helpful_count = isset($review->helpful_count) ? intval($review->helpful_count) : 0;
        $review->is_active = true;
        $saved = $this->saveObject($this->reviewNamespace, "id", $review, $res);
        $this->recalculateRating($destinationId);
        return $saved;
    }

    public function postDeleteOwnReview($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $review = isset($body->id) ? $this->findOne($this->reviewNamespace, "id:" . intval($body->id)) : null;
        if ($profileId === null || $review === null || intval($review->reviewer_profile_id) !== $profileId) {
            return $this->fail($res, "You may remove only your own review.");
        }
        $review->is_active = false;
        $saved = $this->saveObject($this->reviewNamespace, "id", $review, $res);
        $this->recalculateRating($review->destination_id);
        return $saved;
    }

    public function postMarkReviewHelpful($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $reviewId = $this->requiredId($body, "review_id", $res);
        if ($profileId === null || $reviewId === null) {
            return null;
        }
        $review = $this->findOne($this->reviewNamespace, "id:" . $reviewId . ",moderation_status:Approved,is_active:1");
        if ($review === null) {
            return $this->fail($res, "Review was not found.");
        }
        if ($this->findOne($this->helpfulNamespace, "review_id:" . $reviewId . ",profile_id:" . $profileId) !== null) {
            return $this->fail($res, "You already marked this review as helpful.");
        }
        $item = new \stdClass();
        $item->review_id = $reviewId;
        $item->profile_id = $profileId;
        $saved = $this->saveObject($this->helpfulNamespace, "id", $item, $res);
        if ($saved !== null) {
            $review->helpful_count = intval($review->helpful_count) + 1;
            $this->saveObject($this->reviewNamespace, "id", $review, $res);
        }
        return $review;
    }

    public function postSaveComment($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        $text = TravelDestinationRules::plainMarkdown(isset($body->comment_markdown) ? $body->comment_markdown : "", 3000);
        if ($destinationId === null || mb_strlen($text) < 2 || !$this->isPublishedDestination($destinationId)) {
            return $this->fail($res, "A comment of at least two characters is required for a published destination.");
        }
        $comment = isset($body->id) ? $this->findOne($this->commentNamespace, "id:" . intval($body->id)) : null;
        if ($comment !== null && intval($comment->profile_id) !== $profileId) {
            return $this->fail($res, "You may edit only your own comment.");
        }
        $parentId = isset($body->parent_comment_id) ? intval($body->parent_comment_id) : 0;
        if ($parentId > 0) {
            $parent = $this->findOne($this->commentNamespace, "id:" . $parentId . ",destination_id:" . $destinationId);
            if ($parent === null || (!empty($parent->parent_comment_id) && intval($parent->parent_comment_id) > 0)) {
                return $this->fail($res, "Only one reply level is supported.");
            }
        }
        if ($comment === null && $this->recentDuplicateComment($destinationId, $profileId, $text)) {
            return $this->fail($res, "Please wait before posting the same comment again.");
        }
        $comment = $comment !== null ? $comment : new \stdClass();
        $comment->destination_id = $destinationId;
        $comment->profile_id = $profileId;
        $comment->parent_comment_id = $parentId;
        $comment->comment_markdown = $text;
        $comment->moderation_status = "Pending";
        $comment->is_active = true;
        return $this->saveObject($this->commentNamespace, "id", $comment, $res);
    }

    public function postDeleteOwnComment($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $comment = isset($body->id) ? $this->findOne($this->commentNamespace, "id:" . intval($body->id)) : null;
        if ($profileId === null || $comment === null || intval($comment->profile_id) !== $profileId) {
            return $this->fail($res, "You may remove only your own comment.");
        }
        $comment->is_active = false;
        return $this->saveObject($this->commentNamespace, "id", $comment, $res);
    }

    public function postSaveFavorite($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        if ($profileId === null || $destinationId === null || !$this->isPublishedDestination($destinationId)) {
            return null;
        }
        $existing = $this->findOne($this->favoriteNamespace, "destination_id:" . $destinationId . ",profile_id:" . $profileId);
        if ($existing !== null) {
            return $existing;
        }
        $favorite = new \stdClass();
        $favorite->destination_id = $destinationId;
        $favorite->profile_id = $profileId;
        $favorite->list_name = "Favorites";
        return $this->saveObject($this->favoriteNamespace, "id", $favorite, $res);
    }

    public function postRemoveFavorite($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        if ($profileId === null || $destinationId === null) {
            return null;
        }
        $favorite = $this->findOne($this->favoriteNamespace, "destination_id:" . $destinationId . ",profile_id:" . $profileId);
        if ($favorite === null) {
            return array("removed" => false);
        }
        $result = \SOSSData::Delete($this->favoriteNamespace, $favorite);
        return array("removed" => !empty($result->success));
    }

    public function postGetMyFavorites($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        $body = $this->body($req);
        $favorites = $this->rows($this->favoriteNamespace, "profile_id:" . $profileId, "desc", 500, 0);
        $items = array();
        foreach ($favorites as $favorite) {
            $destination = $this->findOne($this->destinationNamespace, "id:" . intval($favorite->destination_id) . ",status:Published");
            if ($destination !== null) {
                $destination->favorite_id = $favorite->id;
                $this->applyCoordinatePrivacy($destination, false);
                $items[] = $destination;
            }
        }
        return $this->paginateArray($items, $body, 20, 50);
    }

    public function postGetMySubmissions($req, $res) {
        $profileId = $this->requireProfile($res);
        if ($profileId === null) {
            return null;
        }
        return $this->pagedRows($this->destinationNamespace, "created_by_profile_id:" . $profileId, $this->body($req), 20, 100);
    }

    public function postSubmitConditionReport($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destination_id", $res);
        $type = isset($body->report_type) ? strtolower(trim($body->report_type)) : "";
        if ($profileId === null || $destinationId === null || !in_array($type, $this->conditionTypes, true)) {
            return $this->fail($res, "Choose a valid condition type.");
        }
        $condition = new \stdClass();
        $condition->destination_id = $destinationId;
        $condition->report_type = $type;
        $condition->description = $this->text($body, "description", 2000);
        $condition->reporter_profile_id = $profileId;
        if ((isset($body->observed_at) && strtotime($body->observed_at) === false) || (isset($body->expires_at) && strtotime($body->expires_at) === false)) {
            return $this->fail($res, "Condition observation or expiry date is invalid.");
        }
        $condition->observed_at = $this->validDateValue(isset($body->observed_at) ? $body->observed_at : date("Y-m-d H:i:s"));
        $condition->expires_at = $this->validDateValue(isset($body->expires_at) ? $body->expires_at : date("Y-m-d H:i:s", strtotime("+7 days")));
        if (strtotime($condition->expires_at) <= strtotime($condition->observed_at)) {
            return $this->fail($res, "Condition expiry must be after its observed time.");
        }
        $condition->media_reference = $this->text($body, "media_reference", 500);
        $condition->moderation_status = "Pending";
        $condition->confirmation_count = 0;
        $condition->dispute_count = 0;
        $condition->is_official = false;
        $condition->is_pinned = false;
        return $this->saveObject($this->conditionNamespace, "id", $condition, $res);
    }

    public function postSubmitContentReport($req, $res) {
        $profileId = $this->requireProfile($res);
        $body = $this->body($req);
        $entityType = isset($body->entity_type) ? strtolower(trim($body->entity_type)) : "";
        $entityId = $this->requiredId($body, "entity_id", $res);
        $reason = isset($body->reason) ? strtolower(trim($body->reason)) : "";
        if ($profileId === null || $entityId === null || !in_array($entityType, array("destination", "media", "review", "comment"), true) || !in_array($reason, $this->reportReasons, true)) {
            return $this->fail($res, "A valid report target and reason are required.");
        }
        $duplicate = $this->findOne($this->reportNamespace, "entity_type:" . $entityType . ",entity_id:" . $entityId . ",reporter_profile_id:" . $profileId . ",reason:" . $reason . ",status:Open");
        if ($duplicate !== null) {
            return $this->fail($res, "You already have an open report for this issue.");
        }
        $report = new \stdClass();
        $report->entity_type = $entityType;
        $report->entity_id = $entityId;
        $report->reporter_profile_id = $profileId;
        $report->reason = $reason;
        $report->description = $this->text($body, "description", 3000);
        $report->status = "Open";
        return $this->saveObject($this->reportNamespace, "id", $report, $res);
    }

    public function postSaveDestination($req, $res) {
        $adminId = $this->requireAdmin($res);
        if ($adminId === null) {
            return null;
        }
        $body = $this->body($req);
        $destination = $this->validatedDestination($body, $res, true);
        if ($destination === null) {
            return null;
        }
        if (!isset($destination->created_by_profile_id) || intval($destination->created_by_profile_id) < 1) {
            $destination->created_by_profile_id = $adminId;
        }
        $destination->status = isset($body->status) && in_array($body->status, $this->destinationStatuses, true) ? $body->status : "Draft";
        if ($destination->status === "Published") {
            $destination->approved_by_profile_id = $adminId;
            $destination->publication_date = isset($destination->publication_date) && $destination->publication_date
                ? $destination->publication_date
                : date("Y-m-d H:i:s");
        }
        $destination->verification_status = $this->allowedText($body, "verification_status", array("Unverified", "Pending", "Verified", "Disputed"), "Unverified");
        $destination->is_featured = !empty($body->is_featured);
        $destination->moderation_notes = $this->text($body, "moderation_notes", 4000);
        $saved = $this->saveObject($this->destinationNamespace, "id", $destination, $res);
        if ($saved !== null) {
            $this->syncDestinationLinks($saved, $body, $res);
        }
        return $saved;
    }

    public function postListAdminDestinations($req, $res) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        $body = $this->body($req);
        $status = isset($body->status) ? trim($body->status) : "";
        if ($status !== "" && !in_array($status, $this->destinationStatuses, true)) {
            return $this->fail($res, "Invalid destination status.");
        }
        $query = $status === "" ? "" : "status:" . $status;
        $page = isset($body->page) ? max(0, intval($body->page)) : 0;
        $pageSize = isset($body->pageSize) ? min(100, max(1, intval($body->pageSize))) : 30;
        $items = $this->rows($this->destinationNamespace, $query, "desc", $pageSize + 1, $page * $pageSize);
        $hasMore = count($items) > $pageSize;
        if ($hasMore) {
            array_pop($items);
        }
        return array("items" => $items, "pagination" => array("page" => $page, "pageSize" => $pageSize, "hasMore" => $hasMore));
    }

    public function postPublishDestination($req, $res) {
        return $this->adminTransition($req, $res, "Published", array("Approved", "Published"), true);
    }

    public function postUnpublishDestination($req, $res) {
        return $this->adminTransition($req, $res, "Approved", array("Published"), false);
    }

    public function postArchiveDestination($req, $res) {
        return $this->adminTransition($req, $res, "Archived", $this->destinationStatuses, false);
    }

    public function postApproveSubmission($req, $res) {
        return $this->adminTransition($req, $res, "Approved", array("Pending Review"), false);
    }

    public function postRejectSubmission($req, $res) {
        return $this->adminTransition($req, $res, "Rejected", array("Pending Review"), false);
    }

    public function postReturnSubmission($req, $res) {
        return $this->adminTransition($req, $res, "Returned for Changes", array("Pending Review"), false);
    }

    public function postApproveMedia($req, $res) {
        return $this->moderateRecord($req, $res, $this->mediaNamespace, array("Pending", "Approved", "Rejected"), "moderation_status");
    }

    public function postModerateReview($req, $res) {
        $record = $this->moderateRecord($req, $res, $this->reviewNamespace, $this->reviewStatuses, "moderation_status");
        if ($record !== null && isset($record->destination_id)) {
            $this->recalculateRating($record->destination_id);
        }
        return $record;
    }

    public function postModerateComment($req, $res) {
        return $this->moderateRecord($req, $res, $this->commentNamespace, $this->reviewStatuses, "moderation_status");
    }

    public function postModerateCondition($req, $res) {
        return $this->moderateRecord($req, $res, $this->conditionNamespace, $this->reviewStatuses, "moderation_status");
    }

    public function postResolveReport($req, $res) {
        $adminId = $this->requireAdmin($res);
        $body = $this->body($req);
        $report = isset($body->id) ? $this->findOne($this->reportNamespace, "id:" . intval($body->id)) : null;
        if ($adminId === null || $report === null) {
            return $this->fail($res, "Report was not found.");
        }
        $report->status = $this->allowedText($body, "status", array("Open", "Investigating", "Resolved", "Dismissed"), "Resolved");
        $report->assigned_moderator_profile_id = $adminId;
        $report->resolution_notes = $this->text($body, "resolution_notes", 3000);
        if (in_array($report->status, array("Resolved", "Dismissed"), true)) {
            $report->resolved_at = date("Y-m-d H:i:s");
        }
        return $this->saveObject($this->reportNamespace, "id", $report, $res);
    }

    public function postMergeDuplicateDestination($req, $res) {
        $adminId = $this->requireAdmin($res);
        $body = $this->body($req);
        $sourceId = isset($body->source_id) ? intval($body->source_id) : 0;
        $targetId = isset($body->target_id) ? intval($body->target_id) : 0;
        if ($adminId === null || $sourceId < 1 || $targetId < 1 || $sourceId === $targetId) {
            return $this->fail($res, "Different source and target destinations are required.");
        }
        $source = $this->findOne($this->destinationNamespace, "id:" . $sourceId);
        $target = $this->findOne($this->destinationNamespace, "id:" . $targetId);
        if ($source === null || $target === null) {
            return $this->fail($res, "Both destinations must exist.");
        }
        $sourceStatus = isset($source->status) ? $source->status : "";
        $source->status = "Archived";
        $source->moderation_reason = "Merged into destination " . $targetId;
        $this->saveObject($this->destinationNamespace, "id", $source, $res);
        $this->logTransition($sourceId, $adminId, $sourceStatus, "Archived", $source->moderation_reason);
        return array("source" => $source, "target" => $target);
    }

    public function postSaveCategory($req, $res) {
        return $this->saveReference($req, $res, $this->categoryNamespace);
    }

    public function postSaveAmenity($req, $res) {
        return $this->saveReference($req, $res, $this->amenityNamespace);
    }

    public function postGetModerationQueue($req, $res) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        return array(
            "destinations" => $this->rows($this->destinationNamespace, "status:Pending Review", "asc", 200, 0),
            "media" => $this->rows($this->mediaNamespace, "moderation_status:Pending", "asc", 200, 0),
            "reviews" => $this->rows($this->reviewNamespace, "moderation_status:Pending,is_active:1", "asc", 200, 0),
            "comments" => $this->rows($this->commentNamespace, "moderation_status:Pending,is_active:1", "asc", 200, 0),
            "conditions" => $this->rows($this->conditionNamespace, "moderation_status:Pending", "asc", 200, 0),
            "reports" => $this->rows($this->reportNamespace, "status:Open", "asc", 200, 0),
            "routes" => $this->rows($this->routeNamespace, "moderation_status:Pending", "asc", 200, 0),
            "visits" => $this->rows($this->visitNamespace, "verification_status:Pending", "asc", 200, 0),
            "guides" => $this->rows($this->guideNamespace, "verification_status:Pending", "asc", 200, 0)
        );
    }

    public function postSeedReferenceData($req, $res) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        $categories = array("Camping", "Hiking", "Stay", "Village", "Viewpoint", "Waterfall", "Beach", "Forest", "Mountain", "Lake or Reservoir", "Cultural Place", "Religious Place", "Wildlife or Nature Area");
        $amenities = array("Drinking water", "Natural water source", "Toilet", "Shower", "Electricity", "Mobile signal", "Wi-Fi", "Parking", "Public transport", "Food nearby", "Shop nearby", "Medical help nearby", "Guide available", "Pet friendly", "Child friendly", "Wheelchair access", "Cooking area", "Campfire area", "Security", "Waste disposal", "Changing rooms", "Equipment rental");
        $this->seedReferenceRows($this->categoryNamespace, $categories);
        $this->seedReferenceRows($this->amenityNamespace, $amenities);
        return array("categories" => $this->activeReferenceRows($this->categoryNamespace), "amenities" => $this->activeReferenceRows($this->amenityNamespace));
    }

    public function postGetDestinationRoutes($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) {
            return $this->fail($res, "Destination was not found.");
        }
        return $this->rows($this->routeNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved", "asc", 50, 0);
    }

    public function postGetOfflineDestinationBundle($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        $destination = $destinationId === null ? null : $this->findOne($this->destinationNamespace, "id:" . $destinationId);
        if ($destination === null || !$this->canReadDestination($destination)) {
            return $this->fail($res, "Destination was not found.");
        }
        $destination->categories = $this->linkedReferenceRows($destinationId, $this->categoryLinkNamespace, "category_id", $this->categoryNamespace);
        $destination->amenities = $this->linkedReferenceRows($destinationId, $this->amenityLinkNamespace, "amenity_id", $this->amenityNamespace);
        $destination->media = $this->approvedMedia($destinationId);
        $destination->description_markdown = $this->fullDestinationDescription($destination);
        $this->applyCoordinatePrivacy($destination, $this->canSeeExactCoordinates($destination));
        return array(
            "bundleVersion" => 1,
            "savedAt" => gmdate("c"),
            "destination" => $destination,
            "routes" => $this->rows($this->routeNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved", "asc", 50, 0),
            "availability" => $this->publicAvailability($destinationId),
            "conditions" => $this->activeConditions($destinationId),
            "notice" => "Offline information may be outdated. Reconnect and verify weather, access, permits and safety before travel."
        );
    }

    public function postGetDestinationVisitSummary($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) {
            return $this->fail($res, "Destination was not found.");
        }
        $visits = $this->rows($this->visitNamespace, "destination_id:" . $destinationId . ",verification_status:Verified", "desc", 1000, 0);
        return array("verifiedVisits" => count($visits), "latestVerifiedVisit" => count($visits) && isset($visits[0]->visit_date) ? $visits[0]->visit_date : null);
    }

    public function postGetDestinationAvailability($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) {
            return $this->fail($res, "Destination was not found.");
        }
        return $this->publicAvailability($destinationId);
    }

    public function postGetDestinationGuides($req, $res) {
        $body = $this->body($req);
        $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) {
            return $this->fail($res, "Destination was not found.");
        }
        $links = $this->rows($this->guideDestinationNamespace, "destination_id:" . $destinationId, "asc", 100, 0);
        $guides = array();
        foreach ($links as $link) {
            $guide = $this->findOne($this->guideNamespace, "id:" . intval($link->guide_id));
            if ($guide === null || !isset($guide->verification_status) || $guide->verification_status !== "Verified" || !$this->booleanValue($guide, "is_available")) {
                continue;
            }
            $profile = $this->findOne("profile", "id:" . intval($guide->profile_id));
            $guides[] = array(
                "id" => intval($guide->id), "profileId" => intval($guide->profile_id),
                "name" => $profile && isset($profile->name) ? strval($profile->name) : "Verified local guide",
                "headline" => isset($guide->headline) ? $guide->headline : "", "bio_markdown" => isset($guide->bio_markdown) ? $guide->bio_markdown : "",
                "languages" => isset($guide->languages) ? $guide->languages : "", "service_areas" => isset($guide->service_areas) ? $guide->service_areas : "",
                "public_contact" => isset($guide->public_contact) ? $guide->public_contact : "", "booking_url" => isset($guide->booking_url) ? $guide->booking_url : "",
                "verification_status" => "Verified"
            );
        }
        return $guides;
    }

    public function postGetSearchSuggestions($req, $res) {
        $body = $this->body($req);
        $query = mb_strtolower($this->text($body, "query", 80));
        $limit = isset($body->limit) ? min(12, max(1, intval($body->limit))) : 8;
        if (mb_strlen($query) < 2) {
            return array();
        }
        $values = array();
        foreach ($this->rows($this->destinationNamespace, "status:Published", "desc", 500, 0) as $destination) {
            foreach (array("name", "province", "district", "nearest_town", "village") as $field) {
                if (!empty($destination->{$field})) { $values[] = array("label" => strval($destination->{$field}), "type" => $field === "name" ? "destination" : "location", "destinationId" => $field === "name" ? intval($destination->id) : null); }
            }
            if (!empty($destination->tags)) {
                foreach (preg_split('/\s*,\s*/', strval($destination->tags)) as $tag) { if ($tag !== "") { $values[] = array("label" => $tag, "type" => "tag", "destinationId" => null); } }
            }
        }
        foreach ($this->activeReferenceRows($this->categoryNamespace) as $category) { $values[] = array("label" => $category->name, "type" => "category", "destinationId" => null); }
        $unique = array();
        foreach ($values as $value) {
            $label = trim($value["label"]); $lower = mb_strtolower($label); $position = mb_strpos($lower, $query);
            if ($position === false || isset($unique[$lower])) { continue; }
            $value["score"] = $position === 0 ? 100 - mb_strlen($label) : 40 - $position; $unique[$lower] = $value;
        }
        $suggestions = array_values($unique);
        usort($suggestions, function ($left, $right) { return $right["score"] <=> $left["score"]; });
        return array_slice($suggestions, 0, $limit);
    }

    public function getGetFeaturedCollections($req, $res) {
        $items = $this->rows($this->collectionNamespace, "publication_status:Published,is_featured:1", "desc", 20, 0);
        foreach ($items as $item) { $item->destination_count = count($this->rows($this->collectionItemNamespace, "collection_id:" . intval($item->id), "asc", 500, 0)); }
        return $items;
    }

    public function postGetCollection($req, $res) {
        $body = $this->body($req);
        $collection = isset($body->id) ? $this->findOne($this->collectionNamespace, "id:" . intval($body->id)) : null;
        if ($collection === null && !empty($body->slug)) { $collection = $this->findOne($this->collectionNamespace, "slug:" . TravelDestinationRules::slug($body->slug)); }
        if ($collection === null || !isset($collection->publication_status) || $collection->publication_status !== "Published") { return $this->fail($res, "Collection was not found."); }
        $collection->destinations = array();
        foreach ($this->rows($this->collectionItemNamespace, "collection_id:" . intval($collection->id), "asc", 200, 0) as $link) {
            $destination = $this->publicDestinationSummary(intval($link->destination_id));
            if ($destination !== null) { $destination->editor_note = isset($link->editor_note) ? $link->editor_note : ""; $collection->destinations[] = $destination; }
        }
        return $collection;
    }

    public function postGetDestinationTranslations($req, $res) {
        $body = $this->body($req); $destinationId = $this->requiredId($body, "destinationId", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) { return $this->fail($res, "Destination was not found."); }
        $rows = $this->rows($this->translationNamespace, "destination_id:" . $destinationId . ",moderation_status:Approved", "asc", 50, 0);
        foreach ($rows as $row) { unset($row->translated_by_profile_id); }
        return $rows;
    }

    public function postGetRecommendations($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; }
        $signalIds = array();
        foreach ($this->rows($this->favoriteNamespace, "profile_id:" . $profileId, "desc", 200, 0) as $row) { $signalIds[intval($row->destination_id)] = true; }
        foreach ($this->rows($this->visitNamespace, "profile_id:" . $profileId . ",verification_status:Verified", "desc", 200, 0) as $row) { $signalIds[intval($row->destination_id)] = true; }
        foreach ($this->rows($this->listNamespace, "profile_id:" . $profileId, "desc", 100, 0) as $list) { foreach ($this->rows($this->listItemNamespace, "list_id:" . intval($list->id), "asc", 200, 0) as $row) { $signalIds[intval($row->destination_id)] = true; } }
        $categoryScores = array(); $tagScores = array(); $provinceScores = array();
        foreach (array_keys($signalIds) as $destinationId) {
            $source = $this->findOne($this->destinationNamespace, "id:" . $destinationId); if ($source === null) { continue; }
            foreach ($this->linkedReferenceRows($destinationId, $this->categoryLinkNamespace, "category_id", $this->categoryNamespace) as $category) { $categoryScores[intval($category->id)] = isset($categoryScores[intval($category->id)]) ? $categoryScores[intval($category->id)] + 3 : 3; }
            foreach (preg_split('/\s*,\s*/', mb_strtolower(isset($source->tags) ? $source->tags : "")) as $tag) { if ($tag !== "") { $tagScores[$tag] = isset($tagScores[$tag]) ? $tagScores[$tag] + 1 : 1; } }
            if (!empty($source->province)) { $provinceScores[mb_strtolower($source->province)] = 2; }
        }
        $recommendations = array();
        foreach ($this->rows($this->destinationNamespace, "status:Published", "desc", 500, 0) as $candidate) {
            if (isset($signalIds[intval($candidate->id)])) { continue; }
            $score = $this->booleanValue($candidate, "is_featured") ? 1 : 0; $reasons = array();
            foreach ($this->linkedReferenceRows($candidate->id, $this->categoryLinkNamespace, "category_id", $this->categoryNamespace) as $category) { if (isset($categoryScores[intval($category->id)])) { $score += $categoryScores[intval($category->id)]; $reasons[] = $category->name; } }
            foreach (preg_split('/\s*,\s*/', mb_strtolower(isset($candidate->tags) ? $candidate->tags : "")) as $tag) { if (isset($tagScores[$tag])) { $score += $tagScores[$tag]; } }
            if (!empty($candidate->province) && isset($provinceScores[mb_strtolower($candidate->province)])) { $score += 2; $reasons[] = $candidate->province; }
            $candidate->recommendation_score = $score; $candidate->recommendation_reason = count($reasons) ? "Matches your interest in " . implode(", ", array_slice(array_unique($reasons), 0, 2)) : "Featured destination";
            $this->applyCoordinatePrivacy($candidate, false); $recommendations[] = $candidate;
        }
        usort($recommendations, function ($left, $right) { $score = intval($right->recommendation_score) <=> intval($left->recommendation_score); return $score !== 0 ? $score : floatval($right->rating_average) <=> floatval($left->rating_average); });
        return array_slice($recommendations, 0, 12);
    }

    public function postSaveDestinationRoute($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; }
        $body = $this->body($req); $destinationId = $this->requiredId($body, "destination_id", $res);
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) { return $this->fail($res, "Destination was not found."); }
        $format = $this->allowedText($body, "format", array("geojson", "gpx"), "geojson");
        $route = new \stdClass(); $route->destination_id = $destinationId; $route->name = $this->text($body, "name", 180);
        $route->route_type = $this->allowedText($body, "route_type", $this->routeTypes, "out_and_back"); $route->format = $format;
        $route->geojson = $format === "geojson" ? $this->normalizeGeoJson(isset($body->geojson) ? $body->geojson : null) : null;
        $route->gpx_media_reference = $format === "gpx" ? $this->validatedRouteMediaReference($this->text($body, "gpx_media_reference", 500)) : "";
        if ($route->name === "" || ($format === "geojson" && $route->geojson === null) || ($format === "gpx" && $route->gpx_media_reference === "")) { return $this->fail($res, "Add a route name and valid GeoJSON or uploaded GPX reference."); }
        $route->distance_km = isset($body->distance_km) ? max(0, min(1000, floatval($body->distance_km))) : 0; $route->elevation_gain_m = isset($body->elevation_gain_m) ? max(0, min(20000, floatval($body->elevation_gain_m))) : 0;
        $route->estimated_minutes = isset($body->estimated_minutes) ? max(0, min(100000, intval($body->estimated_minutes))) : 0; $route->uploaded_by_profile_id = $profileId; $route->moderation_status = "Pending"; $route->display_order = 0;
        return $this->saveObject($this->routeNamespace, "id", $route, $res);
    }

    public function postSubmitVerifiedVisit($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; }
        $body = $this->body($req); $destinationId = $this->requiredId($body, "destination_id", $res); $visitDate = isset($body->visit_date) ? $this->validDateValue($body->visit_date) : "";
        if ($destinationId === null || !$this->isPublishedDestination($destinationId) || $visitDate === "" || strtotime($visitDate) > time()) { return $this->fail($res, "Choose a published destination and a valid visit date that is not in the future."); }
        $existing = $this->findOne($this->visitNamespace, "destination_id:" . $destinationId . ",profile_id:" . $profileId . ",visit_date:" . $visitDate);
        if ($existing !== null) { return $this->fail($res, "A visit for this destination and date already exists."); }
        $visit = new \stdClass(); $visit->destination_id = $destinationId; $visit->profile_id = $profileId; $visit->visit_date = $visitDate;
        $visit->verification_method = $this->allowedText($body, "verification_method", array("photo", "booking", "guide", "manual"), "manual");
        $visit->evidence_media_reference = $this->text($body, "evidence_media_reference", 500); $visit->notes = $this->text($body, "notes", 1500); $visit->verification_status = "Pending"; $visit->verified_by_profile_id = 0;
        return $this->saveObject($this->visitNamespace, "id", $visit, $res);
    }

    public function postGetMyVisits($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $result = $this->pagedRows($this->visitNamespace, "profile_id:" . $profileId, $body, 20, 100);
        foreach ($result["items"] as $item) { $item->destination = $this->publicDestinationSummary(intval($item->destination_id)); }
        return $result;
    }

    public function postSaveTravelList($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $list = isset($body->id) ? $this->findOne($this->listNamespace, "id:" . intval($body->id)) : null;
        if ($list !== null && intval($list->profile_id) !== $profileId) { return $this->fail($res, "You may edit only your own list."); }
        if ($list === null && count($this->rows($this->listNamespace, "profile_id:" . $profileId, "desc", 100, 0)) >= 20) { return $this->fail($res, "You may create up to 20 travel lists."); }
        if ($list === null) { $list = new \stdClass(); $list->profile_id = $profileId; $list->is_default = false; }
        $list->name = $this->text($body, "name", 120); $list->description = $this->text($body, "description", 1000); $list->is_public = $this->bodyBoolean($body, "is_public");
        if ($list->name === "") { return $this->fail($res, "List name is required."); }
        return $this->saveObject($this->listNamespace, "id", $list, $res);
    }

    public function postDeleteTravelList($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $list = isset($body->id) ? $this->findOne($this->listNamespace, "id:" . intval($body->id)) : null;
        if ($list === null || intval($list->profile_id) !== $profileId || $this->booleanValue($list, "is_default")) { return $this->fail($res, "This list cannot be deleted."); }
        foreach ($this->rows($this->listItemNamespace, "list_id:" . intval($list->id), "asc", 1000, 0) as $item) { \SOSSData::Delete($this->listItemNamespace, $item); }
        $deleted = \SOSSData::Delete($this->listNamespace, $list); return !empty($deleted->success) ? array("deleted" => true, "id" => intval($list->id)) : $this->fail($res, "List could not be deleted.");
    }

    public function postAddDestinationToList($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $listId = $this->requiredId($body, "list_id", $res); $destinationId = $this->requiredId($body, "destination_id", $res); if ($listId === null || $destinationId === null) { return null; }
        $list = $this->findOne($this->listNamespace, "id:" . $listId); if ($list === null || intval($list->profile_id) !== $profileId || !$this->isPublishedDestination($destinationId)) { return $this->fail($res, "List or destination is unavailable."); }
        $existing = $this->findOne($this->listItemNamespace, "list_id:" . $listId . ",destination_id:" . $destinationId); if ($existing !== null) { return $existing; }
        $item = new \stdClass(); $item->list_id = $listId; $item->destination_id = $destinationId; $item->notes = $this->text($body, "notes", 1000); $item->display_order = isset($body->display_order) ? max(0, intval($body->display_order)) : 0;
        return $this->saveObject($this->listItemNamespace, "id", $item, $res);
    }

    public function postRemoveDestinationFromList($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $listId = $this->requiredId($body, "list_id", $res); $destinationId = $this->requiredId($body, "destination_id", $res); if ($listId === null || $destinationId === null) { return null; }
        $list = $this->findOne($this->listNamespace, "id:" . $listId); $item = $this->findOne($this->listItemNamespace, "list_id:" . $listId . ",destination_id:" . $destinationId);
        if ($list === null || intval($list->profile_id) !== $profileId || $item === null) { return $this->fail($res, "List item was not found."); }
        $deleted = \SOSSData::Delete($this->listItemNamespace, $item); return !empty($deleted->success) ? array("removed" => true) : $this->fail($res, "List item could not be removed.");
    }

    public function postGetMyTravelLists($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; }
        $lists = $this->rows($this->listNamespace, "profile_id:" . $profileId, "desc", 100, 0);
        foreach ($lists as $list) { $list->items = array(); foreach ($this->rows($this->listItemNamespace, "list_id:" . intval($list->id), "asc", 500, 0) as $item) { $destination = $this->publicDestinationSummary(intval($item->destination_id)); if ($destination !== null) { $destination->list_item_id = intval($item->id); $destination->list_notes = isset($item->notes) ? $item->notes : ""; $list->items[] = $destination; } } }
        return $lists;
    }

    public function postSaveGuideProfile($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $guide = $this->findOne($this->guideNamespace, "profile_id:" . $profileId); if ($guide === null) { $guide = new \stdClass(); $guide->profile_id = $profileId; }
        $guide->headline = $this->text($body, "headline", 180); $guide->bio_markdown = TravelDestinationRules::plainMarkdown(isset($body->bio_markdown) ? $body->bio_markdown : "", 6000);
        $guide->languages = $this->text($body, "languages", 500); $guide->service_areas = $this->text($body, "service_areas", 1000); $guide->public_contact = $this->text($body, "public_contact", 255);
        $guide->booking_url = $this->safeHttpUrl($this->text($body, "booking_url", 500)); $guide->is_available = $this->bodyBoolean($body, "is_available"); $guide->verification_status = "Pending"; $guide->verified_by_profile_id = 0;
        if ($guide->headline === "" || mb_strlen($guide->bio_markdown) < 20 || $guide->languages === "") { return $this->fail($res, "Guide headline, languages and a short biography are required."); }
        $saved = $this->saveObject($this->guideNamespace, "id", $guide, $res); if ($saved !== null) { $this->syncGuideDestinations(intval($saved->id), isset($body->destination_ids) && is_array($body->destination_ids) ? $body->destination_ids : array(), $res); }
        return $saved;
    }

    public function getGetMyGuideProfile($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; }
        $guide = $this->findOne($this->guideNamespace, "profile_id:" . $profileId); if ($guide === null) { return array("profile_id" => $profileId, "verification_status" => "Not submitted", "destination_ids" => array()); }
        $guide->destination_ids = array_map(function ($link) { return intval($link->destination_id); }, $this->rows($this->guideDestinationNamespace, "guide_id:" . intval($guide->id), "asc", 200, 0)); return $guide;
    }

    public function getGetNotificationPreferences($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } return $this->notificationPreferences($profileId);
    }

    public function postSaveNotificationPreferences($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $preference = $this->findOne($this->notificationPreferenceNamespace, "profile_id:" . $profileId); if ($preference === null) { $preference = new \stdClass(); $preference->profile_id = $profileId; }
        foreach (array("submission_updates", "condition_alerts", "trip_reminders", "recommendation_updates") as $field) { $preference->{$field} = $this->bodyBoolean($body, $field); }
        return $this->saveObject($this->notificationPreferenceNamespace, "id", $preference, $res);
    }

    public function postSaveTrip($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req);
        $trip = isset($body->id) ? $this->findOne($this->tripNamespace, "id:" . intval($body->id)) : null; if ($trip !== null && intval($trip->profile_id) !== $profileId) { return $this->fail($res, "You may edit only your own trip."); }
        if ($trip === null && count($this->rows($this->tripNamespace, "profile_id:" . $profileId, "desc", 100, 0)) >= 30) { return $this->fail($res, "You may create up to 30 trips."); }
        if ($trip === null) { $trip = new \stdClass(); $trip->profile_id = $profileId; }
        $trip->name = $this->text($body, "name", 180); $trip->start_date = isset($body->start_date) && trim($body->start_date) !== "" ? $this->validDateValue($body->start_date) : ""; $trip->end_date = isset($body->end_date) && trim($body->end_date) !== "" ? $this->validDateValue($body->end_date) : "";
        $trip->notes = $this->text($body, "notes", 3000); $trip->status = $this->allowedText($body, "status", array("Planning", "Confirmed", "Completed", "Cancelled"), "Planning");
        if ($trip->name === "" || ($trip->start_date !== "" && $trip->end_date !== "" && strtotime($trip->end_date) < strtotime($trip->start_date))) { return $this->fail($res, "Trip name and a valid date range are required."); }
        return $this->saveObject($this->tripNamespace, "id", $trip, $res);
    }

    public function postDeleteTrip($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req); $trip = isset($body->id) ? $this->findOne($this->tripNamespace, "id:" . intval($body->id)) : null;
        if ($trip === null || intval($trip->profile_id) !== $profileId) { return $this->fail($res, "Trip was not found."); }
        foreach ($this->rows($this->tripItemNamespace, "trip_id:" . intval($trip->id), "asc", 1000, 0) as $item) { \SOSSData::Delete($this->tripItemNamespace, $item); }
        $deleted = \SOSSData::Delete($this->tripNamespace, $trip); return !empty($deleted->success) ? array("deleted" => true) : $this->fail($res, "Trip could not be deleted.");
    }

    public function postAddTripDestination($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req); $tripId = $this->requiredId($body, "trip_id", $res); $destinationId = $this->requiredId($body, "destination_id", $res);
        if ($tripId === null || $destinationId === null) { return null; } $trip = $this->findOne($this->tripNamespace, "id:" . $tripId); if ($trip === null || intval($trip->profile_id) !== $profileId || !$this->isPublishedDestination($destinationId)) { return $this->fail($res, "Trip or destination is unavailable."); }
        $item = $this->findOne($this->tripItemNamespace, "trip_id:" . $tripId . ",destination_id:" . $destinationId); if ($item === null) { $item = new \stdClass(); $item->trip_id = $tripId; $item->destination_id = $destinationId; }
        $item->planned_date = isset($body->planned_date) && trim($body->planned_date) !== "" ? $this->validDateValue($body->planned_date) : ""; $item->arrival_time = $this->text($body, "arrival_time", 10); $item->notes = $this->text($body, "notes", 1500); $item->display_order = isset($body->display_order) ? max(0, intval($body->display_order)) : 0;
        return $this->saveObject($this->tripItemNamespace, "id", $item, $res);
    }

    public function postRemoveTripDestination($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $body = $this->body($req); $item = isset($body->item_id) ? $this->findOne($this->tripItemNamespace, "id:" . intval($body->item_id)) : null; $trip = $item ? $this->findOne($this->tripNamespace, "id:" . intval($item->trip_id)) : null;
        if ($item === null || $trip === null || intval($trip->profile_id) !== $profileId) { return $this->fail($res, "Trip item was not found."); }
        $deleted = \SOSSData::Delete($this->tripItemNamespace, $item); return !empty($deleted->success) ? array("removed" => true) : $this->fail($res, "Trip item could not be removed.");
    }

    public function postGetMyTrips($req, $res) {
        $profileId = $this->requireProfile($res); if ($profileId === null) { return null; } $trips = $this->rows($this->tripNamespace, "profile_id:" . $profileId, "desc", 100, 0);
        foreach ($trips as $trip) { $trip->items = array(); foreach ($this->rows($this->tripItemNamespace, "trip_id:" . intval($trip->id), "asc", 500, 0) as $item) { $item->destination = $this->publicDestinationSummary(intval($item->destination_id)); if ($item->destination !== null) { $trip->items[] = $item; } } }
        return $trips;
    }

    public function postModerateDestinationRoute($req, $res) { return $this->moderatePhaseTwoRecord($req, $res, $this->routeNamespace, "moderation_status", array("Approved", "Rejected"), "uploaded_by_profile_id", "route"); }
    public function postModerateVerifiedVisit($req, $res) { return $this->moderatePhaseTwoRecord($req, $res, $this->visitNamespace, "verification_status", $this->visitStatuses, "profile_id", "visit"); }
    public function postVerifyGuideProfile($req, $res) { return $this->moderatePhaseTwoRecord($req, $res, $this->guideNamespace, "verification_status", array("Verified", "Rejected"), "profile_id", "guide"); }

    public function postSaveDestinationAvailability($req, $res) {
        if ($this->requireAdmin($res) === null) { return null; } $body = $this->body($req); $destinationId = $this->requiredId($body, "destination_id", $res); if ($destinationId === null || !$this->isReadableDestinationId($destinationId)) { return $this->fail($res, "Destination was not found."); }
        $record = isset($body->id) ? $this->findOne($this->availabilityNamespace, "id:" . intval($body->id)) : new \stdClass(); if ($record === null) { return $this->fail($res, "Availability record was not found."); }
        $record->destination_id = $destinationId; $record->provider_type = $this->allowedText($body, "provider_type", array("external", "manual", "davvag_orders"), "manual"); $record->external_reference = $this->text($body, "external_reference", 180);
        $record->available_from = isset($body->available_from) && trim($body->available_from) !== "" ? $this->validDateValue($body->available_from) : ""; $record->available_to = isset($body->available_to) && trim($body->available_to) !== "" ? $this->validDateValue($body->available_to) : "";
        $record->availability_status = $this->allowedText($body, "availability_status", array("Available", "Limited", "Unavailable", "Contact"), "Contact"); $record->inventory_summary = $this->text($body, "inventory_summary", 1000); $record->price_from = isset($body->price_from) ? max(0, floatval($body->price_from)) : 0;
        $record->currency_code = strtoupper($this->text($body, "currency_code", 3)); if ($record->currency_code !== "" && !preg_match('/^[A-Z]{3}$/', $record->currency_code)) { return $this->fail($res, "Currency code must contain three letters."); }
        $record->booking_url = $this->safeHttpUrl($this->text($body, "booking_url", 500)); $record->last_checked_at = date("Y-m-d H:i:s"); $record->moderation_status = "Approved";
        if ($record->available_from !== "" && $record->available_to !== "" && strtotime($record->available_to) < strtotime($record->available_from)) { return $this->fail($res, "Availability date range is invalid."); }
        return $this->saveObject($this->availabilityNamespace, "id", $record, $res);
    }

    public function postSaveDestinationTranslation($req, $res) {
        $profileId = $this->requireAdmin($res); if ($profileId === null) { return null; } $body = $this->body($req); $destinationId = $this->requiredId($body, "destination_id", $res); $language = strtolower($this->text($body, "language_code", 20));
        if ($destinationId === null || !$this->isReadableDestinationId($destinationId) || !preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})?$/', $language)) { return $this->fail($res, "Destination or language code is invalid."); }
        $record = $this->findOne($this->translationNamespace, "destination_id:" . $destinationId . ",language_code:" . $language); if ($record === null) { $record = new \stdClass(); $record->destination_id = $destinationId; $record->language_code = $language; }
        $record->name = $this->text($body, "name", 255); $record->short_summary = $this->text($body, "short_summary", 1000); $record->description_markdown = TravelDestinationRules::plainMarkdown(isset($body->description_markdown) ? $body->description_markdown : "", 20000); $record->moderation_status = "Approved"; $record->translated_by_profile_id = $profileId;
        if ($record->name === "" || $record->short_summary === "") { return $this->fail($res, "Translated name and summary are required."); }
        return $this->saveObject($this->translationNamespace, "id", $record, $res);
    }

    public function postSaveFeaturedCollection($req, $res) {
        $profileId = $this->requireAdmin($res); if ($profileId === null) { return null; } $body = $this->body($req); $record = isset($body->id) ? $this->findOne($this->collectionNamespace, "id:" . intval($body->id)) : new \stdClass(); if ($record === null) { return $this->fail($res, "Collection was not found."); }
        $record->title = $this->text($body, "title", 255); $record->slug = TravelDestinationRules::slug(!empty($body->slug) ? $body->slug : $record->title); $record->summary = $this->text($body, "summary", 1500); $record->cover_media_reference = $this->text($body, "cover_media_reference", 500);
        $record->is_featured = $this->bodyBoolean($body, "is_featured"); $record->publication_status = $this->allowedText($body, "publication_status", array("Draft", "Published", "Archived"), "Draft"); $record->published_at = $record->publication_status === "Published" ? date("Y-m-d H:i:s") : ""; $record->created_by_profile_id = $profileId;
        if ($record->title === "" || $record->slug === "") { return $this->fail($res, "Collection title is required."); }
        $saved = $this->saveObject($this->collectionNamespace, "id", $record, $res); if ($saved !== null) { $this->syncCollectionItems(intval($saved->id), isset($body->destination_ids) && is_array($body->destination_ids) ? $body->destination_ids : array(), $res); }
        return $saved;
    }

    public function postGetPhaseTwoAdminData($req, $res) {
        if ($this->requireAdmin($res) === null) { return null; }
        return array(
            "routes" => $this->rows($this->routeNamespace, "moderation_status:Pending", "asc", 200, 0),
            "visits" => $this->rows($this->visitNamespace, "verification_status:Pending", "asc", 200, 0),
            "guides" => $this->rows($this->guideNamespace, "verification_status:Pending", "asc", 200, 0),
            "collections" => $this->rows($this->collectionNamespace, "", "desc", 200, 0),
            "availability" => $this->rows($this->availabilityNamespace, "", "desc", 200, 0),
            "translations" => $this->rows($this->translationNamespace, "", "desc", 200, 0)
        );
    }

    private function isReadableDestinationId($destinationId) {
        $destination = $this->findOne($this->destinationNamespace, "id:" . intval($destinationId));
        return $destination !== null && $this->canReadDestination($destination);
    }

    private function publicDestinationSummary($destinationId) {
        $destination = $this->findOne($this->destinationNamespace, "id:" . intval($destinationId));
        if ($destination === null || !isset($destination->status) || $destination->status !== "Published") { return null; }
        $this->applyCoordinatePrivacy($destination, false); unset($destination->description_markdown, $destination->camping_info, $destination->hiking_info, $destination->stay_info, $destination->village_info, $destination->moderation_notes, $destination->moderation_reason);
        return $destination;
    }

    private function publicAvailability($destinationId) {
        $today = strtotime(date("Y-m-d")); $items = array();
        foreach ($this->rows($this->availabilityNamespace, "destination_id:" . intval($destinationId) . ",moderation_status:Approved", "desc", 100, 0) as $item) {
            if (!empty($item->available_to) && strtotime($item->available_to) < $today) { continue; }
            unset($item->external_reference); $items[] = $item;
        }
        return $items;
    }

    private function activeConditions($destinationId) {
        $now = time(); return array_values(array_filter($this->rows($this->conditionNamespace, "destination_id:" . intval($destinationId) . ",moderation_status:Approved", "desc", 100, 0), function ($item) use ($now) { return empty($item->expires_at) || strtotime($item->expires_at) >= $now; }));
    }

    private function normalizeGeoJson($value) {
        if (is_string($value)) { if (strlen($value) > 500000) { return null; } $value = json_decode($value, true); }
        elseif (is_object($value)) { $value = json_decode(json_encode($value), true); }
        if (!is_array($value)) { return null; }
        if (isset($value["type"]) && in_array($value["type"], array("LineString", "MultiLineString"), true)) { $value = array("type" => "Feature", "properties" => array(), "geometry" => $value); }
        if (isset($value["type"]) && $value["type"] === "Feature") { $value = array("type" => "FeatureCollection", "features" => array($value)); }
        if (!isset($value["type"]) || $value["type"] !== "FeatureCollection" || !isset($value["features"]) || !is_array($value["features"]) || count($value["features"]) > 25) { return null; }
        $safeFeatures = array(); $coordinateCount = 0;
        foreach ($value["features"] as $feature) {
            if (!is_array($feature) || !isset($feature["geometry"]) || !is_array($feature["geometry"])) { return null; }
            $geometry = $feature["geometry"]; $type = isset($geometry["type"]) ? $geometry["type"] : "";
            if (!in_array($type, array("LineString", "MultiLineString"), true) || !isset($geometry["coordinates"])) { return null; }
            $coordinates = $this->sanitizeRouteCoordinates($geometry["coordinates"], $type === "MultiLineString" ? 2 : 1, $coordinateCount); if ($coordinates === null || $coordinateCount > 10000) { return null; }
            $properties = isset($feature["properties"]) && is_array($feature["properties"]) ? $feature["properties"] : array();
            $safeFeatures[] = array("type" => "Feature", "properties" => array("name" => isset($properties["name"]) ? mb_substr(strip_tags(strval($properties["name"])), 0, 180) : "", "color" => isset($properties["color"]) && preg_match('/^#[0-9a-f]{6}$/i', $properties["color"]) ? $properties["color"] : "#c76443"), "geometry" => array("type" => $type, "coordinates" => $coordinates));
        }
        return json_decode(json_encode(array("type" => "FeatureCollection", "features" => $safeFeatures)));
    }

    private function sanitizeRouteCoordinates($coordinates, $depth, &$count) {
        if (!is_array($coordinates)) { return null; }
        if ($depth > 1) { $safe = array(); foreach ($coordinates as $child) { $normalized = $this->sanitizeRouteCoordinates($child, $depth - 1, $count); if ($normalized === null) { return null; } $safe[] = $normalized; } return $safe; }
        $safe = array(); foreach ($coordinates as $point) { if (!is_array($point) || count($point) < 2 || !TravelDestinationRules::validCoordinates($point[1], $point[0])) { return null; } $safe[] = array(round(floatval($point[0]), 7), round(floatval($point[1]), 7)); $count++; }
        return count($safe) >= 2 ? $safe : null;
    }

    private function validatedRouteMediaReference($value) {
        $value = trim(strval($value)); return preg_match('#^components/dock/soss-uploader/service/get/travel_destination_route/[A-Za-z0-9._-]+\.gpx$#i', $value) ? $value : "";
    }

    private function safeHttpUrl($value) {
        $value = trim(strval($value)); if ($value === "") { return ""; } $parts = parse_url($value);
        if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"]) || !in_array(strtolower($parts["scheme"]), array("https", "http"), true) || isset($parts["user"]) || isset($parts["pass"])) { return ""; }
        if (isset($parts["port"]) && !in_array(intval($parts["port"]), array(80, 443), true)) { return ""; } return $value;
    }

    private function syncGuideDestinations($guideId, $destinationIds, $res) {
        foreach ($this->rows($this->guideDestinationNamespace, "guide_id:" . intval($guideId), "asc", 500, 0) as $link) { \SOSSData::Delete($this->guideDestinationNamespace, $link); }
        $links = array(); foreach (array_slice(array_values(array_unique(array_map("intval", $destinationIds))), 0, 50) as $destinationId) { if ($this->isPublishedDestination($destinationId)) { $link = new \stdClass(); $link->guide_id = intval($guideId); $link->destination_id = $destinationId; $links[] = $link; } }
        if (count($links)) { $saved = \SOSSData::Insert($this->guideDestinationNamespace, $links); if (empty($saved->success)) { $res->SetError("Guide destinations could not be saved."); } }
    }

    private function syncCollectionItems($collectionId, $destinationIds, $res) {
        foreach ($this->rows($this->collectionItemNamespace, "collection_id:" . intval($collectionId), "asc", 1000, 0) as $link) { \SOSSData::Delete($this->collectionItemNamespace, $link); }
        $links = array(); foreach (array_slice(array_values(array_unique(array_map("intval", $destinationIds))), 0, 100) as $index => $destinationId) { if ($this->isPublishedDestination($destinationId)) { $link = new \stdClass(); $link->collection_id = intval($collectionId); $link->destination_id = $destinationId; $link->editor_note = ""; $link->display_order = $index + 1; $links[] = $link; } }
        if (count($links)) { $saved = \SOSSData::Insert($this->collectionItemNamespace, $links); if (empty($saved->success)) { $res->SetError("Collection destinations could not be saved."); } }
    }

    private function notificationPreferences($profileId) {
        $preference = $this->findOne($this->notificationPreferenceNamespace, "profile_id:" . intval($profileId));
        return $preference !== null ? $preference : array("profile_id" => intval($profileId), "submission_updates" => true, "condition_alerts" => true, "trip_reminders" => true, "recommendation_updates" => false);
    }

    private function moderatePhaseTwoRecord($req, $res, $namespace, $field, $statuses, $ownerField, $entityType) {
        $adminId = $this->requireAdmin($res); if ($adminId === null) { return null; } $body = $this->body($req); $record = isset($body->id) ? $this->findOne($namespace, "id:" . intval($body->id)) : null; $status = isset($body->status) ? trim($body->status) : "";
        if ($record === null || !in_array($status, $statuses, true)) { return $this->fail($res, "Record or moderation status is invalid."); }
        $record->{$field} = $status; if (property_exists($record, "verified_by_profile_id")) { $record->verified_by_profile_id = $adminId; $record->verified_at = date("Y-m-d H:i:s"); }
        $saved = $this->saveObject($namespace, "id", $record, $res); if ($saved !== null && isset($record->{$ownerField})) { $this->notifyProfile(intval($record->{$ownerField}), ucfirst($entityType) . " update", "Your " . $entityType . " status is now " . $status . ".", "#/app/travel-destinations/favorites"); }
        return $saved;
    }

    private function notifyProfile($profileId, $title, $message, $url) {
        if ($profileId < 1 || !class_exists("\\Profile")) { return; } $preferences = $this->notificationPreferences($profileId); $enabled = is_array($preferences) ? !empty($preferences["submission_updates"]) : $this->booleanValue($preferences, "submission_updates"); if (!$enabled) { return; }
        $data = new \stdClass(); $data->title = $title; $data->message = $message; $data->url = $url; $queued = \Profile::AddNotify($profileId, "travel_destination_update", $data, $url); if (is_object($queued)) { \Profile::Send_Notify(); }
    }

    private function search($body, $mapOnly, $res) {
        $page = isset($body->page) ? max(0, intval($body->page)) : 0;
        $pageSize = isset($body->pageSize) ? intval($body->pageSize) : 20;
        $pageSize = min($mapOnly ? 250 : 50, max(1, $pageSize));
        $sort = isset($body->sort) ? strtolower(trim($body->sort)) : "featured";
        if (!in_array($sort, $this->sortValues, true)) {
            return $this->fail($res, "Invalid destination sort.");
        }
        $latitude = isset($body->latitude) ? $body->latitude : null;
        $longitude = isset($body->longitude) ? $body->longitude : null;
        $radius = isset($body->radius) ? intval($body->radius) : null;
        if (($sort === "nearest" || $radius !== null) && !TravelDestinationRules::validCoordinates($latitude, $longitude)) {
            return $this->fail($res, "Nearest search requires valid coordinates.");
        }
        if ($radius !== null && !TravelDestinationRules::validRadius($radius)) {
            return $this->fail($res, "Invalid search radius.");
        }
        $rows = $this->rows($this->destinationNamespace, "status:Published", "desc", 1000, 0);
        $categoryId = isset($body->categoryId) ? intval($body->categoryId) : 0;
        $amenityIds = isset($body->amenityIds) && is_array($body->amenityIds) ? array_values(array_unique(array_map("intval", $body->amenityIds))) : array();
        $categoryDestinationIds = $categoryId > 0 ? $this->destinationIdsForLink($this->categoryLinkNamespace, "category_id", $categoryId) : null;
        $amenityDestinationIds = count($amenityIds) > 0 ? $this->destinationIdsWithAllAmenities($amenityIds) : null;
        $keyword = isset($body->keyword) ? mb_strtolower(trim($body->keyword)) : "";
        $searchLanguage = isset($body->language) ? strtolower(trim(strval($body->language))) : "";
        $minimumRating = isset($body->minimumRating) ? max(0, min(5, floatval($body->minimumRating))) : 0;
        $items = array();
        foreach ($rows as $item) {
            if ($searchLanguage !== "" && (!isset($item->primary_language) || strtolower(strval($item->primary_language)) !== $searchLanguage)) {
                $translation = $this->findOne($this->translationNamespace, "destination_id:" . intval($item->id) . ",language_code:" . $searchLanguage . ",moderation_status:Approved");
                if ($translation !== null) { $item->original_language = isset($item->primary_language) ? $item->primary_language : ""; $item->content_language = $searchLanguage; $item->name = $translation->name; $item->short_summary = $translation->short_summary; }
            }
            if ($categoryDestinationIds !== null && !in_array(intval($item->id), $categoryDestinationIds, true)) {
                continue;
            }
            if ($amenityDestinationIds !== null && !in_array(intval($item->id), $amenityDestinationIds, true)) {
                continue;
            }
            if (!$this->matchesTextFilters($item, $body, $keyword) || floatval(isset($item->rating_average) ? $item->rating_average : 0) < $minimumRating) {
                continue;
            }
            if (!empty($body->verifiedOnly) && (!isset($item->verification_status) || $item->verification_status !== "Verified")) {
                continue;
            }
            if (isset($body->staySubtype) && trim($body->staySubtype) !== "" && strcasecmp(isset($item->stay_subtype) ? $item->stay_subtype : "", trim($body->staySubtype)) !== 0) {
                continue;
            }
            if ($latitude !== null && $longitude !== null && TravelDestinationRules::validCoordinates($item->latitude, $item->longitude)) {
                if ($radius !== null && !TravelDestinationRules::inBoundingBox($item->latitude, $item->longitude, $latitude, $longitude, $radius)) {
                    continue;
                }
                $item->distance_km = round(TravelDestinationRules::distanceKm($latitude, $longitude, $item->latitude, $item->longitude), 2);
                if ($radius !== null && $item->distance_km > $radius) {
                    continue;
                }
            }
            $this->applyCoordinatePrivacy($item, false);
            if ($mapOnly && !TravelDestinationRules::validCoordinates(
                isset($item->latitude) ? $item->latitude : null,
                isset($item->longitude) ? $item->longitude : null
            )) {
                continue;
            }
            $items[] = $item;
        }
        $this->sortDestinations($items, $sort);
        $total = count($items);
        $items = array_slice($items, $page * $pageSize, $pageSize);
        return array("items" => $items, "pagination" => array("page" => $page, "pageSize" => $pageSize, "total" => $total, "hasMore" => (($page + 1) * $pageSize) < $total));
    }

    private function matchesTextFilters($item, $body, $keyword) {
        $fields = array("name", "short_summary", "tags", "province", "district", "nearest_town", "village");
        if ($keyword !== "") {
            $haystack = "";
            foreach ($fields as $field) {
                $haystack .= " " . (isset($item->{$field}) ? $item->{$field} : "");
            }
            if (mb_strpos(mb_strtolower($haystack), $keyword) === false) {
                return false;
            }
        }
        foreach (array("province", "district", "nearest_town") as $field) {
            if (isset($body->{$field}) && trim($body->{$field}) !== "" && strcasecmp(isset($item->{$field}) ? $item->{$field} : "", trim($body->{$field})) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function sortDestinations(&$items, $sort) {
        usort($items, function ($left, $right) use ($sort) {
            switch ($sort) {
                case "nearest":
                    return floatval(isset($left->distance_km) ? $left->distance_km : PHP_FLOAT_MAX) <=> floatval(isset($right->distance_km) ? $right->distance_km : PHP_FLOAT_MAX);
                case "highest_rated":
                    return floatval(isset($right->rating_average) ? $right->rating_average : 0) <=> floatval(isset($left->rating_average) ? $left->rating_average : 0);
                case "most_reviewed":
                    return intval(isset($right->review_count) ? $right->review_count : 0) <=> intval(isset($left->review_count) ? $left->review_count : 0);
                case "most_viewed":
                    return intval(isset($right->view_count) ? $right->view_count : 0) <=> intval(isset($left->view_count) ? $left->view_count : 0);
                case "recently_verified":
                    return strcmp(isset($right->last_verified_date) ? $right->last_verified_date : "", isset($left->last_verified_date) ? $left->last_verified_date : "");
                case "name":
                    return strcasecmp(isset($left->name) ? $left->name : "", isset($right->name) ? $right->name : "");
                case "recently_added":
                    return strcmp(isset($right->syscreated) ? $right->syscreated : "", isset($left->syscreated) ? $left->syscreated : "");
                case "featured":
                default:
                    $featured = intval(!empty($right->is_featured)) <=> intval(!empty($left->is_featured));
                    return $featured !== 0 ? $featured : strcmp(isset($right->publication_date) ? $right->publication_date : "", isset($left->publication_date) ? $left->publication_date : "");
            }
        });
    }

    private function validatedDestination($body, $res, $requireComplete) {
        $destination = new \stdClass();
        if (isset($body->id) && intval($body->id) > 0) {
            $destination = $this->findOne($this->destinationNamespace, "id:" . intval($body->id));
            if ($destination === null) {
                return $this->fail($res, "Destination was not found.");
            }
        }
        $destination->name = $this->text($body, "name", 255);
        if ($destination->name === "") {
            return $this->fail($res, "Destination name is required.");
        }
        $destination->slug = isset($body->slug) && TravelDestinationRules::slug($body->slug) !== "" ? TravelDestinationRules::slug($body->slug) : TravelDestinationRules::slug($destination->name);
        $sameSlug = $this->findOne($this->destinationNamespace, "slug:" . $destination->slug);
        if ($sameSlug !== null && (!isset($destination->id) || intval($sameSlug->id) !== intval($destination->id))) {
            $destination->slug .= "-" . substr(md5(uniqid("", true)), 0, 6);
        }
        $destination->short_summary = $this->text($body, "short_summary", 600);
        $fullDescription = TravelDestinationRules::plainMarkdown(isset($body->description_markdown) ? $body->description_markdown : "", 250000);
        $destination->description_markdown = mb_substr($fullDescription, 0, 12000);
        $destination->tags = $this->text($body, "tags", 1000);
        $destination->primary_language = $this->text($body, "primary_language", 20);
        $destination->primary_language = $destination->primary_language === "" ? "en" : $destination->primary_language;
        $destination->alternative_names = $this->text($body, "alternative_names", 1000);
        $destination->latitude = isset($body->latitude) ? floatval($body->latitude) : null;
        $destination->longitude = isset($body->longitude) ? floatval($body->longitude) : null;
        if (($requireComplete || $destination->latitude !== null || $destination->longitude !== null) && !TravelDestinationRules::validCoordinates($destination->latitude, $destination->longitude)) {
            return $this->fail($res, "Latitude must be -90 to 90 and longitude must be -180 to 180.");
        }
        $destination->coordinate_accuracy = isset($body->coordinate_accuracy) ? max(0, floatval($body->coordinate_accuracy)) : 0;
        $destination->location_privacy = $this->allowedText($body, "location_privacy", $this->privacyModes, "exact_public");
        foreach (array("province" => 120, "district" => 120, "nearest_town" => 180, "village" => 180, "location_description" => 2000, "access_road_description" => 2000, "public_transport_instructions" => 2000, "parking_information" => 1000, "road_condition" => 500, "external_place_id" => 255, "stay_subtype" => 50, "price_range" => 100, "responsible_travel_markdown" => 5000, "safety_warnings" => 5000) as $field => $length) {
            $destination->{$field} = $field === "responsible_travel_markdown"
                ? TravelDestinationRules::plainMarkdown(isset($body->{$field}) ? $body->{$field} : "", $length)
                : $this->text($body, $field, $length);
        }
        $destination->distance_from_town_km = isset($body->distance_from_town_km) ? max(0, floatval($body->distance_from_town_km)) : 0;
        $destination->walking_distance_km = isset($body->walking_distance_km) ? max(0, floatval($body->walking_distance_km)) : 0;
        $destination->requires_4wd = !empty($body->requires_4wd);
        $destination->location_verification_status = $this->allowedText($body, "location_verification_status", array("Unverified", "Pending", "Verified", "Disputed"), "Unverified");
        foreach (array("camping_info", "hiking_info", "stay_info", "village_info") as $field) {
            $destination->{$field} = isset($body->{$field}) && is_object($body->{$field}) ? $this->sanitizeInfoObject($body->{$field}) : new \stdClass();
        }
        if ($requireComplete && ($destination->short_summary === "" || $destination->description_markdown === "" || empty($body->category_ids) || !is_array($body->category_ids))) {
            return $this->fail($res, "Summary, description and at least one category are required.");
        }
        return $destination;
    }

    private function syncDestinationLinks($destination, $body, $res) {
        $this->syncLinks($destination->id, $body, "category_ids", $this->categoryLinkNamespace, "category_id", true, $res);
        $this->syncLinks($destination->id, $body, "amenity_ids", $this->amenityLinkNamespace, "amenity_id", false, $res);
        $this->syncDestinationDescription($destination->id, $body, $res);
    }

    private function syncDestinationDescription($destinationId, $body, $res) {
        if (!isset($body->description_markdown)) {
            return;
        }
        $content = TravelDestinationRules::plainMarkdown($body->description_markdown, 250000);
        $existing = $this->rows($this->descriptionChunkNamespace, "destination_id:" . intval($destinationId), "asc", 100, 0);
        $length = mb_strlen($content);
        $chunkIndex = 0;
        $inserted = array();
        for ($offset = 0; $offset < $length; $offset += 12000) {
            $chunk = new \stdClass();
            $chunk->destination_id = intval($destinationId);
            $chunk->chunk_index = $chunkIndex++;
            // Use utf8mb4 so pasted text containing emoji and other
            // supplementary Unicode characters works on older MySQL servers.
            $chunk->content_utf8mb4 = mb_substr($content, $offset, 12000);
            $saved = \SOSSData::Insert($this->descriptionChunkNamespace, $chunk);
            if (empty($saved->success)) {
                error_log(
                    "Travel Destinations: description chunk " . intval($chunk->chunk_index)
                    . " failed for destination " . intval($destinationId)
                    . ": " . json_encode($saved)
                );
                if (count($inserted) > 0) {
                    \SOSSData::Delete($this->descriptionChunkNamespace, $inserted);
                }
                $this->fail($res, "The complete destination description could not be saved.");
                return;
            }
            if (isset($saved->result->generatedId)) {
                $chunk->id = intval($saved->result->generatedId);
            }
            $inserted[] = $chunk;
        }
        if (count($existing) > 0) {
            \SOSSData::Delete($this->descriptionChunkNamespace, $existing);
        }
        if (class_exists("\\CacheData")) {
            \CacheData::clearObjects($this->descriptionChunkNamespace);
        }
    }

    private function fullDestinationDescription($destination) {
        if (!$destination || !isset($destination->id)) {
            return $destination && isset($destination->description_markdown) ? strval($destination->description_markdown) : "";
        }
        $chunks = $this->rows($this->descriptionChunkNamespace, "destination_id:" . intval($destination->id), "asc", 100, 0);
        if (count($chunks) === 0) {
            return isset($destination->description_markdown) ? strval($destination->description_markdown) : "";
        }
        usort($chunks, function ($left, $right) {
            return intval($left->chunk_index) - intval($right->chunk_index);
        });
        $content = "";
        foreach ($chunks as $chunk) {
            if (isset($chunk->content_utf8mb4) && $chunk->content_utf8mb4 !== null) {
                $content .= strval($chunk->content_utf8mb4);
            } else {
                // Backward compatibility with chunks written by v0.4.5.
                $content .= isset($chunk->content) ? strval($chunk->content) : "";
            }
        }
        return mb_substr($content, 0, 250000);
    }

    private function syncLinks($destinationId, $body, $bodyField, $namespace, $targetField, $primary, $res) {
        if (!isset($body->{$bodyField}) || !is_array($body->{$bodyField})) {
            return;
        }
        $existing = $this->rows($namespace, "destination_id:" . intval($destinationId), "asc", 1000, 0);
        if (count($existing) > 0) {
            \SOSSData::Delete($namespace, $existing);
        }
        $links = array();
        $seen = array();
        foreach ($body->{$bodyField} as $index => $value) {
            $id = intval($value);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $link = new \stdClass();
            $link->destination_id = intval($destinationId);
            $link->{$targetField} = $id;
            if ($primary) {
                $link->is_primary = $index === 0;
            }
            $links[] = $link;
        }
        if (count($links) > 0) {
            $result = \SOSSData::Insert($namespace, $links);
            if (empty($result->success)) {
                $res->SetError("Destination relationships could not be saved.");
            }
        }
    }

    private function adminTransition($req, $res, $toStatus, $allowedFrom, $publishing) {
        $adminId = $this->requireAdmin($res);
        $body = $this->body($req);
        $destination = isset($body->id) ? $this->findOne($this->destinationNamespace, "id:" . intval($body->id)) : null;
        if ($adminId === null || $destination === null) {
            return $this->fail($res, "Destination was not found.");
        }
        $from = isset($destination->status) ? $destination->status : "Draft";
        if (!in_array($from, $allowedFrom, true)) {
            return $this->fail($res, "This status transition is not allowed.");
        }
        $destination->status = $toStatus;
        $destination->approved_by_profile_id = $adminId;
        $destination->moderation_reason = $this->text($body, "reason", 2000);
        if ($publishing) {
            $destination->publication_date = date("Y-m-d H:i:s");
        }
        $saved = $this->saveObject($this->destinationNamespace, "id", $destination, $res);
        if ($saved !== null) {
            $this->logTransition($destination->id, $adminId, $from, $toStatus, $destination->moderation_reason);
            if (isset($destination->created_by_profile_id)) { $this->notifyProfile(intval($destination->created_by_profile_id), "Destination update", $destination->name . " is now " . $toStatus . ".", "#/app/travel-destinations/my-submissions"); }
        }
        return $saved;
    }

    private function moderateRecord($req, $res, $namespace, $allowedStatuses, $field) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        $body = $this->body($req);
        $record = isset($body->id) ? $this->findOne($namespace, "id:" . intval($body->id)) : null;
        $status = isset($body->status) ? trim($body->status) : "";
        if ($record === null || !in_array($status, $allowedStatuses, true)) {
            return $this->fail($res, "Record or moderation status is invalid.");
        }
        $record->{$field} = $status;
        return $this->saveObject($namespace, "id", $record, $res);
    }

    private function saveReference($req, $res, $namespace) {
        if ($this->requireAdmin($res) === null) {
            return null;
        }
        $body = $this->body($req);
        $record = isset($body->id) ? $this->findOne($namespace, "id:" . intval($body->id)) : new \stdClass();
        if ($record === null) {
            return $this->fail($res, "Reference item was not found.");
        }
        $record->name = $this->text($body, "name", 120);
        if ($record->name === "") {
            return $this->fail($res, "Name is required.");
        }
        $record->slug = TravelDestinationRules::slug(isset($body->slug) ? $body->slug : $record->name);
        $record->description = $this->text($body, "description", 1000);
        $record->sort_order = isset($body->sort_order) ? max(0, intval($body->sort_order)) : 0;
        $record->is_active = !isset($body->is_active) || !empty($body->is_active);
        if ($namespace === $this->categoryNamespace) {
            $record->parent_id = isset($body->parent_id) ? max(0, intval($body->parent_id)) : 0;
            $record->marker_key = $this->text($body, "marker_key", 50);
        } else {
            $record->icon_key = $this->text($body, "icon_key", 50);
        }
        return $this->saveObject($namespace, "id", $record, $res);
    }

    private function recalculateRating($destinationId) {
        $reviews = $this->rows($this->reviewNamespace, "destination_id:" . intval($destinationId) . ",moderation_status:Approved,is_active:1", "desc", 1000, 0);
        $total = 0;
        foreach ($reviews as $review) {
            $total += intval($review->overall_rating);
        }
        $destination = $this->findOne($this->destinationNamespace, "id:" . intval($destinationId));
        if ($destination !== null) {
            $destination->review_count = count($reviews);
            $destination->rating_average = count($reviews) > 0 ? round($total / count($reviews), 2) : 0;
            \SOSSData::Update($this->destinationNamespace, $destination);
        }
    }

    private function canReadDestination($destination) {
        if (isset($destination->status) && $destination->status === "Published") {
            return true;
        }
        $profileId = $this->currentProfileId();
        return $this->isAdmin() || ($profileId !== null && $this->ownsDestination($destination, $profileId));
    }

    private function canSeeExactCoordinates($destination) {
        if ($this->isAdmin()) {
            return true;
        }
        $profileId = $this->currentProfileId();
        return $profileId !== null && $this->ownsDestination($destination, $profileId);
    }

    private function applyCoordinatePrivacy($destination, $canSeeExact) {
        $mode = isset($destination->location_privacy) ? $destination->location_privacy : "exact_public";
        if ($canSeeExact || $mode === "exact_public") {
            return;
        }
        if ($mode === "approximate_public") {
            if (isset($destination->latitude)) {
                $destination->latitude = round(floatval($destination->latitude), 2);
            }
            if (isset($destination->longitude)) {
                $destination->longitude = round(floatval($destination->longitude), 2);
            }
            if (isset($destination->distance_km)) {
                $destination->distance_km = round(floatval($destination->distance_km) / 5) * 5;
            }
            return;
        }
        unset($destination->latitude, $destination->longitude, $destination->external_place_id, $destination->distance_km);
        $destination->coordinates_restricted = true;
    }

    private function directionsUrl($destination) {
        if (!isset($destination->latitude) || !isset($destination->longitude)) {
            return null;
        }
        return "https://www.openstreetmap.org/directions?to=" . rawurlencode($destination->latitude . "," . $destination->longitude);
    }

    private function approvedMedia($destinationId) {
        $rows = $this->rows($this->mediaNamespace, "destination_id:" . intval($destinationId) . ",moderation_status:Approved", "asc", 100, 0);
        foreach ($rows as $item) {
            $reference = isset($item->media_reference) ? $item->media_reference : "";
            if (preg_match('/(^|[\\\\\\/])\\.\\.([\\\\\\/]|$)/', $reference)) {
                $item->media_reference = "";
            }
        }
        return $rows;
    }

    private function linkedReferenceRows($destinationId, $linkNamespace, $targetField, $referenceNamespace) {
        $links = $this->rows($linkNamespace, "destination_id:" . intval($destinationId), "asc", 500, 0);
        $items = array();
        foreach ($links as $link) {
            $targetId = isset($link->{$targetField}) ? intval($link->{$targetField}) : 0;
            $item = $targetId > 0 ? $this->findOne($referenceNamespace, "id:" . $targetId) : null;
            if ($item !== null) {
                if (isset($link->is_primary)) {
                    $item->is_primary = $link->is_primary;
                }
                $items[] = $item;
            }
        }
        return $items;
    }

    private function destinationIdsForLink($namespace, $field, $value) {
        $rows = $this->rows($namespace, $field . ":" . intval($value), "asc", 1000, 0);
        return array_values(array_unique(array_map(function ($item) {
            return intval($item->destination_id);
        }, $rows)));
    }

    private function destinationIdsWithAllAmenities($amenityIds) {
        $counts = array();
        foreach ($amenityIds as $amenityId) {
            foreach ($this->destinationIdsForLink($this->amenityLinkNamespace, "amenity_id", $amenityId) as $destinationId) {
                $counts[$destinationId] = isset($counts[$destinationId]) ? $counts[$destinationId] + 1 : 1;
            }
        }
        $needed = count($amenityIds);
        return array_values(array_map("intval", array_keys(array_filter($counts, function ($count) use ($needed) {
            return $count === $needed;
        }))));
    }

    private function activeReferenceRows($namespace) {
        return $this->rows($namespace, "is_active:1", "asc", 500, 0);
    }

    private function seedReferenceRows($namespace, $names) {
        foreach ($names as $index => $name) {
            $slug = TravelDestinationRules::slug($name);
            if ($this->findOne($namespace, "slug:" . $slug) !== null) {
                continue;
            }
            $item = new \stdClass();
            $item->name = $name;
            $item->slug = $slug;
            $item->description = "";
            $item->sort_order = $index + 1;
            $item->is_active = true;
            if ($namespace === $this->categoryNamespace) {
                $item->parent_id = 0;
                $item->marker_key = $slug;
            } else {
                $item->icon_key = $slug;
            }
            \SOSSData::Insert($namespace, $item);
        }
    }

    private function recentDuplicateComment($destinationId, $profileId, $text) {
        $rows = $this->rows($this->commentNamespace, "destination_id:" . intval($destinationId) . ",profile_id:" . intval($profileId) . ",is_active:1", "desc", 10, 0);
        foreach ($rows as $item) {
            if (isset($item->comment_markdown) && trim($item->comment_markdown) === trim($text)) {
                if (!isset($item->syscreated) || strtotime($item->syscreated) >= strtotime("-1 minute")) {
                    return true;
                }
            }
        }
        return false;
    }

    private function logTransition($destinationId, $profileId, $from, $to, $note) {
        $log = new \stdClass();
        $log->destination_id = intval($destinationId);
        $log->profile_id = intval($profileId);
        $log->from_status = $from;
        $log->to_status = $to;
        $log->note = mb_substr(strval($note), 0, 3000);
        $log->event_date = date("Y-m-d H:i:s");
        \SOSSData::Insert($this->submissionLogNamespace, $log);
    }

    private function pagedRows($namespace, $query, $body, $defaultSize, $maxSize) {
        $page = isset($body->page) ? max(0, intval($body->page)) : 0;
        $pageSize = isset($body->pageSize) ? min($maxSize, max(1, intval($body->pageSize))) : $defaultSize;
        $items = $this->rows($namespace, $query, "desc", $pageSize + 1, $page * $pageSize);
        $hasMore = count($items) > $pageSize;
        if ($hasMore) {
            array_pop($items);
        }
        return array("items" => $items, "pagination" => array("page" => $page, "pageSize" => $pageSize, "hasMore" => $hasMore));
    }

    private function paginateArray($items, $body, $defaultSize, $maxSize) {
        $page = isset($body->page) ? max(0, intval($body->page)) : 0;
        $pageSize = isset($body->pageSize) ? min($maxSize, max(1, intval($body->pageSize))) : $defaultSize;
        $total = count($items);
        return array("items" => array_slice($items, $page * $pageSize, $pageSize), "pagination" => array("page" => $page, "pageSize" => $pageSize, "total" => $total, "hasMore" => (($page + 1) * $pageSize) < $total));
    }

    private function rows($namespace, $query, $sort, $size, $page) {
        $result = \SOSSData::Query($namespace, urlencode($query), null, $sort, $size, $page);
        return !empty($result->success) && isset($result->result) && is_array($result->result) ? $result->result : array();
    }

    private function findOne($namespace, $query) {
        $rows = $this->rows($namespace, $query, "desc", 1, 0);
        return count($rows) > 0 ? $rows[0] : null;
    }

    private function saveObject($namespace, $key, $object, $res) {
        $isUpdate = isset($object->{$key}) && intval($object->{$key}) > 0;
        $result = $isUpdate ? \SOSSData::Update($namespace, $object) : \SOSSData::Insert($namespace, $object);
        if (empty($result->success)) {
            return $this->fail($res, "The record could not be saved.");
        }
        if (!$isUpdate && isset($result->result->generatedId)) {
            $object->{$key} = intval($result->result->generatedId);
        }
        if (class_exists("\\CacheData")) {
            \CacheData::clearObjects($namespace);
        }
        return $object;
    }

    private function isPublishedDestination($destinationId) {
        return $this->findOne($this->destinationNamespace, "id:" . intval($destinationId) . ",status:Published") !== null;
    }

    private function ownsDestination($destination, $profileId) {
        return isset($destination->created_by_profile_id) && intval($destination->created_by_profile_id) === intval($profileId);
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function requiredId($body, $field, $res) {
        $id = isset($body->{$field}) ? intval($body->{$field}) : 0;
        if ($id < 1) {
            $this->fail($res, "A valid " . $field . " is required.");
            return null;
        }
        return $id;
    }

    private function text($body, $field, $maxLength) {
        return isset($body->{$field}) ? mb_substr(trim(strip_tags(str_replace("\0", "", strval($body->{$field})))), 0, $maxLength) : "";
    }

    private function allowedText($body, $field, $allowed, $default) {
        $value = isset($body->{$field}) ? trim(strval($body->{$field})) : $default;
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function validDateValue($value) {
        if ($value === null || trim(strval($value)) === "" || strtotime($value) === false) {
            return date("Y-m-d H:i:s");
        }
        return date("Y-m-d H:i:s", strtotime($value));
    }

    private function sanitizeInfoObject($value) {
        $safe = new \stdClass();
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= 80 || !preg_match('/^[A-Za-z0-9_]{1,80}$/', strval($key))) {
                continue;
            }
            if (is_bool($item) || is_int($item) || is_float($item)) {
                $safe->{$key} = $item;
            } elseif (is_string($item)) {
                $text = trim(strip_tags(str_replace("\0", "", $item)));
                if (preg_match('/(url|link|website)$/i', strval($key))) {
                    $safe->{$key} = filter_var($text, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $text) ? mb_substr($text, 0, 1000) : "";
                } else {
                    $safe->{$key} = mb_substr($text, 0, 2000);
                }
            }
            $count++;
        }
        return $safe;
    }

    private function currentProfileId() {
        if (!class_exists("\\Profile")) {
            return null;
        }
        $storeProfile = \Profile::getUserProfile();
        $profile = is_object($storeProfile) && isset($storeProfile->profile) ? $storeProfile->profile : $storeProfile;
        return is_object($profile) && isset($profile->id) && intval($profile->id) > 0 ? intval($profile->id) : null;
    }

    private function weatherSettings() {
        return $this->findOne($this->weatherSettingsNamespace, "provider:open_meteo");
    }

    private function safeAdminWeatherSettings($settings) {
        return array(
            "id" => $settings && isset($settings->id) ? intval($settings->id) : null,
            "provider" => "open_meteo",
            "enabled" => $settings ? $this->booleanValue($settings, "is_enabled") : false,
            "forecast_days" => $settings && isset($settings->forecast_days) ? min(7, max(1, intval($settings->forecast_days))) : 3,
            "temperature_unit" => $settings && isset($settings->temperature_unit) && in_array($settings->temperature_unit, array("celsius", "fahrenheit"), true) ? $settings->temperature_unit : "celsius",
            "wind_speed_unit" => $settings && isset($settings->wind_speed_unit) && in_array($settings->wind_speed_unit, array("kmh", "ms", "mph", "kn"), true) ? $settings->wind_speed_unit : "kmh",
            "license_confirmed" => $settings ? $this->booleanValue($settings, "license_confirmed") : false,
            "cache_minutes" => 60
        );
    }

    private function fetchOpenMeteoForecast($latitude, $longitude, $settings) {
        if (!function_exists("curl_init") || !TravelDestinationRules::validCoordinates($latitude, $longitude)) {
            return null;
        }
        $query = http_build_query(array(
            "latitude" => round(floatval($latitude), 4),
            "longitude" => round(floatval($longitude), 4),
            "current" => "temperature_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_gusts_10m,visibility",
            "daily" => "weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,sunrise,sunset,wind_speed_10m_max,wind_gusts_10m_max",
            "temperature_unit" => $settings["temperature_unit"],
            "wind_speed_unit" => $settings["wind_speed_unit"],
            "precipitation_unit" => "mm",
            "timezone" => "auto",
            "forecast_days" => $settings["forecast_days"]
        ), "", "&", PHP_QUERY_RFC3986);
        $curl = curl_init("https://api.open-meteo.com/v1/forecast?" . $query);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => "DAVVAG Travel Destinations weather integration",
            CURLOPT_HTTPHEADER => array("Accept: application/json"),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        if (defined("CURLOPT_PROTOCOLS") && defined("CURLPROTO_HTTPS")) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $response = curl_exec($curl);
        $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        $error = curl_errno($curl);
        curl_close($curl);
        if ($error !== 0 || $status !== 200 || !is_string($response) || strlen($response) > 1048576) {
            return null;
        }
        $decoded = json_decode($response);
        return is_object($decoded) && empty($decoded->error) ? $decoded : null;
    }

    private function normalizeWeatherResponse($payload, $settings) {
        if (!is_object($payload) || !isset($payload->current) || !is_object($payload->current)
            || !isset($payload->daily) || !is_object($payload->daily)) {
            return null;
        }
        $current = $payload->current;
        $daily = $payload->daily;
        if (!isset($current->time) || !isset($current->temperature_2m) || !isset($daily->time) || !is_array($daily->time)) {
            return null;
        }
        $currentUnits = isset($payload->current_units) && is_object($payload->current_units) ? $payload->current_units : new \stdClass();
        $dailyUnits = isset($payload->daily_units) && is_object($payload->daily_units) ? $payload->daily_units : new \stdClass();
        $days = array();
        $dayCount = min(intval($settings["forecast_days"]), count($daily->time));
        for ($index = 0; $index < $dayCount; $index++) {
            $weatherCode = intval($this->weatherArrayValue($daily, "weather_code", $index, 0));
            $days[] = array(
                "date" => strval($this->weatherArrayValue($daily, "time", $index, "")),
                "summary" => $this->weatherCodeLabel($weatherCode),
                "weatherCode" => $weatherCode,
                "temperatureMax" => $this->weatherNumber($this->weatherArrayValue($daily, "temperature_2m_max", $index, null)),
                "temperatureMin" => $this->weatherNumber($this->weatherArrayValue($daily, "temperature_2m_min", $index, null)),
                "precipitation" => $this->weatherNumber($this->weatherArrayValue($daily, "precipitation_sum", $index, null)),
                "precipitationProbability" => $this->weatherNumber($this->weatherArrayValue($daily, "precipitation_probability_max", $index, null)),
                "windSpeedMax" => $this->weatherNumber($this->weatherArrayValue($daily, "wind_speed_10m_max", $index, null)),
                "windGustMax" => $this->weatherNumber($this->weatherArrayValue($daily, "wind_gusts_10m_max", $index, null)),
                "sunrise" => strval($this->weatherArrayValue($daily, "sunrise", $index, "")),
                "sunset" => strval($this->weatherArrayValue($daily, "sunset", $index, ""))
            );
        }
        $currentCode = isset($current->weather_code) ? intval($current->weather_code) : 0;
        return array(
            "available" => true,
            "cached" => false,
            "cacheMinutes" => 60,
            "provider" => array(
                "name" => "Open-Meteo",
                "attributionUrl" => "https://open-meteo.com/",
                "licence" => "CC BY 4.0"
            ),
            "timezone" => isset($payload->timezone) ? strval($payload->timezone) : "GMT",
            "observationTime" => strval($current->time),
            "generatedAt" => gmdate("c"),
            "units" => array(
                "temperature" => isset($currentUnits->temperature_2m) ? strval($currentUnits->temperature_2m) : ($settings["temperature_unit"] === "fahrenheit" ? "°F" : "°C"),
                "windSpeed" => isset($currentUnits->wind_speed_10m) ? strval($currentUnits->wind_speed_10m) : $settings["wind_speed_unit"],
                "precipitation" => isset($currentUnits->precipitation) ? strval($currentUnits->precipitation) : "mm",
                "visibility" => isset($currentUnits->visibility) ? strval($currentUnits->visibility) : "m",
                "precipitationProbability" => isset($dailyUnits->precipitation_probability_max) ? strval($dailyUnits->precipitation_probability_max) : "%"
            ),
            "current" => array(
                "summary" => $this->weatherCodeLabel($currentCode),
                "weatherCode" => $currentCode,
                "temperature" => $this->weatherNumber($current->temperature_2m),
                "apparentTemperature" => isset($current->apparent_temperature) ? $this->weatherNumber($current->apparent_temperature) : null,
                "precipitation" => isset($current->precipitation) ? $this->weatherNumber($current->precipitation) : null,
                "windSpeed" => isset($current->wind_speed_10m) ? $this->weatherNumber($current->wind_speed_10m) : null,
                "windGust" => isset($current->wind_gusts_10m) ? $this->weatherNumber($current->wind_gusts_10m) : null,
                "visibility" => isset($current->visibility) ? $this->weatherNumber($current->visibility) : null
            ),
            "forecast" => $days
        );
    }

    private function weatherArrayValue($object, $field, $index, $fallback) {
        return isset($object->{$field}) && is_array($object->{$field}) && array_key_exists($index, $object->{$field})
            ? $object->{$field}[$index]
            : $fallback;
    }

    private function weatherNumber($value) {
        return is_numeric($value) ? round(floatval($value), 1) : null;
    }

    private function weatherCodeLabel($code) {
        $code = intval($code);
        $labels = array(
            0 => "Clear sky", 1 => "Mainly clear", 2 => "Partly cloudy", 3 => "Overcast",
            45 => "Fog", 48 => "Freezing fog", 51 => "Light drizzle", 53 => "Drizzle", 55 => "Heavy drizzle",
            56 => "Light freezing drizzle", 57 => "Freezing drizzle", 61 => "Light rain", 63 => "Rain", 65 => "Heavy rain",
            66 => "Light freezing rain", 67 => "Freezing rain", 71 => "Light snow", 73 => "Snow", 75 => "Heavy snow",
            77 => "Snow grains", 80 => "Light rain showers", 81 => "Rain showers", 82 => "Heavy rain showers",
            85 => "Light snow showers", 86 => "Heavy snow showers", 95 => "Thunderstorm", 96 => "Thunderstorm with hail", 99 => "Severe thunderstorm with hail"
        );
        return isset($labels[$code]) ? $labels[$code] : "Weather update";
    }

    private function mapSettings() {
        return $this->findOne($this->mapSettingsNamespace, "provider:google_maps");
    }

    private function mapSettingsDefaults() {
        return array(
            "enabled" => false,
            "provider" => "provider-neutral",
            "apiKey" => "",
            "mapId" => "",
            "language" => "en",
            "region" => "LK",
            "defaultCenter" => array("lat" => 7.8731, "lng" => 80.7718),
            "defaultZoom" => 8,
            "geocodingEnabled" => false
        );
    }

    private function safeAdminMapSettings($settings) {
        $defaults = $this->mapSettingsDefaults();
        return array(
            "id" => $settings && isset($settings->id) ? intval($settings->id) : null,
            "enabled" => $settings ? $this->booleanValue($settings, "is_enabled") : false,
            "has_api_key" => $this->providerValue($settings, "api_key_enc") !== "",
            "map_id" => $settings && isset($settings->map_id) ? strval($settings->map_id) : "",
            "language" => $settings && !empty($settings->language) ? strval($settings->language) : "en",
            "region" => $settings && !empty($settings->region) ? strval($settings->region) : "LK",
            "default_latitude" => $settings && isset($settings->default_latitude) ? floatval($settings->default_latitude) : $defaults["defaultCenter"]["lat"],
            "default_longitude" => $settings && isset($settings->default_longitude) ? floatval($settings->default_longitude) : $defaults["defaultCenter"]["lng"],
            "default_zoom" => $settings && isset($settings->default_zoom) ? intval($settings->default_zoom) : 8,
            "enable_geocoding" => $settings ? $this->booleanValue($settings, "enable_geocoding") : false,
            "encryption_ready" => $this->providerSecret() !== "",
            "required_environment_variable" => "DAVVAG_PROVIDER_SECRET"
        );
    }

    private function bodyBoolean($body, $field) {
        if (!isset($body->{$field})) {
            return false;
        }
        $value = $body->{$field};
        return $value === true || $value === 1 || $value === "1" || strtolower(strval($value)) === "true";
    }

    private function booleanValue($item, $field) {
        if (!$item || !isset($item->{$field})) {
            return false;
        }
        $value = $item->{$field};
        return $value === true || $value === 1 || $value === "1" || strtolower(strval($value)) === "true";
    }

    private function providerSecret() {
        $value = getenv("DAVVAG_PROVIDER_SECRET");
        if ($value !== false && trim($value) !== "") {
            return trim($value);
        }
        return defined("DAVVAG_PROVIDER_SECRET") ? trim(strval(DAVVAG_PROVIDER_SECRET)) : "";
    }

    private function encryptProviderSecret($plain) {
        $key = hash("sha256", $this->providerSecret(), true);
        $iv = random_bytes(12);
        $tag = "";
        $cipher = openssl_encrypt($plain, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $iv, $tag);
        return array("v" => 1, "iv" => base64_encode($iv), "tag" => base64_encode($tag), "data" => base64_encode($cipher));
    }

    private function providerValue($item, $field) {
        if (!$item || !isset($item->{$field}) || $this->providerSecret() === "") {
            return "";
        }
        $payload = $item->{$field};
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (is_object($payload)) {
            $payload = get_object_vars($payload);
        }
        if (!is_array($payload) || empty($payload["iv"]) || empty($payload["tag"]) || !isset($payload["data"])) {
            return "";
        }
        $plain = openssl_decrypt(
            base64_decode($payload["data"]),
            "aes-256-gcm",
            hash("sha256", $this->providerSecret(), true),
            OPENSSL_RAW_DATA,
            base64_decode($payload["iv"]),
            base64_decode($payload["tag"])
        );
        return $plain === false ? "" : $plain;
    }

    private function allowedGoogleMapUrl($url) {
        $parts = parse_url(trim(strval($url)));
        if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) {
            return false;
        }
        if (strtolower($parts["scheme"]) !== "https" || isset($parts["user"]) || isset($parts["pass"]) || (isset($parts["port"]) && intval($parts["port"]) !== 443)) {
            return false;
        }
        $host = strtolower(rtrim($parts["host"], "."));
        return $host === "maps.app.goo.gl"
            || $host === "goo.gl"
            || $host === "maps.google.com"
            || preg_match('/(^|\.)google\.(com|[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/', $host) === 1;
    }

    private function mapRedirectLocation($url) {
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => "DAVVAG Travel Destinations map-link resolver",
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        curl_exec($curl);
        $status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        $redirectUrl = strval(curl_getinfo($curl, CURLINFO_REDIRECT_URL));
        curl_close($curl);
        return $status >= 300 && $status < 400 ? trim($redirectUrl) : "";
    }

    private function absoluteRedirectUrl($baseUrl, $redirectUrl) {
        if (preg_match('/^https:\/\//i', $redirectUrl)) {
            return $redirectUrl;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base["host"])) {
            return "";
        }
        if (strpos($redirectUrl, "//") === 0) {
            return "https:" . $redirectUrl;
        }
        if (strpos($redirectUrl, "/") === 0) {
            return "https://" . $base["host"] . $redirectUrl;
        }
        $path = isset($base["path"]) ? $base["path"] : "/";
        $directory = rtrim(str_replace("\\", "/", dirname($path)), "/");
        return "https://" . $base["host"] . ($directory === "" ? "" : $directory) . "/" . $redirectUrl;
    }

    private function isAdmin() {
        if (defined("GROUPID")) {
            return strtolower(strval(GROUPID)) === "sysadmin";
        }
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
            return is_object($user) && isset($user->group) && strtolower($user->group) === "sysadmin";
        }
        return false;
    }

    private function requireProfile($res) {
        $profileId = $this->currentProfileId();
        if ($profileId === null) {
            $this->fail($res, "Sign in with an active profile to continue.");
        }
        return $profileId;
    }

    private function requireAdmin($res) {
        if (!$this->isAdmin()) {
            $this->fail($res, "Administrator access is required.");
            return null;
        }
        return $this->requireProfile($res);
    }

    private function fail($res, $message) {
        $res->SetError($message);
        return null;
    }
}
?>
