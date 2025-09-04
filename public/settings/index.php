<?php
// public/settings/index.php
// First-time onboarding + profile settings (address, phone, logo)

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/middleware.php';

require_login(); // allow access even if not onboarded

$config = require __DIR__ . '/../../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

$uid = (int)($_SESSION['user']['id'] ?? 0);
if ($uid <= 0) {
  header('Location: ' . $base . '/login.php'); exit;
}

// Load current settings (if any)
$st = $pdo->prepare("SELECT display_name, address, phone, logo_path FROM user_settings WHERE user_id=?");
$st->execute([$uid]);
$settings = $st->fetch() ?: ['display_name'=>'','address'=>'','phone'=>'','logo_path'=>''];

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $display = trim($_POST['display_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $logoRel = $settings['logo_path'] ?? null;

    // Optional: upload logo
    if (!empty($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $saved = save_logo_upload($_FILES['logo'], $uid);
      if ($saved) $logoRel = $saved;
    }

    // Upsert settings
    $up = $pdo->prepare("
      INSERT INTO user_settings (user_id, display_name, address, phone, logo_path)
      VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        display_name=VALUES(display_name),
        address=VALUES(address),
        phone=VALUES(phone),
        logo_path=COALESCE(VALUES(logo_path), logo_path)
    ");
    $up->execute([$uid, $display, $address, $phone, $logoRel]);

    // Also keep users.phone in sync if provided
    if ($phone !== '') {
      $up2 = $pdo->prepare("UPDATE users SET phone=? WHERE id=?");
      $up2->execute([$phone, $uid]);
    }

    // Mark onboarded
    $pdo->prepare("UPDATE users SET onboarded=1 WHERE id=?")->execute([$uid]);

    // Redirect to dashboard after save
    header('Location: ' . $base . '/index.php'); exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Settings · Onboarding</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=h($base)?>/assets/app.css">
  <style>
    .wrap{max-width:720px;margin:24px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:700px){.row{grid-template-columns:1fr}}
    .logo-prev{width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid #333;background:#111}
    .hint{font-size:12px;color:#9aa;margin-top:6px}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <h1 style="margin:0 0 6px;">Account Settings</h1>
      <p class="hint">Complete your profile details. You can change these anytime.</p>

      <?php if ($err): ?><div class="alert"><?=h($err)?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <div class="row">
          <div>
            <label>Display Name</label>
            <input name="display_name" value="<?=h($settings['display_name'])?>" placeholder="Your Company / Your Name">
          </div>
          <div>
            <label>Phone</label>
            <input name="phone" value="<?=h($settings['phone'])?>" placeholder="+94 7x xxx xxxx">
          </div>
        </div>

        <label style="margin-top:12px;">Address</label>
        <textarea name="address" rows="4" placeholder="Street, City, Country"><?=h($settings['address'])?></textarea>

        <div class="row" style="margin-top:12px;align-items:center">
          <div>
            <label>Logo (PNG/JPG/WebP ≤ 2MB)</label>
            <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.gif">
            <div class="hint">Used on dashboard and invoice print.</div>
          </div>
          <div>
            <?php if (!empty($settings['logo_path'])): ?>
              <img class="logo-prev" src="<?=h($base . '/' . ltrim($settings['logo_path'],'/'))?>" alt="Logo">
            <?php else: ?>
              <div class="hint">No logo uploaded yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <div style="margin-top:16px;">
          <button type="submit">Save & Continue</button>
          <a class="btn" href="<?=h($base)?>/index.php" style="background:#333;margin-left:6px;">Skip for now</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
