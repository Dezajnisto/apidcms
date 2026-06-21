<?php
// plugins/HTMLcode/HTMLcodePlugin.php

class HTMLcodePlugin
{
    public function execute($data, $pluginData)
    {
        // Проверяем, есть ли данные и ключ 'Code'
        if (!empty($pluginData) && isset($pluginData['Code']) && isset($pluginData['Position'])) {
            $code = $pluginData['Code'];
            $position = $pluginData['Position'];

            // Добавляем данные в массив для передачи в шаблон
            if (!isset($data['plugins'])) {
                $data['plugins'] = [];
            }
            if (!isset($data['plugins'][$position])) {
                $data['plugins'][$position] = '';
            }
            $data['plugins'][$position] .= $code;

        } else {
            echo "Plugin data is empty or missing 'Code' or 'Position' key\n"; // Добавьте эту строку для отладки
        }

        // Возвращаем дополнительные данные, если необходимо
        return $data;
    }
}