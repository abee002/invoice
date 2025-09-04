<?php
// public/products/create.php
// Create a new product (owner-scoped). SKU is optional but unique per owner if provided.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

$err = '';

function generate_sku(): string {
  return 'SKU-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name        = trim($_POST['name'] ?? '');
    $sku         = trim($_POST['sku'] ?? '');
    $unit        = trim($_POST['unit'] ?? 'pcs');
    $price       = (float)($_POST['price'] ?? 0);
    $tax_rate    = (float)($_POST['tax_rate'] ?? 0);
    $status      = isset($_POST['status']) ? 1 : 1; // default active
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
      throw new RuntimeException('Product name is required.');
    }
    if ($price < 0) {
      throw new RuntimeException('Price cannot be negative.');
    }
    if ($tax_rate < 0) {
      throw new RuntimeException('Tax rate cannot be negative.');
    }

    // If SKU empty, generate one; else check uniqueness for this owner
    if ($sku === '') {
      // Try a few times to avoid collision
      for ($i=0; $i<5; $i++) {
        $candidate = generate_sku();
        $st = $pdo->prepare("SELECT 1 FROM products WHERE owner_id=? AND sku=? LIMIT 1");
        $st->execute([$uid, $candidate]);
        if (!$st->fetchColumn()) { $sku = $candidate; break; }
      }
      if ($sku === '') { $sku = generate_sku(); }
    } else {
      // Ensure not already used by this owner
      $st = $pdo->prepare("SELECT 1 FROM products WHERE owner_id=? AND sku=? LIMIT 1");
      $st->execute([$uid, $sku]);
      if ($st->fetchColumn()) {
        throw new RuntimeException('This SKU is already used by another product.');
      }
    }

    $ins = $pdo->prepare("INSERT INTO products (owner_id, sku, name, description, unit, price, tax_rate, status)
                          VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute([$uid, $sku, $name, $description, $unit, $price, $tax_rate, $status]);

    header('Location: ' . $base . '/products/index.php'); exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>New Product</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:900px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:820px){.row{grid-template-columns:1fr}}
    .muted{color:#9aa}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Add Product</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/products/index.php" style="background:#333;">Back</a>
          <a class="btn" href="<?=h($base)?>/invoices/create.php" style="background:#333;margin-left:6px;">New Invoice</a>
        </div>
      </div>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <form method="post" novalidate>
        <label>Product Name*</label>
        <input name="name" placeholder="e.g. Website Design" required>

        <div class="row">
          <div>
            <label>SKU (optional)</label>
            <input name="sku" placeholder="e.g. SKU-ABC123">
            <div class="muted" style="margin-top:4px;">Leave blank to auto-generate.</div>
          </div>
          <div>
            <label>Unit</label>
            <input name="unit" value="pcs" placeholder="pcs / hr / kg">
          </div>
        </div>

        <div class="row" style="margin-top:8px;">
          <div>
            <label>Price</label>
            <input type="number" name="price" step="0.01" min="0" value="0" required>
          </div>
          <div>
            <label>Tax %</label>
            <input type="number" name="tax_rate" step="0.01" min="0" value="0">
          </div>
        </div>

        <label style="margin-top:8px;">Description (optional)</label>
        <textarea name="description" rows="3" placeholder="Short description of the product/service"></textarea>

        <div style="margin-top:10px;">
          <label><input type="checkbox" name="status" checked> Active</label>
        </div>

        <div style="margin-top:16px;">
          <button type="submit">Save Product</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
