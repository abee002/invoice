<?php
// public/login.php
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

$err = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf_token(); // 🔐 CSRF check
  try {
    $identifier = trim($_POST['identifier'] ?? '');
    $channel    = trim($_POST['channel'] ?? 'auto'); // auto | email | phone
    $contact    = trim($_POST['contact'] ?? '');     // used when identifier is username with no contact

    if ($identifier === '') {
      throw new RuntimeException('Please enter your email, phone, or username.');
    }

    // Find or create the user by identifier (email / phone / username)
    $user = find_or_create_user($pdo, $identifier);
    $user_id = (int)$user['id'];

    // Determine channel & destination
    $chosenChannel = null;
    $destination   = null;

    // If identifier itself is an email/phone, use that
    if (is_email_id($identifier)) {
      $chosenChannel = 'email';
      $destination   = strtolower($identifier);
    } elseif (is_phone_id($identifier)) {
      $chosenChannel = 'phone';
      $destination   = normalize_phone($identifier);
    } else {
      // identifier treated as username
      // Prefer user-stored email/phone; allow override via radio; or use provided contact field
      $hasEmail = !empty($user['email']);
      $hasPhone = !empty($user['phone']);

      // If a specific channel was picked
      if ($channel === 'email' || $channel === 'phone') {
        $chosenChannel = $channel;
        if ($channel === 'email') {
          if ($hasEmail) $destination = $user['email'];
        } else {
          if ($hasPhone) $destination = $user['phone'];
        }
      }

      // If we still don't have a destination, use the other stored one
      if (!$destination) {
        if ($hasEmail) { $chosenChannel = 'email'; $destination = $user['email']; }
        elseif ($hasPhone) { $chosenChannel = 'phone'; $destination = $user['phone']; }
      }

      // If user had no stored contact, use the contact field
      if (!$destination && $contact !== '') {
        if (is_email_id($contact)) {
          $chosenChannel = 'email';
          $destination   = strtolower($contact);
          // persist to user
          $up = $pdo->prepare("UPDATE users SET email=? WHERE id=?");
          $up->execute([$destination, $user_id]);
          $user['email'] = $destination;
        } elseif (is_phone_id($contact)) {
          $chosenChannel = 'phone';
          $destination   = normalize_phone($contact);
          $up = $pdo->prepare("UPDATE users SET phone=? WHERE id=?");
          $up->execute([$destination, $user_id]);
          $user['phone'] = $destination;
        } else {
          throw new RuntimeException('Enter a valid email or phone in the Contact field.');
        }
      }

      if (!$destination || !$chosenChannel) {
        throw new RuntimeException('Choose a channel and/or provide a valid contact for OTP.');
      }
    }

    // Create OTP
    $code = create_otp($pdo, $user_id, $chosenChannel, $destination);

    // Dispatch OTP (email/SMS or dev echo)
    dispatch_otp($config, $chosenChannel, $destination, $code);

    // Stash temporary login context for verify step
    $_SESSION['pending_login'] = [
      'user_id'   => $user_id,
      'username'  => $user['username'],
      'channel'   => $chosenChannel,
      'dest'      => $destination,
      'created'   => time(),
    ];

    // Go to verify page
    header('Location: ' . $base . '/verify.php?u=' . urlencode($user['username']));
    exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sign in · OTP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=htmlspecialchars($base)?>/assets/app.css">
  <style>
    .wrap{max-width:460px;margin:60px auto;background:#151515;border:1px solid #222;border-radius:12px;padding:18px}
    .sub{color:#bbb;margin:4px 0 14px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .muted{color:#9aa}
    .help{font-size:12px;color:#9aa;margin-top:6px}
    .alert-info{background:#0b2b3d;border:1px solid #174a62;color:#d6f0ff;padding:8px;border-radius:8px;margin-top:10px}
    .radio{display:flex;gap:12px;margin-top:6px}
    .hint{font-size:12px;color:#aaa;margin-top:8px}
  </style>
</head>
<body>
  <div class="container">
    <div class="wrap">
      <h1 style="margin:0 0 6px;">Sign in</h1>
      <p class="sub">Use your <b>email</b>, <b>phone</b>, or <b>username</b>. We’ll send a 6-digit OTP.</p>

      <?php if ($err): ?><div class="alert"><?=htmlspecialchars($err)?></div><?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <label>Email / Phone / Username*</label>
        <input type="text" name="identifier" placeholder="e.g. you@example.com or +9477xxxxxxx or yourname" required>

        <div class="hint">If you typed a username and we don’t have your contact yet, fill the “Contact for OTP” below.</div>

        <label style="margin-top:12px;">Preferred Channel (optional when using username)</label>
        <div class="radio">
          <label><input type="radio" name="channel" value="auto" checked> Auto</label>
          <label><input type="radio" name="channel" value="email"> Email</label>
          <label><input type="radio" name="channel" value="phone"> Phone</label>
        </div>

        <label style="margin-top:12px;">Contact for OTP (only if needed)</label>
        <input type="text" name="contact" placeholder="you@example.com or +9477xxxxxxx">

        <button type="submit">Send OTP</button>
      </form>

      <?php if (!empty($_SESSION['__dev_last_otp']) && ($config['security']['dev_echo_otp'] ?? false)): ?>
        <div class="alert-info">
          <div><b>DEV MODE</b>: OTP sent to <code><?=htmlspecialchars($_SESSION['__dev_last_dest'] ?? '')?></code></div>
          <div>Code: <code style="font-size:18px;"><?=htmlspecialchars($_SESSION['__dev_last_otp'])?></code></div>
          <div class="help">Disable this in <code>config.php → security.dev_echo_otp = false</code> for production.</div>
        </div>
      <?php endif; ?>

      <p class="help">Having trouble? Try entering your email/phone directly, or provide a contact above.</p>
    </div>
  </div>
</body>
</html>
