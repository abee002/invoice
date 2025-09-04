<?php
// public/customers/edit.php
// Edit an existing customer (owner-scoped)

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid ID'); }

// Ensure this row belongs to the current owner
ensure_owner_scope($pdo, 'customers', $id, 'owner_id', 'id');

// Load record
$st = $pdo->prepare("SELECT id, owner_id, customer_code, customer_name, address, email, phone, status FROM customers WHERE id=? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Customer not found'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name    = trim($_POST['customer_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status  = isset($_POST['status']) ? 1 : 0;

    if ($name === '') throw new RuntimeException('Customer name is required.');

    $up = $pdo->prepare("UPDATE customers 
                         SET customer_name=?, address=?, email=?, phone=?, status=? 
                         WHERE id=? AND owner_id=?");
    $up->execute([$name, $address, $email, $phone, $status, $id, $uid]);

    header('Location: ' . $base . '/customers/index.php'); exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Customer · <?=h($row['customer_code'])?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:800px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:720px){.row{grid-template-columns:1fr}}
    .muted{color:#9aa}
    .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Edit Customer</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/customers/index.php" style="background:#333;">Back</a>
        </div>
      </div>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <div class="muted" style="margin-bottom:10px;">
        Code: <span class="code"><?=h($row['customer_code'])?></span>
      </div>

      <form method="post" novalidate>
        <label>Customer Name*</label>
        <input name="customer_name" value="<?=h($row['customer_name'])?>" required>

        <div class="row">
          <div>
            <label>Email</label>
            <input type="email" name="email" value="<?=h($row['email'])?>" placeholder="name@example.com">
          </div>
          <div>
            <label>Phone</label>
            <input name="phone" value="<?=h($row['phone'])?>" placeholder="+94 7x xxx xxxx">
          </div>
        </div>

        <label>Address</label>
        <textarea name="address" rows="3" placeholder="Street, City, Country"><?=h($row['address'])?></textarea>

        <div style="margin-top:10px;">
          <label><input type="checkbox" name="status" <?=((int)$row['status']===1?'checked':'')?>> Active</label>
        </div>

        <div style="margin-top:16px;">
          <button type="submit">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
