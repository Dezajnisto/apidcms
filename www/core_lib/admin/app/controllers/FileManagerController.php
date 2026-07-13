<?php
/**
 * Контроллер для управления файлами
 * v2 — добавлена пагинация (page, per_page)
 */

namespace Admin;

use Exception;

class FileManagerController extends BaseController {
    
    private $uploadsPath;
    private $allowedTypes = [
        'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'],
        'documents' => ['pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx', 'zip']
    ];
    private $maxFileSize = 5 * 1024 * 1024;
    
    public function __construct($app) {
        parent::__construct($app);
        $this->uploadsPath = ROOT_PATH . '/storage/uploads/';
        $this->createUploadsStructure();
    }
    
    private function createUploadsStructure() {
        $folders = [
            'images', 'images/thumbnails', 'images/original',
            'documents', 'temp'
        ];
        foreach ($folders as $folder) {
            $path = $this->uploadsPath . $folder;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
    
    /**
     * Главная страница файлового менеджера
     * Поддерживает page + per_page для пагинации
     */
    public function index() {
        try {
            $currentPath = $_GET['path'] ?? '';
            $fullPath = $this->getFullPath($currentPath);
            
            // Параметры пагинации
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = intval($_GET['per_page'] ?? 20);
            $cols = intval($_GET['cols'] ?? 6);
            // Ограничиваем допустимые значения per_page
            if (!in_array($perPage, [10, 20, 50])) {
                $perPage = 20;
            }
            // Ограничиваем допустимое кол-во колонок
            if (!in_array($cols, [2, 3, 4, 6])) {
                $cols = 6;
            }
            
            // Получаем элементы с пагинацией
            $result = $this->getDirectoryItems($fullPath, $currentPath, $page, $perPage);
            
            $this->render('filemanager/index', [
                'title' => 'Файловый менеджер',
                'currentPath' => $currentPath,
                'items' => $result['items'],
                'pagination' => $result['pagination'],
                'breadcrumbs' => $this->getBreadcrumbs($currentPath),
                'storageUrl' => '/storage/uploads/',
                'cols' => $cols
            ]);
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => 'Ошибка при загрузке файлового менеджера: ' . $e->getMessage()
            ]);
        }
    }
    
    private function getFullPath($relativePath) {
        $relativePath = str_replace(['..', '\\', '%'], '', $relativePath);
        $relativePath = ltrim($relativePath, '/');
        $relativePath = rtrim($relativePath, '/');
        
        if (empty($relativePath)) {
            return $this->uploadsPath;
        }
        
        $fullPath = $this->uploadsPath . $relativePath;
        $realUploadsPath = realpath($this->uploadsPath);
        $realFullPath = realpath($fullPath);
        
        if ($realFullPath === false) {
            if (!is_dir($fullPath)) {
                if (!mkdir($fullPath, 0755, true)) {
                    throw new Exception('Не удалось создать директорию: ' . $fullPath);
                }
            }
            $realFullPath = realpath($fullPath);
            if ($realFullPath === false) {
                throw new Exception('Недопустимый путь: ' . $fullPath);
            }
        }
        
        if (strpos($realFullPath, $realUploadsPath) !== 0) {
            throw new Exception('Доступ за пределы разрешенной директории запрещен');
        }
        
        return $realFullPath;
    }
    
    /**
     * Получить элементы директории с пагинацией
     * @return array ['items' => [...], 'pagination' => [...]]
     */
    private function getDirectoryItems($fullPath, $currentPath, $page = 1, $perPage = 50) {
        $items = [];
        
        if (!is_dir($fullPath)) {
            return [
                'items' => [],
                'pagination' => ['total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1]
            ];
        }
        
        $files = scandir($fullPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $fullPath . '/' . $file;
            $relativePath = ltrim($currentPath . '/' . $file, '/');
            $modTime = filemtime($filePath);
            
            $items[] = [
                'name' => $file,
                'path' => $relativePath,
                'is_dir' => is_dir($filePath),
                'size' => $this->formatFilesize(filesize($filePath)),
                'size_raw' => filesize($filePath),
                'modified' => $modTime,
                'modified_formatted' => date('d.m.Y H:i', $modTime),
                'extension' => pathinfo($file, PATHINFO_EXTENSION),
                'is_image' => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $this->allowedTypes['images'])
            ];
        }
        
        // Сортируем: сначала папки, потом файлы
        usort($items, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            return strcmp($a['name'], $b['name']);
        });
        
        // Пагинация
        $total = count($items);
        $totalPages = max(1, ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $slicedItems = array_slice($items, $offset, $perPage);
        
        return [
            'items' => $slicedItems,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total)
            ]
        ];
    }
    
    private function formatFilesize($bytes) {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    private function getBreadcrumbs($path) {
        $parts = explode('/', $path);
        $breadcrumbs = [];
        $currentPath = '';
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            $currentPath .= $part . '/';
            $breadcrumbs[] = [
                'name' => $part,
                'path' => rtrim($currentPath, '/')
            ];
        }
        
        return $breadcrumbs;
    }
    
    // === Методы upload, delete, createFolder, getFileInfo, rename, popup и пр. без изменений ===
    
    public function upload() {
        $startBufferLevel = ob_get_level();
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');
            $currentPath = $_POST['path'] ?? 'images';
            $fullPath = $this->getFullPath($currentPath);
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Ошибка загрузки файла');
            $uploadedFile = $_FILES['file'];
            if ($uploadedFile['size'] > $this->maxFileSize) throw new Exception('Файл слишком большой. Максимальный размер: 5MB');
            $extension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!$this->isAllowedExtension($extension)) throw new Exception('Тип файла не разрешен');
            $filename = $this->generateSafeFilename($uploadedFile['name']);
            $targetPath = $fullPath . '/' . $filename;
            if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) throw new Exception('Не удалось сохранить файл');
            chmod($targetPath, 0644);
            $this->jsonResponse(['success' => true, 'message' => 'Файл успешно загружен', 'filename' => $filename]);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function delete() {
        $startBufferLevel = ob_get_level();
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');
            $path = $_POST['path'] ?? '';
            $type = $_POST['type'] ?? 'file';
            if (empty($path)) throw new Exception('Не указан путь');
            $fullPath = $this->getFullPath($path);
            if ($type === 'folder') { $this->deleteFolder($fullPath); }
            else { $this->deleteFile($fullPath); }
            $this->jsonResponse(['success' => true, 'message' => ($type === 'folder' ? 'Папка' : 'Файл') . ' успешно удален']);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function createFolder() {
        $startBufferLevel = ob_get_level();
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');
            $currentPath = $_POST['path'] ?? 'images';
            $folderName = $_POST['name'] ?? '';
            if (empty($folderName)) throw new Exception('Не указано название папки');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folderName)) throw new Exception('Название папки может содержать только буквы, цифры, дефисы и подчеркивания');
            $fullPath = $this->getFullPath($currentPath);
            $newFolderPath = $fullPath . '/' . $folderName;
            if (file_exists($newFolderPath)) throw new Exception('Папка с таким названием уже существует');
            if (!mkdir($newFolderPath, 0755, true)) throw new Exception('Не удалось создать папку');
            $this->jsonResponse(['success' => true, 'message' => 'Папка успешно создана']);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function getFileInfo() {
        $startBufferLevel = ob_get_level();
        try {
            $path = $_GET['path'] ?? '';
            if (empty($path)) throw new Exception('Не указан путь');
            $fullPath = $this->getFullPath($path);
            if (!file_exists($fullPath)) throw new Exception('Файл не существует');
            $info = [
                'name' => basename($fullPath), 'path' => $path,
                'size' => $this->formatFilesize(filesize($fullPath)),
                'modified' => date('d.m.Y H:i', filemtime($fullPath)),
                'is_image' => false, 'dimensions' => null,
                'url' => '/storage/uploads/' . $path
            ];
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if (in_array($extension, $this->allowedTypes['images'])) {
                $imageInfo = getimagesize($fullPath);
                if ($imageInfo) {
                    $info['is_image'] = true;
                    $info['dimensions'] = $imageInfo[0] . ' × ' . $imageInfo[1];
                    $info['mime'] = $imageInfo['mime'];
                }
            }
            $this->jsonResponse(['success' => true, 'data' => $info]);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function deleteFile($filePath) {
        if (!file_exists($filePath)) throw new Exception('Файл не существует: ' . basename($filePath));
        if (!is_file($filePath)) throw new Exception('Указанный путь не является файлом: ' . $filePath);
        if (!is_writable($filePath)) throw new Exception('Нет прав на удаление файла: ' . basename($filePath));
        if (!unlink($filePath)) throw new Exception('Не удалось удалить файл: ' . basename($filePath));
    }
    
    private function deleteFolder($folderPath) {
        if (!file_exists($folderPath)) throw new Exception('Папка не существует: ' . basename($folderPath));
        if (!is_dir($folderPath)) throw new Exception('Указанный путь не является папкой: ' . $folderPath);
        if (!is_writable($folderPath)) throw new Exception('Нет прав на удаление папки: ' . basename($folderPath));
        $files = array_diff(scandir($folderPath), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $folderPath . '/' . $file;
            if (is_dir($filePath)) { $this->deleteFolder($filePath); }
            else { if (!unlink($filePath)) throw new Exception('Не удалось удалить файл: ' . $file); }
        }
        if (!rmdir($folderPath)) throw new Exception('Не удалось удалить папку: ' . basename($folderPath));
    }
    
    private function isAllowedExtension($extension) {
        foreach ($this->allowedTypes as $type => $extensions) {
            if (in_array($extension, $extensions)) return true;
        }
        return false;
    }
    
    public function rename() {
        $startBufferLevel = ob_get_level();
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');
            $path = $_POST['path'] ?? '';
            $newName = $_POST['new_name'] ?? '';
            $type = $_POST['type'] ?? 'file';
            if (empty($path) || empty($newName)) throw new Exception('Не указаны путь или новое название');
            if ($type === 'file') {
                $oldExtension = pathinfo($path, PATHINFO_EXTENSION);
                $newExtension = pathinfo($newName, PATHINFO_EXTENSION);
                if (empty($newExtension) && !empty($oldExtension)) $newName = $newName . '.' . $oldExtension;
            }
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $newName)) throw new Exception('Название может содержать только латинские буквы, цифры, точки, дефисы и подчеркивания');
            $fullPath = $this->getFullPath($path);
            $directory = dirname($fullPath);
            $newFullPath = $directory . '/' . $newName;
            if (!file_exists($fullPath)) throw new Exception(($type === 'folder' ? 'Папка' : 'Файл') . ' не существует');
            if (file_exists($newFullPath)) throw new Exception('Файл или папка с таким названием уже существует');
            if (!rename($fullPath, $newFullPath)) throw new Exception('Не удалось переименовать ' . ($type === 'folder' ? 'папку' : 'файл'));
            $this->jsonResponse(['success' => true, 'message' => ($type === 'folder' ? 'Папка' : 'Файл') . ' успешно переименован']);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Превью изображения на лету (через Imagick, без сохранения)
     * GET /admin/filemanager/thumb?path=...&w=100&h=100&q=70
     */
    public function thumbnail() {
        try {
            $path = ltrim($_GET['path'] ?? '', '/');
            $w = min(400, max(32, intval($_GET['w'] ?? 100)));
            $h = min(400, max(32, intval($_GET['h'] ?? 100)));
            $quality = min(85, max(50, intval($_GET['q'] ?? 70)));

            if (empty($path)) throw new \Exception('Не указан путь');

            $fullPath = $this->uploadsPath . $path;
            $realPath = realpath($fullPath);
            $realUploads = realpath($this->uploadsPath);

            if ($realPath === false || !is_file($realPath)) throw new \Exception('Файл не найден');
            if (strpos($realPath, $realUploads) !== 0) throw new \Exception('Доступ запрещён');

            $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) throw new \Exception('Неподдерживаемый формат');

            $fileMtime = filemtime($realPath);
            $etag = md5($realPath . $w . $h . $quality . $fileMtime);

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
                while (ob_get_level() > 0) ob_end_clean();
                header('HTTP/1.1 304 Not Modified');
                exit;
            }

            $imagick = new \Imagick($realPath);
            $imagick->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1, true);
            $imagick->setImageCompressionQuality($quality);

            $format = strtoupper($ext);
            if ($format === 'JPG') $format = 'JPEG';
            if ($format === 'AVIF') $format = 'AVIF';
            $imagick->setImageFormat($format);

            $imageData = $imagick->getImageBlob();
            $mimeType = $imagick->getImageMimeType();
            $imagick->clear();

            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: ' . $mimeType);
            header('Cache-Control: public, max-age=86400');
            header('ETag: "' . $etag . '"');
            header('Content-Length: ' . strlen($imageData));
            echo $imageData;
            exit;

        } catch (\Throwable $e) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: image/png');
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            exit;
        }
    }

    private function transliterate($text) {
        $cyrillic = [
            'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
            'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
            'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
            'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
        ];
        $latin = [
            'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
            'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
            'A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O','P',
            'R','S','T','U','F','Kh','Ts','Ch','Sh','Shch','','Y','','E','Yu','Ya'
        ];
        $text = str_replace($cyrillic, $latin, $text);
        $text = preg_replace('/[^\w\.\-]/', '_', $text);
        $text = preg_replace('/_{2,}/', '_', $text);
        $text = trim($text, '_');
        if (strlen($text) > 100) $text = substr($text, 0, 100);
        return $text;
    }

    private function generateSafeFilename($filename) {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = $this->transliterate($name);
        if (empty($name)) $name = 'file_' . time();
        return $name . '.' . $extension;
    }
    
    private function jsonResponse($data) {
        while (ob_get_level() > 0) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            http_response_code(200);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            echo json_encode(['success' => false, 'message' => 'Ошибка кодирования JSON: ' . json_last_error_msg()]);
        } else {
            echo $json;
        }
        exit;
    }

    public function popup() {
        try {
            $currentPath = $_GET['path'] ?? '';
            $callback = $_GET['callback'] ?? 'selectFile';
            $fullPath = $this->getFullPath($currentPath);

            // Пагинация (как в основном файл-менеджере)
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = intval($_GET['per_page'] ?? 20);
            $cols = intval($_GET['cols'] ?? 4);
            if (!in_array($perPage, [10, 20, 50])) $perPage = 20;
            if (!in_array($cols, [2, 3, 4, 6])) $cols = 4;

            $result = $this->getDirectoryItems($fullPath, $currentPath, $page, $perPage);

            $this->render('filemanager/popup', [
                'title' => 'Выбор файла',
                'currentPath' => $currentPath,
                'items' => $result['items'],
                'pagination' => $result['pagination'],
                'cols' => $cols,
                'callback' => $callback,
                'storageUrl' => '/storage/uploads/',
                'breadcrumbs' => $this->getBreadcrumbs($currentPath),
                'is_popup' => true
            ]);
        } catch (Exception $e) {
            echo "<html><body>";
            echo "<h1>Ошибка</h1>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<button onclick='window.close()'>Закрыть</button>";
            echo "</body></html>";
        }
    }

    public function uploadPopup() {
        $startBufferLevel = ob_get_level();
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Метод не поддерживается');
            $currentPath = $_POST['path'] ?? '';
            $fullPath = $this->getFullPath($currentPath);
            if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) throw new Exception('Файлы не выбраны');
            $uploadedFiles = $_FILES['files'];
            $results = [];
            for ($i = 0; $i < count($uploadedFiles['name']); $i++) {
                if ($uploadedFiles['error'][$i] !== UPLOAD_ERR_OK) {
                    $results[] = ['name' => $uploadedFiles['name'][$i], 'success' => false, 'message' => 'Ошибка загрузки файла'];
                    continue;
                }
                if ($uploadedFiles['size'][$i] > $this->maxFileSize) {
                    $results[] = ['name' => $uploadedFiles['name'][$i], 'success' => false, 'message' => 'Файл слишком большой. Максимальный размер: 5MB'];
                    continue;
                }
                $extension = strtolower(pathinfo($uploadedFiles['name'][$i], PATHINFO_EXTENSION));
                if (!$this->isAllowedExtension($extension)) {
                    $results[] = ['name' => $uploadedFiles['name'][$i], 'success' => false, 'message' => 'Тип файла не разрешен'];
                    continue;
                }
                $filename = $this->generateSafeFilename($uploadedFiles['name'][$i]);
                $targetPath = $fullPath . '/' . $filename;
                if (!move_uploaded_file($uploadedFiles['tmp_name'][$i], $targetPath)) {
                    $results[] = ['name' => $uploadedFiles['name'][$i], 'success' => false, 'message' => 'Не удалось сохранить файл'];
                    continue;
                }
                chmod($targetPath, 0644);
                $results[] = ['name' => $uploadedFiles['name'][$i], 'saved_as' => $filename, 'success' => true, 'message' => 'Файл успешно загружен'];
            }
            $hasSuccess = false;
            foreach ($results as $result) { if ($result['success']) { $hasSuccess = true; break; } }
            $this->jsonResponse(['success' => $hasSuccess, 'message' => $hasSuccess ? 'Файлы загружены' : 'Не удалось загрузить файлы', 'results' => $results]);
        } catch (\Throwable $e) {
            while (ob_get_level() > $startBufferLevel) ob_end_clean();
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }    

}
