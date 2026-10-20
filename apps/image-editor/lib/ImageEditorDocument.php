<?php
namespace image_editor;

final class ImageEditorDocument
{
    const VERSION = 1;
    const MAX_PAGES = 30;
    const MAX_ELEMENTS = 500;
    const MAX_TOTAL_ELEMENTS = 2000;
    const MAX_SIDE = 8192;

    public static function normalize($input, $project)
    {
        $document = self::objectFrom($input);
        $version = self::integer($document, "documentVersion", 0);
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException("Unsupported document version.");
        }
        self::rejectUnsafeKeys($document, 0);
        $pages = self::arrayValue($document, "pages");
        if (count($pages) < 1 || count($pages) > self::MAX_PAGES) {
            throw new \InvalidArgumentException("Designs require between 1 and " . self::MAX_PAGES . " pages.");
        }
        $seenPages = array();
        $seenElements = array();
        $totalElements = 0;
        $normalizedPages = array();
        foreach ($pages as $pageIndex => $rawPage) {
            $page = self::objectFrom($rawPage);
            $pageUuid = self::uuid(self::string($page, "pageUuid", 64));
            if (isset($seenPages[$pageUuid])) {
                throw new \InvalidArgumentException("Page identifiers must be unique.");
            }
            $seenPages[$pageUuid] = true;
            $width = self::boundedInt($page, "width", 64, self::MAX_SIDE);
            $height = self::boundedInt($page, "height", 64, self::MAX_SIDE);
            if ($width !== intval($project->width_px) || $height !== intval($project->height_px)) {
                throw new \InvalidArgumentException("Page dimensions do not match the project.");
            }
            $background = self::objectValue($page, "background");
            if (self::string($background, "type", 20) !== "solid") {
                throw new \InvalidArgumentException("Only solid backgrounds are supported.");
            }
            $elements = self::arrayValue($page, "elements");
            if (count($elements) > self::MAX_ELEMENTS) {
                throw new \InvalidArgumentException("A page contains too many elements.");
            }
            $totalElements += count($elements);
            if ($totalElements > self::MAX_TOTAL_ELEMENTS) {
                throw new \InvalidArgumentException("The design contains too many elements.");
            }
            $normalizedElements = array();
            foreach ($elements as $index => $rawElement) {
                $element = self::objectFrom($rawElement);
                $uuid = self::uuid(self::string($element, "elementUuid", 64));
                if (isset($seenElements[$uuid])) {
                    throw new \InvalidArgumentException("Element identifiers must be unique across pages.");
                }
                $seenElements[$uuid] = true;
                $type = self::enum(self::string($element, "type", 20), array("text", "image", "rect", "ellipse", "triangle", "line"), "element type");
                $properties = self::objectValue($element, "properties");
                self::assertAllowedProperties($type, $properties);
                $normalizedElements[] = (object) array(
                    "elementUuid" => $uuid,
                    "type" => $type,
                    "name" => self::plain(self::string($element, "name", 120, ucfirst($type)), 120),
                    "zIndex" => intval($index),
                    "locked" => self::boolean($element, "locked"),
                    "hidden" => self::boolean($element, "hidden"),
                    "properties" => self::normalizeProperties($type, $properties)
                );
            }
            $normalizedPages[] = (object) array(
                "pageUuid" => $pageUuid,
                "width" => $width,
                "height" => $height,
                "background" => (object) array("type" => "solid", "color" => self::color(self::string($background, "color", 32, "#ffffff"))),
                "elements" => $normalizedElements
            );
        }
        return (object) array(
            "documentVersion" => self::VERSION,
            "pages" => $normalizedPages
        );
    }

    public static function checksum($document)
    {
        return hash("sha256", json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function assetIds($document)
    {
        $ids = array();
        foreach ($document->pages as $page) {
            foreach ($page->elements as $element) {
                if ($element->type === "image") {
                    $ids[intval($element->properties->assetId)] = true;
                }
            }
        }
        return array_keys($ids);
    }

    public static function fontIds($document)
    {
        $ids = array();
        foreach ($document->pages as $page) {
            foreach ($page->elements as $element) {
                if ($element->type === "text" && !empty($element->properties->fontId)) {
                    $ids[intval($element->properties->fontId)] = true;
                }
            }
        }
        return array_keys($ids);
    }

    private static function normalizeProperties($type, $p)
    {
        $out = (object) array(
            "x" => self::number($p, "x", 0, -self::MAX_SIDE * 2, self::MAX_SIDE * 2),
            "y" => self::number($p, "y", 0, -self::MAX_SIDE * 2, self::MAX_SIDE * 2),
            "width" => self::number($p, "width", 1, 1, self::MAX_SIDE * 2),
            "height" => self::number($p, "height", 1, 1, self::MAX_SIDE * 2),
            "scaleX" => self::number($p, "scaleX", 1, 0.001, 100),
            "scaleY" => self::number($p, "scaleY", 1, 0.001, 100),
            "rotation" => self::number($p, "rotation", 0, -3600, 3600),
            "opacity" => self::number($p, "opacity", 1, 0, 1),
            "flipX" => self::boolean($p, "flipX"),
            "flipY" => self::boolean($p, "flipY"),
            "fill" => self::color(self::string($p, "fill", 32, "#000000"), true),
            "stroke" => self::color(self::string($p, "stroke", 32, ""), true),
            "strokeWidth" => self::number($p, "strokeWidth", 0, 0, 100),
            "cornerRadius" => self::number($p, "cornerRadius", 0, 0, 2048)
        );
        if ($type === "text") {
            $fontId = self::integer($p, "fontId", 0);
            $out->text = self::plain(self::string($p, "text", 5000, "Text"), 5000, true);
            $out->fontId = $fontId > 0 ? $fontId : null;
            $out->fontFamily = self::plain(self::string($p, "fontFamily", 180, "Arial"), 180);
            $out->fontSize = self::number($p, "fontSize", 48, 6, 512);
            $out->fontWeight = self::enum(strval(self::string($p, "fontWeight", 4, "400")), array("100", "200", "300", "400", "500", "600", "700", "800", "900"), "font weight");
            $out->fontStyle = self::enum(self::string($p, "fontStyle", 16, "normal"), array("normal", "italic", "oblique"), "font style");
            $out->textAlign = self::enum(self::string($p, "textAlign", 16, "left"), array("left", "center", "right", "justify"), "text alignment");
            $out->lineHeight = self::number($p, "lineHeight", 1.16, 0.5, 4);
            $out->charSpacing = self::number($p, "charSpacing", 0, -1000, 5000);
        } elseif ($type === "image") {
            $out->assetId = self::boundedInt($p, "assetId", 1, PHP_INT_MAX);
            $out->mediaReference = self::mediaReference(self::string($p, "mediaReference", 500));
            $out->cropX = self::number($p, "cropX", 0, 0, self::MAX_SIDE * 4);
            $out->cropY = self::number($p, "cropY", 0, 0, self::MAX_SIDE * 4);
            $out->brightness = self::number($p, "brightness", 0, -1, 1);
            $out->grayscale = self::boolean($p, "grayscale");
        }
        return $out;
    }

    private static function assertAllowedProperties($type, $properties)
    {
        $common = array("x", "y", "width", "height", "scaleX", "scaleY", "rotation", "opacity", "flipX", "flipY", "fill", "stroke", "strokeWidth", "cornerRadius");
        $specific = $type === "text" ? array("text", "fontId", "fontFamily", "fontSize", "fontWeight", "fontStyle", "textAlign", "lineHeight", "charSpacing") : ($type === "image" ? array("assetId", "mediaReference", "cropX", "cropY", "brightness", "grayscale") : array());
        $allowed = array_flip(array_merge($common, $specific));
        foreach (get_object_vars($properties) as $key => $value) {
            if (!isset($allowed[$key])) {
                throw new \InvalidArgumentException("Unsupported property: " . $key);
            }
        }
    }

    private static function rejectUnsafeKeys($value, $depth)
    {
        if ($depth > 8) throw new \InvalidArgumentException("Document nesting is too deep.");
        $array = is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : null);
        if ($array === null) return;
        foreach ($array as $key => $child) {
            if (in_array(strtolower(strval($key)), array("__proto__", "prototype", "constructor"), true)) {
                throw new \InvalidArgumentException("Unsafe document key.");
            }
            self::rejectUnsafeKeys($child, $depth + 1);
        }
    }

    private static function objectFrom($value) { if (is_array($value)) $value = (object) $value; if (!is_object($value)) throw new \InvalidArgumentException("Invalid document object."); return $value; }
    private static function objectValue($object, $key) { if (!isset($object->{$key})) throw new \InvalidArgumentException("Missing " . $key . "."); return self::objectFrom($object->{$key}); }
    private static function arrayValue($object, $key) { $value = isset($object->{$key}) ? $object->{$key} : null; if (is_object($value)) $value = get_object_vars($value); if (!is_array($value)) throw new \InvalidArgumentException("Invalid " . $key . "."); return $value; }
    private static function string($object, $key, $max, $default = "") { $value = isset($object->{$key}) ? strval($object->{$key}) : $default; if (strlen($value) > $max * 4) throw new \InvalidArgumentException($key . " is too long."); return self::slice($value, $max); }
    private static function integer($object, $key, $default) { return isset($object->{$key}) && is_numeric($object->{$key}) ? intval($object->{$key}) : $default; }
    private static function boundedInt($object, $key, $min, $max) { $value = self::integer($object, $key, 0); if ($value < $min || $value > $max) throw new \InvalidArgumentException("Invalid " . $key . "."); return $value; }
    private static function number($object, $key, $default, $min, $max) { $value = isset($object->{$key}) ? $object->{$key} : $default; if (!is_numeric($value)) throw new \InvalidArgumentException("Invalid " . $key . "."); $value = floatval($value); if (is_nan($value) || is_infinite($value) || $value < $min || $value > $max) throw new \InvalidArgumentException("Invalid " . $key . "."); return round($value, 4); }
    private static function boolean($object, $key) { $value = isset($object->{$key}) ? $object->{$key} : false; return $value === true || $value === 1 || $value === "1" || $value === "true"; }
    private static function enum($value, $allowed, $label) { if (!in_array($value, $allowed, true)) throw new \InvalidArgumentException("Invalid " . $label . "."); return $value; }
    private static function uuid($value) { if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/D', $value)) throw new \InvalidArgumentException("Invalid identifier."); return $value; }
    private static function color($value, $emptyAllowed = false)
    {
        $value = strtolower(trim(strval($value)));
        if ($emptyAllowed && $value === "") return "";
        if ($value === "transparent") return "#00000000";
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4})$/D', $value, $short)) {
            return "#" . implode("", array_map(function ($part) { return $part . $part; }, str_split($short[1])));
        }
        if (preg_match('/^#[0-9a-f]{6}(?:[0-9a-f]{2})?$/D', $value)) return $value;
        if (preg_match('/^rgba?\(\s*([0-9]+(?:\.[0-9]+)?)\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*,\s*([0-9]+(?:\.[0-9]+)?)\s*(?:,\s*([0-9]+(?:\.[0-9]+)?)\s*)?\)$/D', $value, $rgb)) {
            $red = floatval($rgb[1]); $green = floatval($rgb[2]); $blue = floatval($rgb[3]);
            if ($red <= 255 && $green <= 255 && $blue <= 255) {
                $color = sprintf("#%02x%02x%02x", round($red), round($green), round($blue));
                if (isset($rgb[4]) && $rgb[4] !== "") {
                    $alpha = floatval($rgb[4]);
                    if ($alpha < 0 || $alpha > 1) throw new \InvalidArgumentException("Invalid color.");
                    $color .= sprintf("%02x", round($alpha * 255));
                }
                return $color;
            }
        }
        throw new \InvalidArgumentException("Invalid color.");
    }
    private static function mediaReference($value) { if (!preg_match('#^components/(?:dock|davvag-cms-v7)/soss-uploader/service/get/image_editor_asset/[A-Za-z0-9][A-Za-z0-9._-]*$#D', $value)) throw new \InvalidArgumentException("Invalid image media reference."); return $value; }
    private static function plain($value, $max, $keepLines = false) { $value = str_replace("\0", "", strip_tags($value)); if (!$keepLines) $value = preg_replace('/\s+/u', ' ', $value); return trim(self::slice($value, $max)); }
    private static function slice($value, $max) { return function_exists("mb_substr") ? mb_substr($value, 0, $max, "UTF-8") : substr($value, 0, $max); }
}
