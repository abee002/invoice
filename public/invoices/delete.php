<?php
// public/invoices/delete.php
// Delete an invoice (owner-scoped). Items and payments will cascade-delete.

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

// Ensure this invoice belongs to the logged-in owner
ensure_owner_scope($pdo, 'invoices', $id, 'owner_id', 'id');

try {
  $del = $pdo->prepare("DELETE FROM invoices WHERE id=? AND owner_id=? LIMIT 1");
  $del->execute([$id, $uid]);

  header('Location: ' . $base . '/invoices/list.php');
  exit;
} catch (Throwable $e) {
  // Fallback error page
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
        <p>We couldn’t delete this invoice. Please try again, or contact support if it persists.</p>
        <p style="margin-top:14px;">
          <a class="btn" href="<?=h($base)?>/invoices/view.php?id=<?=$id?>" style="background:#333;">Back to invoice</a>
          <a class="btn" href="<?=h($base)?>/invoices/list.php" style="margin-left:6px;">Invoice history</a>
        </p>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}
