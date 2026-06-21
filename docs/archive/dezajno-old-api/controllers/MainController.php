<?php

class MainController
{
    private $twig;

    public function __construct($cacheEnabled)
    {
        $loader = new \Twig\Loader\FilesystemLoader('templates');
        $this->twig = new \Twig\Environment($loader, [
            'cache' => $cacheEnabled ? __DIR__ . '/../cache/twig' : false, // Включение/отключение кэширования Twig
        ]);
    }

    public function renderTemplate($template, $data = [])
    {
        // Добавляем данные плагинов в шаблон
        if (isset($data['plugins'])) {
            foreach ($data['plugins'] as $position => $code) {
                $this->twig->addGlobal($position, $code);
            }
        } else {
            echo "No plugin data found\n"; // Добавьте эту строку для отладки
        }

        // Рендерим шаблон
        echo $this->twig->render($template, $data);
    }
}