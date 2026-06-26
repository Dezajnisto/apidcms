<?php
/**
 * Контроллер для управления уведомлениями о заявках форм
 */

namespace Admin;

class NotificationsController extends BaseController {
    
    /**
     * Главная страница уведомлений
     */
    public function index() {
        // Получаем все таблицы, которые используются в формах
        $forms = $this->getFormTables();
        
        // Собираем статистику по всем формам
        $stats = [];
        $totalUnread = 0;
        
        foreach ($forms as $form) {
            $table = $form['source_table'];
            $formStats = $this->getFormStats($table);
            $stats[$table] = $formStats;
            $totalUnread += $formStats['unread_count'];
        }
        
        // Получаем последние заявки из всех форм
        $recentSubmissions = $this->getRecentSubmissions($forms, 10);
        
        $this->render('notifications/index', [
            'title' => 'Уведомления о заявках',
            'forms' => $forms,
            'stats' => $stats,
            'total_unread' => $totalUnread,
            'recent_submissions' => $recentSubmissions,
            'current_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Просмотр заявок конкретной формы
     */
    public function viewForm($table) {
        // Проверяем существование таблицы
        if (!$this->db->tableExists($table)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена"
            ]);
            return;
        }
        
        // Получаем настройки пагинации
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        // Получаем заявки
        $submissions = $this->db->query(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$perPage, $offset]
        )->fetchAll();
        
        // Получаем общее количество
        $totalCount = $this->db->query("SELECT COUNT(*) as count FROM {$table}")->fetch()['count'];
        $totalPages = ceil($totalCount / $perPage);
        
        // Получаем информацию о форме
        $formInfo = $this->getFormInfoByTable($table);
        
        $this->render('notifications/view_form', [
            'title' => "Заявки: " . ($formInfo['title'] ?? $table),
            'table_name' => $table,
            'form_info' => $formInfo,
            'submissions' => $submissions,
            'structure' => $this->db->getTableStructure($table),
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_count' => $totalCount
        ]);
    }
    
    /**
     * Просмотр конкретной заявки
     */
    public function viewSubmission($table, $id) {
        if (!$this->db->tableExists($table)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена"
            ]);
            return;
        }
        
        $submission = $this->db->getById($table, $id);
        
        if (!$submission) {
            $this->render('error/404', [
                'message' => "Заявка #{$id} не найдена"
            ]);
            return;
        }
        
        // Помечаем как прочитанную (добавляем поле если его нет)
        $this->markAsRead($table, $id);
        
        $formInfo = $this->getFormInfoByTable($table);
        
        $this->render('notifications/view_submission', [
            'title' => "Заявка #{$id}",
            'table_name' => $table,
            'form_info' => $formInfo,
            'submission' => $submission,
            'structure' => $this->db->getTableStructure($table)
        ]);
    }
    
    /**
     * Удаление заявки
     */
    public function deleteSubmission($table, $id) {
        if (!$this->db->tableExists($table)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена"
            ]);
            return;
        }
        
        $success = $this->db->delete($table, $id);
        
        if ($success) {
            $this->redirect("/notifications/form/{$table}?deleted=1");
        } else {
            $this->render('error/404', [
                'message' => "Не удалось удалить заявку #{$id}"
            ]);
        }
    }
    
    /**
     * Отметить все заявки как прочитанные для формы
     */
    public function markAllRead($table) {
        if ($this->db->tableExists($table)) {
            // Если в таблице есть поле read_status, обновляем его
            $structure = $this->db->getTableStructure($table);
            $hasReadStatus = false;
            
            foreach ($structure as $column) {
                if ($column['name'] === 'read_status') {
                    $hasReadStatus = true;
                    break;
                }
            }
            
            if ($hasReadStatus) {
                $this->db->query(
                    "UPDATE {$table} SET read_status = 'read' WHERE read_status = 'unread' OR read_status IS NULL"
                );
            }
        }
        
        $this->redirect("/notifications/form/{$table}?marked_read=1");
    }
    
    /**
     * Получить список таблиц, используемых в формах
     */
    private function getFormTables() {
        $forms = $this->db->query(
            "SELECT name, display_name AS title, source_table, '' AS url
             FROM forms 
             WHERE status = 'active'"
        )->fetchAll();
        
        return $forms;
    }
    
    /**
     * Получить статистику по форме
     */
    private function getFormStats($table) {
        if (!$this->db->tableExists($table)) {
            return [
                'total_count' => 0,
                'unread_count' => 0,
                'today_count' => 0
            ];
        }
        
        $totalCount = $this->db->query("SELECT COUNT(*) as count FROM {$table}")->fetch()['count'];
        
        // Проверяем наличие поля read_status
        $structure = $this->db->getTableStructure($table);
        $hasReadStatus = false;
        
        foreach ($structure as $column) {
            if ($column['name'] === 'read_status') {
                $hasReadStatus = true;
                break;
            }
        }
        
        if ($hasReadStatus) {
            $unreadCount = $this->db->query(
                "SELECT COUNT(*) as count FROM {$table} WHERE read_status = 'unread' OR read_status IS NULL"
            )->fetch()['count'];
        } else {
            $unreadCount = $totalCount; // Если нет поля статуса, считаем все непрочитанными
        }
        
        // Заявки за сегодня
        $todayCount = $this->db->query(
            "SELECT COUNT(*) as count FROM {$table} WHERE DATE(created_at) = DATE('now')"
        )->fetch()['count'];
        
        return [
            'total_count' => $totalCount,
            'unread_count' => $unreadCount,
            'today_count' => $todayCount
        ];
    }
    
    /**
     * Получить последние заявки из всех форм
     */
    private function getRecentSubmissions($forms, $limit = 10) {
        $allSubmissions = [];
        
        foreach ($forms as $form) {
            $table = $form['source_table'];
            
            if ($this->db->tableExists($table)) {
                $submissions = $this->db->query(
                    "SELECT *, '{$table}' as source_table 
                     FROM {$table} 
                     ORDER BY created_at DESC 
                     LIMIT ?",
                    [$limit]
                )->fetchAll();
                
                foreach ($submissions as $submission) {
                    $submission['form_title'] = $form['title'];
                    $allSubmissions[] = $submission;
                }
            }
        }
        
        // Сортируем по дате создания (новые сверху)
        usort($allSubmissions, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        // Ограничиваем общее количество
        return array_slice($allSubmissions, 0, $limit);
    }
    
    /**
     * Получить информацию о форме по названию таблицы
     */
    private function getFormInfoByTable($table) {
        return $this->db->query(
            "SELECT display_name AS title, fields AS form_config 
             FROM forms 
             WHERE source_table = ? AND status = 'active'
             LIMIT 1",
            [$table]
        )->fetch();
    }
    
    /**
     * Пометить заявку как прочитанную
     */
    private function markAsRead($table, $id) {
        // Проверяем наличие поля read_status
        $structure = $this->db->getTableStructure($table);
        $hasReadStatus = false;
        
        foreach ($structure as $column) {
            if ($column['name'] === 'read_status') {
                $hasReadStatus = true;
                break;
            }
        }
        
        // Если поля нет - добавляем его
        if (!$hasReadStatus) {
            $this->db->addColumn($table, 'read_status', 'TEXT', true, 'unread');
        }
        
        // Обновляем статус
        $this->db->query(
            "UPDATE {$table} SET read_status = 'read' WHERE id = ?",
            [$id]
        );
    }
}
?>