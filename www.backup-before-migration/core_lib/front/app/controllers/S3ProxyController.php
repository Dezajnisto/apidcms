<?php
/**
 * Контроллер для проксирования S3 файлов
 */

namespace Front;

use Core\S3Proxy;

class S3ProxyController extends BaseController {
    
    public function proxy($path) {
        $s3Proxy = new S3Proxy();
        $s3Proxy->proxyFile($path);
    }
}