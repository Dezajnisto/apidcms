<?php
/**
 * core/config/front.php — default frontend configuration
 *
 * Project overrides go in PROJECT_ROOT/config/front.php
 * and are merged on top of these defaults.
 */

return [
    'database' => [
        'path'      => STORAGE_PATH . '/database/',
        'file'      => 'cms.db',
        'full_path' => STORAGE_PATH . '/database/cms.db',
    ],
    'paths' => [
        'root'        => PROJECT_ROOT,
        'front_views' => CORE_PATH . '/views/front',
        'public'      => PUBLIC_PATH,
        'storage'     => STORAGE_PATH,
        'themes'      => THEMES_PATH,
    ],
    'twig' => [
        'cache'      => STORAGE_PATH . '/cache/twig',
        'auto_reload' => true,
    ],
    'theme' => [
        'active' => 'default',
    ],
];
