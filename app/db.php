<?php
// app/db.php
// Bootstraps configuration and creates a shared PDO connection ($pdo).

$config = require __DIR__ . '/config.php';

// (Optional) Set your app timezone
if (function_exists('date_default_timezone_set')) {
  date_default_timezone_set('Asia/Colombo');
}

$dsn = sprintf(
  'mysql:host=%s;port=%s;dbname=%s;charset=%s',
  $config['db']['host'],
  $config['db']['port'],
  $config['db']['name'],
  $config['db']['charset']
);

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], $options);
  // Encourage strict behavior for safer math & invalid data detection
  $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
} catch (PDOException $e) {
  http_response_code(500);
  exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
