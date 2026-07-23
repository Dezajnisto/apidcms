<?php
/**
 * Контроллер для управления кэшем системы
 * 
 * Очищает кэш Twig и другие временные файлы
 */

namespace Admin;

use Exception;

class CacheController extends BaseController {
    
    /**
     * Пути к папкам с кэшем
     */
    private $cachePaths = [
        'twig_admin' => ROOT_PATH . '/storage/cache/twig_admin/',
        'twig_front' => ROOT_PATH . '/storage/cache/twig/',
        'external' => ROOT_PATH . '/admin/views/cache/'
    ];
    
    /**
     * Главная страница управления кэшем
     */
    public function index() {
        try {
            $cacheInfo = $this->getCacheInfo();
            
            $this->render('cache/index', [
                'title' => 'Управление кэшем',
                'cacheInfo' => $cacheInfo
            ]);
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => 'Ошибка при загрузке информации о кэше: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Очистка кэша
     */
    public function clear() {
        try {
            $type = $_GET['type'] ?? 'all';
            $cleared = [];
            
            switch ($type) {
                case 'twig_admin':
                    $cleared[] = $this->clearTwigCache($this->cachePaths['twig_admin']);
                    break;
                    
                case 'twig_front':
                    $cleared[] = $this->clearTwigCache($this->cachePaths['twig_front']);
                    break;
                    
                case 'external':
                    $count = \Core\ExternalPageLoader::clearAllCache();
                    $cleared[] = $count >= 0;
                    break;

                case 'all':
                default:
                    $cleared[] = $this->clearTwigCache($this->cachePaths['twig_admin']);
                    $cleared[] = $this->clearTwigCache($this->cachePaths['twig_front']);
                    $cleared[] = \Core\ExternalPageLoader::clearAllCache() >= 0;
                    break;
            }
            
            // Проверяем успешность очистки
            $success = !in_array(false, $cleared, true);
            
            if ($success) {
                $this->redirect("/admin/cache?cleared=1&type=" . urlencode($type));
            } else {
                throw new Exception("Не удалось полностью очистить кэш");
            }
            
        } catch (Exception $e) {
            $this->render('error/404', [
                'message' => 'Ошибка при очистке кэша: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получить информацию о кэше
     */
    private function getCacheInfo() {
        $info = [];
        
        foreach ($this->cachePaths as $name => $path) {
            $info[$name] = [
                'path' => $path,
                'exists' => is_dir($path),
                'file_count' => 0,
                'total_size' => 0,
                'readable' => is_readable($path),
                'writable' => is_writable($path)
            ];
            
            // External cache: only count external_*.json files
            if ($name === 'external') {
                if ($info[$name]['exists']) {
                    $externalFiles = glob($path . '/external_*.json');
                    $info[$name]['file_count'] = count($externalFiles);
                    $size = 0;
                    foreach ($externalFiles as $f) {
                        $size += filesize($f);
                    }
                    $info[$name]['total_size'] = $this->formatBytes($size);
                }
                continue;
            }

            if ($info[$name]['exists']) {
                $size = 0;
                $count = 0;
                
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                        $count++;
                    }
                }
                
                $info[$name]['file_count'] = $count;
                $info[$name]['total_size'] = $this->formatBytes($size);
            }
        }
        
        return $info;
    }
    
    /**
     * Очистка кэша Twig
     */
    private function clearTwigCache($cachePath) {
        if (!is_dir($cachePath)) {
            return true; // Папки нет - считаем что очищено
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cachePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        return true;
    }
    
    /**
     * Форматирование размера в байтах
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
?>