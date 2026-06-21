<?php
// plugins/LastPost/LastPostPlugin.php

class LastPostPlugin
{
    private $twig;

    public function __construct()
    {
        // Инициализация Twig
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__);
        $this->twig = new \Twig\Environment($loader);
    }

    public function execute($data, $pluginData, $urlModel)
    {
        // Проверяем, есть ли данные и ключи 'Count Post', 'Group Post', 'Position' и 'Sort'
        if (!empty($pluginData) && isset($pluginData['Count Post']) && isset($pluginData['Group Post']) && isset($pluginData['Position']) && isset($pluginData['Sort'])) {
            $countPost = $pluginData['Count Post'];

            // Проверка, является ли 'Count Post' числом
            if (!is_numeric($countPost)) {
                echo "Count Post is not a number: $countPost\n"; // Добавьте эту строку для отладки
                return [];
            }
            $countPost = (int)$pluginData['Count Post'];
            $groupPost = $pluginData['Group Post'];
            $position = $pluginData['Position'];
            $sort = $pluginData['Sort'];

            // Проверяем наличие ключа 'settingsData' в массиве $data
            if (!isset($data['settingsData']) || !isset($data['settingsData']['Pages'])) {
                echo "Key 'settingsData' or 'settingsData[Pages]' is not defined in the data array\n"; // Добавьте эту строку для отладки
                return $data;
            }

            // Получаем идентификатор таблицы Pages из settingsData
            $pagesTableId = $data['settingsData']['Pages'];

            // Определяем фильтр для запроса
            $filter = "(Type,neq,List)";
            if ($groupPost !== 'All') {
                $filter .= "~and(Group,eq,{$groupPost})";
            }

            // Получаем записи с учетом фильтра, сортировки и лимита
            $lastPosts = $urlModel->getRecordsByGroup($pagesTableId, $groupPost, 0, $countPost, $sort);

            // Форматируем вывод во внутреннем шаблоне плагина
            $formattedRecords = $this->formatRecords($lastPosts);

            // Добавляем данные в массив для передачи в шаблон
            if (!isset($data['plugins'])) {
                $data['plugins'] = [];
            }
            if (!isset($data['plugins'][$position])) {
                $data['plugins'][$position] = '';
            }
            $data['plugins'][$position] .= $formattedRecords;

            // Возвращаем только данные, относящиеся к плагину
            return ['plugins' => $data['plugins']];
        } else {
            echo "Plugin data is empty or missing 'Count Post', 'Group Post', 'Position' or 'Sort' key\n"; // Добавьте эту строку для отладки
        }

        // Возвращаем пустой массив, если данные некорректны
        return [];
    }

    private function formatRecords($lastPosts)
    {
        // Рендерим шаблон Twig с данными
        return $this->twig->render('LastPost.twig', ['lastPosts' => $lastPosts]);
    }
}