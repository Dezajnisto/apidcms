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
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        .data-table th,
        .data-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
            word-break: break-word;
        }
        .data-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
            position: sticky;
            top: 0;
            cursor: pointer;
        }
        .data-table th:hover {
            background-color: #e9ecef;
        }
        .data-table th.sorted-asc::after {
            content: " ↑";
            color: #3498db;
        }
        .data-table th.sorted-desc::after {
            content: " ↓";
            color: #3498db;
        }
        .data-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .data-table tr:hover {
            background-color: #f1f1f1;
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
        .table-actions {
            margin-bottom: 20px;
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
        .actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
            border: 1px solid;
        }
        .action-edit {
            background: #3498db;
            color: white;
            border-color: #2980b9;
        }
        .action-edit:hover {
            background: #2980b9;
        }
        .action-delete {
            background: #e74c3c;
            color: white;
            border-color: #c0392b;
        }
        .action-delete:hover {
            background: #c0392b;
        }
        .notification {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
        }
        .search-box {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .search-button {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .search-button:hover {
            background: #2980b9;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #3498db;
        }
        .pagination a:hover {
            background: #3498db;
            color: white;
        }
        .pagination .current {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }
        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
        }
        .results-info {
            margin-bottom: 10px;
            color: #666;
            font-size: 14px;
        }
        @media (max-width: 768px) {
            .data-table {
                font-size: 12px;
            }
            .data-table th,
            .data-table td {
                padding: 8px;
            }
            .container {
                padding: 10px;
            }
            .search-box {
                flex-direction: column;
            }
            .search-input {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="/" class="back-link">← Назад к списку таблиц</a>
        <h1><?= htmlspecialchars($title) ?></h1>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1'): ?>
            <div class="notification">
                Запись успешно удалена
            </div>
        <?php endif; ?>
        
        <div class="table-info">
            <strong>Таблица:</strong> <?= htmlspecialchars($tableName) ?> | 
            <strong>Всего записей:</strong> <?= $totalCount ?>
            <?php if (!empty($search)): ?>
                | <strong>Найдено:</strong> <?= $totalCount ?>
            <?php endif; ?>
        </div>
        
        <div class="table-actions">
            <a href="/table/<?= urlencode($tableName) ?>/create" class="btn btn-success">+ Добавить запись</a>
        </div>

        <!-- Поиск -->
        <form method="GET" action="" class="search-box">
            <input type="text" 
                   name="search" 
                   class="search-input" 
                   placeholder="Поиск по таблице..." 
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="search-button">Найти</button>
            <?php if (!empty($search)): ?>
                <a href="/table/<?= urlencode($tableName) ?>" class="btn">Сбросить поиск</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($search)): ?>
            <div class="results-info">
                Результаты поиска для: "<strong><?= htmlspecialchars($search) ?></strong>"
            </div>
        <?php endif; ?>
        
        <?php if (empty($data)): ?>
            <div class="empty-state">
                <h2>Данные не найдены</h2>
                <p>
                    <?php if (!empty($search)): ?>
                        По запросу "<?= htmlspecialchars($search) ?>" ничего не найдено.
                        <a href="/table/<?= urlencode($tableName) ?>">Показать все записи</a>
                    <?php else: ?>
                        В таблице "<?= htmlspecialchars($tableName) ?>" нет данных.
                    <?php endif; ?>
                </p>
                <?php if (empty($search)): ?>
                    <a href="/table/<?= urlencode($tableName) ?>/create" class="btn btn-success">Добавить первую запись</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <?php 
                            $hasIdColumn = false;
                            foreach ($structure as $column) {
                                if ($column['name'] === 'id') {
                                    $hasIdColumn = true;
                                    break;
                                }
                            }
                            
                            if ($hasIdColumn): ?>
                                <th class="<?= $sort === 'id' ? 'sorted-' . strtolower($order) : '' ?>"
                                    onclick="sortTable('id')">ID</th>
                            <?php endif; ?>
                            
                            <?php foreach ($structure as $column): ?>
                                <?php if ($column['name'] !== 'id'): ?>
                                    <th class="<?= $sort === $column['name'] ? 'sorted-' . strtolower($order) : '' ?>"
                                        onclick="sortTable('<?= htmlspecialchars($column['name']) ?>')">
                                        <?= htmlspecialchars($column['name']) ?>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                            <?php if ($hasIdColumn): ?>
                                <th>Действия</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                            <tr>
                                <?php if ($hasIdColumn): ?>
                                    <td><?= isset($row['id']) ? htmlspecialchars($row['id']) : '' ?></td>
                                <?php endif; ?>
                                
                                <?php foreach ($structure as $column): ?>
                                    <?php if ($column['name'] !== 'id'): ?>
                                        <td>
                                            <?php 
                                            $value = $row[$column['name']] ?? '';
                                            if (is_string($value) && strlen($value) > 100) {
                                                echo htmlspecialchars(substr($value, 0, 100)) . '...';
                                            } else {
                                                echo htmlspecialchars($value);
                                            }
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <?php if ($hasIdColumn && isset($row['id'])): ?>
                                    <td>
                                        <div class="actions">
                                            <a href="/table/<?= urlencode($tableName) ?>/id/<?= $row['id'] ?>" class="action-btn" title="Просмотр">
                                                👁️
                                            </a>
                                            <a href="/table/<?= urlencode($tableName) ?>/edit/<?= $row['id'] ?>" class="action-btn action-edit" title="Редактировать">
                                                ✏️
                                            </a>
                                            <a href="/table/<?= urlencode($tableName) ?>/delete/<?= $row['id'] ?>" 
                                               class="action-btn action-delete" 
                                               title="Удалить"
                                               onclick="return confirm('Вы уверены, что хотите удалить эту запись?')">
                                                🗑️
                                            </a>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">« Первая</a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1])) ?>">‹ Назад</a>
                    <?php else: ?>
                        <span class="disabled">« Первая</span>
                        <span class="disabled">‹ Назад</span>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <?php if ($i == $currentPage): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>">Вперёд ›</a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">Последняя »</a>
                    <?php else: ?>
                        <span class="disabled">Вперёд ›</span>
                        <span class="disabled">Последняя »</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    function sortTable(column) {
        const urlParams = new URLSearchParams(window.location.search);
        let currentSort = urlParams.get('sort');
        let currentOrder = urlParams.get('order') || 'ASC';
        
        if (currentSort === column) {
            currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
        } else {
            currentOrder = 'ASC';
        }
        
        urlParams.set('sort', column);
        urlParams.set('order', currentOrder);
        
        window.location.href = '?' + urlParams.toString();
    }
    </script>
</body>
</html>