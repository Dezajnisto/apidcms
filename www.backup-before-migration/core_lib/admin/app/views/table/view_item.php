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
        .item-details {
            margin-top: 20px;
        }
        .detail-row {
            display: flex;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
        }
        .detail-label {
            font-weight: bold;
            width: 200px;
            color: #2c3e50;
        }
        .detail-value {
            flex: 1;
            word-break: break-word;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .table-info {
            background: #e8f4fc;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
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
        @media (max-width: 768px) {
            .detail-row {
                flex-direction: column;
            }
            .detail-label {
                width: 100%;
                margin-bottom: 5px;
            }
            .container {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/table/<?= urlencode($tableName) ?>" class="back-link">← Назад к таблице</a>
        <a href="/" class="back-link" style="margin-left: 10px;">Список таблиц</a>
        
        <h1><?= htmlspecialchars($title) ?></h1>
        
        <?php if (isset($_GET['created']) && $_GET['created'] == '1'): ?>
            <div class="notification">
                Запись успешно создана
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
            <div class="info-notification">
                Запись успешно обновлена
            </div>
        <?php endif; ?>
        
        <div class="table-info">
            <strong>Таблица:</strong> <?= htmlspecialchars($tableName) ?> | 
            <strong>ID записи:</strong> <?= htmlspecialchars($item['id'] ?? 'N/A') ?>
        </div>
        
        <?php if (empty($item)): ?>
            <div class="empty-state">
                <h2>Запись не найдена</h2>
                <p>Запрошенная запись не существует в таблице "<?= htmlspecialchars($tableName) ?>".</p>
            </div>
        <?php else: ?>
            <div class="item-details">
                <?php foreach ($structure as $column): ?>
                    <div class="detail-row">
                        <div class="detail-label">
                            <?= htmlspecialchars($column['name']) ?>
                            <?php if ($column['pk'] == 1): ?>
                                <span style="color: #e74c3c; font-size: 0.8em;">(PRIMARY KEY)</span>
                            <?php endif; ?>
                        </div>
                        <div class="detail-value">
                            <?php 
                            $value = $item[$column['name']] ?? '';
                            if (is_string($value)) {
                                // Если это JSON, попробуем его красиво отформатировать
                                if (preg_match('/^\{.*\}$|^\[.*\]$/', trim($value))) {
                                    $json = json_decode($value);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        echo '<pre>' . htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                                    } else {
                                        echo nl2br(htmlspecialchars($value));
                                    }
                                } else {
                                    echo nl2br(htmlspecialchars($value));
                                }
                            } else {
                                echo htmlspecialchars(strval($value));
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>