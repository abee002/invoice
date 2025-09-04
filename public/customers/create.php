<?php
// public/customers/create.php
// Create a new customer (unique customer_code per owner)

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);

$err = '';

function generate_unique_customer_code(PDO $pdo, int $owner_id): string {
  for ($i = 0; $i < 10; $i++) {
    $code = 'CUST-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $st = $pdo->prepare("SELECT 1 FROM customers WHERE owner_id=? AND customer_code=? LIMIT 1");
    $st->execute([$owner_id, $code]);
    if (!$st->fetchColumn()) return $code;
  }
  return 'CUST-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name    = trim($_POST['customer_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status  = isset($_POST['status']) ? 1 : 1; // default active

    if ($name === '') {
      throw new RuntimeException('Customer name is required.');
    }

    // Make a unique code per owner
    $code = generate_unique_customer_code($pdo, $uid);

    $st = $pdo->prepare("INSERT INTO customers (owner_id, customer_code, customer_name, address, email, phone, status)
                         VALUES (?,?,?,?,?,?,?)");
    $st->execute([$uid, $code, $name, $address, $email, $phone, $status]);

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
  <title>New Customer</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:800px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:720px){.row{grid-template-columns:1fr}}
    .muted{color:#9aa}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Add Customer</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/customers/index.php" style="background:#333;">Back</a>
        </div>
      </div>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <form method="post" novalidate>
        <label>Customer Name*</label>
        <input name="customer_name" required placeholder="e.g. ABC Traders">

        <div class="row">
          <div>
            <label>Email</label>
            <input type="email" name="email" placeholder="name@example.com">
          </div>
          <div>
            <label>Phone</label>
            <input name="phone" placeholder="+94 7x xxx xxxx">
          </div>
        </div>

        <label>Address</label>
        <textarea name="address" rows="3" placeholder="Street, City, Country"></textarea>

        <div style="margin-top:10px;">
          <label><input type="checkbox" name="status" checked> Active</label>
        </div>

        <div style="margin-top:16px;">
          <button type="submit">Save Customer</button>
        </div>
      </form>
      <p class="muted" style="margin-top:10px;">A unique <code>customer_code</code> will be generated automatically.</p>
    </div>
  </div>
</body>
</html>
