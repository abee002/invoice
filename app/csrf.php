<?php
// app/csrf.php
// Lightweight CSRF protection helpers.
// Usage in a form:
//   require_once __DIR__ . '/csrf.php';
//   <form method="post"> <?= csrf_field() ? > ... </form>
//
// Verification (at top of POST handlers):
//   require_once __DIR__ . '/csrf.php';
//   require_csrf_token(); // exits with 400 if invalid

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/** Get or generate the per-session CSRF token */
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

/** Echo a hidden input for CSRF (call inside forms) */
function csrf_field(): string {
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return '<input type="hidden" name="_csrf" value="' . $t . '">';
}

/** Validate token from POST/GET against the session token */
function is_csrf_valid(): bool {
  $sent = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
  if (!is_string($sent) || $sent === '') return false;
  $sess = $_SESSION['csrf_token'] ?? '';
  if (!is_string($sess) || $sess === '') return false;
  return hash_equals($sess, $sent);
}

/** Require a valid CSRF token (for POST/PUT/PATCH/DELETE) */
function require_csrf_token(): void {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
    return; // no check for safe methods
  }
  if (!is_csrf_valid()) {
    http_response_code(400);
    exit('Invalid CSRF token.');
  }
}
