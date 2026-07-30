<?php
/**
 * core/config/admin.php — default admin configuration
 *
 * Project overrides go in PROJECT_ROOT/config/admin.php
 * and are merged on top of these defaults.
 */

return [
    'database' => [
        'path'     => STORAGE_PATH . '/database/',
        'file'     => 'cms.db',
        'full_path' => STORAGE_PATH . '/database/cms.db',
    ],
    'paths' => [
        'root'       => PROJECT_ROOT,
        'admin_views' => CORE_PATH . '/views/admin',
        'admin_src'   => CORE_PATH . '/src/Admin',
        'storage'     => STORAGE_PATH,
        'public'      => PUBLIC_PATH,
    ],
    'security' => [
        'admin_username'  => 'admin',
        'admin_password'  => 'admin',
        'session_timeout' => 3600,
    ],
    'theme' => [
        'active' => 'default',
    ],
    'ai' => [
        'api_key' => '',
        'model'   => 'deepseek-chat',
    ],
];
