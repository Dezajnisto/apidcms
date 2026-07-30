<?php
/**
 * Контроллер для управления Twig-шаблонами
 * 
 * Позволяет создавать, редактировать и удалять шаблоны через админку
 */

namespace Admin;

use Admin\Lang;
use Exception;

class TemplateController extends BaseController {
    
    /**
     * Путь к папке с шаблонами фронтенда
     */
    private $templatesPath;
    
    /**
     * Конструктор
     * 
     * @param mixed $app Экземпляр приложения
     */
    public function __construct($app) {
        parent::__construct($app);
        $this->templatesPath = FRONT_APP_PATH . '/views/';
    }
    
    /**
     * Список всех шаблонов
     */
    public function index() {
        try {
            $templates = $this->getTemplatesList();
            
            $this->render('template/index', [
                'title' => $this->lang->t('template.manage_title'),
                'templates' => $templates,
                'templatesPath' => $this->templatesPath
            ]);
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => $this->lang->t('template.load_error') . ' ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Редактирование шаблона
     * 
     * @param string $templateName Название шаблона
     */
    public function edit($templateName) {
        try {
            // Проверяем безопасность имени файла
            if (!$this->isValidTemplateName($templateName)) {
                throw new Exception($this->lang->t('template.invalid_name'));
            }
            
            $templatePath = $this->templatesPath . $templateName;
            
            // Проверяем существование файла
            if (!file_exists($templatePath)) {
                throw new Exception($this->lang->t('template.not_found', ['name' => $templateName]));
            }
            
            // Получаем содержимое файла
            $content = file_get_contents($templatePath);
            
            if ($content === false) {
                throw new Exception($this->lang->t('template.read_error'));
            }
            
            // Если форма отправлена - сохраняем изменения
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $newContent = $_POST['content'] ?? '';
                
                if ($this->saveTemplate($templatePath, $newContent)) {
                    $this->redirect("/admin/templates?saved=1");
                    return;
                } else {
                    throw new Exception($this->lang->t('template.save_error'));
                }
            }
            
            $this->render('template/edit', [
                'title' => $this->lang->t('template.edit_title', ['name' => $templateName]),
                'templateName' => $templateName,
                'content' => $content,
                'templatePath' => $templatePath
            ]);
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Создание нового шаблона
     */
    public function create() {
        try {
            // Если форма отправлена
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $templateName = $_POST['template_name'] ?? '';
                $content = $_POST['content'] ?? '';
                
                if (empty($templateName)) {
                    throw new Exception($this->lang->t('template.name_required'));
                }
                
                // Добавляем расширение .html.twig если его нет
                if (!preg_match('/\.html\.twig$/', $templateName)) {
                    $templateName .= '.html.twig';
                }
                
                // Проверяем безопасность имени файла
                if (!$this->isValidTemplateName($templateName)) {
                    throw new Exception($this->lang->t('template.invalid_name'));
                }
                
                $templatePath = $this->templatesPath . $templateName;
                
                // Проверяем, не существует ли уже такой файл
                if (file_exists($templatePath)) {
                    throw new Exception($this->lang->t('template.exists', ['name' => $templateName]));
                }
                
                // Сохраняем новый шаблон
                if ($this->saveTemplate($templatePath, $content)) {
                    $this->redirect("/admin/templates?created=1");
                    return;
                } else {
                    throw new Exception($this->lang->t('template.create_error'));
                }
            }
            
            $this->render('template/create', [
                'title' => $this->lang->t('template.create_title')
            ]);
            
        } catch (Exception $e) {
            $this->render('template/create', [
                'title' => $this->lang->t('template.create_title'),
                'error' => $e->getMessage(),
                'formData' => $_POST
            ]);
        }
    }
    
    /**
     * Удаление шаблона
     * 
     * @param string $templateName Название шаблона
     */
    public function delete($templateName) {
        try {
            // Проверяем безопасность имени файла
            if (!$this->isValidTemplateName($templateName)) {
                throw new Exception($this->lang->t('template.invalid_name'));
            }
            
            $templatePath = $this->templatesPath . $templateName;
            
            // Проверяем существование файла
            if (!file_exists($templatePath)) {
                throw new Exception($this->lang->t('template.not_found', ['name' => $templateName]));
            }
            
            // Не позволяем удалить базовый шаблон
            if ($templateName === 'base.html.twig') {
                throw new Exception($this->lang->t('template.cant_delete_base'));
            }
            
            // Удаляем файл
            if (unlink($templatePath)) {
                $this->redirect("/admin/templates?deleted=1");
            } else {
                throw new Exception($this->lang->t('template.delete_error'));
            }
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить список всех шаблонов
     * 
     * @return array Массив с информацией о шаблонах
     */
    private function getTemplatesList() {
        $templates = [];
        
        if (!is_dir($this->templatesPath)) {
            throw new Exception($this->lang->t('template.dir_not_found') . ' ' . $this->templatesPath);
        }
        
        $files = scandir($this->templatesPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            if (pathinfo($file, PATHINFO_EXTENSION) === 'twig') {
                $filePath = $this->templatesPath . $file;
                $templates[] = [
                    'name' => $file,
                    'path' => $filePath,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'is_base' => $file === 'base.html.twig'
                ];
            }
        }
        
        // Сортируем по имени
        usort($templates, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $templates;
    }
    
    /**
     * Проверяет безопасность имени шаблона
     * 
     * @param string $templateName Имя шаблона
     * @return bool true если имя безопасное
     */
    private function isValidTemplateName($templateName) {
        // Запрещаем переход по директориям и специальные символы
        if (strpos($templateName, '..') !== false || 
            strpos($templateName, '/') !== false || 
            strpos($templateName, '\\') !== false) {
            return false;
        }
        
        // Проверяем расширение
        if (!preg_match('/\.html\.twig$/', $templateName)) {
            return false;
        }
        
        // Проверяем допустимые символы в имени
        $baseName = str_replace('.html.twig', '', $templateName);
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $baseName)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Сохраняет содержимое шаблона в файл
     * 
     * @param string $filePath Путь к файлу
     * @param string $content Содержимое
     * @return bool true если успешно
     */
    private function saveTemplate($filePath, $content) {
        // Создаем резервную копию
        if (file_exists($filePath)) {
            $backupPath = $filePath . '.backup.' . date('Y-m-d-His');
            copy($filePath, $backupPath);
        }
        
        // Сохраняем новый контент
        $result = file_put_contents($filePath, $content);
        
        return $result !== false;
    }
}
?>