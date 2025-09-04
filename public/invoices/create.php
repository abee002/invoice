<?php
// public/invoices/create.php
// Create a new invoice (owner-scoped), with discount + tax inclusive option.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');
$uid    = (int)($_SESSION['user']['id'] ?? 0);

$err = '';

// Load selectable customers & products for this owner
$cq = $pdo->prepare("SELECT id, customer_name FROM customers WHERE owner_id=? AND status=1 ORDER BY customer_name");
$cq->execute([$uid]);
$customers = $cq->fetchAll();

$pq = $pdo->prepare("SELECT id, name, price, tax_rate FROM products WHERE owner_id=? AND status=1 ORDER BY name");
$pq->execute([$uid]);
$products = $pq->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $customer_id    = (int)($_POST['customer_id'] ?? 0);
    $invoice_date   = $_POST['invoice_date'] ?? date('Y-m-d');
    $due_date       = $_POST['due_date'] ?? null;
    $discount       = (float)($_POST['discount_amount'] ?? 0);
    $tax_inclusive  = isset($_POST['tax_inclusive']) ? 1 : 0;
    $notes          = trim($_POST['notes'] ?? '');

    if ($customer_id <= 0) {
      throw new RuntimeException('Please select a customer.');
    }

    // Ensure customer belongs to this owner
    $cc = $pdo->prepare("SELECT 1 FROM customers WHERE id=? AND owner_id=?");
    $cc->execute([$customer_id, $uid]);
    if (!$cc->fetchColumn()) {
      throw new RuntimeException('Invalid customer.');
    }

    // Collect items
    $items = [];
    foreach (($_POST['item'] ?? []) as $i) {
      $desc  = trim($i['description'] ?? '');
      $qty   = (float)($i['qty'] ?? 0);
      $price = (float)($i['unit_price'] ?? 0);
      $rate  = (float)($i['tax_rate'] ?? 0);
      $pid   = !empty($i['product_id']) ? (int)$i['product_id'] : null;

      if ($desc !== '' && $qty > 0 && $price >= 0) {
        $items[] = [
          'product_id'  => $pid,
          'description' => $desc,
          'qty'         => $qty,
          'unit_price'  => $price,
          'tax_rate'    => $rate,
        ];
      }
    }

    if (count($items) === 0) {
      throw new RuntimeException('Please add at least one line item.');
    }

    // Totals
    $totals = calc_invoice_totals($items, $discount, (bool)$tax_inclusive);

    // Generate unique invoice number for this owner
    $invoice_no = generate_invoice_no($pdo, $uid);

    // Save
    $pdo->beginTransaction();

    $ins = $pdo->prepare("
      INSERT INTO invoices
        (owner_id, invoice_no, customer_id, invoice_date, due_date, discount_amount, tax_inclusive, notes,
         status, status_changed_at, sub_total, tax_total, grand_total, amount_paid, balance_due)
      VALUES
        (?,?,?,?,?,?,?,?, 'pending', NOW(), ?,?,?, 0, ?)
    ");
    $ins->execute([
      $uid, $invoice_no, $customer_id, $invoice_date, $due_date, $discount, $tax_inclusive, $notes,
      $totals['sub_total'], $totals['tax_total'], $totals['grand_total'], $totals['grand_total']
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare("
      INSERT INTO invoice_items
        (invoice_id, product_id, description, qty, unit_price, tax_rate, line_subtotal, line_tax, line_total)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");

    foreach ($items as $it) {
      // compute per-line breakdown using helper for a single line
      $singleTotals = calc_invoice_totals([[
        'qty' => $it['qty'],
        'unit_price' => $it['unit_price'],
        'tax_rate' => $it['tax_rate'],
      ]], 0.0, (bool)$tax_inclusive);

      $insItem->execute([
        $invoice_id,
        $it['product_id'],
        $it['description'],
        $it['qty'],
        $it['unit_price'],
        $it['tax_rate'],
        $singleTotals['sub_total'],
        $singleTotals['tax_total'],
        $singleTotals['grand_total'],
      ]);
    }

    $pdo->commit();

    header('Location: ' . $base . '/invoices/view.php?id=' . $invoice_id);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>New Invoice</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:1100px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    @media(max-width:900px){.row{grid-template-columns:1fr}}
    .lines{width:100%;border-collapse:collapse;margin-top:10px;background:#151515;border:1px solid #222}
    .lines th,.lines td{padding:8px;border-bottom:1px solid #222;vertical-align:top}
    .lines th{background:#0f172a;text-align:left}
    .r{text-align:right}
    .muted{color:#9aa}
    .small{font-size:12px;color:#aab}
    .btn-icon{padding:6px 10px}
  </style>
  <script>
    function addRow() {
      const tbody = document.getElementById('items');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>
          <select name="item[][product_id]" onchange="fillFromProduct(this)">
            <option value="">— free text —</option>
            <?php foreach ($products as $p): ?>
              <option value="<?=$p['id']?>"
                      data-name="<?=h($p['name'])?>"
                      data-price="<?=h($p['price'])?>"
                      data-tax="<?=h($p['tax_rate'])?>"><?=h($p['name'])?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td><input name="item[][description]" class="desc" placeholder="Description" required></td>
        <td><input name="item[][qty]" type="number" step="0.01" min="0.01" class="qty" required></td>
        <td><input name="item[][unit_price]" type="number" step="0.01" min="0" class="price" required></td>
        <td><input name="item[][tax_rate]" type="number" step="0.01" min="0" class="tax"></td>
        <td class="r"><button type="button" class="btn btn-icon" onclick="this.closest('tr').remove()">✕</button></td>
      `;
      tbody.appendChild(tr);
    }
    function fillFromProduct(sel) {
      const opt = sel.options[sel.selectedIndex];
      if (!opt || !opt.value) return;
      const tr = sel.closest('tr');
      tr.querySelector('.desc').value  = opt.getAttribute('data-name') || '';
      tr.querySelector('.price').value = opt.getAttribute('data-price') || '';
      tr.querySelector('.tax').value   = opt.getAttribute('data-tax') || '';
      tr.querySelector('.qty').focus();
    }
    document.addEventListener('DOMContentLoaded', () => addRow());
  </script>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h1 style="margin:0;">Create Invoice</h1>
        <div>
          <a class="btn" href="<?=h($base)?>/invoices/list.php" style="background:#333;">Invoice History</a>
          <a class="btn" href="<?=h($base)?>/index.php" style="margin-left:6px;background:#333;">Dashboard</a>
        </div>
      </div>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <form method="post" novalidate>
        <label>Customer*</label>
        <select name="customer_id" required>
          <option value="">— select —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['customer_name'])?></option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>Invoice Date*</label>
            <input type="date" name="invoice_date" value="<?=h(date('Y-m-d'))?>" required>
          </div>
          <div>
            <label>Due Date</label>
            <input type="date" name="due_date">
          </div>
          <div>
            <label>Discount Amount</label>
            <input type="number" step="0.01" min="0" name="discount_amount" value="0">
          </div>
          <div style="display:flex;align-items:end;">
            <label style="margin-top:24px;">
              <input type="checkbox" name="tax_inclusive"> Prices include tax
            </label>
          </div>
        </div>

        <h3 style="margin-top:16px;">Items</h3>
        <table class="lines">
          <thead>
            <tr>
              <th style="width:220px;">Product</th>
              <th>Description</th>
              <th style="width:120px;">Qty</th>
              <th style="width:140px;">Unit Price</th>
              <th style="width:120px;">Tax %</th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody id="items"></tbody>
        </table>
        <button type="button" class="btn" style="margin-top:10px;" onclick="addRow()">+ Add Line</button>

        <label style="margin-top:14px;">Notes (optional)</label>
        <textarea name="notes" rows="3" placeholder="Terms or remarks for this invoice"></textarea>

        <div style="margin-top:16px;">
          <button type="submit">Save Invoice</button>
        </div>
        <p class="small" style="margin-top:8px;">Totals will be computed on save. You can see the breakdown on the next page.</p>
      </form>
    </div>
  </div>
</body>
</html>
