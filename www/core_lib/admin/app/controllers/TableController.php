<?php
/**
 * Контроллер для работы с таблицами
 * 
 * Отображает данные из произвольных таблиц
 */

namespace Admin;

use Core\Database;
use Exception;

class TableController extends BaseController {
    
    /**
     * Load relations from page_config for a table
     * Returns [column_name => ['table', 'label', 'value', 'options' => [...]]]
     */
    private function getRelations($table) {
        $relations = [];
        
        try {
            $navItems = $this->db->query(
                "SELECT page_config FROM navigation WHERE source_table = ? AND page_type = 'dynamic' AND status = 'active'",
                [$table]
            )->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($navItems as $nav) {
                if (empty($nav['page_config'])) continue;
                $config = json_decode($nav['page_config'], true);
                if (empty($config['relations']) || !is_array($config['relations'])) continue;
                
                foreach ($config['relations'] as $columnName => $rel) {
                    if (isset($relations[$columnName])) continue; // first wins
                    
                    $relTable = $rel['table'] ?? '';
                    $relLabel = $rel['label'] ?? 'title';
                    $relValue = $rel['value'] ?? 'id';
                    
                    if (empty($relTable)) continue;
                    
                    // Check if related table exists
                    if (!in_array($relTable, $this->db->getTables())) continue;
                    
                    // Load options
                    try {
                        $rows = $this->db->getAll($relTable);
                        $options = [];
                        foreach ($rows as $row) {
                            $options[] = [
                                'value' => $row[$relValue] ?? '',
                                'label' => $row[$relLabel] ?? ($row[$relValue] ?? '')
                            ];
                        }
                        $relations[$columnName] = [
                            'table' => $relTable,
                            'label' => $relLabel,
                            'value' => $relValue,
                            'options' => $options
                        ];
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently ignore
        }
        
        return $relations;
    }
    
    /**
     * Просмотр содержимого таблицы с поддержкой поиска и сортировки
     * 
     * @param string $table Название таблицы
     */
    public function view($table) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем параметры
        $search = $_GET['search'] ?? '';
        $sort = $_GET['sort'] ?? 'id';
        $order = $_GET['order'] ?? 'ASC';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 10;

        // Валидация параметров сортировки
        $structure = $this->db->getTableStructure($table);
        $validColumns = array_map(function($col) { 
            return $col['name']; 
        }, $structure);
        
        if (!in_array($sort, $validColumns)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        // Вычисляем offset для пагинации
        $offset = ($page - 1) * $perPage;

        // Получаем данные
        $data = $this->db->search($table, $search, $sort, $order, $perPage, $offset);
        $totalCount = $this->db->getCount($table, $search);
        $totalPages = ceil($totalCount / $perPage);

        // Отображаем шаблон
        $this->render('table/view', [
            'tableName' => $table,
            'data' => $data,
            'structure' => $structure,
            'title' => "Таблица: {$table}",
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
            'perPage' => $perPage,
            '_GET' => $_GET
        ]);
    }
    
    /**
     * Просмотр отдельной записи
     * 
     * @param string $table Название таблицы
     * @param int $id ID записи
     */
    public function viewItem($table, $id) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем запись по ID
        $item = $this->db->getById($table, $id);
        
        if (!$item) {
            $this->render('error/404', [
                'message' => "Запись с ID {$id} не найдена в таблице '{$table}'"
            ]);
            return;
        }
        
        // Получаем структуру таблицы для отображения
        $structure = $this->db->getTableStructure($table);
        
        // Отображаем шаблон
        $this->render('table/view_item', [
            'tableName' => $table,
            'item' => $item,
            'structure' => $structure,
            'title' => "Запись #{$id} из таблицы: {$table}",
            'currentPage' => $_GET['page'] ?? 1,
            'get' => $_GET
        ]);
    }

    /**
     * Показать форму создания записи
     * 
     * @param string $table Название таблицы
     */
    public function createForm($table) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем структуру таблицы
        $structure = $this->db->getTableStructure($table);
        
        // Load relations from page_config
        $relations = $this->getRelations($table);
        
        // Отображаем форму создания
        $this->render('table/form', [
            'tableName' => $table,
            'structure' => $structure,
            'title' => "Добавление записи в таблицу: {$table}",
            'action' => 'create',
            'item' => null,
            'relations' => $relations,
            'currentPage' => $_GET['page'] ?? 1
        ]);
    }

    /**
     * Создать новую запись
     * 
     * @param string $table Название таблицы
     */
    public function create($table) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем структуру таблицы
        $structure = $this->db->getTableStructure($table);
        
        // Подготавливаем данные
        $data = [];
        foreach ($structure as $column) {
            $columnName = $column['name'];
            
            // Пропускаем автоинкрементные поля
            if ($column['pk'] == 1 && stripos($column['type'], 'INTEGER') !== false) {
                continue;
            }
            
            // Пропускаем поля с created_at (они заполнятся автоматически)
            if ($columnName === 'created_at') {
                continue;
            }
            
            // Получаем значение из POST
            if (isset($_POST[$columnName])) {
                $data[$columnName] = $_POST[$columnName];
            } elseif ($column['notnull'] == 1 && $column['pk'] == 0) {
                // Для обязательных полей, если значение не передано, устанавливаем пустую строку
                $data[$columnName] = '';
            }
        }
        
        try {
            // Вставляем данные
            $newId = $this->db->insert($table, $data);
            
            // Перенаправляем на просмотр созданной записи
                            $page = $_GET['page'] ?? 1;
                $this->redirect("/table/{$table}/id/{$newId}?created=1&page={$page}");
            
        } catch (Exception $e) {
            // В случае ошибки показываем форму снова
            $relations = $this->getRelations($table);
            $this->render('table/form', [
                'tableName' => $table,
                'structure' => $structure,
                'title' => "Добавление записи в таблицу: {$table}",
                'action' => 'create',
                'item' => $_POST,
                'relations' => $relations,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Показать форму редактирования записи
     * 
     * @param string $table Название таблицы
     * @param int $id ID записи
     */
    public function editForm($table, $id) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем запись
        $item = $this->db->getById($table, $id);
        
        if (!$item) {
            $this->render('error/404', [
                'message' => "Запись с ID {$id} не найдена в таблице '{$table}'"
            ]);
            return;
        }
        
        // Получаем структуру таблицы
        $structure = $this->db->getTableStructure($table);
        
        // Load relations from page_config
        $relations = $this->getRelations($table);
        
        // Отображаем форму редактирования
        $this->render('table/form', [
            'tableName' => $table,
            'structure' => $structure,
            'title' => "Редактирование записи в таблице: {$table}",
            'action' => 'edit',
            'item' => $item,
            'itemId' => $id,
            'relations' => $relations,
            'currentPage' => $_GET['page'] ?? 1
        ]);
    }

    /**
     * Обновить запись
     * 
     * @param string $table Название таблицы
     * @param int $id ID записи
     */
    public function update($table, $id) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Проверяем существование записи
        $existingItem = $this->db->getById($table, $id);
        if (!$existingItem) {
            $this->render('error/404', [
                'message' => "Запись с ID {$id} не найдена в таблице '{$table}'"
            ]);
            return;
        }
        
        // Получаем структуру таблицы
        $structure = $this->db->getTableStructure($table);
        
        // Подготавливаем данные
        $data = [];
        foreach ($structure as $column) {
            $columnName = $column['name'];
            
            // Пропускаем первичный ключ
            if ($column['pk'] == 1) {
                continue;
            }
            
            // Получаем значение из POST
            if (isset($_POST[$columnName])) {
                $value = $_POST[$columnName];
                
                // Пустые строки для nullable-полей → null (иначе '' станет 0 для FK)
                if ($value === '' && $column['notnull'] == 0) {
                    $value = null;
                }
                
                $data[$columnName] = $value;
            }
        }
        
        try {
            // Обновляем данные
            $success = $this->db->update($table, $id, $data);
            
            if ($success) {
                // Перенаправляем на просмотр обновленной записи
                                $page = $_GET['page'] ?? 1;
                $this->redirect("/table/{$table}/id/{$id}?updated=1&page={$page}");
            } else {
                throw new Exception("Не удалось обновить запись");
            }
            
        } catch (Exception $e) {
            // В случае ошибки показываем форму снова
            $relations = $this->getRelations($table);
            $this->render('table/form', [
                'tableName' => $table,
                'structure' => $structure,
                'title' => "Редактирование записи в таблице: {$table}",
                'action' => 'edit',
                'item' => array_merge($existingItem, $_POST),
                'itemId' => $id,
                'relations' => $relations,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Удалить запись
     * 
     * @param string $table Название таблицы
     * @param int $id ID записи
     */
    public function delete($table, $id) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        try {
            // Для известных таблиц сначала удаляем связанные записи
            if ($table === 'poetry' && in_array('poetry_category', $tables)) {
                // Удаляем связи из poetry_category
                $this->db->query(
                    "DELETE FROM poetry_category WHERE poetry_id = ?", 
                    [$id]
                );
            } elseif ($table === 'category' && in_array('poetry_category', $tables)) {
                // Удаляем связи из poetry_category
                $this->db->query(
                    "DELETE FROM poetry_category WHERE category_id = ?", 
                    [$id]
                );
            }
            
            // Теперь удаляем основную запись
            $success = $this->db->delete($table, $id);
            
            if ($success) {
                // Перенаправляем на таблицу с сообщением об успехе (с сохранением страницы)
                $page = !empty($_GET['page']) ? (int)$_GET['page'] : 1;
                $redirectUrl = "/table/{$table}?deleted=1";
                if ($page > 1) {
                    $redirectUrl .= "&page={$page}";
                }
                $this->redirect($redirectUrl);
            } else {
                throw new Exception("Не удалось удалить запись");
            }
            
        } catch (Exception $e) {
            // В случае ошибки показываем страницу с ошибкой
            $errorMessage = "Ошибка при удалении: " . $e->getMessage();
            
            // Более понятное сообщение для ошибок внешнего ключа
            if (strpos($e->getMessage(), 'FOREIGN KEY constraint failed') !== false) {
                $errorMessage = "Не удалось удалить запись, потому что на нее есть ссылки в других таблицах. " .
                            "Сначала удалите связанные записи из таблиц связей.";
            }
            
            $this->render('error/404', [
                'message' => $errorMessage
            ]);
        }
    }

    /**
     * Показать структуру таблицы
     * 
     * @param string $table Название таблицы
     */
    public function structure($table) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        // Получаем структуру таблицы
        $structure = $this->db->getTableStructure($table);
        
        $this->render('table/structure', [
            'tableName' => $table,
            'structure' => $structure,
            'title' => "Структура таблицы: {$table}",
            'get' => $_GET
        ]);
    }

    /**
     * Показать форму добавления колонки
     * 
     * @param string $table Название таблицы
     */
    public function addColumnForm($table) {
        // Проверяем существование таблицы
        $tables = $this->db->getTables();
        
        if (!in_array($table, $tables)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена в базе данных"
            ]);
            return;
        }
        
        $this->render('table/add_column', [
            'tableName' => $table,
            'title' => "Добавление колонки в таблицу: {$table}",
            'formData' => []
        ]);
    }

    /**
     * Добавить колонку в таблицу
     * 
     * @param string $table Название таблицы
     */
    public function addColumn($table) {
        try {
            // Проверяем существование таблицы
            $tables = $this->db->getTables();
            
            if (!in_array($table, $tables)) {
                throw new Exception("Таблица '{$table}' не найдена");
            }
            
            $columnName = $_POST['column_name'] ?? '';
            $columnType = $_POST['column_type'] ?? '';
            $nullable = isset($_POST['nullable']);
            $defaultValue = $_POST['default_value'] ?? null;
            
            if (empty($columnName) || empty($columnType)) {
                throw new Exception("Название и тип колонки обязательны для заполнения");
            }
            
            // Проверяем валидность имени колонки
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnName)) {
                throw new Exception("Название колонки может содержать только буквы, цифры и подчеркивания, и должно начинаться с буквы или подчеркивания");
            }
            
            // Проверяем, что колонка не существует
            $structure = $this->db->getTableStructure($table);
            foreach ($structure as $column) {
                if ($column['name'] === $columnName) {
                    throw new Exception("Колонка с названием '{$columnName}' уже существует в таблице");
                }
            }
            
            // Добавляем колонку
            $this->db->addColumn($table, $columnName, $columnType, $nullable, $defaultValue);
            
            // Перенаправляем на страницу структуры
            $this->redirect("/table/{$table}/structure?column_added=1");
            
        } catch (Exception $e) {
            $this->render('table/add_column', [
                'tableName' => $table,
                'title' => "Добавление колонки в таблицу: {$table}",
                'error' => $e->getMessage(),
                'formData' => $_POST
            ]);
        }
    }

    /**
     * Удалить колонку из таблицы
     * 
     * @param string $table Название таблицы
     * @param string $column Название колонки
     */
    public function deleteColumn($table, $column) {
        try {
            // Проверяем существование таблицы
            $tables = $this->db->getTables();
            
            if (!in_array($table, $tables)) {
                $this->render('error/404', [
                    'message' => "Таблица '{$table}' не найдена в базе данных"
                ]);
                return;
            }
            
            // Проверяем, что колонка существует
            $structure = $this->db->getTableStructure($table);
            $columnExists = false;
            foreach ($structure as $col) {
                if ($col['name'] === $column) {
                    $columnExists = true;
                    break;
                }
            }
            
            if (!$columnExists) {
                $this->render('error/404', [
                    'message' => "Колонка '{$column}' не найдена в таблице '{$table}'"
                ]);
                return;
            }
            
            // Удаляем колонку
            $this->db->deleteColumn($table, $column);
            
            // Перенаправляем на страницу структуры
            $this->redirect("/table/{$table}/structure?column_deleted=1");
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => "Ошибка при удалении колонки: " . $e->getMessage()
            ]);
        }
    }


    /**
     * Дублировать запись
     */
    public function duplicate($table, $id) {
        $tables = $this->db->getTables();
        if (!in_array($table, $tables)) {
            $this->setFlash("error", "Таблица '{$table}' не найдена");
            $this->redirect('/');
            return;
        }

        $item = $this->db->getById($table, $id);
        if (!$item) {
            $this->setFlash("error", "Запись с ID {$id} не найдена");
            $this->redirect("/table/{$table}");
            return;
        }

        $structure = $this->db->getTableStructure($table);
        $data = [];
        foreach ($structure as $column) {
            $name = $column['name'];
            if ($column['pk'] == 1 && stripos($column['type'], 'INTEGER') !== false) {
                continue;
            }
            if (in_array($name, ['created_at', 'updated_at'])) {
                continue;
            }
            if (isset($item[$name])) {
                $data[$name] = $item[$name];
            }
        }

        try {
            $newId = $this->db->insert($table, $data);
            $this->setFlash("success", "Запись скопирована. Новый ID: {$newId}");
            $page = $_GET['page'] ?? 1;
            $this->redirect("/table/{$table}/id/{$newId}?page={$page}");
        } catch (Exception $e) {
            $this->setFlash("error", "Ошибка при копировании: " . $e->getMessage());
            $this->redirect("/table/{$table}/id/{$id}");
        }
    }

}
