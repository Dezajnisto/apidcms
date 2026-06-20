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
        .columns-container {
            margin-top: 20px;
        }
        .column-item {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #eee;
        }
        .column-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 10px;
        }
        .column-title {
            font-weight: bold;
            color: #2c3e50;
        }
        .remove-column {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .remove-column:hover {
            background: #c0392b;
        }
        .add-column {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .add-column:hover {
            background: #219a52;
        }
        .column-fields {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: end;
        }
        @media (max-width: 768px) {
            .column-fields {
                grid-template-columns: 1fr;
            }
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
        .system-column {
            background: #f0f8ff;
            border: 1px solid #3498db;
        }
        .system-column .column-header {
            background: #3498db;
            color: white;
            padding: 10px;
            margin: -15px -15px 10px -15px;
            border-radius: 4px 4px 0 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/" class="back-link">← Назад к списку таблиц</a>
        <h1><?= htmlspecialchars($title) ?></h1>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-help">
            <strong>Подсказка:</strong> Создайте новую таблицу с необходимыми колонками. Первая колонка будет автоматически установлена как первичный ключ.
        </div>
        
        <form method="POST" action="/store-table" id="createTableForm">
            <div class="form-group">
                <label for="table_name">
                    Название таблицы <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="table_name" 
                    name="table_name" 
                    value="<?= isset($formData['table_name']) ? htmlspecialchars($formData['table_name']) : '' ?>"
                    required
                    pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                    title="Название таблицы может содержать только буквы, цифры и подчеркивания, и должно начинаться с буквы или подчеркивания"
                >
                <div class="field-info">
                    Может содержать только буквы, цифры и подчеркивания. Должно начинаться с буквы или подчеркивания.
                </div>
            </div>

            <div class="columns-container">
                <h3>Колонки таблицы</h3>
                
                <div id="columnsList">
                    <!-- Колонки будут добавляться здесь динамически -->
                </div>
                
                <button type="button" class="add-column" onclick="addColumn()">+ Добавить колонку</button>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">Создать таблицу</button>
                <a href="/" class="btn btn-danger">Отмена</a>
            </div>
        </form>
    </div>

    <div class="form-group">
        <div class="checkbox-group">
            <input type="checkbox" id="add_timestamps" name="add_timestamps" value="1" checked>
            <label for="add_timestamps">Добавить поля created_at и updated_at</label>
        </div>
        <div class="field-info">
            Автоматически добавит поля для отслеживания времени создания и обновления записей
        </div>
    </div>

    <script>
        let columnCount = 0;

        // Добавляем первую колонку при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            addFirstColumn(); // Добавляем фиксированную колонку id
            addColumn(); // Добавляем первую пользовательскую колонку
        });

        function addColumn() {
            columnCount++;
            const columnsList = document.getElementById('columnsList');
            
            const columnDiv = document.createElement('div');
            columnDiv.className = 'column-item';
            columnDiv.innerHTML = `
                <div class="column-header">
                    <span class="column-title">Колонка #${columnCount}</span>
                    <button type="button" class="remove-column" onclick="removeColumn(this)">× Удалить</button>
                </div>
                <div class="column-fields">
                    <div>
                        <label>Название колонки <span class="required">*</span></label>
                        <input type="text" name="columns[${columnCount}][name]" required 
                            pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                            title="Название колонки может содержать только буквы, цифры и подчеркивания">
                    </div>
                    <div>
                        <label>Тип данных <span class="required">*</span></label>
                        <select name="columns[${columnCount}][type]" required>
                            <option value="TEXT">TEXT</option>
                            <option value="VARCHAR(255)">VARCHAR(255)</option>
                            <option value="INTEGER">INTEGER</option>
                            <option value="REAL">REAL</option>
                            <option value="NUMERIC">NUMERIC</option>
                            <option value="BOOLEAN">BOOLEAN</option>
                            <option value="DATE">DATE</option>
                            <option value="DATETIME">DATETIME</option>
                            <option value="BLOB">BLOB</option>
                        </select>
                    </div>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="columns[${columnCount}][nullable]" checked>
                            NULL
                        </label>
                    </div>
                    <div>
                        <label>Значение по умолчанию</label>
                        <input type="text" name="columns[${columnCount}][default]" placeholder="Необязательно">
                    </div>
                </div>
            `;
            
            columnsList.appendChild(columnDiv);
        }

        function removeColumn(button) {
            const columnItem = button.closest('.column-item');
            
            // Проверяем, что это не системная колонка
            if (!columnItem.querySelector('input[readonly]')) {
                columnItem.remove();
                columnCount--;
                
                // Обновляем номера колонок
                const columns = document.querySelectorAll('.column-item:not(:first-child)');
                columns.forEach((col, index) => {
                    const title = col.querySelector('.column-title');
                    if (title) {
                        title.textContent = `Колонка #${index + 1}`;
                    }
                });
            }
}
    </script>
</body>
</html>