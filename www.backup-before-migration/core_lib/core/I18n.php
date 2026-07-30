<?php
namespace Core;

/**
 * I18n — internationalization helper for JSON-based multilingual fields.
 * 
 * Supports the flat-JSON pattern: a single column stores all translations.
 *   Plain string:  "Hello"           → always returned as-is (single-language site)
 *   i18n object:   {"ru":"Привет","en":"Hello"} → locale-specific value resolved
 *
 * Usage:
 *   $value = I18n::resolve($row['title'], $locale, 'ru');
 *   $items = I18n::resolveArray($items, ['title','description'], $locale);
 */
class I18n
{
    /**
     * Resolve a single value to a locale-specific string.
     *
     * @param mixed  $value    Raw value (string, array, or JSON-decoded object)
     * @param string $locale   Target locale (e.g. 'en')
     * @param string $fallback Fallback locale if target not found (default: 'ru')
     * @return string
     */
    public static function resolve($value, string $locale, string $fallback = 'ru'): string
    {
        if (is_string($value)) {
            // Check if it looks like a JSON i18n object: {"ru":"...","en":"..."}
            if (strlen($value) > 0 && $value[0] === '{' && strpos($value, '"' . $locale . '"') !== false) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return self::pick($decoded, $locale, $fallback);
                }
            }
            return $value;
        }

        if (is_array($value)) {
            return self::pick($value, $locale, $fallback);
        }

        return (string)$value;
    }

    /**
     * Resolve multiple fields in an array of items.
     *
     * @param array  $items   Array of associative arrays
     * @param array  $fields  Field names to resolve
     * @param string $locale  Target locale
     * @param string $fallback Fallback locale
     * @return array
     */
    public static function resolveArray(array $items, array $fields, string $locale, string $fallback = 'ru'): array
    {
        foreach ($items as &$item) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && !is_null($item[$field]) && $item[$field] !== '') {
                    $item[$field] = self::resolve($item[$field], $locale, $fallback);
                }
            }
        }
        return $items;
    }

    /**
     * Build an i18n JSON string from locale→value pairs.
     * Returns a plain string if only one locale is provided.
     *
     * @param array  $values   ['ru' => 'Привет', 'en' => 'Hello']
     * @param string $baseLang Base language (if only this lang exists, return plain string)
     * @return string
     */
    public static function encode(array $values, string $baseLang = 'ru'): string
    {
        // Filter empty values
        $values = array_filter($values, function ($v) { return $v !== null && $v !== ''; });
        
        // If only the base language has a value, return as plain string
        if (count($values) === 1 && isset($values[$baseLang])) {
            return $values[$baseLang];
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Check if a value is a JSON i18n object (not a plain string).
     */
    public static function isJsonObject($value): bool
    {
        if (!is_string($value)) return is_array($value);
        if (strlen($value) === 0 || $value[0] !== '{') return false;
        $decoded = json_decode($value, true);
        return is_array($decoded) && !empty($decoded);
    }

    /**
     * Build SQL expression for locale-aware search.
     * Returns SQL fragment for json_extract on the given column.
     *
     * @param string $column  Column name
     * @param array  $locales Locales to search in (e.g. ['ru', 'en'])
     * @return string SQL fragment like "COALESCE(json_extract(col,'$.en'),col)"
     */
    public static function searchExpr(string $column, array $locales): string
    {
        $parts = [];
        foreach ($locales as $loc) {
            $parts[] = "json_extract({$column}, '$." . addslashes($loc) . "')";
        }
        // Also check plain string values (for legacy / single-lang sites)
        $parts[] = $column;
        // Return chain: first non-null locale wins
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    /**
     * Pick locale from an associative array, with fallback chain.
     */
    private static function pick(array $data, string $locale, string $fallback): string
    {
        return $data[$locale] ?? $data[$fallback] ?? (string)reset($data);
    }
}
