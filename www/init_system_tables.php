<?php
/**
 * init_system_tables.php — инициализация системных таблиц
 *
 * Устанавливает PROJECT_ROOT и делегирует в ядро.
 */
define('PROJECT_ROOT', __DIR__);
require __DIR__ . '/core_lib/init_system_tables.php';
