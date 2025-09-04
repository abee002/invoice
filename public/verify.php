<?php
// public/verify.php
// Step 2 of OTP login: enter the 6-digit code, verify, then redirect.

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/middleware.php';
require_once __DIR__ . '/../app/csrf.php';

$config = require __DIR__ . '/../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

// If already logged in, go home
if (!empty($_SESSION['user']['id'])) {
  header('Location: ' . $base . '/index.php'); exit;
}

// Must have pending login context
$pending = $_SESSION['pending_login'] ?? null;
if (!$pending || empty($pending['user_id'])) {
  header('Location: ' . $base . '/login.php'); exit;
}
$user_id   = (int)$pending['user_id'];
$username  = $pending['username'] ?? '';
$channel   = $pending['channel'] ?? 'email';
$dest      = $pending['dest'] ?? '';

// --- Helpers for display masking ---
function mask_email(string $email): string {
  if (!strpos($email, '@')) return $email;
  [$u, $d] = explode('@', $email, 2);
  $uMasked = strlen($u) <= 2 ? str_repeat('*', strlen($u)) : substr($u, 0, 1) . str_repeat('*', max(1, strlen($u)-2)) . substr($u, -1);
  return $uMasked . '@' . $d;
}
function mask_phone(string $phone): string {
  $digits = preg_replace('/\D/', '', $phone);
  if (strlen($digits) <= 4) return $phone;
  return substr($phone, 0, -4) . str_repeat('*', 4);
}

$err  = '';
$info = '';

// Resend OTP handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
  require_csrf_token(); // 🔐 CSRF check
  try {
    // Re-confirm destination from DB if needed
    if ($channel === 'email' && !$dest) {
      $st = $pdo->prepare("SELECT email FROM users WHERE id=?");
      $st->execute([$user_id]);
      $row = $st->fetch();
      $dest = strtolower($row['email'] ?? '');
    } elseif ($channel === 'phone' && !$dest) {
      $st = $pdo->prepare("SELECT phone FROM users WHERE id=?");
      $st->execute([$user_id]);
      $row = $st->fetch();
      $dest = normalize_phone($row['phone'] ?? '');
    }
    if (!$dest) throw new RuntimeException('No contact found to resend OTP.');

    $code = create_otp($pdo, $user_id, $channel, $dest);
    dispatch_otp($config, $channel, $dest, $code);
    $info = 'A new code has been sent.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// Verify code handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
  require_csrf_token(); // 🔐 CSRF check
  try {
    $code = trim($_POST['code'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) {
      throw new RuntimeException('Enter the 6-digit code.');
    }

    if (!validate_otp($pdo, $user_id, $code)) {
      throw new RuntimeException('Invalid or expired code. Try again or resend.');
    }

    // Load user, login, redirect
    $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$user_id]);
    $user = $st->fetch();
    if (!$user) throw new RuntimeException('User not found.');

    login_user($user);
    unset($_SESSION['pending_login']);     // cleanup temp state

    if ((int)$user['onboarded'] === 0) {
      header('Location: ' . $base . '/settings/'); exit;
    }
    header('Location: ' . $base . '/index.php'); exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

// UI bits
$destLabel = $channel === 'phone' ? mask_phone($dest) : mask_email($dest);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Verify OTP · <?=htmlspecialchars($username)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=htmlspecialchars($base)?>/assets/app.css">
  <style>
    .wrap{max-width:460px;margin:60px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .sub{color:#bbb;margin:4px 0 14px}
    .otp{letter-spacing:6px;text-align:center;font-size:20px}
    .row{display:flex;gap:10px;align-items:center}
    .row button{white-space:nowrap}
    .hint{font-size:12px;color:#9aa;margin-top:8px}
    .alert-info{background:#0b2b3d;border:1px solid #174a62;color:#d6f0ff;padding:8px;border-radius:8px;margin-top:10px}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <h1 style="margin:0 0 6px;">Verify code</h1>
      <p class="sub">We sent a 6-digit code to your <?=htmlspecialchars($channel)?>: <b><?=htmlspecialchars($destLabel)?></b></p>

      <?php if ($err): ?><div class="alert"><?=htmlspecialchars($err)?></div><?php endif; ?>
      <?php if ($info): ?><div class="alert-info"><?=htmlspecialchars($info)?></div><?php endif; ?>

      <form method="post" class="row" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input class="otp" name="code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required>
        <button type="submit">Verify</button>
      </form>

      <form method="post" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        <button type="submit">Resend code</button>
        <a class="btn" href="<?=htmlspecialchars($base)?>/login.php" style="margin-left:6px;background:#333;">Change contact</a>
      </form>

      <?php if (!empty($_SESSION['__dev_last_otp']) && ($config['security']['dev_echo_otp'] ?? false)): ?>
        <div class="alert-info">
          <div><b>DEV MODE</b>: OTP sent to <code><?=htmlspecialchars($_SESSION['__dev_last_dest'] ?? '')?></code></div>
          <div>Code: <code style="font-size:18px;"><?=htmlspecialchars($_SESSION['__dev_last_otp'])?></code></div>
          <div class="hint">Disable this in <code>config.php → security.dev_echo_otp = false</code> for production.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
