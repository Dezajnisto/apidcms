<?php
/**
 * SessionProxy — обёртка для доступа к $_SESSION из Twig-шаблонов
 *
 * Позволяет:
 *   {{ session.form_success }}         — чтение
 *   {% set _ = session.remove('key') %} — удаление
 *   {% if session.form_success is defined %} — проверка
 */

namespace Core;

class SessionProxy implements \ArrayAccess {
    public function offsetExists($key): bool {
        return isset($_SESSION[$key]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($key) {
        return $_SESSION[$key] ?? null;
    }

    public function offsetSet($key, $value): void {
        $_SESSION[$key] = $value;
    }

    public function offsetUnset($key): void {
        unset($_SESSION[$key]);
    }

    /**
     * Удалить значение из сессии
     * Используется в Twig: {% set _ = session.remove('key') %}
     */
    public function remove($key): void {
        unset($_SESSION[$key]);
    }
}
