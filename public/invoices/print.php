<?php
// public/invoices/print.php
// Print-friendly invoice page (owner-scoped). Opens a clean layout for printing.

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

// Items
$it = $pdo->prepare("SELECT description, qty, unit_price, tax_rate, line_total FROM invoice_items WHERE invoice_id=? ORDER BY id ASC");
$it->execute([$id]);
$items = $it->fetchAll();

// Seller details
$sq = $pdo->prepare("SELECT display_name, address, phone, logo_path FROM user_settings WHERE user_id=?");
$sq->execute([$uid]);
$settings = $sq->fetch() ?: ['display_name'=>'','address'=>'','phone'=>'','logo_path'=>''];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Print · <?=h($invoice['invoice_no'])?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    /* Minimal light print styles (independent from app.css) */
    :root{ --text:#111; --muted:#555; --border:#ddd; --accent:#0f172a; }
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;color:var(--text);background:#fff}
    .page{max-width:800px;margin:20px auto;padding:0 16px}
    .toolbar{display:flex;justify-content:space-between;align-items:center;margin:10px 0}
    .toolbar .btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none}
    .invoice{border:1px solid var(--border);border-radius:12px;padding:20px}
    .top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
    .brand{display:flex;gap:12px;align-items:center}
    .brand img{width:56px;height:56px;object-fit:cover;border-radius:10px;border:1px solid #eee}
    h1{margin:0;font-size:20px}
    .muted{color:var(--muted)}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px}
    @media(max-width:700px){.cols{grid-template-columns:1fr}}
    .box{border:1px solid var(--border);border-radius:10px;padding:12px}
    .lines{width:100%;border-collapse:collapse;margin-top:14px}
    .lines th,.lines td{border-bottom:1px solid var(--border);padding:10px;vertical-align:top}
    .lines th{background:#f8fafc;text-align:left}
    .r{text-align:right}
    .totals{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px}
    @media(max-width:700px){.totals{grid-template-columns:1fr}}
    .note{min-height:80px;white-space:pre-wrap}
    .print-hint{font-size:12px;color:#666;margin-top:6px}
    @media print {
      .toolbar{display:none}
      body{margin:0}
      .page{margin:0;padding:0}
      .invoice{border:0;border-radius:0}
      a{color:inherit;text-decoration:none}
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="toolbar">
      <div><a class="btn" href="<?=h($base)?>/invoices/view.php?id=<?=$invoice['id']?>">Back</a></div>
      <div>
        <button class="btn" onclick="window.print()">Print</button>
      </div>
    </div>

    <div class="invoice">
      <div class="top">
        <div class="brand">
          <?php if (!empty($settings['logo_path'])): ?>
            <img src="<?=h($base . '/' . ltrim($settings['logo_path'],'/'))?>" alt="Logo">
          <?php endif; ?>
          <div>
            <h1>Invoice <?=h($invoice['invoice_no'])?></h1>
            <div class="muted"><?=h($settings['display_name'] ?: ($config['app']['company_name'] ?? ''))?></div>
            <?php if ($settings['address']): ?>
              <div class="muted" style="white-space:pre-wrap;"><?=h($settings['address'])?></div>
            <?php endif; ?>
            <?php if ($settings['phone']): ?>
              <div class="muted">Phone: <?=h($settings['phone'])?></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="text-align:right">
          <div><b>Date:</b> <?=h($invoice['invoice_date'])?></div>
          <div><b>Due:</b> <?=h($invoice['due_date'] ?: '-')?></div>
          <div><b>Status:</b> <?=h(ucfirst($invoice['status']))?></div>
        </div>
      </div>

      <div class="cols">
        <div class="box">
          <div><b>Bill To</b></div>
          <div><?=h($invoice['customer_name'])?></div>
          <?php if ($invoice['customer_address']): ?>
            <div class="muted" style="white-space:pre-wrap;margin-top:6px;"><?=h($invoice['customer_address'])?></div>
          <?php endif; ?>
          <div class="muted" style="margin-top:6px;">
            <?php if ($invoice['customer_phone']): ?>📞 <?=h($invoice['customer_phone'])?> &nbsp;<?php endif; ?>
            <?php if ($invoice['customer_email']): ?>✉️ <?=h($invoice['customer_email'])?><?php endif; ?>
          </div>
        </div>
        <div class="box">
          <div><b>Summary</b></div>
          <div class="muted">Currency: <?=h($config['app']['currency'] ?? 'LKR')?></div>
          <div class="muted">Created: <?=h($invoice['created_at'])?></div>
          <div class="muted">Status changed: <?=h($invoice['status_changed_at'])?></div>
        </div>
      </div>

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

      <div class="totals">
        <div class="box">
          <div><b>Notes</b></div>
          <div class="note"><?= nl2br(h($invoice['notes'])) ?: '<span class="muted">—</span>' ?></div>
        </div>
        <div class="box">
          <div style="display:grid;grid-template-columns:1fr auto;gap:6px;">
            <div>Sub Total</div>        <div class="r"><b><?=money((float)$invoice['sub_total'])?></b></div>
            <div>Tax Total</div>        <div class="r"><b><?=money((float)$invoice['tax_total'])?></b></div>
            <div>Discount</div>         <div class="r"><b><?=money((float)$invoice['discount_amount'])?></b></div>
            <div>Grand Total</div>      <div class="r"><b><?=money((float)$invoice['grand_total'])?></b></div>
            <div>Amount Paid</div>      <div class="r"><b><?=money((float)$invoice['amount_paid'])?></b></div>
            <div>Balance Due</div>      <div class="r"><b><?=money((float)$invoice['balance_due'])?></b></div>
          </div>
          <div class="print-hint">Thank you for your business!</div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
