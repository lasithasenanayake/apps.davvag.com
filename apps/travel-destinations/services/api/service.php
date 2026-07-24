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

    private $destinationStatuses = array("Draft", "Pending Review", "Returned for Changes", "Approved", "Rejected", "Published", "Archived");
    private $privacyModes = array("exact_public", "approximate_public", "hidden_sensitive", "approved_only");
    private $sortValues = array("nearest", "highest_rated", "most_reviewed", "recently_added", "recently_verified", "most_viewed", "featured", "name");
    private $reviewStatuses = array("Pending", "Approved", "Rejected", "Hidden");
    private $reportReasons = array("incorrect_location", "duplicate_destination", "closed_destination", "private_property", "unsafe_destination", "misleading_description", "inappropriate_image", "inappropriate_review", "inappropriate_comment", "environmental_concern", "spam");
    private $conditionTypes = array("road_blocked", "trail_closed", "heavy_rain", "flooding", "landslide", "strong_wind", "fire_risk", "unsafe_water", "construction", "entrance_closed", "permit_change", "high_crowd_level", "mobile_signal_unavailable", "campsite_unavailable", "general_update");

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
        $this->applyCoordinatePrivacy($destination, $this->canSeeExactCoordinates($destination));
        $destination->directions_url = $this->directionsUrl($destination);
        return $destination;
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
            "reports" => $this->rows($this->reportNamespace, "status:Open", "asc", 200, 0)
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
        $minimumRating = isset($body->minimumRating) ? max(0, min(5, floatval($body->minimumRating))) : 0;
        $items = array();
        foreach ($rows as $item) {
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
            $chunk->content = mb_substr($content, $offset, 12000);
            $saved = \SOSSData::Insert($this->descriptionChunkNamespace, $chunk);
            if (empty($saved->success)) {
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
            $content .= isset($chunk->content) ? strval($chunk->content) : "";
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
