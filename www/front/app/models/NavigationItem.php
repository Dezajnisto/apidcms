<?php
/**
 * Модель элемента навигации с поддержкой конфигурации форм
 */

namespace Front;

class NavigationItem {
    public $id;
    public $title;
    public $url;
    public $page_type;
    public $source_table;
    public $template;
    public $items_per_page;
    public $sort_field;
    public $sort_order;
    public $filters;
    public $menu_order;
    public $location;
    public $status;
    public $description;
    public $form_config;
    public $page_config;
    
    /**
     * Конструктор
     */
    public function __construct($data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        
        // Парсим JSON фильтры
        if ($this->filters && is_string($this->filters)) {
            $this->filters = json_decode($this->filters, true);
        }
        
        // Парсим JSON расширенной конфигурации
        if ($this->page_config && is_string($this->page_config)) {
            $decoded = json_decode($this->page_config, true);
            $this->page_config = is_array($decoded) ? $decoded : [];
        } else {
            $this->page_config = [];
        }
    }
    
    /**
     * Получить конфигурацию для страницы
     */
    public function getPageConfig() {
        $config = [
            'type' => $this->page_type,
            'source_table' => $this->source_table,
            'template' => $this->template ?: 'default',
            'items_per_page' => $this->items_per_page ?: 10,
            'sort' => [
                'field' => $this->sort_field ?: 'created_at',
                'order' => $this->sort_order ?: 'DESC'
            ],
            'filters' => $this->filters ?: []
        ];
        
        // Добавляем конфигурацию формы, если она есть и страница является формой
        if ($this->page_type === 'form' && !empty($this->form_config)) {
            $formConfig = $this->getFormConfig();
            if (is_array($formConfig) && !empty($formConfig)) {
                $config = array_merge($config, $formConfig);
            }
        }
        
        // Мержим расширенную конфигурацию из page_config (get_filters и т.д.)
        if (!empty($this->page_config)) {
            $config = array_merge($config, $this->page_config);
        }
        
        return $config;
    }
    
    /**
     * Получить конфигурацию формы
     */
    public function getFormConfig() {
        if (empty($this->form_config)) {
            return [];
        }
        
        if (is_array($this->form_config)) {
            return $this->form_config;
        }
        
        if (is_string($this->form_config)) {
            $decoded = json_decode($this->form_config, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return [];
    }
    
    /**
     * Проверить, является ли страница формой
     */
    public function isForm() {
        return $this->page_type === 'form';
    }
}
