<?php
require_once dirname(__DIR__) . "/lib/FontInspector.php";

use image_editor\FontInspector;

$path = dirname(__DIR__) . "/components/editor-runtime/fonts/DejaVuSans.ttf";
$font = FontInspector::inspect($path, "DejaVuSans.ttf");
if ($font["format"] !== "ttf" || $font["family"] === "" || strlen($font["checksum"]) !== 64) {
    throw new RuntimeException("The bundled font did not pass metadata inspection.");
}

try {
    FontInspector::inspect($path, "DejaVuSans.otf");
    throw new RuntimeException("A mismatched font extension was accepted.");
} catch (InvalidArgumentException $error) {
    if (stripos($error->getMessage(), "signature") === false) throw $error;
}

echo "FontInspector rules: OK\n";
?>
