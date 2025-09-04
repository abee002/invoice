<?php
// public/customers/delete.php
// Delete a customer (owner-scoped). Will block if invoices exist for this customer.

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login();
require_onboarded($pdo);

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid ID'); }

// Ensure this row belongs to the current owner
ensure_owner_scope($pdo, 'customers', $id, 'owner_id', 'id');

// Check if any invoices reference this customer
$chk = $pdo->prepare("SELECT COUNT(*) AS c FROM invoices WHERE customer_id=?");
$chk->execute([$id]);
$hasInvoices = (int)($chk->fetch()['c'] ?? 0) > 0;

if ($hasInvoices) {
  // Show a friendly message (FK is RESTRICT anyway)
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Cannot delete customer</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
    <style>.wrap{max-width:720px;margin:40px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}</style>
  </head>
  <body>
    <div class="container">
      <div class="wrap">
        <h1 style="margin:0 0 10px;">Delete blocked</h1>
        <div class="alert">
          This customer has one or more invoices linked and cannot be deleted.
        </div>
        <p>Tip: If you don’t want to use this customer anymore, mark them as <b>Inactive</b> from the edit page.</p>
        <p style="margin-top:14px;">
          <a class="btn" href="<?=h($base)?>/customers/edit.php?id=<?=$id?>" style="background:#333;">Edit customer</a>
          <a class="btn" href="<?=h($base)?>/customers/index.php" style="margin-left:6px;">Back to list</a>
        </p>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// Try delete
try {
  $del = $pdo->prepare("DELETE FROM customers WHERE id=? AND owner_id=? LIMIT 1");
  $del->execute([$id, $uid]);
  header('Location: ' . $base . '/customers/index.php');
  exit;
} catch (Throwable $e) {
  // Fallback UI if FK stops it
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Delete failed</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
    <style>.wrap{max-width:720px;margin:40px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}</style>
  </head>
  <body>
    <div class="container">
      <div class="wrap">
        <h1 style="margin:0 0 10px;">Delete failed</h1>
        <div class="alert"><?=h($e->getMessage())?></div>
        <p>It looks like this record is linked elsewhere. Try setting the customer to <b>Inactive</b> instead.</p>
        <p style="margin-top:14px;">
          <a class="btn" href="<?=h($base)?>/customers/edit.php?id=<?=$id?>" style="background:#333;">Edit customer</a>
          <a class="btn" href="<?=h($base)?>/customers/index.php" style="margin-left:6px;">Back to list</a>
        </p>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}
