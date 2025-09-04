<?php
// public/payments/list.php
// Owner-scoped list of payments with simple filters and totals.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

// --- Filters ---
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
$q    = trim($_GET['q'] ?? ''); // search method/ref/invoice_no/customer

$where = ['i.owner_id = ?'];
$params = [$uid];

if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
  $where[] = 'p.payment_date >= ?';
  $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  $where[] = 'p.payment_date <= ?';
  $params[] = $to;
}
if ($q !== '') {
  $where[] = '(p.method LIKE ? OR p.reference_no LIKE ? OR i.invoice_no LIKE ? OR c.customer_name LIKE ?)';
  $kw = '%' . $q . '%';
  $params[] = $kw; $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
$whereSql = implode(' AND ', $where);

// Fetch latest 200 payments
$sql = "
  SELECT p.id, p.payment_date, p.method, p.reference_no, p.amount,
         i.id AS invoice_id, i.invoice_no,
         c.customer_name
  FROM payments p
  JOIN invoices i ON i.id = p.invoice_id
  JOIN customers c ON c.id = i.customer_id
  WHERE {$whereSql}
  ORDER BY p.payment_date DESC, p.id DESC
  LIMIT 200
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Total for the filtered view
$sumSql = "
  SELECT COALESCE(SUM(p.amount),0) AS total_amount
  FROM payments p
  JOIN invoices i ON i.id = p.invoice_id
  WHERE i.owner_id = ?
    " . ($from ? " AND p.payment_date >= ?" : "") . "
    " . ($to   ? " AND p.payment_date <= ?" : "") . "
    " . ($q    ? " AND (p.method LIKE ? OR p.reference_no LIKE ? OR i.invoice_no LIKE ?)" : "") . "
";
$sumParams = [$uid];
if ($from) $sumParams[] = $from;
if ($to)   $sumParams[] = $to;
if ($q) { $kw = '%'.$q.'%'; array_push($sumParams, $kw, $kw, $kw); }

$totQ = $pdo->prepare($sumSql);
$totQ->execute($sumParams);
$totals = $totQ->fetch();
$totalAmount = (float)($totals['total_amount'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Payments</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .top{display:flex;justify-content:space-between;align-items:center;margin:8px 0 12px}
    .filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
    @media(max-width:900px){.filters{grid-template-columns:1fr 1fr}}
    @media(max-width:560px){.filters{grid-template-columns:1fr}}
    .tbl{width:100%;border-collapse:collapse;background:#151515;border:1px solid #222;margin-top:12px}
    th,td{padding:10px;border-bottom:1px solid #222}
    th{background:#0f172a;text-align:left}
    .r{text-align:right}
    .muted{color:#9aa}
    .sum{background:#111;border:1px solid #222;border-radius:10px;padding:10px;margin-top:12px;display:flex;justify-content:space-between;align-items:center}
  </style>
</head>
<body>
  <div class="container">
    <div class="top">
      <h1 style="margin:0;">Payments</h1>
      <div>
        <a class="btn" href="<?=h($base)?>/invoices/list.php" style="background:#333;">Invoices</a>
        <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;margin-left:6px;">Dashboard</a>
      </div>
    </div>

    <form class="filters" method="get" action="">
      <div>
        <label>From</label>
        <input type="date" name="from" value="<?=h($from)?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="to" value="<?=h($to)?>">
      </div>
      <div>
        <label>Search</label>
        <input name="q" value="<?=h($q)?>" placeholder="Method / Ref / Invoice / Customer">
      </div>
      <div style="display:flex;align-items:end;gap:8px">
        <button type="submit">Apply</button>
        <a class="btn" href="<?=h($base)?>/payments/list.php" style="background:#333;">Clear</a>
      </div>
    </form>

    <div class="sum">
      <div><b>Total in view:</b> <?=money($totalAmount)?></div>
      <div class="muted">Showing up to <?=count($rows)?> payments (max 200)</div>
    </div>

    <?php if (!$rows): ?>
      <p class="muted" style="margin-top:12px;">No payments found for the selected filters.</p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:140px;">Date</th>
            <th style="width:160px;">Invoice</th>
            <th>Customer</th>
            <th style="width:160px;">Method</th>
            <th>Reference</th>
            <th class="r" style="width:160px;">Amount</th>
            <th class="r" style="width:100px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?=h($r['payment_date'])?></td>
              <td><?=h($r['invoice_no'])?></td>
              <td><?=h($r['customer_name'])?></td>
              <td><?=h($r['method'])?></td>
              <td><?=h($r['reference_no'])?></td>
              <td class="r"><?=money((float)$r['amount'])?></td>
              <td class="r">
                <a href="<?=h($base)?>/invoices/view.php?id=<?=$r['invoice_id']?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
