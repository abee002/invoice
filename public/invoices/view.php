<?php
// public/invoices/view.php
// View a single invoice (owner-scoped), show lines, totals, history fields,
// and allow quick status changes (completed / pending / cancelled).

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid invoice id'); }

// Fetch invoice (ensure owner scope)
$inv = $pdo->prepare("
  SELECT i.*, c.customer_name, c.address AS customer_address, c.email AS customer_email, c.phone AS customer_phone
  FROM invoices i
  JOIN customers c ON c.id = i.customer_id
  WHERE i.id=? AND i.owner_id=? LIMIT 1
");
$inv->execute([$id, $uid]);
$invoice = $inv->fetch();
if (!$invoice) { http_response_code(404); exit('Invoice not found'); }

// Handle status changes (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
  $newStatus = $_POST['set_status'];
  update_invoice_status($pdo, $id, $newStatus);
  // Reload fresh invoice row
  $inv->execute([$id, $uid]);
  $invoice = $inv->fetch();
}

// Load items
$it = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id ASC");
$it->execute([$id]);
$items = $it->fetchAll();

// Load payments (if you start using the payments table)
$payQ = $pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date ASC, id ASC");
$payQ->execute([$id]);
$payments = $payQ->fetchAll();

// Company/user header details
$sq = $pdo->prepare("SELECT display_name, address, phone, logo_path FROM user_settings WHERE user_id=?");
$sq->execute([$uid]);
$settings = $sq->fetch() ?: ['display_name'=>'','address'=>'','phone'=>'','logo_path'=>''];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?=h($invoice['invoice_no'])?> · Invoice</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .top{display:flex;justify-content:space-between;align-items:center;margin:8px 0 12px}
    .brand{display:flex;align-items:center;gap:12px}
    .brand img{width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid #333;background:#111}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:800px){.cols{grid-template-columns:1fr}}
    .card{background:#151515;border:1px solid #222;border-radius:12px;padding:14px}
    .muted{color:#9aa}
    .lines{width:100%;border-collapse:collapse;margin-top:8px;background:#151515;border:1px solid #222}
    .lines th,.lines td{padding:8px;border-bottom:1px solid #222;vertical-align:top}
    .lines th{background:#0f172a;text-align:left}
    .r{text-align:right}
    .status{padding:2px 8px;border-radius:999px;font-size:12px;display:inline-block}
    .s-pending{background:#3b3b0a;color:#fff}
    .s-completed{background:#064e3b;color:#b8f3ce}
    .s-cancelled{background:#4a0a0a;color:#ffdada}
    .toolbar a.btn,.toolbar form button{margin-right:6px}
    .totals{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:700px){.totals{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="container">
    <div class="top">
      <div class="brand">
        <?php if (!empty($settings['logo_path'])): ?>
          <img src="<?=h($base . '/' . ltrim($settings['logo_path'],'/'))?>" alt="Logo">
        <?php endif; ?>
        <div>
          <h1 style="margin:0;">Invoice <?=h($invoice['invoice_no'])?></h1>
          <div class="muted"><?=h($settings['display_name'] ?: ($config['app']['company_name'] ?? ''))?></div>
        </div>
      </div>
      <div class="toolbar">
        <a class="btn" href="<?=h($base)?>/invoices/list.php" style="background:#333;">Back</a>
        <a class="btn" href="<?=h($base)?>/invoices/print.php?id=<?=$invoice['id']?>" target="_blank" rel="noopener">Print</a>
        <a class="btn" href="<?=h($base)?>/invoices/delete.php?id=<?=$invoice['id']?>" style="background:#333;" onclick="return confirm('Delete this invoice? This cannot be undone.')">Delete</a>
        <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;">Dashboard</a>
      </div>
    </div>

    <div class="cols">
      <div class="card">
        <h3 style="margin:0 0 8px;">Bill To</h3>
        <div><b><?=h($invoice['customer_name'])?></b></div>
        <?php if ($invoice['customer_address']): ?><pre style="margin:6px 0"><?=h($invoice['customer_address'])?></pre><?php endif; ?>
        <div class="muted">
          <?php if ($invoice['customer_phone']): ?>📞 <?=h($invoice['customer_phone'])?> &nbsp;<?php endif; ?>
          <?php if ($invoice['customer_email']): ?>✉️ <?=h($invoice['customer_email'])?><?php endif; ?>
        </div>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px;">Invoice Info</h3>
        <div>Date: <b><?=h($invoice['invoice_date'])?></b></div>
        <div>Due: <b><?=h($invoice['due_date'] ?: '-')?></b></div>
        <div>Total: <b><?=money((float)$invoice['grand_total'])?></b></div>
        <div>Status:
          <?php
            $s = strtolower($invoice['status']);
            $cls = $s === 'completed' ? 's-completed' : ($s === 'cancelled' ? 's-cancelled' : 's-pending');
          ?>
          <span class="status <?=$cls?>"><?=h($invoice['status'])?></span>
        </div>
        <div class="muted" style="margin-top:6px;">Created: <?=h($invoice['created_at'])?></div>
        <div class="muted">Status changed: <?=h($invoice['status_changed_at'])?></div>

        <form method="post" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn" name="set_status" value="pending"    type="submit">Mark Pending</button>
          <button class="btn" name="set_status" value="completed"  type="submit">Mark Completed</button>
          <button class="btn" name="set_status" value="cancelled"  type="submit" style="background:#7f1d1d">Mark Cancelled</button>
        </form>
      </div>
    </div>

    <h3 style="margin-top:16px;">Items</h3>
    <table class="lines">
      <thead>
        <tr>
          <th>Description</th>
          <th class="r" style="width:120px;">Qty</th>
          <th class="r" style="width:140px;">Unit Price</th>
          <th class="r" style="width:120px;">Tax %</th>
          <th class="r" style="width:160px;">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $ln): ?>
          <tr>
            <td><?=h($ln['description'])?></td>
            <td class="r"><?=money((float)$ln['qty'])?></td>
            <td class="r"><?=money((float)$ln['unit_price'])?></td>
            <td class="r"><?=number_format((float)$ln['tax_rate'], 2)?></td>
            <td class="r"><?=money((float)$ln['line_total'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals" style="margin-top:12px;">
      <div class="card">
        <h3 style="margin:0 0 8px;">Notes</h3>
        <div class="muted"><?= nl2br(h($invoice['notes'])) ?: '—' ?></div>
      </div>
      <div class="card">
        <div style="display:grid;grid-template-columns:1fr auto;gap:6px;">
          <div>Sub Total</div>        <div class="r"><b><?=money((float)$invoice['sub_total'])?></b></div>
          <div>Tax Total</div>        <div class="r"><b><?=money((float)$invoice['tax_total'])?></b></div>
          <div>Discount</div>         <div class="r"><b><?=money((float)$invoice['discount_amount'])?></b></div>
          <div>Grand Total</div>      <div class="r"><b><?=money((float)$invoice['grand_total'])?></b></div>
          <div>Amount Paid</div>      <div class="r"><b><?=money((float)$invoice['amount_paid'])?></b></div>
          <div>Balance Due</div>      <div class="r"><b><?=money((float)$invoice['balance_due'])?></b></div>
        </div>
        <div style="margin-top:10px;">
          <a class="btn" href="<?=h($base)?>/payments/create.php?invoice_id=<?=$invoice['id']?>">+ Add Payment</a>
        </div>
      </div>
    </div>

    <h3 style="margin-top:16px;">Payments</h3>
    <?php if (!$payments): ?>
      <p class="muted">No payments recorded.</p>
    <?php else: ?>
      <table class="lines">
        <thead>
          <tr>
            <th style="width:140px;">Date</th>
            <th style="width:160px;">Method</th>
            <th>Reference</th>
            <th class="r" style="width:160px;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?=h($p['payment_date'])?></td>
              <td><?=h($p['method'])?></td>
              <td><?=h($p['reference_no'])?></td>
              <td class="r"><?=money((float)$p['amount'])?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
