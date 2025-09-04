<?php
// public/products/index.php
// Owner-scoped product list (unique sku per owner)

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);

// Filters/search
$q = trim($_GET['q'] ?? '');
$where = ['owner_id = ?'];
$params = [$uid];
if ($q !== '') {
  $where[] = '(name LIKE ? OR sku LIKE ?)';
  $kw = '%' . $q . '%';
  $params[] = $kw; $params[] = $kw;
}
$whereSql = implode(' AND ', $where);

// Fetch products
$st = $pdo->prepare("
  SELECT id, sku, name, unit, price, tax_rate, status, created_at
  FROM products
  WHERE {$whereSql}
  ORDER BY id DESC
");
$st->execute($params);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Products</title>
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
    .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace}
  </style>
</head>
<body>
  <div class="container">
    <div class="top">
      <h1 style="margin:0;">Products</h1>
      <div>
        <a class="btn" href="<?=h($base)?>/products/create.php">+ New Product</a>
        <a class="btn" href="<?=h($base)?>/invoices/create.php" style="background:#333;margin-left:6px;">New Invoice</a>
        <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;margin-left:6px;">Dashboard</a>
      </div>
    </div>

    <form class="search" method="get" action="">
      <input name="q" value="<?=h($q)?>" placeholder="Search by name or SKU">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?>
        <a class="btn" href="<?=h($base)?>/products/index.php" style="background:#333;">Clear</a>
      <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <p class="muted" style="margin-top:12px;">No products yet. Add your first product.</p>
    <?php else: ?>
      <table class="tbl" style="margin-top:12px;">
        <thead>
          <tr>
            <th style="width:140px;">SKU</th>
            <th>Name</th>
            <th style="width:90px;">Unit</th>
            <th class="r" style="width:140px;">Price</th>
            <th class="r" style="width:100px;">Tax %</th>
            <th style="width:100px;">Status</th>
            <th style="width:180px;">Created</th>
            <th class="r" style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="code"><?=h($r['sku'])?></td>
              <td><?=h($r['name'])?></td>
              <td><?=h($r['unit'])?></td>
              <td class="r"><?=money((float)$r['price'])?></td>
              <td class="r"><?=number_format((float)$r['tax_rate'], 2)?></td>
              <td>
                <?php if ((int)$r['status'] === 1): ?>
                  <span class="badge b-on">Active</span>
                <?php else: ?>
                  <span class="badge b-off">Inactive</span>
                <?php endif; ?>
              </td>
              <td><?=h($r['created_at'])?></td>
              <td class="r">
                <a href="<?=h($base)?>/products/edit.php?id=<?=$r['id']?>">Edit</a>
                |
                <a href="<?=h($base)?>/products/delete.php?id=<?=$r['id']?>" onclick="return confirm('Delete this product? This cannot be undone.')">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
