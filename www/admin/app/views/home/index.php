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
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .table-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            background: #f9f9f9;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .table-name {
            font-size: 1.2em;
            font-weight: bold;
            color: #2980b9;
            margin-bottom: 10px;
        }
        .table-info {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 10px;
        }
        .view-link {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .view-link:hover {
            background: #2980b9;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .structure-list {
            font-size: 0.8em;
            color: #888;
            margin-top: 10px;
        }
        .structure-item {
            margin: 2px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= htmlspecialchars($title) ?></h1>
        
        <div class="table-actions" style="margin-bottom: 20px;">
            <a href="/create-table" class="btn btn-success">+ Создать новую таблицу</a>
        </div>

        <?php if (isset($_GET['table_deleted']) && $_GET['table_deleted'] == '1'): ?>
            <div class="notification">
                Таблица успешно удалена
            </div>
        <?php endif; ?>

        <?php if (empty($tables)): ?>
            <div class="empty-state">
                <h2>Таблицы не найдены</h2>
                <p>В базе данных пока нет таблиц. Используйте программу DB Browser для SQLite чтобы создать таблицы.</p>
            </div>
        <?php else: ?>
            <div class="tables-grid">
                <?php foreach ($tables as $table): ?>
                    <div class="table-card">
                        <div class="table-name">
                            <?= htmlspecialchars($table['name']) ?>
                        </div>
                        <div class="table-info">
                            Колонок: <?= $table['columns'] ?>
                        </div>
                        <?php if (!empty($table['structure'])): ?>
                            <div class="structure-list">
                                <strong>Структура:</strong>
                                <?php foreach ($table['structure'] as $column): ?>
                                    <div class="structure-item">
                                        <?= htmlspecialchars($column['name']) ?> (<?= htmlspecialchars($column['type']) ?>)
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <a href="/table/<?= urlencode($table['name']) ?>" class="view-link">
                            Просмотреть данные
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>