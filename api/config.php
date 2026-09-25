<?php
/* =========================================================
   OJ APARTMENT — Central Configuration
   =========================================================
   Copy this file to config.local.php and fill in your own
   values. config.local.php is never committed to version
   control.
   ========================================================= */

/* ── Database ── */
define('DB_HOST',     'localhost');
define('DB_PORT',     '3306');
define('DB_NAME',     'oj_apartment');
define('DB_USER',     'root');          // change in production
define('DB_PASS',     '');              // change in production
define('DB_CHARSET',  'utf8mb4');

/* ── App ── */
define('APP_NAME',    'OJ Apartment');
define('APP_URL',     'http://localhost/OJ APARTMENT');   // no trailing slash
define('APP_ENV',     'development');   // 'production' on live server
define('APP_SECRET',  'CHANGE_ME_TO_A_LONG_RANDOM_STRING_32+_CHARS');

/* ── M-Pesa / Safaricom Daraja ── */
define('MPESA_ENV',             'sandbox');   // 'sandbox' | 'live'
define('MPESA_CONSUMER_KEY',    'YOUR_CONSUMER_KEY');
define('MPESA_CONSUMER_SECRET', 'YOUR_CONSUMER_SECRET');
define('MPESA_SHORTCODE',       '174379');    // sandbox test shortcode
define('MPESA_PASSKEY',         'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');
define('MPESA_CALLBACK_URL',    APP_URL . '/api/mpesa.php?action=callback');
define('MPESA_B2C_INITIATOR',   'testapi');
define('MPESA_B2C_CREDENTIAL',  'YOUR_SECURITY_CREDENTIAL');

/* ── Email (PHPMailer or native mail()) ── */
define('MAIL_FROM',     'no-reply@ojapartment.co.ke');
define('MAIL_FROM_NAME','OJ Apartment');
define('MAIL_TO_ADMIN', 'admin@ojapartment.co.ke');
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'your@gmail.com');
define('SMTP_PASS',     'your_app_password');

/* ── Session ── */
define('SESSION_NAME',     'oj_session');
define('SESSION_LIFETIME', 86400);   // 24 hours in seconds

/* ── CORS — allowed origins ── */
define('CORS_ORIGIN', 'http://localhost');

/* ── Override with local config if it exists ── */
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
