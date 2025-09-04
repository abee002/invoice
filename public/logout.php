<?php
// public/logout.php
// Destroys the session and returns to the login screen.

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/middleware.php';

$config = require __DIR__ . '/../app/config.php';
$base   = rtrim($config['app']['base_url'] ?? '/invoice-app/public', '/');

logout_user();
header('Location: ' . $base . '/login.php');
exit;
