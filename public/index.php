<?php
// public/index.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/middleware.php';

require_login();               // must be logged in
require_onboarded($pdo);       // redirect to /settings/ until first setup done

$uid = $_SESSION['user']['id'];
$base = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

// Fetch basic profile/settings
$st = $pdo->prepare("SELECT display_name, address, phone, logo_path FROM user_settings WHERE user_id=?");
$st->execute([$uid]);
$settings = $st->fetch() ?: ['display_name'=>$_SESSION['user']['username'] ?? 'User', 'address'=>'', 'phone'=>'', 'logo_path'=>''];

// Stats
$pending = (int)$pdo->prepare("SELECT COUNT(*) AS c FROM invoices WHERE owner_id=? AND status='pending'");
$pdo->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'"); // ensure strict
$cnt = $pdo->prepare("SELECT 
  SUM(status='pending')    AS pending_count,
  SUM(status='completed')  AS completed_count,
  SUM(status='cancelled')  AS cancelled_count,
  COALESCE(SUM(CASE WHEN status='pending' THEN balance_due ELSE 0 END),0) AS pending_balance
FROM invoices WHERE owner_id=?");
$cnt->execute([$uid]);
$stats = $cnt->fetch();
if (!$stats) {
  $stats = ['pending_count'=>0,'completed_count'=>0,'cancelled_count'=>0,'pending_balance'=>0];
}

// Recent invoices (history)
$q = $pdo->prepare("SELECT i.id,i.invoice_no,i.grand_total,i.status,i.created_at,i.status_changed_at,
                           c.customer_name
                    FROM invoices i 
                    JOIN customers c ON c.id=i.customer_id
                    WHERE i.owner_id=?
                    ORDER BY i.created_at DESC
                    LIMIT 10");
$q->execute([$uid]);
$recent = $q->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard · Invoices</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=htmlspecialchars($base)?>/assets/app.css">
  <style>
    .topbar{display:flex;align-items:center;justify-content:space-between;margin:8px 0 16px}
    .brand{display:flex;align-items:center;gap:12px}
    .brand img{width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid #333;background:#111}
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .card{background:#151515;border:1px solid #222;border-radius:12px;padding:14px}
    .card h2{margin:0 0 6px;font-size:14px;color:#aaa}
    .card .big{font-size:26px;font-weight:700}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:560px){.cards{grid-template-columns:1fr}}
    .table{width:100%;border-collapse:collapse;margin-top:12px;background:#151515;border:1px solid #222}
    .table th,.table td{padding:10px;border-bottom:1px solid #222}
    .status{padding:2px 8px;border-radius:999px;font-size:12px;display:inline-block}
    .s-pending{background:#3b3b0a;color:#fff}
    .s-completed{background:#064e3b;color:#b8f3ce}
    .s-cancelled{background:#4a0a0a;color:#ffdada}
    .nav a{margin-right:12px}
  </style>
</head>
<body>
  <div class="container">
    <div class="topbar">
      <div class="brand">
        <?php if (!empty($settings['logo_path'])): ?>
          <img src="<?=htmlspecialchars($base) . '/' . ltrim($settings['logo_path'],'/')?>" alt="Logo">
        <?php endif; ?>
        <div>
          <h1 style="margin:0;">Welcome, <?=htmlspecialchars($settings['display_name'] ?: ($_SESSION['user']['name'] ?? 'User'))?></h1>
          <small><?=htmlspecialchars($config['app']['company_name'] ?? 'Your Company')?></small>
        </div>
      </div>
      <nav class="nav">
        <a href="<?=htmlspecialchars($base)?>/settings/">Settings</a>
        <a href="<?=htmlspecialchars($base)?>/logout.php">Logout</a>
      </nav>
    </div>

    <nav style="margin-bottom:10px;">
      <a href="<?=htmlspecialchars($base)?>/customers/">Customers</a> |
      <a href="<?=htmlspecialchars($base)?>/invoices/create.php">New Invoice</a> |
      <a href="<?=htmlspecialchars($base)?>/invoices/list.php">Invoice History</a> |
      <a href="<?=htmlspecialchars($base)?>/payments/list.php">Payments</a>
    </nav>

    <div class="cards">
      <div class="card">
        <h2>Pending Invoices</h2>
        <div class="big"><?= (int)$stats['pending_count'] ?></div>
        <div><small>Pending Balance: <b><?= number_format((float)$stats['pending_balance'], 2) ?></b></small></div>
      </div>
      <div class="card">
        <h2>Completed</h2>
        <div class="big"><?= (int)$stats['completed_count'] ?></div>
      </div>
      <div class="card">
        <h2>Cancelled</h2>
        <div class="big"><?= (int)$stats['cancelled_count'] ?></div>
      </div>
      <div class="card">
        <h2>Quick Actions</h2>
        <div>
          <a class="btn" href="<?=htmlspecialchars($base)?>/invoices/create.php">+ Create Invoice</a>
          <a class="btn" href="<?=htmlspecialchars($base)?>/customers/create.php" style="margin-left:6px;">+ Add Customer</a>
        </div>
      </div>
    </div>

    <h3 style="margin-top:20px;">Recent Invoices</h3>
    <?php if (!$recent): ?>
      <p>No invoices yet. <a href="<?=htmlspecialchars($base)?>/invoices/create.php">Create your first invoice →</a></p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th class="r">Total</th>
            <th>Status</th>
            <th>Created</th>
            <th>Status Changed</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td><?=htmlspecialchars($row['invoice_no'])?></td>
              <td><?=htmlspecialchars($row['customer_name'])?></td>
              <td class="r"><?=number_format((float)$row['grand_total'], 2)?></td>
              <td>
                <?php
                  $s = strtolower($row['status']);
                  $cls = $s === 'completed' ? 's-completed' : ($s === 'cancelled' ? 's-cancelled' : 's-pending');
                ?>
                <span class="status <?=$cls?>"><?=htmlspecialchars($row['status'])?></span>
              </td>
              <td><?=htmlspecialchars($row['created_at'])?></td>
              <td><?=htmlspecialchars($row['status_changed_at'])?></td>
              <td><a href="<?=htmlspecialchars($base)?>/invoices/view.php?id=<?=$row['id']?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:10px;"><a href="<?=htmlspecialchars($base)?>/invoices/list.php">See all invoices →</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
