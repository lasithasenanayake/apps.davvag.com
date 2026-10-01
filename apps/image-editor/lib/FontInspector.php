<?php
namespace image_editor;

final class FontInspector
{
    public static function inspect($path, $originalName)
    {
        if (!is_file($path)) throw new \InvalidArgumentException("The uploaded font could not be found.");
        $size = filesize($path);
        if ($size < 64 || $size > 10485760) throw new \InvalidArgumentException("The font must be between 64 bytes and 10 MB.");
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, array("ttf", "otf"), true)) throw new \InvalidArgumentException("Phase 1 currently accepts TTF and OTF fonts.");
        $data = file_get_contents($path);
        $signature = substr($data, 0, 4);
        $format = $signature === "OTTO" ? "otf" : ($signature === "\x00\x01\x00\x00" || $signature === "true" ? "ttf" : "");
        if ($format === "" || $format !== $extension) throw new \InvalidArgumentException("The font signature does not match its extension.");
        $numTables = self::u16($data, 4);
        if ($numTables < 1 || $numTables > 128 || 12 + $numTables * 16 > $size) throw new \InvalidArgumentException("The font table directory is invalid.");
        $tables = array();
        for ($i = 0; $i < $numTables; $i++) {
            $base = 12 + $i * 16; $tag = substr($data, $base, 4); $offset = self::u32($data, $base + 8); $length = self::u32($data, $base + 12);
            if ($offset < 0 || $length < 0 || $offset + $length > $size) throw new \InvalidArgumentException("The font contains an out-of-bounds table.");
            $tables[$tag] = array($offset, $length);
        }
        if (!isset($tables["head"]) || !isset($tables["name"])) throw new \InvalidArgumentException("Required font tables are missing.");
        $names = self::names($data, $tables["name"]);
        $weight = 400; $fsType = 0;
        if (isset($tables["OS/2"]) && $tables["OS/2"][1] >= 10) { $weight = self::u16($data, $tables["OS/2"][0] + 4); $fsType = self::u16($data, $tables["OS/2"][0] + 8); }
        $weight = max(100, min(900, intval(round($weight / 100) * 100)));
        $subfamily = isset($names[2]) ? $names[2] : "Regular";
        $style = stripos($subfamily, "italic") !== false ? "italic" : (stripos($subfamily, "oblique") !== false ? "oblique" : "normal");
        return array(
            "format" => $format,
            "mime_type" => $format === "otf" ? "font/otf" : "font/ttf",
            "byte_size" => $size,
            "checksum" => hash_file("sha256", $path),
            "family" => isset($names[1]) ? $names[1] : "",
            "subfamily" => $subfamily,
            "postscript_name" => isset($names[6]) ? $names[6] : "",
            "font_weight" => $weight,
            "font_style" => $style,
            "fs_type" => $fsType
        );
    }

    private static function names($data, $table)
    {
        list($offset, $length) = $table;
        if ($length < 6) throw new \InvalidArgumentException("The font name table is invalid.");
        $count = self::u16($data, $offset + 2); $storage = self::u16($data, $offset + 4);
        if ($count > 256 || 6 + $count * 12 > $length) throw new \InvalidArgumentException("The font name table is invalid.");
        $wanted = array(1 => true, 2 => true, 6 => true); $result = array();
        for ($i = 0; $i < $count; $i++) {
            $base = $offset + 6 + $i * 12; $platform = self::u16($data, $base); $nameId = self::u16($data, $base + 6); $size = self::u16($data, $base + 8); $relative = self::u16($data, $base + 10);
            if (!isset($wanted[$nameId]) || $size < 1 || $size > 1024 || $storage + $relative + $size > $length) continue;
            $raw = substr($data, $offset + $storage + $relative, $size);
            if ($platform === 0 || $platform === 3) $text = function_exists("mb_convert_encoding") ? mb_convert_encoding($raw, "UTF-8", "UTF-16BE") : iconv("UTF-16BE", "UTF-8//IGNORE", $raw);
            else $text = $raw;
            $text = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', strval($text)));
            if ($text !== "" && !isset($result[$nameId])) $result[$nameId] = self::slice($text, 160);
        }
        return $result;
    }

    private static function u16($data, $offset) { $value = unpack("n", substr($data, $offset, 2)); return intval($value[1]); }
    private static function u32($data, $offset) { $value = unpack("N", substr($data, $offset, 4)); return intval($value[1]); }
    private static function slice($value, $max) { return function_exists("mb_substr") ? mb_substr($value, 0, $max, "UTF-8") : substr($value, 0, $max); }
}
