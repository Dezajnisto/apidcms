<?php
/**
 * Класс для работы с базой данных SQLite
 * 
 * Обеспечивает простое подключение и базовые операции с БД
 */

namespace Core;

class Database {
    private $pdo;
    private $config;
    
    /**
     * Конструктор класса Database
     * 
     * @param array $config Конфигурация базы данных
     */
    public function __construct($config) {
        // Проверяем, что доступ из авторизованного контекста
        if (!defined('ADMIN_ACCESS') && !defined('FRONT_ACCESS')) {
        throw new \Exception('Direct database access not allowed');
        }

        $this->config = $config;
        $this->connect();
    }
    
    /**
     * Подключение к базе данных SQLite
     * 
     * Создает подключение и файл базы данных, если он не существует
     */
    private function connect() {
        try {
            // Проверяем конфигурацию
            if (!isset($this->config['path']) || !isset($this->config['file'])) {
                throw new \Exception("Неверная конфигурация базы данных");
            }
            
            // Проверяем существование папки для базы данных
            if (!is_dir($this->config['path'])) {
                if (!mkdir($this->config['path'], 0755, true)) {
                    throw new \Exception("Не удалось создать папку для базы данных: " . $this->config['path']);
                }
            }
            
            // Создаем подключение к SQLite
            $dsn = "sqlite:" . $this->config['full_path'];
            $this->pdo = new \PDO($dsn); // Исправлено: добавлен \ перед PDO
            
            // Устанавливаем режим ошибок
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            
            // Включаем foreign keys для SQLite
            $this->pdo->exec("PRAGMA foreign_keys = ON");
            
        } catch (\PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        } catch (\Exception $e) {
            die("Ошибка: " . $e->getMessage());
        }
    }
    
    /**
     * Получить подключение PDO
     * 
     * @return \PDO Объект PDO для работы с БД
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Выполнить SQL запрос
     * 
     * @param string $sql SQL запрос
     * @param array $params Параметры для подготовленного запроса
     * @return \PDOStatement Результат выполнения запроса
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            die("Ошибка выполнения запроса: " . $e->getMessage() . "<br>SQL: " . $sql);
        }
    }

    /**
     * Выполнить сырой SQL (для CREATE TABLE, ALTER и т.д.)
     * Используется плагинами для миграций
     */
    public function exec($sql) {
        try {
            return $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            error_log("Database::exec() error: " . $e->getMessage() . " SQL: " . $sql);
            throw $e;
        }
    }
    
    /**
     * Получить все записи из таблицы
     * 
     * @param string $tableName Название таблицы
     * @return array Массив записей
     */
    public function getAll($tableName) {
        $sql = "SELECT * FROM " . $this->quoteIdentifier($tableName);
        $stmt = $this->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить одну запись по ID
     * 
     * @param string $tableName Название таблицы
     * @param int $id ID записи
     * @return array|null Запись или null если не найдена
     */
    public function getById($tableName, $id) {
        $sql = "SELECT * FROM " . $this->quoteIdentifier($tableName) . " WHERE id = ?";
        $stmt = $this->query($sql, [$id]);
        return $stmt->fetch();
    }
    
    /**
     * Экранирование имени таблицы/поля
     * 
     * @param string $identifier Имя таблицы или поля
     * @return string Экранированное имя
     */
    public function quoteIdentifier($identifier) {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
    
    /**
     * Получить список всех таблиц в базе данных
     * 
     * @return array Массив с именами таблиц
     */
    public function getTables() {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
        $stmt = $this->query($sql);
        $tables = $stmt->fetchAll();
        
        $result = [];
        foreach ($tables as $table) {
            $result[] = $table['name'];
        }
        
        return $result;
    }
    
    /**
     * Получить структуру таблицы
     * 
     * @param string $tableName Название таблицы
     * @return array Массив с информацией о полях таблицы
     */
    public function getTableStructure($tableName) {
        $sql = "PRAGMA table_info(" . $this->quoteIdentifier($tableName) . ")";
        $stmt = $this->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Поиск записей в таблице с поддержкой сортировки и пагинации
     * 
     * @param string $tableName Название таблицы
     * @param string $searchQuery Поисковый запрос
     * @param string $sortColumn Колонка для сортировки
     * @param string $sortOrder Порядок сортировки (ASC/DESC)
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array Массив записей
     */
    public function search($tableName, $searchQuery = '', $sortColumn = 'id', $sortOrder = 'ASC', $limit = null, $offset = null) {
        $sql = "SELECT * FROM " . $this->quoteIdentifier($tableName);
        $params = [];

        // Добавляем поиск, если задан
        if (!empty($searchQuery)) {
            $structure = $this->getTableStructure($tableName);
            $searchConditions = [];
            
            foreach ($structure as $column) {
                // Ищем во всех текстовых полях
                if (in_array(strtolower($column['type']), ['text', 'varchar', 'char', 'string'])) {
                    $searchConditions[] = $this->quoteIdentifier($column['name']) . " LIKE ?";
                    $params[] = '%' . $searchQuery . '%';
                }
            }
            
            if (!empty($searchConditions)) {
                $sql .= " WHERE " . implode(' OR ', $searchConditions);
            }
        }

        // Добавляем сортировку
        $sortOrder = strtoupper($sortOrder);
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'ASC';
        }
        $sql .= " ORDER BY " . $this->quoteIdentifier($sortColumn) . " " . $sortOrder;

        // Добавляем пагинацию
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset !== null) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Получить количество записей с учетом поиска
     * 
     * @param string $tableName Название таблицы
     * @param string $searchQuery Поисковый запрос
     * @return int Количество записей
     */
    public function getCount($tableName, $searchQuery = '') {
        $sql = "SELECT COUNT(*) as count FROM " . $this->quoteIdentifier($tableName);
        $params = [];

        if (!empty($searchQuery)) {
            $structure = $this->getTableStructure($tableName);
            $searchConditions = [];
            
            foreach ($structure as $column) {
                if (in_array(strtolower($column['type']), ['text', 'varchar', 'char', 'string'])) {
                    $searchConditions[] = $this->quoteIdentifier($column['name']) . " LIKE ?";
                    $params[] = '%' . $searchQuery . '%';
                }
            }
            
            if (!empty($searchConditions)) {
                $sql .= " WHERE " . implode(' OR ', $searchConditions);
            }
        }

        $result = $this->query($sql, $params)->fetch();
        return (int)$result['count'];
    }

    /**
     * Вставить новую запись в таблицу
     * 
     * @param string $tableName Название таблицы
     * @param array $data Данные для вставки
     * @return int ID новой записи
     */
    public function insert($tableName, $data) {
        $columns = [];
        $placeholders = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $columns[] = $this->quoteIdentifier($column);
            $placeholders[] = '?';
            $values[] = $value;
        }
        
        $sql = "INSERT INTO " . $this->quoteIdentifier($tableName) . 
               " (" . implode(', ', $columns) . ") " .
               "VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->query($sql, $values);
        return $this->pdo->lastInsertId();
    }

    /**
     * Обновить запись в таблице
     * 
     * @param string $tableName Название таблицы
     * @param int $id ID записи
     * @param array $data Данные для обновления
     * @return bool Успешность операции
     */
    public function update($tableName, $id, $data) {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = $this->quoteIdentifier($column) . " = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        
        $sql = "UPDATE " . $this->quoteIdentifier($tableName) . 
               " SET " . implode(', ', $setParts) . 
               " WHERE id = ?";
        
        $stmt = $this->query($sql, $values);
        return $stmt->rowCount() > 0;
    }

    /**
     * Удалить запись из таблицы
     * 
     * @param string $tableName Название таблицы
     * @param int $id ID записи
     * @return bool Успешность операции
     */
    public function delete($tableName, $id) {
        $sql = "DELETE FROM " . $this->quoteIdentifier($tableName) . " WHERE id = ?";
        $stmt = $this->query($sql, [$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Создать новую таблицу
     * 
     * @param string $tableName Название таблицы
     * @param array $columns Массив колонок [['name' => '', 'type' => '', 'nullable' => bool]]
     * @return bool Успешность операции
     */
    public function createTable($tableName, $columns) {
        if (empty($columns)) {
            throw new \Exception("Таблица должна содержать хотя бы одну колонку");
        }
        
        $columnDefinitions = [];
        
        // Всегда добавляем системный первичный ключ в начало
        $columnDefinitions[] = "id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
        
        // Добавляем пользовательские колонки
        foreach ($columns as $column) {
            $definition = $this->quoteIdentifier($column['name']) . " " . $column['type'];
            
            // Для обычных колонок
            if (isset($column['nullable']) && !$column['nullable']) {
                $definition .= " NOT NULL";
            }
            
            // Добавляем DEFAULT только если значение указано и не пустое
            if (isset($column['default']) && $column['default'] !== '') {
                $def = $column['default'];
                // SQL expressions (CURRENT_TIMESTAMP, NULL, etc.) must NOT be quoted
                if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|NULL)$/i', $def)) {
                    $definition .= " DEFAULT " . $def;
                } else {
                    $definition .= " DEFAULT " . $this->pdo->quote($def);
                }
            }
            
            $columnDefinitions[] = $definition;
        }
        
        $sql = "CREATE TABLE " . $this->quoteIdentifier($tableName) . 
               " (" . implode(", ", $columnDefinitions) . ")";
        
        $this->query($sql);
        return true;
    }

    /**
     * Добавить колонку в таблицу
     * 
     * @param string $tableName Название таблицы
     * @param string $columnName Название колонки
     * @param string $columnType Тип колонки
     * @param bool $nullable Может ли быть NULL
     * @param mixed $defaultValue Значение по умолчанию
     * @return bool Успешность операции
     */
    public function addColumn($tableName, $columnName, $columnType, $nullable = true, $defaultValue = null) {
        $sql = "ALTER TABLE " . $this->quoteIdentifier($tableName) . 
               " ADD COLUMN " . $this->quoteIdentifier($columnName) . " " . $columnType;
        
        if (!$nullable) {
            $sql .= " NOT NULL";
        }
        
        if ($defaultValue !== null) {
            if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|NULL)$/i', (string)$defaultValue)) {
                $sql .= " DEFAULT " . $defaultValue;
            } else {
                $sql .= " DEFAULT " . $this->pdo->quote($defaultValue);
            }
        }
        
        $this->query($sql);
        return true;
    }

    /**
     * Удалить таблицу
     * 
     * @param string $tableName Название таблицы
     * @return bool Успешность операции
     */
    public function dropTable($tableName) {
        $sql = "DROP TABLE " . $this->quoteIdentifier($tableName);
        $this->query($sql);
        return true;
    }

    /**
     * Проверить существование таблицы
     * 
     * @param string $tableName Название таблицы
     * @return bool Существует ли таблица
     */
    public function tableExists($tableName) {
        $tables = $this->getTables();
        return in_array($tableName, $tables);
    }

    /**
     * Временно отключить проверку внешних ключей
     * 
     * @param bool $disable Отключить или включить
     */
    public function disableForeignKeys($disable = true) {
        if ($disable) {
            $this->query("PRAGMA foreign_keys = OFF");
        } else {
            $this->query("PRAGMA foreign_keys = ON");
        }
    }

    /**
     * Удаляет колонку из таблицы
     * 
     * В SQLite нет прямой команды DROP COLUMN, поэтому нужно:
     * 1. Создать временную таблицу без удаляемой колонки
     * 2. Скопировать данные из старой таблицы во временную
     * 3. Удалить старую таблицу
     * 4. Переименовать временную таблицу в исходное имя
     * 
     * @param string $table Название таблицы
     * @param string $column Название колонки для удаления
     * @return bool Успешность выполнения операции
     * @throws Exception Если произошла ошибка
     */
    public function deleteColumn($table, $column) {
        try {
            // Начинаем транзакцию для безопасности
            $this->pdo->beginTransaction();
            
            // Получаем структуру таблицы
            $structure = $this->getTableStructure($table);
            
            // Проверяем, что колонка существует
            $columnExists = false;
            foreach ($structure as $col) {
                if ($col['name'] === $column) {
                    $columnExists = true;
                    break;
                }
            }
            
            if (!$columnExists) {
                throw new Exception("Колонка '{$column}' не существует в таблице '{$table}'");
            }
            
            // Формируем список колонок без удаляемой
            $columns = [];
            foreach ($structure as $col) {
                if ($col['name'] !== $column) {
                    $columns[] = $col['name'];
                }
            }
            
            if (empty($columns)) {
                throw new Exception("Нельзя удалить все колонки таблицы");
            }
            
            // Создаем временное имя для новой таблицы
            $tempTable = $table . '_new_' . time();
            
            // Создаем SQL для новой таблицы
            $columnDefinitions = [];
            foreach ($structure as $col) {
                if ($col['name'] === $column) {
                    continue; // Пропускаем удаляемую колонку
                }
                
                $definition = $this->quoteIdentifier($col['name']) . " " . $col['type'];
                
                // Добавляем ограничения
                if ($col['notnull']) {
                    $definition .= " NOT NULL";
                }
                if ($col['dflt_value'] !== null) {
                    $dflt = $col['dflt_value'];
                    if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|NULL)$/i', $dflt)) {
                        $definition .= " DEFAULT " . $dflt;
                    } else {
                        $definition .= " DEFAULT " . $this->pdo->quote($dflt);
                    }
                }
                if ($col['pk']) {
                    $definition .= " PRIMARY KEY";
                    if (stripos($col['type'], 'INTEGER') !== false && $col['name'] === 'id') {
                        $definition .= " AUTOINCREMENT";
                    }
                }
                
                $columnDefinitions[] = $definition;
            }
            
            // Создаем новую таблицу без удаляемой колонки
            $createSql = "CREATE TABLE " . $this->quoteIdentifier($tempTable) . " (" . implode(", ", $columnDefinitions) . ")";
            $this->pdo->exec($createSql);
            
            // Копируем данные из старой таблицы в новую
            $columnsList = implode(", ", array_map([$this, 'quoteIdentifier'], $columns));
            $copySql = "INSERT INTO " . $this->quoteIdentifier($tempTable) . " (" . $columnsList . ") SELECT " . $columnsList . " FROM " . $this->quoteIdentifier($table);
            $this->pdo->exec($copySql);
            
            // Удаляем старую таблицу
            $this->pdo->exec("DROP TABLE " . $this->quoteIdentifier($table));
            
            // Переименовываем новую таблицу в старое имя
            $this->pdo->exec("ALTER TABLE " . $this->quoteIdentifier($tempTable) . " RENAME TO " . $this->quoteIdentifier($table));
            
            // Фиксируем транзакцию
            $this->pdo->commit();
            
            return true;
            
        } catch (Exception $e) {
            // Откатываем транзакцию в случае ошибки
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception("Ошибка при удалении колонки: " . $e->getMessage());
        }
    }
}
?>