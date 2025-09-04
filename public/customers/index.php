<?php
// public/customers/index.php
// Owner-scoped customer list (unique customer_code per owner)

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);

// Fetch customers for this owner
$st = $pdo->prepare("
  SELECT id, customer_code, customer_name, email, phone, status, created_at
  FROM customers
  WHERE owner_id = ?
  ORDER BY id DESC
");
$st->execute([$uid]);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Customers</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .top{display:flex;justify-content:space-between;align-items:center;margin:8px 0 12px}
    .search{display:flex;gap:8px}
    .badge{padding:2px 8px;border-radius:999px;font-size:12px;display:inline-block}
    .b-on{background:#064e3b;color:#b8f3ce}
    .b-off{background:#4a0a0a;color:#ffdada}
    .tbl{width:100%;border-collapse:collapse;background:#151515;border:1px solid #222}
    th, td{padding:10px;border-bottom:1px solid #222}
    th{background:#0f172a;text-align:left}
    .r{text-align:right}
    .muted{color:#9aa}
  </style>
</head>
<body>
  <div class="container">
    <div class="top">
      <h1 style="margin:0;">Customers</h1>
      <div>
        <a class="btn" href="<?=h($base)?>/customers/create.php">+ New Customer</a>
        <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;margin-left:6px;">Dashboard</a>
      </div>
    </div>

    <?php if (!$rows): ?>
      <p>No customers yet. Create your first customer.</p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:120px;">Code</th>
            <th>Name</th>
            <th style="width:220px;">Contact</th>
            <th style="width:100px;">Status</th>
            <th style="width:180px;">Created</th>
            <th style="width:120px;" class="r">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h($r['customer_code'])?></td>
              <td><?=h($r['customer_name'])?></td>
              <td>
                <?php if ($r['phone']): ?><div>📞 <?=h($r['phone'])?></div><?php endif; ?>
                <?php if ($r['email']): ?><div class="muted">✉️ <?=h($r['email'])?></div><?php endif; ?>
              </td>
              <td>
                <?php if ((int)$r['status'] === 1): ?>
                  <span class="badge b-on">Active</span>
                <?php else: ?>
                  <span class="badge b-off">Inactive</span>
                <?php endif; ?>
              </td>
              <td><?=h($r['created_at'])?></td>
              <td class="r">
                <a href="<?=h($base)?>/customers/edit.php?id=<?=$r['id']?>">Edit</a>
                |
                <a href="<?=h($base)?>/customers/delete.php?id=<?=$r['id']?>" onclick="return confirm('Delete this customer? This cannot be undone.')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
