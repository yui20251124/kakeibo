<?php
declare(strict_types=1);
session_start();

/** ====== config 読み込み（基本は www の外）====== */
$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];

/** ====== util ====== */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_verify(): void {
  $t = $_POST['csrf'] ?? '';
  if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
    http_response_code(400);
    exit('Bad Request (CSRF)');
  }
}
function is_login(): bool { return !empty($_SESSION['user_id']); }
function require_login(): void {
  if (!is_login()) { header('Location: ?page=login'); exit; }
}

/** ====== DB ====== */
function pdo(array $db): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}
$pdo = pdo($db);

/** ====== routing ====== */
$page = $_GET['page'] ?? (is_login() ? 'list' : 'login');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** ====== handlers ====== */
$error = '';

/* logout */
if ($page === 'logout' && $method === 'POST') {
  csrf_verify();
  session_destroy();
  header('Location: ?page=login');
  exit;
}

/* register */
if ($page === 'register' && $method === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  if ($email === '' || $pass === '') {
    $error = 'メールとパスワードを入力してください。';
    $page = 'login';
  } else {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO users(email, password_hash) VALUES(?, ?)");
      $st->execute([$email, $hash]);
      $_SESSION['user_id'] = (int)$pdo->lastInsertId();
      header('Location: ?page=list');
      exit;
    } catch (Throwable $e) {
      $error = '登録できませんでした（同じメールがある可能性）。';
      $page = 'login';
    }
  }
}

/* login */
if ($page === 'login' && $method === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';

  $st = $pdo->prepare("SELECT id, password_hash FROM users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();

  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['user_id'] = (int)$u['id'];
    header('Location: ?page=list');
    exit;
  }
  $error = 'メールまたはパスワードが違います。';
  $page = 'login';
}

/* create expense */
if ($page === 'create' && $method === 'POST') {
  require_login();
  csrf_verify();

  $spent_on = $_POST['spent_on'] ?? '';
  $category = trim($_POST['category'] ?? '');
  $title = trim($_POST['title'] ?? '');
  $amount = (int)($_POST['amount'] ?? 0);
  $memo = trim($_POST['memo'] ?? '');

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $spent_on) || $category==='' || $title==='' || $amount<=0) {
    $error = '入力を確認してください。';
    $page = 'new';
  } else {
    $st = $pdo->prepare("INSERT INTO expenses(user_id, spent_on, category, title, amount, memo) VALUES(?,?,?,?,?,?)");
    $st->execute([(int)$_SESSION['user_id'], $spent_on, $category, $title, $amount, $memo ?: null]);
    header('Location: ?page=list&ym=' . substr($spent_on,0,7));
    exit;
  }
}

/* update expense */
if ($page === 'update' && $method === 'POST') {
  require_login();
  csrf_verify();

  $id = (int)($_POST['id'] ?? 0);
  $spent_on = $_POST['spent_on'] ?? '';
  $category = trim($_POST['category'] ?? '');
  $title = trim($_POST['title'] ?? '');
  $amount = (int)($_POST['amount'] ?? 0);
  $memo = trim($_POST['memo'] ?? '');

  if ($id<=0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $spent_on) || $category==='' || $title==='' || $amount<=0) {
    $error = '入力を確認してください。';
    $page = 'edit';
    $_GET['id'] = (string)$id;
  } else {
    $st = $pdo->prepare("UPDATE expenses SET spent_on=?, category=?, title=?, amount=?, memo=? WHERE id=? AND user_id=?");
    $st->execute([$spent_on, $category, $title, $amount, $memo ?: null, $id, (int)$_SESSION['user_id']]);
    header('Location: ?page=list&ym=' . substr($spent_on,0,7));
    exit;
  }
}

/* delete expense */
if ($page === 'delete' && $method === 'POST') {
  require_login();
  csrf_verify();

  $id = (int)($_POST['id'] ?? 0);
  $st = $pdo->prepare("DELETE FROM expenses WHERE id=? AND user_id=?");
  $st->execute([$id, (int)$_SESSION['user_id']]);
  header('Location: ?page=list');
  exit;
}

/** ====== view helper ====== */
function layout_start(string $title): void {
  echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>'.h($title).'</title>';
  echo '<link rel="stylesheet" href="style.css"></head><body><div class="wrap">';
}
function layout_end(): void { echo '</div></body></html>'; }

/** ====== views ====== */
if ($page === 'login') {
  layout_start('ログイン | 家計簿');
  if ($error) echo '<p class="err">'.h($error).'</p>';
  $csrf = csrf_token();
  ?>
  <div class="card">
    <h1>家計簿ログイン</h1>

    <form method="post" action="?page=login">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <label>メール</label>
      <input name="email" type="email" required>
      <label>パスワード</label>
      <input name="password" type="password" required>
      <div class="row"><button class="btn" type="submit">ログイン</button></div>
    </form>

    <hr style="border:none;border-top:1px solid #eee;margin:16px 0;">

    <form method="post" action="?page=register">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <label>（初回）新規登録</label>
      <input name="email" type="email" required>
      <input name="password" type="password" required style="margin-top:10px;">
      <div class="row"><button class="btn secondary" type="submit">新規登録</button></div>
    </form>
  </div>
  <?php
  layout_end();
  exit;
}

if ($page === 'new') {
  require_login();
  layout_start('追加 | 家計簿');
  if ($error) echo '<p class="err">'.h($error).'</p>';
  $csrf = csrf_token();
  ?>
  <div class="top">
    <h1>支出を追加</h1>
    <a class="btn secondary" href="?page=list">戻る</a>
  </div>

  <div class="card">
    <form method="post" action="?page=create">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <label>日付</label>
      <input type="date" name="spent_on" value="<?=h($_POST['spent_on'] ?? date('Y-m-d'))?>" required>

      <label>カテゴリ</label>
      <input name="category" value="<?=h($_POST['category'] ?? '食費')?>" required>

      <label>品目</label>
      <input name="title" value="<?=h($_POST['title'] ?? '')?>" required>

      <label>金額</label>
      <input type="number" name="amount" min="1" value="<?=h($_POST['amount'] ?? '')?>" required>

      <label>メモ</label>
      <textarea name="memo" style="min-height:80px;"><?=h($_POST['memo'] ?? '')?></textarea>

      <div class="row"><button class="btn" type="submit">保存</button></div>
    </form>
  </div>
  <?php
  layout_end();
  exit;
}

if ($page === 'edit') {
  require_login();
  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare("SELECT * FROM expenses WHERE id=? AND user_id=? LIMIT 1");
  $st->execute([$id, (int)$_SESSION['user_id']]);
  $r = $st->fetch();
  if (!$r) { http_response_code(404); exit('Not found'); }

  layout_start('編集 | 家計簿');
  if ($error) echo '<p class="err">'.h($error).'</p>';
  $csrf = csrf_token();
  ?>
  <div class="top">
    <h1>支出を編集</h1>
    <a class="btn secondary" href="?page=list&ym=<?=h(substr($r['spent_on'],0,7))?>">戻る</a>
  </div>

  <div class="card">
    <form method="post" action="?page=update">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

      <label>日付</label>
      <input type="date" name="spent_on" value="<?=h($_POST['spent_on'] ?? $r['spent_on'])?>" required>

      <label>カテゴリ</label>
      <input name="category" value="<?=h($_POST['category'] ?? $r['category'])?>" required>

      <label>品目</label>
      <input name="title" value="<?=h($_POST['title'] ?? $r['title'])?>" required>

      <label>金額</label>
      <input type="number" name="amount" min="1" value="<?=h((string)($_POST['amount'] ?? $r['amount']))?>" required>

      <label>メモ</label>
      <textarea name="memo" style="min-height:80px;"><?=h($_POST['memo'] ?? ($r['memo'] ?? ''))?></textarea>

      <div class="row"><button class="btn" type="submit">更新</button></div>
    </form>
  </div>
  <?php
  layout_end();
  exit;
}

/* list */
require_login();
$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$start = $ym . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));

$st = $pdo->prepare("
  SELECT * FROM expenses
  WHERE user_id=? AND spent_on >= ? AND spent_on < ?
  ORDER BY spent_on DESC, id DESC
");
$st->execute([(int)$_SESSION['user_id'], $start, $end]);
$rows = $st->fetchAll();
$sum = array_sum(array_map(fn($r)=> (int)$r['amount'], $rows));

layout_start('一覧 | 家計簿');
$csrf = csrf_token();
?>
<div class="top">
  <h1>家計簿（<?=h($ym)?>）</h1>
  <div class="row" style="margin:0;">
    <a class="btn secondary" href="?page=new">＋追加</a>
    <form method="post" action="?page=logout">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <button class="btn" type="submit">ログアウト</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <form method="get" action="" class="row" style="align-items:center;">
    <label style="margin:0;">月</label>
    <input type="month" name="ym" value="<?=h($ym)?>" style="max-width:220px;">
    <button class="btn secondary" type="submit">表示</button>
    <div style="margin-left:auto;font-weight:800;">合計：¥<?=number_format((int)$sum)?></div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <table>
    <thead>
      <tr>
        <th>日付</th><th>カテゴリ</th><th>品目</th><th>金額</th><th>操作</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">まだデータがありません。</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?=h($r['spent_on'])?></td>
          <td><?=h($r['category'])?></td>
          <td><?=h($r['title'])?></td>
          <td>¥<?=number_format((int)$r['amount'])?></td>
          <td>
            <a href="?page=edit&id=<?= (int)$r['id'] ?>">編集</a>
            &nbsp;|&nbsp;
            <form method="post" action="?page=delete" style="display:inline;">
              <input type="hidden" name="csrf" value="<?=h($csrf)?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="linkbtn">削除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
layout_end();
