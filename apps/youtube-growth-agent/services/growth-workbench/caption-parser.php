<?php
namespace youtube_growth_agent;

final class YouTubeCaptionParser {
    public static function parseVtt($content, $durationMs, $maxSegments = 5000, $maxCharacters = 250000) {
        $content = is_string($content) ? $content : "";
        if ($content === "" || strlen($content) > 2097152) {
            return self::failure("The downloaded caption file is empty or exceeds the 2 MB safety limit.");
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        $blocks = preg_split('/\n{2,}/', trim($content));
        $segments = array();
        $plainParts = array();
        $plainLength = 0;
        $previousEnd = 0;
        $durationMs = max(1000, intval($durationMs));

        foreach ($blocks as $block) {
            $lines = preg_split('/\n/', trim($block));
            $timingIndex = -1;
            $startMs = 0;
            $endMs = 0;
            foreach ($lines as $index => $line) {
                if (preg_match('/((?:\d{1,}:)?\d{2}:\d{2}[\.,]\d{3})\s+-->\s+((?:\d{1,}:)?\d{2}:\d{2}[\.,]\d{3})/', trim($line), $matches)) {
                    $timingIndex = $index;
                    $startMs = self::timestampMs($matches[1]);
                    $endMs = self::timestampMs($matches[2]);
                    break;
                }
            }
            if ($timingIndex < 0 || $endMs <= $startMs || $startMs >= $durationMs + 1000) {
                continue;
            }

            $textLines = array_slice($lines, $timingIndex + 1);
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(implode(' ', $textLines)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === "") {
                continue;
            }

            $startMs = max($startMs, $previousEnd);
            $endMs = min($endMs, $durationMs);
            if ($endMs <= $startMs) {
                continue;
            }
            if (count($segments) >= intval($maxSegments)) {
                return self::failure("The caption track contains more than " . intval($maxSegments) . " timestamped segments.");
            }
            if ($plainLength + strlen($text) + 1 > intval($maxCharacters)) {
                return self::failure("The caption transcript exceeds the 250,000 character storage limit.");
            }

            $segments[] = (object)array("startMs" => $startMs, "endMs" => $endMs, "text" => $text);
            $plainParts[] = $text;
            $plainLength += strlen($text) + 1;
            $previousEnd = $endMs;
        }

        if (!count($segments)) {
            return self::failure("The caption track did not contain readable WebVTT timestamp cues.");
        }
        return (object)array(
            "success" => true,
            "error" => "",
            "segments" => $segments,
            "plainText" => trim(implode("\n", $plainParts))
        );
    }

    private static function timestampMs($value) {
        $value = str_replace(',', '.', trim((string)$value));
        $parts = explode(':', $value);
        if (count($parts) === 2) {
            array_unshift($parts, '0');
        }
        if (count($parts) !== 3) {
            return -1;
        }
        return intval($parts[0]) * 3600000 + intval($parts[1]) * 60000 + intval(round(floatval($parts[2]) * 1000));
    }

    private static function failure($message) {
        return (object)array("success" => false, "error" => $message, "segments" => array(), "plainText" => "");
    }
}
?>
