<?php
// ╔══════════════════════════════════════════════════════╗
// ║        WP Sales Manager — Secure Config              ║
// ╚══════════════════════════════════════════════════════╝
// Direct browser access to this file is forbidden
if (!defined('WPSM_SECURE')) { http_response_code(403); exit('Access Denied'); }

// ── Database ─────────────────────────────────────────────
// ⚠️ আপনার cPanel → MySQL Databases থেকে এই তথ্যগুলো বসান
// ── Database Configuration ────────────────────────────────
// ⚠️  SETUP: cPanel → MySQL Databases → এখান থেকে তথ্য নিন
// ──────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');           // সাধারণত localhost
define('DB_USER', 'your_db_username');   // ← cPanel MySQL username (যেমন: cpanel_user_dbuser)
define('DB_PASS', 'your_db_password');   // ← cPanel MySQL password
define('DB_NAME', 'your_db_name');       // ← Database name (যেমন: cpanel_user_salesdb)
// ──────────────────────────────────────────────────────────
// 💡 প্রথমবার: browser-এ yoursite.com/api/index.php?action=init খুলুন
//    এটা automatically সব DB tables তৈরি করবে

// ── App Settings ─────────────────────────────────────────
// ⚠️ প্রতি আপডেটে শুধু এই একটা লাইন বদলান — সব জায়গায় আপনাআপনি আপডেট হবে
define('APP_VERSION',      '5.0');
define('SITE_TITLE',       'Wp Theme Bazar - Joynal Abdin');
define('TIMEZONE',         'Asia/Dhaka');
define('EXPIRY_WARN_DAYS', 7);

// ── Security Settings ────────────────────────────────────
define('SESSION_LIFETIME',     3600);        // 1 hour (in seconds)
define('SESSION_NAME',         'wpsm_sess'); // Custom session name
define('MAX_LOGIN_ATTEMPTS',   5);           // Max failed login attempts
define('LOGIN_LOCKOUT_TIME',   900);         // 15 minute lockout
define('CSRF_TOKEN_LENGTH',    48);
define('BCRYPT_COST',          12);          // Password hashing strength

// ── Rate Limiting ─────────────────────────────────────────
define('RATE_LIMIT_WINDOW',    60);    // 60 seconds
define('RATE_LIMIT_MAX',       120);   // Max 120 requests/minute

date_default_timezone_set(TIMEZONE);

// ── Production Error Handling ─────────────────────────────
// B10 Fix: Suppress PHP errors/warnings in production — log instead of display
error_reporting(E_ALL);
ini_set('display_errors', '0');      // Never show errors to users
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');          // Always log for debugging
ini_set('error_log', dirname(__DIR__) . '/backups/php_errors.log');
