<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 25px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid #3498db;
            border-radius: 4px;
            transition: all 0.3s;
        }
        .back-link:hover {
            background-color: #3498db;
            color: white;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #2c3e50;
        }
        input[type="text"],
        input[type="number"],
        input[type="email"],
        input[type="date"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
        }
        textarea {
            min-height: 120px;
            resize: vertical;
            font-family: Arial, sans-serif;
        }
        .btn {
            display: inline-block;
            padding: 12px 25px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
            margin-right: 10px;
        }
        .btn:hover {
            background: #2980b9;
        }
        .btn-danger {
            background: #e74c3c;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .btn-success {
            background: #27ae60;
        }
        .btn-success:hover {
            background: #219a52;
        }
        .form-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .field-info {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .required {
            color: #e74c3c;
        }
        .form-help {
            background: #e8f4fc;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/table/<?= urlencode($tableName) ?>" class="back-link">← Назад к таблице</a>
        <a href="/" class="back-link" style="margin-left: 10px;">Список таблиц</a>
        
        <h1><?= htmlspecialchars($title) ?></h1>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-help">
            <strong>Подсказка:</strong> Заполните все необходимые поля. Поля с <span class="required">*</span> обязательны для заполнения.
        </div>
        
        <form method="POST" action="/table/<?= urlencode($tableName) ?>/<?= $action === 'create' ? 'store' : 'update/' . $itemId ?>">
                <?php foreach ($structure as $column): ?>
                    <?php 
                    // Пропускаем автоинкрементные первичные ключи при создании
                    if ($action === 'create' && $column['pk'] == 1) {
                        // Проверяем, является ли колонка автоинкрементной
                        $isAutoIncrement = false;
                        if (stripos($column['type'], 'INTEGER') !== false && $column['pk'] == 1) {
                            $isAutoIncrement = true;
                        }
                        
                        // Пропускаем автоинкрементные поля
                        if ($isAutoIncrement) {
                            continue;
                        }
                    }
                    
                    // Пропускаем поля с created_at при создании (они заполнятся автоматически)
                    if ($action === 'create' && $column['name'] === 'created_at') {
                        continue;
                    }
                    ?>
                    
                    <div class="form-group">
                        <label for="<?= htmlspecialchars($column['name']) ?>">
                            <?= htmlspecialchars($column['name']) ?>
                            <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                            
                            <?php if ($column['pk'] == 1): ?>
                                <span style="color: #27ae60;">(Первичный ключ)</span>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($column['pk'] == 1 && stripos($column['type'], 'INTEGER') !== false): ?>
                            <!-- Поле первичного ключа (только для редактирования) -->
                            <input 
                                type="text" 
                                id="<?= htmlspecialchars($column['name']) ?>" 
                                value="<?= isset($item[$column['name']]) ? htmlspecialchars($item[$column['name']]) : 'Автоматически' ?>" 
                                readonly 
                                style="background: #f5f5f5;"
                            >
                            <div class="field-info">
                                Это поле заполняется автоматически
                            </div>
                        <?php elseif (stripos($column['type'], 'text') !== false): ?>
                            <textarea 
                                id="<?= htmlspecialchars($column['name']) ?>" 
                                name="<?= htmlspecialchars($column['name']) ?>" 
                                rows="6"
                                <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>required<?php endif; ?>
                            ><?= isset($item[$column['name']]) ? htmlspecialchars($item[$column['name']]) : '' ?></textarea>
                        <?php elseif (stripos($column['type'], 'int') !== false || stripos($column['type'], 'integer') !== false): ?>
                            <input 
                                type="number" 
                                id="<?= htmlspecialchars($column['name']) ?>" 
                                name="<?= htmlspecialchars($column['name']) ?>" 
                                value="<?= isset($item[$column['name']]) ? htmlspecialchars($item[$column['name']]) : '' ?>"
                                <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>required<?php endif; ?>
                            >
                        <?php elseif (stripos($column['type'], 'date') !== false || stripos($column['type'], 'time') !== false): ?>
                            <input 
                                type="datetime-local" 
                                id="<?= htmlspecialchars($column['name']) ?>" 
                                name="<?= htmlspecialchars($column['name']) ?>" 
                                value="<?= isset($item[$column['name']]) ? htmlspecialchars($item[$column['name']]) : '' ?>"
                                <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>required<?php endif; ?>
                            >
                        <?php else: ?>
                            <input 
                                type="text" 
                                id="<?= htmlspecialchars($column['name']) ?>" 
                                name="<?= htmlspecialchars($column['name']) ?>" 
                                value="<?= isset($item[$column['name']]) ? htmlspecialchars($item[$column['name']]) : '' ?>"
                                <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>required<?php endif; ?>
                            >
                        <?php endif; ?>
                        
                        <div class="field-info">
                            Тип: <?= htmlspecialchars($column['type']) ?>
                            <?php if ($column['pk'] == 1): ?>
                                <span style="color: #27ae60;">(Первичный ключ)</span>
                            <?php endif; ?>
                            <?php if ($column['notnull'] == 1 && $column['pk'] == 0): ?>
                                <span style="color: #e74c3c;">Обязательное поле</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">
                    <?= $action === 'create' ? 'Создать запись' : 'Сохранить изменения' ?>
                </button>
                <a href="/table/<?= urlencode($tableName) ?>" class="btn btn-danger">Отмена</a>
            </div>
        </form>
    </div>
</body>
</html>