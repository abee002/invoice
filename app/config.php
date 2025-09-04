<?php
// app/config.php
// Central config for database, app metadata, and OTP delivery.
// Adjust values for your local machine / server.

return [
  // --- Database ---
  'db' => [
    'host'    => '127.0.0.1',
    'port'    => '3306',
    'name'    => 'invoice_app',
    'user'    => 'root',
    'pass'    => '',             // ← set your MySQL password
    'charset' => 'utf8mb4',
  ],

  // --- App Settings ---
  'app' => [
    // If you serve from: http://localhost/invoice-app/public
    // keep base_url as below. If you serve from domain root (/),
    // set base_url to '' or '/'.
    'base_url'       => '/invoice-app/public',

    // Default company meta shown on dashboard/print
    'company_name'   => 'Your Company (Pvt) Ltd',
    'company_address'=> "No. 1, Sample Road,\nColombo, Sri Lanka",
    'company_phone'  => '+94 77 000 0000',
    'company_email'  => 'info@example.com',
    'currency'       => 'LKR',
  ],

  // --- Security / Auth ---
  'security' => [
    // Session lifetime for login (seconds) → 24 hours
    'session_lifetime' => 86400,

    // In development, also show OTP on screen/log for testing.
    // Turn OFF in production.
    'dev_echo_otp'     => true,
  ],

  // --- Email (for OTP/emailing invoices) ---
  'mail' => [
    'enabled'   => false,           // set true when SMTP is configured
    'from_email'=> 'no-reply@example.com',
    'from_name' => 'Invoice App',
    'smtp'      => [
      'host'   => 'smtp.example.com',
      'port'   => 587,
      'user'   => '',
      'pass'   => '',
      'secure' => 'tls',            // 'tls' or 'ssl'
    ],
  ],

  // --- SMS (for OTP via phone) ---
  // Plug any provider (e.g., Twilio, YCloud) in your sender code.
  'sms' => [
    'enabled'   => false,           // set true when provider is configured
    'provider'  => 'ycloud',        // label only (for your reference)
    'api_key'   => '',
    'sender_id' => 'YourBrand',
  ],
];
