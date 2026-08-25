<?php
require_once dirname(__DIR__) . "/services/growth-workbench/caption-parser.php";

use youtube_growth_agent\YouTubeCaptionParser;

function expectCaption($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$vtt = "WEBVTT\n\n00:00:01.250 --> 00:00:03.500\nHello &amp; welcome\n\n2\n00:00:03.000 --> 00:00:05.750\n<v Speaker>Second cue</v>\n";
$parsed = YouTubeCaptionParser::parseVtt($vtt, 6000);
expectCaption($parsed->success, "valid WebVTT should parse");
expectCaption(count($parsed->segments) === 2, "two cues should be returned");
expectCaption($parsed->segments[0]->startMs === 1250 && $parsed->segments[0]->endMs === 3500, "millisecond timestamps should be retained");
expectCaption($parsed->segments[1]->startMs === 3500 && $parsed->segments[1]->endMs === 5750, "overlapping cues should be normalized without losing text");
expectCaption($parsed->segments[0]->text === "Hello & welcome", "entities should be decoded");
expectCaption($parsed->segments[1]->text === "Second cue", "WebVTT markup should be removed");

$short = YouTubeCaptionParser::parseVtt("WEBVTT\n\n00:01.000 --> 00:02.000\nShort timestamp\n", 3000);
expectCaption($short->success && $short->segments[0]->startMs === 1000, "MM:SS.mmm timestamps should parse");

$invalid = YouTubeCaptionParser::parseVtt("WEBVTT\n\nNo timestamps", 3000);
expectCaption(!$invalid->success && count($invalid->segments) === 0, "malformed tracks should be rejected");

$oversized = YouTubeCaptionParser::parseVtt(str_repeat("x", 2097153), 3000);
expectCaption(!$oversized->success, "caption files above 2 MB should be rejected");

echo "CAPTION_PARSER_TESTS_OK" . PHP_EOL;
?>
