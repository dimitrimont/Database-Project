<?php
require __DIR__ . '/queries.php';

$desc = 'Football Database';
$error = null;
$columns = [];
$rows = [];

try {
    [$desc, $sql, $params] = build_query($_GET);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    $error = $e->getMessage();
}

if ($error === null) {
    require __DIR__ . '/db_connect.php';
    try {
        if (!empty($params)) {
            $stmt = $dbc->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = $dbc->query($sql);
        }
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = $meta['name'] ?? "col{$i}";
        }
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        // The SQL and the file paths go to the server's log, not to the visitor.
        error_log("Query '" . ($_GET['q'] ?? '') . "' failed: " . $e->getMessage());
        http_response_code(500);
        $error = 'The query could not be run.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($desc); ?></title>
</head>
<body>
<h1><?php echo htmlspecialchars($desc); ?></h1>
<nav>
    <a href="index.html">Back to Menu</a> |
    <a href="search_players.html">Search Player</a>
</nav>

<?php if ($error !== null): ?>
    <p role="alert"><?php echo htmlspecialchars($error); ?></p>
<?php else: ?>
<p><?php echo count($rows); ?> rows</p>
<table border="1" cellpadding="4" cellspacing="0">
    <tr>
        <?php foreach ($columns as $column): ?>
            <th><?php echo htmlspecialchars($column); ?></th>
        <?php endforeach; ?>
    </tr>

    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($columns as $column): ?>
                <td><?php echo htmlspecialchars((string)($row[$column] ?? '')); ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>
