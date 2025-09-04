<?php
// public/invoices/list.php
// Invoice history (owner-scoped) with filters by status and simple search.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

// --- Filters ---
$allowedStatuses = ['all','pending','completed','cancelled'];
$status = strtolower(trim($_GET['status'] ?? 'all'));
if (!in_array($status, $allowedStatuses, true)) $status = 'all';

$q = trim($_GET['q'] ?? ''); // search by invoice_no or customer_name

$params = [$uid];
$where  = ['i.owner_id = ?'];

if ($status !== 'all') {
  $where[] = 'i.status = ?';
  $params[] = $status;
}

if ($q !== '') {
  $where[] = '(i.invoice_no LIKE ? OR c.customer_name LIKE ?)';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);

// Fetch (limit 100 most recent)
$sql = "
  SELECT i.id, i.invoice_no, i.grand_total, i.status, i.created_at, i.status_changed_at,
         c.customer_name
  FROM invoices i
  JOIN customers c ON c.id = i.customer_id
  WHERE {$whereSql}
  ORDER BY i.created_at DESC
  LIMIT 100
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Quick counts per status for tabs
$cntSql = "
  SELECT 
    SUM(i.status='pending')   AS pending_count,
    SUM(i.status='completed') AS completed_count,
    SUM(i.status='cancelled') AS cancelled_count,
    COUNT(*)                  AS all_count
  FROM invoices i
  WHERE i.owner_id = ?
";
$cnt = $pdo->prepare($cntSql);
$cnt->execute([$uid]);
$counts = $cnt->fetch() ?: ['pending_count'=>0,'completed_count'=>0,'cancelled_count'=>0,'all_count'=>0];

function tabUrl($base, $status, $q) {
  $u = $base . '/invoices/list.php?status=' . urlencode($status);
  if ($q !== '') $u .= '&q=' . urlencode($q);
  return $u;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice History</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .top{display:flex;justify-content:space-between;align-items:center;margin:8px 0 12px}
    .tabs{display:flex;gap:8px;flex-wrap:wrap}
    .tab{padding:6px 10px;border-radius:999px;border:1px solid #333;background:#1b1b1b}
    .tab.active{background:#2563eb;border-color:#2563eb;color:#fff}
    .search{display:flex;gap:8px;align-items:center}
    .tbl{width:100%;border-collapse:collapse;background:#151515;border:1px solid #222}
    th, td{padding:10px;border-bottom:1px solid #222}
    th{background:#0f172a;text-align:left}
    .r{text-align:right}
    .status{padding:2px 8px;border-radius:999px;font-size:12px;display:inline-block}
    .s-pending{background:#3b3b0a;color:#fff}
    .s-completed{background:#064e3b;color:#b8f3ce}
    .s-cancelled{background:#4a0a0a;color:#ffdada}
    .muted{color:#9aa}
  </style>
</head>
<body>
  <div class="container">
    <div class="top">
      <h1 style="margin:0;">Invoice History</h1>
      <div>
        <a class="btn" href="<?=h($base)?>/invoices/create.php">+ New Invoice</a>
        <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;margin-left:6px;">Dashboard</a>
      </div>
    </div>

    <div class="tabs" style="margin-bottom:10px;">
      <a class="tab <?=($status==='all'?'active':'')?>" href="<?=h(tabUrl($base,'all',$q))?>">All (<?= (int)$counts['all_count']?>)</a>
      <a class="tab <?=($status==='pending'?'active':'')?>" href="<?=h(tabUrl($base,'pending',$q))?>">Pending (<?= (int)$counts['pending_count']?>)</a>
      <a class="tab <?=($status==='completed'?'active':'')?>" href="<?=h(tabUrl($base,'completed',$q))?>">Completed (<?= (int)$counts['completed_count']?>)</a>
      <a class="tab <?=($status==='cancelled'?'active':'')?>" href="<?=h(tabUrl($base,'cancelled',$q))?>">Cancelled (<?= (int)$counts['cancelled_count']?>)</a>
    </div>

    <form class="search" method="get" action="">
      <input type="hidden" name="status" value="<?=h($status)?>">
      <input name="q" value="<?=h($q)?>" placeholder="Search by invoice no or customer">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?>
        <a class="btn" href="<?=h($base)?>/invoices/list.php?status=<?=h(urlencode($status))?>" style="background:#333;">Clear</a>
      <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <p class="muted" style="margin-top:12px;">No invoices found.</p>
    <?php else: ?>
      <table class="tbl" style="margin-top:12px;">
        <thead>
          <tr>
            <th style="width:160px;">Invoice #</th>
            <th>Customer</th>
            <th class="r" style="width:140px;">Total</th>
            <th style="width:120px;">Status</th>
            <th style="width:180px;">Created</th>
            <th style="width:200px;">Status Changed</th>
            <th class="r" style="width:100px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $s = strtolower($r['status']);
              $cls = $s === 'completed' ? 's-completed' : ($s === 'cancelled' ? 's-cancelled' : 's-pending');
            ?>
            <tr>
              <td><?=h($r['invoice_no'])?></td>
              <td><?=h($r['customer_name'])?></td>
              <td class="r"><?=money((float)$r['grand_total'])?></td>
              <td><span class="status <?=$cls?>"><?=h($r['status'])?></span></td>
              <td><?=h($r['created_at'])?></td>
              <td><?=h($r['status_changed_at'])?></td>
              <td class="r"><a href="<?=h($base)?>/invoices/view.php?id=<?=$r['id']?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" style="margin-top:8px;">Showing latest <?=count($rows)?> invoices (max 100). Use search to narrow down.</p>
    <?php endif; ?>
  </div>
</body>
</html>
