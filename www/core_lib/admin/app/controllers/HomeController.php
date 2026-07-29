<?php
/**
 * Home page controller
 *
 * Displays table list and basic information
 */

namespace Admin;

use Core\Database;
use Admin\Core\Lang;
use Exception;

class HomeController extends BaseController {
    
    /**
     * Main page - dashboard
     */
    public function index() {
        // Count tables for dashboard
        $tableCount = count($this->db->getTables());

        $this->render('home/index', [
            'title' => Lang::t('home.title'),
            'table_count' => $tableCount,
        ]);
    }

    /**
     * Tables page - list of all database tables
     */
    public function tables() {
        // Get all tables
        $tables = $this->db->getTables();

        // System tables (created by init_system_tables.php + VisitStats)
        $systemTableNames = ['pages', 'forms', 'navigation', 'system_settings', 'entity_relations', 'visit_stats'];

        // Plugin tables (declared in plugin.json)
        $pm = \Core\PluginManager::getInstance();
        $pluginTablesMap = $pm->getPluginTables();
        $pluginTableNames = [];
        foreach ($pluginTablesMap as $pluginTables) {
            $pluginTableNames = array_merge($pluginTableNames, $pluginTables);
        }

        // Build grouped table info
        $groups = [];

        // Helper: build table info list for given names
        $buildInfo = function(array $tableNames) use ($tables) {
            $info = [];
            foreach ($tableNames as $tableName) {
                if (in_array($tableName, $tables, true)) {
                    $structure = $this->db->getTableStructure($tableName);
                    $info[] = [
                        'name' => $tableName,
                        'columns' => count($structure),
                        'structure' => $structure,
                    ];
                }
            }
            return $info;
        };

        // System group
        $systemInfo = $buildInfo($systemTableNames);
        if (!empty($systemInfo)) {
            $groups[] = ['label' => Lang::t('home.system_tables'), 'tables' => $systemInfo];
        }

        // Plugin groups (one per plugin that has tables)
        foreach ($pluginTablesMap as $pluginName => $pluginTables) {
            $pluginInfo = $buildInfo($pluginTables);
            if (!empty($pluginInfo)) {
                $groups[] = ['label' => Lang::t('home.plugin_tables', ['name' => $pluginName]), 'tables' => $pluginInfo];
            }
        }

        // User tables (everything not in system or plugin lists)
        $allKnown = array_merge($systemTableNames, $pluginTableNames);
        $userTableNames = array_diff($tables, $allKnown);
        $userInfo = $buildInfo($userTableNames);
        if (!empty($userInfo)) {
            $groups[] = ['label' => Lang::t('home.user_tables'), 'tables' => $userInfo];
        }

        // Render
        $this->render('home/tables', [
            'groups' => $groups,
            'title' => Lang::t('home.tables_list'),
            '_GET' => $_GET,
        ]);
    }

    /**
     * Show create table form
     */
    public function createTableForm() {
        $this->render('home/create_table', [
            'title' => Lang::t('create_table.title'),
            'formData' => []
        ]);
    }

    /**
     * Create new table
     */
    public function createTable() {
        try {
            $tableName = $_POST['table_name'] ?? '';
            $columns = $_POST['columns'] ?? [];
            $addTimestamps = isset($_POST['add_timestamps']);
            
            if (empty($tableName)) {
                throw new Exception(Lang::t('create_table.name_required'));
            }
            
            // Validate table name
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
                throw new Exception(Lang::t('create_table.name_pattern'));
            }
            
            // Check table does not exist
            if ($this->db->tableExists($tableName)) {
                throw new Exception(Lang::t('create_table.table_exists', ['name' => $tableName]));
            }
            
            // Prepare columns
            $tableColumns = [];
            foreach ($columns as $index => $column) {
                if (empty($column['name']) || empty($column['type'])) {
                    continue; // Skip empty columns
                }
                
                $tableColumns[] = [
                    'name' => $column['name'],
                    'type' => strtoupper($column['type']),
                    'nullable' => isset($column['nullable']),
                    'default' => $column['default'] ?? null
                ];
            }
            
            // Add timestamps if requested
            if ($addTimestamps) {
                $tableColumns[] = [
                    'name' => 'created_at',
                    'type' => 'DATETIME',
                    'nullable' => false,
                    'default' => 'CURRENT_TIMESTAMP'
                ];
                $tableColumns[] = [
                    'name' => 'updated_at', 
                    'type' => 'DATETIME',
                    'nullable' => true
                ];
            }
            
            // Create table (system id column added automatically by Database::createTable)
            $this->db->createTable($tableName, $tableColumns);
            
            // Redirect to table page
            $this->redirect("/table/{$tableName}?created=1");
            
        } catch (Exception $e) {
            $this->render('home/create_table', [
                'title' => Lang::t('create_table.title'),
                'error' => $e->getMessage(),
                'formData' => $_POST
            ]);
        }
    }


    /**
     * Create table from raw SQL
     */
    public function createTableSql() {
        try {
            $sql = $_POST['sql_code'] ?? '';
            
            if (empty(trim($sql))) {
                throw new \Exception(Lang::t('home.sql_empty'));
            }
            
            // Allow only CREATE TABLE (with optional IF NOT EXISTS)
            $sqlTrimmed = trim($sql);
            if (!preg_match('/^CREATE\s+TABLE/i', $sqlTrimmed)) {
                throw new \Exception(Lang::t('home.sql_only_create'));
            }
            
            // Block nested dangerous statements
            $dangerous = ['DROP', 'DELETE', 'INSERT', 'UPDATE', 'ALTER', 'TRUNCATE', 'REPLACE'];
            foreach ($dangerous as $cmd) {
                if (preg_match('/;\s*' . $cmd . '/i', $sql)) {
                    throw new \Exception(Lang::t('home.sql_dangerous_cmd', ['cmd' => $cmd]));
                }
            }
            
            // Execute SQL
            $this->db->exec($sql);
            
            // Extract table name from SQL for redirect
            preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\x27]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\x27]?\s*\(/i', $sql, $matches);
            $tableName = $matches[1] ?? 'unknown';
            
            $this->redirect("/table/{$tableName}?created=1");
            
        } catch (\Exception $e) {
            $this->render('home/create_table', [
                'title' => Lang::t('create_table.title'),
                'error' => $e->getMessage()
            ]);
        }
    }


    /**
     * Удалить таблицу
     * 
     * @param string $table Название таблицы
     */
    public function deleteTable($table) {
        try {
            // Verify table exists
            if (!$this->db->tableExists($table)) {
                $this->render('error/404', [
                    'message' => Lang::t('table.table_not_found_short', ['table' => $table])
                ]);
                return;
            }
            
            // Delete table
            $this->db->dropTable($table);
            
            // Redirect to home with message
            $this->redirect("/tables?table_deleted=1");
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => Lang::t('home.delete_table_error') . ' ' . $e->getMessage()
            ]);
        }
    }
}
?>