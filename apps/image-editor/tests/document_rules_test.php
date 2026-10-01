<?php
require_once dirname(__DIR__) . "/lib/ImageEditorDocument.php";

use image_editor\ImageEditorDocument;

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function expectInvalid($document, $project, $messagePart)
{
    try {
        ImageEditorDocument::normalize($document, $project);
    } catch (InvalidArgumentException $error) {
        check(stripos($error->getMessage(), $messagePart) !== false, "Unexpected validation error: " . $error->getMessage());
        return;
    }
    throw new RuntimeException("Expected document validation to fail.");
}

$project = (object) array("width_px" => 1280, "height_px" => 720);
$document = (object) array(
    "documentVersion" => 1,
    "pages" => array((object) array(
        "pageUuid" => "page-0001",
        "width" => 1280,
        "height" => 720,
        "background" => (object) array("type" => "solid", "color" => "#FFFFFF"),
        "elements" => array((object) array(
            "elementUuid" => "text-0001",
            "type" => "text",
            "name" => "Heading",
            "zIndex" => 99,
            "locked" => false,
            "hidden" => false,
            "properties" => (object) array("text" => "<b>Hello</b>", "fontId" => 7, "fontFamily" => "Example")
        ))
    ))
);

$normalized = ImageEditorDocument::normalize($document, $project);
check($normalized->pages[0]->background->color === "#ffffff", "Color was not normalized.");
check($normalized->pages[0]->elements[0]->zIndex === 0, "Layer order must be canonical.");
check($normalized->pages[0]->elements[0]->properties->text === "Hello", "Markup must be removed from text.");
check(ImageEditorDocument::fontIds($normalized) === array(7), "Font references were not collected.");
check(strlen(ImageEditorDocument::checksum($normalized)) === 64, "Checksum must be SHA-256.");

$wrongSize = json_decode(json_encode($document));
$wrongSize->pages[0]->width = 100;
expectInvalid($wrongSize, $project, "dimensions");

$unknownProperty = json_decode(json_encode($document));
$unknownProperty->pages[0]->elements[0]->properties->script = "alert(1)";
expectInvalid($unknownProperty, $project, "Unsupported property");

$unsafe = json_decode('{"documentVersion":1,"pages":[{"pageUuid":"page-0001","width":1280,"height":720,"background":{"type":"solid","color":"#ffffff"},"elements":[],"constructor":{"polluted":true}}]}');
expectInvalid($unsafe, $project, "Unsafe document key");

echo "ImageEditorDocument rules: OK\n";
?>
