<?php
namespace lesson_market_place;

class MarketplaceException extends \RuntimeException {}

/** Pure validation: no authentication, database, wallet, or browser state. */
final class MarketplaceRules
{
    public static function integer($value, $label, $minimum = 1, $maximum = 1000000000)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string)$value)
            || strlen((string)$value) > 10 || (int)$value < $minimum || (int)$value > $maximum) {
            throw new MarketplaceException("$label must be a whole number between $minimum and $maximum.");
        }
        return (int)$value;
    }

    public static function text($value, $label, $maximum, $required = false)
    {
        if (!is_string($value)) throw new MarketplaceException("$label must be text.");
        $value = trim($value);
        if (strlen($value) > $maximum || ($required && $value === '')) throw new MarketplaceException("$label is missing or too long.");
        return $value;
    }

    public static function slug($value)
    {
        $value = self::text($value, 'Package code', 100, true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) throw new MarketplaceException('Use lowercase letters, numbers and hyphens for the package code.');
        return $value;
    }

    public static function boolean($value)
    {
        if (in_array($value, [true, 1, '1', 'true'], true)) return true;
        if (in_array($value, [false, 0, '0', 'false'], true)) return false;
        throw new MarketplaceException('Approval must be true or false.');
    }

    public static function price($mode, $value)
    {
        if (!in_array($mode, ['free', 'credits'], true)) throw new MarketplaceException('Choose free or credits pricing.');
        $amount = self::integer($value, 'Credit price', 0);
        if (($mode === 'free' && $amount !== 0) || ($mode === 'credits' && $amount < 1)) throw new MarketplaceException('Free packages cost zero; paid packages require a positive whole credit price.');
        return $amount;
    }

    public static function lessonIds($values)
    {
        if (!is_array($values) || count($values) < 1 || count($values) > 200) throw new MarketplaceException('Choose between 1 and 200 lessons.');
        $ids = array_map(function ($id) { return self::integer($id, 'Lesson ID'); }, $values);
        if (count(array_unique($ids)) !== count($ids)) throw new MarketplaceException('A lesson cannot appear twice in a package.');
        return $ids;
    }

    public static function initialState($price, $approval)
    {
        return $approval ? 'pending_approval' : ($price > 0 ? 'awaiting_payment' : 'active');
    }

    public static function transition($state, $action, $price)
    {
        if ($action === 'cancel' && in_array($state, ['pending_approval', 'awaiting_payment'], true)) return 'cancelled';
        if ($state === 'pending_approval' && $action === 'approve') return $price > 0 ? 'awaiting_payment' : 'active';
        if ($state === 'pending_approval' && $action === 'reject') return 'rejected';
        if ($state === 'awaiting_payment' && $action === 'confirm') return 'active';
        throw new MarketplaceException('This request has changed or the action is unavailable. Refresh before continuing.');
    }

    public static function key($value)
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9:_-]{8,100}$/D', $value)) throw new MarketplaceException('A valid operation key is required. Retry with the original key.');
        return $value;
    }

    public static function returnRoute($value)
    {
        if (!is_string($value) || strlen($value) > 600 || !preg_match('~^#/app/lesson-market-place/(?:package\?slug=[a-z0-9-]+|my-enrolments)$~D', $value)) {
            throw new MarketplaceException('The return destination must be an internal marketplace page.');
        }
        return $value;
    }

    public static function safeUrl($value)
    {
        $value = self::text($value, 'URL', 2000);
        if ($value === '') return '';
        if (preg_match('~^components/(?:dock|davvag-cms-v7)/soss-uploader/service/get/lmp_cover/[A-Za-z0-9_-][A-Za-z0-9._-]*$~D', $value) && strpos($value, '..') === false) return $value;
        $parts = parse_url($value);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $value)) throw new MarketplaceException('Use an HTTPS URL or an uploaded marketplace cover.');
        return $value;
    }

    public static function richText($value)
    {
        $value = self::text($value, 'Description', 50000);
        if ($value === '') return '';
        if (!class_exists('DOMDocument')) throw new MarketplaceException('The DOM PHP extension is required to sanitize descriptions.');
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $value . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $clean = function ($node) use (&$clean, $doc) {
                foreach (iterator_to_array($node->childNodes) as $child) {
                    if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) { $node->removeChild($child); continue; }
                    if (!($child instanceof \DOMElement)) continue;
                    $tag = strtolower($child->tagName);
                    if (!in_array($tag, ['div','p','br','strong','b','em','i','u','ul','ol','li','h2','h3','h4','blockquote','a'], true)) { $node->replaceChild($doc->createTextNode($child->textContent), $child); continue; }
                    $href = $child->getAttribute('href');
                    foreach (iterator_to_array($child->attributes) as $attribute) $child->removeAttribute($attribute->name);
                    if ($tag === 'a' && $href !== '') {
                        try { $child->setAttribute('href', self::safeUrl($href)); $child->setAttribute('rel', 'noopener noreferrer'); } catch (MarketplaceException $ignored) {}
                    }
                    $clean($child);
                }
            };
            $clean($doc);
            $root = $doc->getElementsByTagName('div')->item(0);
            $html = ''; if ($root) foreach ($root->childNodes as $node) $html .= $doc->saveHTML($node);
            return $html;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    /** Match Lesson Manager's actual subject ordering and preceding-lesson gate. */
    public static function prerequisites($includedIds, $subjectLessons)
    {
        usort($subjectLessons, function ($a, $b) { return [(int)$a->lesson_order, (int)$a->id] <=> [(int)$b->lesson_order, (int)$b->id]; });
        $missing = []; $previous = null;
        foreach ($subjectLessons as $lesson) {
            if (strtolower($lesson->status ?? '') !== 'published') continue;
            if (in_array((int)$lesson->id, $includedIds, true) && $previous && self::boolean($previous->progression_enabled ?? true)
                && !in_array((int)$previous->id, $includedIds, true)) $missing[] = (int)$previous->id;
            $previous = $lesson;
        }
        return array_values(array_unique($missing));
    }
}
