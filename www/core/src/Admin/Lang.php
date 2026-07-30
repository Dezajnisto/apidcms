<?php
/**
 * Minimal i18n helper for admin panel.
 * Loads JSON language pack, provides t(key) with fallback to Russian.
 */

namespace Admin;

class Lang
{
    private static ?Lang $instance = null;
    private array $translations = [];
    private string $locale;

    /**
     * Get singleton, initialising with a locale if not already loaded.
     */
    public static function getInstance(string $locale = 'ru'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($locale);
        }
        return self::$instance;
    }

    /**
     * Re-initialise for a different locale (e.g. on language switch).
     */
    public static function reload(string $locale): self
    {
        self::$instance = new self($locale);
        return self::$instance;
    }

    

    private function __construct(string $locale)
    {
        $this->locale = $locale;
        $this->load($locale);
    }

    /**
     * Load JSON pack for locale; fall back to ru.json for missing keys.
     */
    private function load(string $locale): void
    {
        // Admin lang directory: core/lang/
        $langDir = dirname(__DIR__, 2) . '/lang';

        // Always load Russian as fallback base
        $ruPath = $langDir . '/ru.json';
        if (file_exists($ruPath)) {
            $data = json_decode(file_get_contents($ruPath), true);
            if (is_array($data)) {
                $this->translations = $data;
            }
        }

        // Overlay requested locale (skip if already ru)
        if ($locale !== 'ru') {
            $localePath = $langDir . '/' . $locale . '.json';
            if (file_exists($localePath)) {
                $data = json_decode(file_get_contents($localePath), true);
                if (is_array($data)) {
                    foreach ($data as $k => $v) {
                        $this->translations[$k] = $v;
                    }
                }
            }
        }
    }

    /**
     * Translate a key with optional parameter substitution.
     * Parameters are replaced via {name} placeholders:
     *   Lang::t('forms.saved', ['name' => 'Feedback'])
     *   => "Form 'Feedback' saved"
     */
    public function t(string $key, array $params = [], ?string $default = null): string
    {
        $text = $this->translations[$key] ?? $default ?? $key;
        if ($params) {
            foreach ($params as $k => $v) {
                $text = str_replace('{' . $k . '}', $v, $text);
            }
        }
        return $text;
    }

    /**
     * Get current locale code.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get all translations (useful for debugging).
     */
    public function all(): array
    {
        return $this->translations;
    }
}
