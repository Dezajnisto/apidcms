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
            max-width: 1000px;
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
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid #3498db;
            border-radius: 4px;
        }
        .back-link:hover {
            background-color: #3498db;
            color: white;
        }
        .structure-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        .structure-table th,
        .structure-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .structure-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        .structure-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .structure-table tr:hover {
            background-color: #f1f1f1;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
            margin-right: 10px;
            margin-bottom: 10px;
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
        .table-actions {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .notification {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
        }
        .info-notification {
            background: #d1ecf1;
            color: #0c5460;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #bee5eb;
            border-radius: 4px;
        }
        .pk-badge {
            background: #27ae60;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-left: 5px;
        }
        .notnull-badge {
            background: #e74c3c;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.8em;
            margin-left: 5px;
        }
        .actions-cell {
            display: flex;
            gap: 5px;
        }
        .action-btn {
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
            border: 1px solid;
        }
        .action-danger {
            background: #e74c3c;
            color: white;
            border-color: #c0392b;
        }
        .action-danger:hover {
            background: #c0392b;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        @media (max-width: 768px) {
            .structure-table {
                font-size: 12px;
            }
            .structure-table th,
            .structure-table td {
                padding: 8px;
            }
            .container {
                padding: 10px;
            }
            .table-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/table/<?= urlencode($tableName) ?>" class="back-link">← Назад к данным таблицы</a>
        <a href="/" class="back-link" style="margin-left: 10px;">Список таблиц</a>
        
        <h1><?= htmlspecialchars($title) ?></h1>

        <?php if (isset($_GET['column_added']) && $_GET['column_added'] == '1'): ?>
            <div class="notification">
                Колонка успешно добавлена
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['column_deleted']) && $_GET['column_deleted'] == '1'): ?>
            <div class="info-notification">
                Колонка успешно удалена
            </div>
        <?php endif; ?>
        
        <div class="table-actions">
            <a href="/table/<?= urlencode($tableName) ?>/add-column" class="btn btn-success">+ Добавить колонку</a>
            <a href="/table/<?= urlencode($tableName) ?>/delete-table" 
               class="btn btn-danger" 
               onclick="return confirm('ВНИМАНИЕ: Вы уверены, что хотите удалить таблицу \"<?= htmlspecialchars($tableName) ?>\"? Это действие нельзя отменить.')">
                🗑️ Удалить таблицу
            </a>
        </div>

        <?php if (empty($structure)): ?>
            <div class="empty-state">
                <h2>Таблица не содержит колонок</h2>
                <p>Добавьте первую колонку чтобы начать работу с таблицей.</p>
                <a href="/table/<?= urlencode($tableName) ?>/add-column" class="btn btn-success">Добавить колонку</a>
            </div>
        <?php else: ?>
            <table class="structure-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Может быть NULL</th>
                        <th>Первичный ключ</th>
                        <th>Значение по умолчанию</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($structure as $column): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($column['name']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($column['type']) ?></td>
                            <td><?= $column['notnull'] ? 'Нет' : 'Да' ?></td>
                            <td>
                                <?php if ($column['pk']): ?>
                                    <span class="pk-badge">PK</span>
                                <?php else: ?>
                                    Нет
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($column['dflt_value'] ?? 'NULL') ?></td>
                            <td class="actions-cell">
                                <?php if (!$column['pk'] && $column['name'] !== 'id'): ?>
                                    <a href="/table/<?= urlencode($tableName) ?>/delete-column/<?= urlencode($column['name']) ?>" 
                                       class="action-btn action-danger" 
                                       onclick="return confirm('Вы уверены, что хотите удалить колонку \"<?= htmlspecialchars($column['name']) ?>\"?')">
                                        Удалить
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 0.9em;">Системная</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>