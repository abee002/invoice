<?php
// app/auth.php
// Session setup (24h), simple auth helpers, and OTP utilities (email/phone).

// Load config (needed for session lifetime + OTP dev mode)
$config = require __DIR__ . '/config.php';

// ----- Session: 24 hours -----
$lifetime = (int)($config['security']['session_lifetime'] ?? 86400);
ini_set('session.gc_maxlifetime', $lifetime);
session_set_cookie_params([
  'lifetime' => $lifetime,
  'path'     => '/',
  'secure'   => false, // set true if HTTPS
  'httponly' => true,
  'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// ----- Basic auth helpers -----
function current_user_id(): ?int {
  return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}
function current_username(): ?string {
  return $_SESSION['user']['username'] ?? null;
}
function login_user(array $user): void {
  $_SESSION['user'] = [
    'id'       => (int)$user['id'],
    'username' => $user['username'] ?? null,
    'name'     => $user['username'] ?? null,
    'role'     => $user['role'] ?? 'user',
  ];
}
function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
  }
  session_destroy();
}

// ----- Identifier helpers -----
function sanitize_identifier(string $raw): string {
  return trim($raw);
}
function is_email_id(string $v): bool {
  return (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
}
function is_phone_id(string $v): bool {
  // Very loose check (digits, +, space, -, (), length >= 7)
  $v = preg_replace('/\s+/', '', $v);
  return (bool)preg_match('/^\+?[0-9\-\(\)]{7,}$/', $v);
}
function normalize_phone(string $v): string {
  // Keep + and digits
  $v = preg_replace('/[^0-9\+]/', '', $v);
  return $v;
}

// ----- User lookup / creation -----
function get_user_by_username(PDO $pdo, string $username): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE username = ?");
  $st->execute([$username]);
  $u = $st->fetch();
  return $u ?: null;
}
function get_user_by_email(PDO $pdo, string $email): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE email = ?");
  $st->execute([$email]);
  $u = $st->fetch();
  return $u ?: null;
}
function get_user_by_phone(PDO $pdo, string $phone): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
  $st->execute([$phone]);
  $u = $st->fetch();
  return $u ?: null;
}

function generate_unique_username(PDO $pdo, string $base): string {
  $base = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', strtolower($base));
  if ($base === '') $base = 'user';
  $username = $base;
  $i = 1;
  while (true) {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $st->execute([$username]);
    if (!$st->fetchColumn()) return $username;
    $i++;
    $username = $base . $i;
  }
}

function create_user(PDO $pdo, string $username, ?string $email = null, ?string $phone = null): array {
  $st = $pdo->prepare("INSERT INTO users (username, email, phone, onboarded) VALUES (?,?,?,0)");
  $st->execute([$username, $email, $phone]);
  $id = (int)$pdo->lastInsertId();
  // Create empty settings row (optional; we'll upsert later)
  $ss = $pdo->prepare("INSERT INTO user_settings (user_id) VALUES (?)");
  try { $ss->execute([$id]); } catch (Throwable $e) { /* ignore if already */ }
  return [
    'id' => $id,
    'username' => $username,
    'email' => $email,
    'phone' => $phone,
    'onboarded' => 0
  ];
}

/**
 * Find existing user by username/email/phone; if not found, create a new one.
 * Returns array with: id, username, email, phone, onboarded
 */
function find_or_create_user(PDO $pdo, string $identifier): array {
  $id = sanitize_identifier($identifier);
  if ($id === '') throw new InvalidArgumentException('Empty identifier');

  if (is_email_id($id)) {
    $email = strtolower($id);
    $u = get_user_by_email($pdo, $email);
    if ($u) return $u;
    $base = explode('@', $email)[0] ?? 'user';
    $username = generate_unique_username($pdo, $base);
    return create_user($pdo, $username, $email, null);
  }

  if (is_phone_id($id)) {
    $phone = normalize_phone($id);
    $u = get_user_by_phone($pdo, $phone);
    if ($u) return $u;
    $base = 'u' . substr(preg_replace('/\D/', '', $phone), -6);
    $username = generate_unique_username($pdo, $base);
    return create_user($pdo, $username, null, $phone);
  }

  // Treat as username
  $username = strtolower(preg_replace('/\s+/', '', $id));
  $u = get_user_by_username($pdo, $username);
  if ($u) return $u;

  // If it's a new username, create minimal user
  $username = generate_unique_username($pdo, $username);
  return create_user($pdo, $username, null, null);
}

// ----- OTP creation / validation -----
function create_otp(PDO $pdo, int $user_id, string $channel, string $destination): string {
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $st = $pdo->prepare("INSERT INTO otp_codes (user_id, channel, destination, code, expires_at)
                       VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
  $st->execute([$user_id, $channel, $destination, $code]);
  return $code;
}

function validate_otp(PDO $pdo, int $user_id, string $code): bool {
  $st = $pdo->prepare("SELECT id FROM otp_codes
                       WHERE user_id=? AND code=? AND used_at IS NULL AND expires_at > NOW()
                       ORDER BY id DESC LIMIT 1");
  $st->execute([$user_id, $code]);
  $row = $st->fetch();
  if (!$row) return false;
  $up = $pdo->prepare("UPDATE otp_codes SET used_at=NOW() WHERE id=?");
  $up->execute([$row['id']]);
  return true;
}

// ----- OTP delivery (email/SMS stubs) -----
function send_otp_email(array $cfg, string $toEmail, string $code): bool {
  // Minimal stub: in production, integrate PHPMailer / SMTP using $cfg['mail']['smtp']...
  if (!($cfg['mail']['enabled'] ?? false)) {
    return false;
  }
  // TODO: implement real email send (PHPMailer). For now, return false to fall back to dev echo.
  return false;
}

function send_otp_sms(array $cfg, string $toPhone, string $code): bool {
  // Minimal stub: integrate your SMS provider (e.g., YCloud/Twilio) here using $cfg['sms'].
  if (!($cfg['sms']['enabled'] ?? false)) {
    return false;
  }
  // TODO: implement real SMS API call. For now, return false to fall back to dev echo.
  return false;
}

/**
 * Dispatch OTP according to chosen channel. If mail/SMS not configured and
 * dev_echo_otp=true, we store it in session for display on verify screen.
 */
function dispatch_otp(array $cfg, string $channel, string $destination, string $code): void {
  $sent = false;
  if ($channel === 'email') {
    $sent = send_otp_email($cfg, $destination, $code);
  } elseif ($channel === 'phone') {
    $sent = send_otp_sms($cfg, $destination, $code);
  }
  if (!$sent && ($cfg['security']['dev_echo_otp'] ?? false)) {
    $_SESSION['__dev_last_otp'] = $code;
    $_SESSION['__dev_last_dest'] = $destination;
  }
}
