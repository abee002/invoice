<?php
// public/payments/create.php
// Add a payment to an invoice (owner-scoped). Recomputes balance & status.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

$invoice_id = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
if ($invoice_id <= 0) { http_response_code(400); exit('Invalid invoice'); }

// Load invoice ensuring owner scope
$invQ = $pdo->prepare("SELECT id, owner_id, invoice_no, grand_total, amount_paid, balance_due, status 
                       FROM invoices WHERE id=? AND owner_id=? LIMIT 1");
$invQ->execute([$invoice_id, $uid]);
$inv = $invQ->fetch();
if (!$inv) { http_response_code(404); exit('Invoice not found'); }

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($inv['status'] === 'cancelled') {
      throw new RuntimeException('Cannot add a payment to a cancelled invoice.');
    }

    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $date   = $_POST['payment_date'] ?? date('Y-m-d');
    $ref    = trim($_POST['reference_no'] ?? '');

    if ($amount <= 0) {
      throw new RuntimeException('Enter a valid amount greater than 0.');
    }

    // Optional guard: do not allow over-payment by more than 0.01
    $maxAllowed = max(0.0, (float)$inv['balance_due']);
    if ($maxAllowed > 0 && $amount - $maxAllowed > 0.01) {
      throw new RuntimeException('Amount exceeds the remaining balance.');
    }

    if ($method === '') {
      throw new RuntimeException('Please specify a payment method (Cash / Bank / Online).');
    }

    // Save and recompute in a transaction
    $pdo->beginTransaction();

    $ins = $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, method, reference_no, amount) 
                          VALUES (?,?,?,?,?)");
    $ins->execute([$invoice_id, $date, $method, $ref, $amount]);

    // Recompute invoice totals (amount_paid, balance_due, and maybe status)
    recompute_invoice_balance($pdo, $invoice_id);

    $pdo->commit();

    header('Location: ' . $base . '/invoices/view.php?id=' . $invoice_id);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
    // Reload fresh invoice numbers after a failed attempt
    $invQ->execute([$invoice_id, $uid]);
    $inv = $invQ->fetch();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add Payment · <?=h($inv['invoice_no'])?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:720px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:720px){.row{grid-template-columns:1fr}}
    .muted{color:#9aa}
    .box{background:#111;border:1px solid #222;border-radius:10px;padding:10px;margin-top:10px}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Add Payment</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/invoices/view.php?id=<?=$invoice_id?>" style="background:#333;">Back to Invoice</a>
          <a class="btn" href="<?=h($base)?>/invoices/list.php" style="background:#333;margin-left:6px;">Invoice History</a>
        </div>
      </div>

      <div class="box">
        <div><b>Invoice:</b> <?=h($inv['invoice_no'])?></div>
        <div class="muted" style="margin-top:6px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">
          <div>Grand Total: <b><?=money((float)$inv['grand_total'])?></b></div>
          <div>Paid: <b><?=money((float)$inv['amount_paid'])?></b></div>
          <div>Balance Due: <b><?=money((float)$inv['balance_due'])?></b></div>
          <div>Status: <b><?=h(ucfirst($inv['status']))?></b></div>
        </div>
      </div>

      <?php if ($err): ?><div class="alert" style="margin-top:10px;"><?=h($err)?></div><?php endif; ?>

      <?php if ((float)$inv['balance_due'] <= 0): ?>
        <div class="box" style="margin-top:10px;">
          This invoice is fully paid. No additional payment is required.
        </div>
      <?php else: ?>
        <form method="post" style="margin-top:12px;" novalidate>
          <input type="hidden" name="invoice_id" value="<?=$invoice_id?>">
          <div class="row">
            <div>
              <label>Payment Date</label>
              <input type="date" name="payment_date" value="<?=h(date('Y-m-d'))?>" required>
            </div>
            <div>
              <label>Method</label>
              <input name="method" placeholder="Cash / Bank / Online" required>
            </div>
          </div>

          <label style="margin-top:10px;">Reference No</label>
          <input name="reference_no" placeholder="Slip # / Txn ID (optional)">

          <label style="margin-top:10px;">Amount</label>
          <input type="number" name="amount" step="0.01" min="0.01" max="<?=h((float)$inv['balance_due'])?>" value="<?=h((float)$inv['balance_due'])?>" required>

          <div style="margin-top:16px;">
            <button type="submit">Save Payment</button>
          </div>
          <p class="muted" style="margin-top:6px;">Max allowed is the current balance due.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
