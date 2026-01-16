<?php
// エラー表示（※本番では消す）
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// DB設定読み込み
require_once __DIR__ . '/../config.php';

// DB接続
try {
    $pdo = new PDO(
        $dsn,
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    echo 'DB接続エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// データ取得
$sql = "SELECT * FROM kakeibo ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>家計簿</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <h1>家計簿</h1>

  <table border="1" cellpadding="8">
    <tr>
      <th>日付</th>
      <th>項目</th>
      <th>金額</th>
    </tr>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['date']) ?></td>
        <td><?= htmlspecialchars($row['item']) ?></td>
        <td><?= htmlspecialchars($row['amount']) ?> 円</td>
      </tr>
    <?php endforeach; ?>
  </table>
</body>
</html>
