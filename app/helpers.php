<?php
// app/helpers.php
// Small utility functions used across the app (money formatting, totals, IDs, uploads).

/** HTML escape */
function h(?string $v): string {
  return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format money with 2 decimals */
function money(float $v): string {
  return number_format($v, 2, '.', ',');
}

/**
 * Calculate invoice totals.
 * $items = [
 *   ['qty'=>1.5, 'unit_price'=>100.00, 'tax_rate'=>15.0],
 *   ...
 * ]
 * $taxInclusive = true => unit_price already includes tax
 * Returns: ['sub_total','tax_total','grand_total']
 */
function calc_invoice_totals(array $items, float $discountAmount, bool $taxInclusive): array {
  $sub = 0.0; $tax = 0.0; $grand = 0.0;

  foreach ($items as $it) {
    $qty   = (float)($it['qty'] ?? 0);
    $price = (float)($it['unit_price'] ?? 0);
    $rate  = max(0.0, (float)($it['tax_rate'] ?? 0)); // percent
    if ($qty <= 0 || $price < 0) continue;

    if ($taxInclusive) {
      $line_total = $qty * $price;
      $line_sub   = $line_total / (1 + ($rate/100));
      $line_tax   = $line_total - $line_sub;
    } else {
      $line_sub   = $qty * $price;
      $line_tax   = $line_sub * ($rate/100);
      $line_total = $line_sub + $line_tax;
    }
    $sub   += $line_sub;
    $tax   += $line_tax;
    $grand += $line_total;
  }

  $grand = max(0.0, $grand - max(0.0, $discountAmount));

  return [
    'sub_total'   => round($sub, 2),
    'tax_total'   => round($tax, 2),
    'grand_total' => round($grand, 2),
  ];
}

/**
 * Generate a unique invoice number per owner.
 * Pattern: INV-YYYYMMDD-xxxxx (random suffix), checked for uniqueness.
 */
function generate_invoice_no(PDO $pdo, int $owner_id): string {
  for ($i = 0; $i < 10; $i++) {
    $candidate = 'INV-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $st = $pdo->prepare("SELECT 1 FROM invoices WHERE owner_id=? AND invoice_no=? LIMIT 1");
    $st->execute([$owner_id, $candidate]);
    if (!$st->fetchColumn()) return $candidate;
  }
  // Fallback (extremely unlikely)
  return 'INV-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 7);
}

/** Update invoice status and timestamp */
function update_invoice_status(PDO $pdo, int $invoice_id, string $newStatus): void {
  $allowed = ['pending','completed','cancelled'];
  if (!in_array($newStatus, $allowed, true)) return;
  $st = $pdo->prepare("UPDATE invoices SET status=?, status_changed_at=NOW() WHERE id=?");
  $st->execute([$newStatus, $invoice_id]);
}

/**
 * After adding a payment, recompute amount_paid & balance_due and optionally flip status.
 * Call this if you implement partial payments now/soon.
 */
function recompute_invoice_balance(PDO $pdo, int $invoice_id): void {
  $sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE invoice_id=?");
  $sum->execute([$invoice_id]);
  $paid = (float)($sum->fetch()['paid'] ?? 0);

  $get = $pdo->prepare("SELECT grand_total, status FROM invoices WHERE id=?");
  $get->execute([$invoice_id]);
  $row = $get->fetch();
  if (!$row) return;

  $grand = (float)$row['grand_total'];
  $balance = max(0.0, $grand - $paid);

  $status = $row['status'];
  if ($balance <= 0.00001) {
    $status = 'completed';
  } elseif ($paid > 0 && $status === 'pending') {
    // Keep pending until fully paid or explicitly changed; adjust if you want "part-paid"
    $status = 'pending';
  }

  $upd = $pdo->prepare("UPDATE invoices SET amount_paid=?, balance_due=?, status=?, status_changed_at=IF(status<>?, NOW(), status_changed_at) WHERE id=?");
  $upd->execute([round($paid,2), round($balance,2), $status, $status, $invoice_id]);
}

/** Build a path relative to /public (for linking in browser) */
function public_path(string $rel = ''): string {
  // filesystem: /.../public
  return realpath(__DIR__ . '/../public') . ($rel ? '/' . ltrim($rel, '/') : '');
}

/** Ensure directory exists */
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

/**
 * Save uploaded logo file into /public/assets/uploads and return relative web path.
 * Usage:
 *   $rel = save_logo_upload($_FILES['logo'], $user_id);
 *   // store $rel into user_settings.logo_path
 */
function save_logo_upload(array $file, int $user_id): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

  $tmp  = $file['tmp_name'];
  $name = $file['name'] ?? ('logo_' . $user_id);
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  // Basic whitelist
  $allowed = ['png','jpg','jpeg','webp','gif'];
  if (!in_array($ext, $allowed, true)) $ext = 'png';

  // Validate size (<= 2MB)
  if (($file['size'] ?? 0) > 2 * 1024 * 1024) return null;

  // Target dir (filesystem)
  $uploadsFs = public_path('assets/uploads');
  ensure_dir($uploadsFs);

  $fname = 'logo_' . $user_id . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
  $destFs = $uploadsFs . '/' . $fname;

  if (!@move_uploaded_file($tmp, $destFs)) return null;

  // Relative path to be used in <img src> (relative to /public)
  $rel = 'assets/uploads/' . $fname;
  return $rel;
}
