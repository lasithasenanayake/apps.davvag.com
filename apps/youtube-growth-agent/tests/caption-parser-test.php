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

$rolling = YouTubeCaptionParser::normalizeSegments(array(
    (object)array("startMs" => 199, "endMs" => 3869, "text" => "First spoken phrase"),
    (object)array("startMs" => 3869, "endMs" => 3879, "text" => "First spoken phrase"),
    (object)array("startMs" => 3879, "endMs" => 7909, "text" => "First spoken phrase second phrase"),
    (object)array("startMs" => 7909, "endMs" => 7919, "text" => "second phrase"),
    (object)array("startMs" => 7919, "endMs" => 10709, "text" => "second phrase final phrase")
));
expectCaption(count($rolling) === 3, "ASR rolling cues should collapse to unique phrases");
expectCaption($rolling[0]->text === "First spoken phrase", "the first ASR phrase should be preserved");
expectCaption($rolling[1]->text === "second phrase" && $rolling[1]->startMs === 3879, "only the appended ASR phrase should remain");
expectCaption($rolling[2]->text === "final phrase" && $rolling[2]->startMs === 7919, "subsequent rolling prefixes should be removed");
expectCaption(YouTubeCaptionParser::plainText($rolling) === "First spoken phrase\nsecond phrase\nfinal phrase", "plain text should contain each rolling phrase once");

$intentionalRepeat = YouTubeCaptionParser::normalizeSegments(array(
    (object)array("startMs" => 0, "endMs" => 1200, "text" => "Repeat this"),
    (object)array("startMs" => 1200, "endMs" => 2500, "text" => "Repeat this")
));
expectCaption(count($intentionalRepeat) === 2, "long repeated speech cues should not be removed as ASR markers");

$oversized = YouTubeCaptionParser::parseVtt(str_repeat("x", 2097153), 3000);
expectCaption(!$oversized->success, "caption files above 2 MB should be rejected");

echo "CAPTION_PARSER_TESTS_OK" . PHP_EOL;
?>
