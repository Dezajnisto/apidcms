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
            max-width: 600px;
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
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus,
        select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
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
        .btn-success {
            background: #27ae60;
        }
        .btn-success:hover {
            background: #219a52;
        }
        .btn-danger {
            background: #e74c3c;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .form-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
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
        .field-info {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input {
            width: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/table/<?= urlencode($tableName) ?>/structure" class="back-link">← Назад к структуре</a>
        <a href="/table/<?= urlencode($tableName) ?>" class="back-link" style="margin-left: 10px;">Данные таблицы</a>
        
        <h1><?= htmlspecialchars($title) ?></h1>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-help">
            <strong>Подсказка:</strong> Добавьте новую колонку в таблицу "<?= htmlspecialchars($tableName) ?>". Укажите название, тип данных и дополнительные параметры.
        </div>
        
        <form method="POST" action="/table/<?= urlencode($tableName) ?>/store-column">
            <div class="form-group">
                <label for="column_name">
                    Название колонки <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="column_name" 
                    name="column_name" 
                    value="<?= isset($formData['column_name']) ? htmlspecialchars($formData['column_name']) : '' ?>"
                    required
                    pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                    title="Название колонки может содержать только буквы, цифры и подчеркивания, и должно начинаться с буквы или подчеркивания"
                >
                <div class="field-info">
                    Может содержать только буквы, цифры и подчеркивания. Должно начинаться с буквы или подчеркивания.
                </div>
            </div>

            <div class="form-group">
                <label for="column_type">
                    Тип данных <span class="required">*</span>
                </label>
                <select id="column_type" name="column_type" required>
                    <option value="">-- Выберите тип данных --</option>
                    <option value="INTEGER" <?= isset($formData['column_type']) && $formData['column_type'] === 'INTEGER' ? 'selected' : '' ?>>INTEGER (целое число)</option>
                    <option value="TEXT" <?= isset($formData['column_type']) && $formData['column_type'] === 'TEXT' ? 'selected' : '' ?>>TEXT (текст)</option>
                    <option value="VARCHAR(255)" <?= isset($formData['column_type']) && $formData['column_type'] === 'VARCHAR(255)' ? 'selected' : '' ?>>VARCHAR(255) (строка до 255 символов)</option>
                    <option value="REAL" <?= isset($formData['column_type']) && $formData['column_type'] === 'REAL' ? 'selected' : '' ?>>REAL (вещественное число)</option>
                    <option value="NUMERIC" <?= isset($formData['column_type']) && $formData['column_type'] === 'NUMERIC' ? 'selected' : '' ?>>NUMERIC (число с фиксированной точностью)</option>
                    <option value="BOOLEAN" <?= isset($formData['column_type']) && $formData['column_type'] === 'BOOLEAN' ? 'selected' : '' ?>>BOOLEAN (логическое значение)</option>
                    <option value="DATE" <?= isset($formData['column_type']) && $formData['column_type'] === 'DATE' ? 'selected' : '' ?>>DATE (дата)</option>
                    <option value="DATETIME" <?= isset($formData['column_type']) && $formData['column_type'] === 'DATETIME' ? 'selected' : '' ?>>DATETIME (дата и время)</option>
                    <option value="BLOB" <?= isset($formData['column_type']) && $formData['column_type'] === 'BLOB' ? 'selected' : '' ?>>BLOB (бинарные данные)</option>
                </select>
                <div class="field-info">
                    Выберите тип данных для новой колонки
                </div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="nullable" name="nullable" <?= isset($formData['nullable']) ? 'checked' : 'checked' ?>>
                    <label for="nullable">Разрешить NULL значения</label>
                </div>
                <div class="field-info">
                    Если отмечено, колонка может содержать пустые значения
                </div>
            </div>

            <div class="form-group">
                <label for="default_value">Значение по умолчанию</label>
                <input 
                    type="text" 
                    id="default_value" 
                    name="default_value" 
                    value="<?= isset($formData['default_value']) ? htmlspecialchars($formData['default_value']) : '' ?>"
                >
                <div class="field-info">
                    Укажите значение, которое будет установлено по умолчанию для новых записей
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">Добавить колонку</button>
                <a href="/table/<?= urlencode($tableName) ?>/structure" class="btn btn-danger">Отмена</a>
            </div>
        </form>
    </div>
</body>
</html>