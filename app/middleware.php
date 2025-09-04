<?php
// app/middleware.php
// Guards for login and first-time onboarding.

require_once __DIR__ . '/auth.php';   // starts session + auth helpers
$config = require __DIR__ . '/config.php';

// Compute base URL once (e.g., /invoice-app/public or /)
$__BASE = rtrim($config['app']['base_url'] ?? '', '/');

/** Internal redirect helper */
function __redirect_to(string $path): void {
  global $__BASE;
  $target = ($__BASE === '' || $__BASE === '/') ? '/' . ltrim($path, '/') : $__BASE . '/' . ltrim($path, '/');
  header('Location: ' . $target);
  exit;
}

/** Require an authenticated session (24h cookie is handled in auth.php) */
function require_login(): void {
  if (empty($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    __redirect_to('login.php');
  }
}

/**
 * Require the user to have completed onboarding.
 * Pass in PDO so we can check the `users.onboarded` flag.
 */
function require_onboarded(PDO $pdo): void {
  require_login();
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  if ($uid <= 0) {
    __redirect_to('login.php');
  }
  $st = $pdo->prepare("SELECT onboarded FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $row = $st->fetch();
  if (!$row || (int)$row['onboarded'] === 0) {
    __redirect_to('settings/');
  }
}

/**
 * Optional helper to ensure rows belong to the logged-in owner.
 * Usage example:
 *   ensure_owner_scope($pdo, 'customers', $customerId, 'owner_id');
 */
function ensure_owner_scope(PDO $pdo, string $table, int $rowId, string $ownerColumn = 'owner_id', string $idColumn = 'id'): void {
  require_login();
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $sql = "SELECT 1 FROM {$table} WHERE {$idColumn}=? AND {$ownerColumn}=? LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([$rowId, $uid]);
  if (!$st->fetchColumn()) {
    http_response_code(403);
    exit('Forbidden');
  }
}
