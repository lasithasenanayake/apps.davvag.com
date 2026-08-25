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
            $segments[] = (object)array("startMs" => $startMs, "endMs" => $endMs, "text" => $text);
            $previousEnd = $endMs;
        }

        $segments = self::normalizeSegments($segments);
        if (!count($segments)) {
            return self::failure("The caption track did not contain readable WebVTT timestamp cues.");
        }
        $plainText = self::plainText($segments);
        if (strlen($plainText) > intval($maxCharacters)) {
            return self::failure("The caption transcript exceeds the 250,000 character storage limit.");
        }
        return (object)array(
            "success" => true,
            "error" => "",
            "segments" => $segments,
            "plainText" => $plainText
        );
    }

    public static function normalizeSegments($segments) {
        if (is_object($segments)) { $segments = (array)$segments; }
        if (!is_array($segments)) { return array(); }
        $output = array();
        foreach ($segments as $value) {
            $item = is_object($value) ? $value : (object)$value;
            $startMs = isset($item->startMs) ? max(0, intval($item->startMs)) : -1;
            $endMs = isset($item->endMs) ? intval($item->endMs) : -1;
            $text = self::cleanText(isset($item->text) ? $item->text : "");
            if ($startMs < 0 || $endMs <= $startMs || $text === "") { continue; }

            if (count($output)) {
                $previous = $output[count($output) - 1];
                $adjacent = $startMs <= $previous->endMs + 250;
                if ($adjacent) {
                    $remainder = self::rollingRemainder($previous->text, $text);
                    if ($remainder !== null) {
                        if ($remainder === "") {
                            if ($endMs - $startMs <= 250) { continue; }
                        } else {
                            $text = $remainder;
                            $startMs = max($startMs, $previous->endMs);
                            if ($endMs <= $startMs) { continue; }
                        }
                    }
                }
            }
            $output[] = (object)array("startMs" => $startMs, "endMs" => $endMs, "text" => $text);
        }
        return $output;
    }

    public static function plainText($segments) {
        $parts = array();
        foreach (is_array($segments) ? $segments : array() as $value) {
            $item = is_object($value) ? $value : (object)$value;
            $text = self::cleanText(isset($item->text) ? $item->text : "");
            if ($text !== "") { $parts[] = $text; }
        }
        return trim(implode("\n", $parts));
    }

    private static function rollingRemainder($previous, $current) {
        $previous = self::cleanText($previous);
        $current = self::cleanText($current);
        if ($previous === "" || $current === "") { return null; }
        if ($current === $previous) { return ""; }
        if (strpos($current, $previous) === 0) {
            return self::cleanText(substr($current, strlen($previous)));
        }

        $previousTokens = preg_split('/\s+/u', $previous, -1, PREG_SPLIT_NO_EMPTY);
        $currentTokens = preg_split('/\s+/u', $current, -1, PREG_SPLIT_NO_EMPTY);
        $maximum = min(count($previousTokens), count($currentTokens));
        for ($size = $maximum; $size >= 2; $size--) {
            if (array_slice($previousTokens, -$size) === array_slice($currentTokens, 0, $size)) {
                return self::cleanText(implode(" ", array_slice($currentTokens, $size)));
            }
        }
        return null;
    }

    private static function cleanText($value) {
        return trim(preg_replace('/\s+/u', ' ', (string)$value));
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
