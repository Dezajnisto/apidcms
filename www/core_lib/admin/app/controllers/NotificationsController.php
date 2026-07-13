<?php
/**
 * NotificationsController — уведомления о заявках форм (v2: form_name фильтрация)
 */

namespace Admin;

class NotificationsController extends BaseController {

    /**
     * Главная страница уведомлений
     */
    public function index() {
        $forms = $this->getFormTables();

        $stats = [];
        $totalUnread = 0;

        foreach ($forms as $form) {
            $formName = $form['name'];
            $formStats = $this->getFormStats($formName, $form['source_table']);
            $stats[$formName] = $formStats;
            $totalUnread += $formStats['unread_count'];
        }

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
     * Просмотр заявок конкретной формы (по имени формы)
     */
    public function viewForm($formName) {
        $formInfo = $this->getFormInfoByName($formName);
        $table = $formInfo['source_table'] ?? $formName;

        if (!$this->db->tableExists($table)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена"
            ]);
            return;
        }

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $hasFormName = $this->hasColumn($table, 'form_name');
        $whereForm = $hasFormName ? " AND form_name = ?" : "";
        $formParams = $hasFormName ? [$formName, $perPage, $offset] : [$perPage, $offset];
        $countParams = $hasFormName ? [$formName] : [];

        $submissions = $this->db->query(
            "SELECT * FROM {$table} WHERE 1=1{$whereForm} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            $formParams
        )->fetchAll();

        $totalCount = $this->db->query(
            "SELECT COUNT(*) as count FROM {$table} WHERE 1=1{$whereForm}",
            $countParams
        )->fetch()['count'];
        $totalPages = ceil($totalCount / $perPage);

        $formInfo['form_name'] = $formName;

        $this->render('notifications/view_form', [
            'title' => "Заявки: " . ($formInfo['title'] ?? $formName),
            'table_name' => $table,
            'form_name' => $formName,
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
    public function viewSubmission($formName, $id) {
        $formInfo = $this->getFormInfoByName($formName);
        $table = $formInfo['source_table'] ?? $formName;

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

        $this->markAsRead($table, $id);

        $this->render('notifications/view_submission', [
            'title' => "Заявка #{$id}",
            'table_name' => $table,
            'form_name' => $formName,
            'form_info' => $formInfo,
            'submission' => $submission,
            'structure' => $this->db->getTableStructure($table)
        ]);
    }

    /**
     * Удаление заявки
     */
    public function deleteSubmission($formName, $id) {
        $formInfo = $this->getFormInfoByName($formName);
        $table = $formInfo['source_table'] ?? $formName;

        if (!$this->db->tableExists($table)) {
            $this->render('error/404', [
                'message' => "Таблица '{$table}' не найдена"
            ]);
            return;
        }

        $success = $this->db->delete($table, $id);

        if ($success) {
            $this->redirect("/notifications/form/{$formName}?deleted=1");
        } else {
            $this->render('error/404', [
                'message' => "Не удалось удалить заявку #{$id}"
            ]);
        }
    }

    /**
     * Отметить все заявки как прочитанные для формы
     */
    public function markAllRead($formName) {
        $formInfo = $this->getFormInfoByName($formName);
        $table = $formInfo['source_table'] ?? $formName;

        if ($this->db->tableExists($table)) {
            $structure = $this->db->getTableStructure($table);
            $hasReadStatus = false;
            $hasFormName = false;

            foreach ($structure as $column) {
                if ($column['name'] === 'read_status') {
                    $hasReadStatus = true;
                }
                if ($column['name'] === 'form_name') {
                    $hasFormName = true;
                }
            }

            if ($hasReadStatus) {
                if ($hasFormName) {
                    $this->db->query(
                        "UPDATE {$table} SET read_status = 'read' WHERE (read_status = 'unread' OR read_status IS NULL) AND form_name = ?",
                        [$formName]
                    );
                } else {
                    $this->db->query(
                        "UPDATE {$table} SET read_status = 'read' WHERE read_status = 'unread' OR read_status IS NULL"
                    );
                }
            }
        }

        $this->redirect("/notifications/form/{$formName}?marked_read=1");
    }

    // ================================================================
    // Private helpers
    // ================================================================

    /**
     * Получить список форм из таблицы forms
     */
    private function getFormTables() {
        $forms = $this->db->query(
            "SELECT name, display_name AS title, source_table
             FROM forms
             WHERE status = 'active'"
        )->fetchAll();

        return $forms;
    }

    /**
     * Получить статистику по форме (с фильтром по form_name)
     */
    private function getFormStats($formName, $table) {
        if (!$this->db->tableExists($table)) {
            return [
                'total_count' => 0,
                'unread_count' => 0,
                'today_count' => 0
            ];
        }

        $hasFormName = $this->hasColumn($table, 'form_name');
        $whereForm = $hasFormName ? " AND form_name = ?" : "";
        $formParam = $hasFormName ? [$formName] : [];

        $totalCount = $this->db->query(
            "SELECT COUNT(*) as count FROM {$table} WHERE 1=1{$whereForm}",
            $formParam
        )->fetch()['count'];

        $hasReadStatus = $this->hasColumn($table, 'read_status');

        if ($hasReadStatus) {
            $unreadCount = $this->db->query(
                "SELECT COUNT(*) as count FROM {$table} WHERE (read_status = 'unread' OR read_status IS NULL){$whereForm}",
                $formParam
            )->fetch()['count'];
        } else {
            $unreadCount = $totalCount;
        }

        $todayCount = $this->db->query(
            "SELECT COUNT(*) as count FROM {$table} WHERE DATE(created_at) = DATE('now'){$whereForm}",
            $formParam
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
            $formName = $form['name'];

            if ($this->db->tableExists($table)) {
                $hasFormName = $this->hasColumn($table, 'form_name');
                $whereForm = $hasFormName ? " AND form_name = ?" : "";
                $params = $hasFormName ? [$formName, $limit] : [$limit];

                $submissions = $this->db->query(
                    "SELECT *, '{$table}' as source_table
                     FROM {$table}
                     WHERE 1=1{$whereForm}
                     ORDER BY created_at DESC
                     LIMIT ?",
                    $params
                )->fetchAll();

                foreach ($submissions as $submission) {
                    $submission['form_title'] = $form['title'];
                    $submission['form_name'] = $form['name'];
                    $allSubmissions[] = $submission;
                }
            }
        }

        usort($allSubmissions, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return array_slice($allSubmissions, 0, $limit);
    }

    /**
     * Получить информацию о форме по имени (из таблицы forms)
     */
    private function getFormInfoByName($formName) {
        return $this->db->query(
            "SELECT display_name AS title, source_table, fields AS form_config
             FROM forms
             WHERE name = ? AND status = 'active'
             LIMIT 1",
            [$formName]
        )->fetch();
    }

    /**
     * Устаревший метод — оставлен для обратной совместимости
     * @deprecated использовать getFormInfoByName
     */
    private function getFormInfoByTable($table) {
        return $this->db->query(
            "SELECT display_name AS title, source_table AS table, fields AS form_config
             FROM forms
             WHERE source_table = ? AND status = 'active'
             LIMIT 1",
            [$table]
        )->fetch();
    }

    /**
     * Проверить существование колонки в таблице
     */
    private function hasColumn($table, $columnName) {
        $structure = $this->db->getTableStructure($table);
        foreach ($structure as $column) {
            if ($column['name'] === $columnName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Пометить заявку как прочитанную
     */
    private function markAsRead($table, $id) {
        $hasReadStatus = $this->hasColumn($table, 'read_status');

        if (!$hasReadStatus) {
            $this->db->addColumn($table, 'read_status', 'TEXT', true, 'unread');
        }

        $this->db->query(
            "UPDATE {$table} SET read_status = 'read' WHERE id = ?",
            [$id]
        );
    }
}
