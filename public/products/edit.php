<?php
// public/products/edit.php
// Edit an existing product (owner-scoped). Ensures SKU uniqueness per owner.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid product id'); }

// Ensure this row belongs to current owner
ensure_owner_scope($pdo, 'products', $id, 'owner_id', 'id');

// Load product
$st = $pdo->prepare("SELECT id, owner_id, sku, name, description, unit, price, tax_rate, status FROM products WHERE id=? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Product not found'); }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name        = trim($_POST['name'] ?? '');
    $sku         = trim($_POST['sku'] ?? '');
    $unit        = trim($_POST['unit'] ?? 'pcs');
    $price       = (float)($_POST['price'] ?? 0);
    $tax_rate    = (float)($_POST['tax_rate'] ?? 0);
    $status      = isset($_POST['status']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');

    if ($name === '') throw new RuntimeException('Product name is required.');
    if ($price < 0) throw new RuntimeException('Price cannot be negative.');
    if ($tax_rate < 0) throw new RuntimeException('Tax rate cannot be negative.');

    // If SKU provided and changed, ensure uniqueness per owner
    if ($sku !== '' && $sku !== ($row['sku'] ?? '')) {
      $chk = $pdo->prepare("SELECT 1 FROM products WHERE owner_id=? AND sku=? AND id<>? LIMIT 1");
      $chk->execute([$uid, $sku, $id]);
      if ($chk->fetchColumn()) {
        throw new RuntimeException('This SKU is already used by another product.');
      }
    }

    $up = $pdo->prepare("UPDATE products
                         SET sku=?, name=?, description=?, unit=?, price=?, tax_rate=?, status=?
                         WHERE id=? AND owner_id=?");
    $up->execute([$sku, $name, $description, $unit, $price, $tax_rate, $status, $id, $uid]);

    header('Location: ' . $base . '/products/index.php'); exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
    // reload latest data
    $st->execute([$id]);
    $row = $st->fetch();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Product · <?=h($row['name'])?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:900px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:820px){.row{grid-template-columns:1fr}}
    .muted{color:#9aa}
    .code{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Edit Product</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/products/index.php" style="background:#333;">Back</a>
          <a class="btn" href="<?=h($base)?>/invoices/create.php" style="background:#333;margin-left:6px;">New Invoice</a>
        </div>
      </div>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <form method="post" novalidate>
        <label>Product Name*</label>
        <input name="name" value="<?=h($row['name'])?>" required>

        <div class="row">
          <div>
            <label>SKU (optional)</label>
            <input name="sku" value="<?=h($row['sku'])?>" placeholder="e.g. SKU-ABC123">
            <div class="muted" style="margin-top:4px;">Must be unique within your account.</div>
          </div>
          <div>
            <label>Unit</label>
            <input name="unit" value="<?=h($row['unit'])?>" placeholder="pcs / hr / kg">
          </div>
        </div>

        <div class="row" style="margin-top:8px;">
          <div>
            <label>Price</label>
            <input type="number" name="price" step="0.01" min="0" value="<?=h((float)$row['price'])?>" required>
          </div>
          <div>
            <label>Tax %</label>
            <input type="number" name="tax_rate" step="0.01" min="0" value="<?=h((float)$row['tax_rate'])?>">
          </div>
        </div>

        <label style="margin-top:8px;">Description (optional)</label>
        <textarea name="description" rows="3" placeholder="Short description of the product/service"><?=h($row['description'])?></textarea>

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
