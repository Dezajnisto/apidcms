<?php
/**
 * Контроллер главной страницы
 * 
 * Отображает список таблиц и основную информацию
 */

namespace Admin;

use Core\Database;
use Exception;

class HomeController extends BaseController {
    
    /**
     * Главная страница
     * 
     * Показывает список всех таблиц в базе данных
     */
    public function index() {
        // Получаем список всех таблиц
        $tables = $this->db->getTables();
        
        // Подготавливаем информацию о таблицах
        $tablesInfo = [];
        foreach ($tables as $tableName) {
            $structure = $this->db->getTableStructure($tableName);
            $tablesInfo[] = [
                'name' => $tableName,
                'columns' => count($structure),
                'structure' => $structure
            ];
        }
        
        // Отображаем шаблон
        $this->render('home/index', [
            'tables' => $tablesInfo,
            'title' => 'Главная страница CMS',
            '_GET' => $_GET
        ]);
    }

    /**
     * Показать форму создания таблицы
     */
    public function createTableForm() {
        $this->render('home/create_table', [
            'title' => 'Создание новой таблицы',
            'formData' => []
        ]);
    }

    /**
     * Создать новую таблицу
     */
    public function createTable() {
        try {
            $tableName = $_POST['table_name'] ?? '';
            $columns = $_POST['columns'] ?? [];
            $addTimestamps = isset($_POST['add_timestamps']);
            
            if (empty($tableName)) {
                throw new Exception("Название таблицы не может быть пустым");
            }
            
            // Проверяем валидность имени таблицы
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
                throw new Exception("Название таблицы может содержать только буквы, цифры и подчеркивания, и должно начинаться с буквы или подчеркивания");
            }
            
            // Проверяем, что таблица не существует
            if ($this->db->tableExists($tableName)) {
                throw new Exception("Таблица с названием '{$tableName}' уже существует");
            }
            
            // Подготавливаем колонки
            $tableColumns = [];
            foreach ($columns as $index => $column) {
                if (empty($column['name']) || empty($column['type'])) {
                    continue; // Пропускаем пустые колонки
                }
                
                $tableColumns[] = [
                    'name' => $column['name'],
                    'type' => strtoupper($column['type']),
                    'nullable' => isset($column['nullable']),
                    'default' => $column['default'] ?? null
                ];
            }
            
            // Добавляем временные метки, если запрошено
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
            
            // Создаем таблицу (системная колонка id добавляется автоматически в Database::createTable)
            $this->db->createTable($tableName, $tableColumns);
            
            // Перенаправляем на страницу таблицы
            $this->redirect("/table/{$tableName}?created=1");
            
        } catch (Exception $e) {
            $this->render('home/create_table', [
                'title' => 'Создание новой таблицы',
                'error' => $e->getMessage(),
                'formData' => $_POST
            ]);
        }
    }


    /**
     * Создать таблицу из сырого SQL
     */
    public function createTableSql() {
        try {
            $sql = $_POST['sql_code'] ?? '';
            
            if (empty(trim($sql))) {
                throw new \Exception("SQL-код не может быть пустым");
            }
            
            // Разрешаем только CREATE TABLE (с опциональным IF NOT EXISTS)
            $sqlTrimmed = trim($sql);
            if (!preg_match('/^CREATE\s+TABLE/i', $sqlTrimmed)) {
                throw new \Exception("Разрешены только операторы CREATE TABLE. Другие SQL-операции запрещены.");
            }
            
            // Запрещаем вложенные опасные операторы
            $dangerous = ['DROP', 'DELETE', 'INSERT', 'UPDATE', 'ALTER', 'TRUNCATE', 'REPLACE'];
            foreach ($dangerous as $cmd) {
                if (preg_match('/;\s*' . $cmd . '/i', $sql)) {
                    throw new \Exception("Обнаружена запрещённая команда {$cmd}. Разрешён только один CREATE TABLE.");
                }
            }
            
            // Выполняем SQL
            $this->db->exec($sql);
            
            // Извлекаем имя таблицы из SQL для редиректа
            preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\'']?([a-zA-Z_][a-zA-Z0-9_]*)[`"\'']?\s*\(/i', $sql, $matches);
            $tableName = $matches[1] ?? 'unknown';
            
            $this->redirect("/table/{$tableName}?created=1");
            
        } catch (\Exception $e) {
            $this->render('home/create_table', [
                'title' => 'Создание новой таблицы',
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
            // Проверяем существование таблицы
            if (!$this->db->tableExists($table)) {
                $this->render('error/404', [
                    'message' => "Таблица '{$table}' не найдена"
                ]);
                return;
            }
            
            // Удаляем таблицу
            $this->db->dropTable($table);
            
            // Перенаправляем на главную с сообщением
            $this->redirect("/?table_deleted=1");
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => "Ошибка при удалении таблицы: " . $e->getMessage()
            ]);
        }
    }
}
?>