<?php
// ═══════════════════════════════════════════════════════════
// WP Sales Manager Pro — API Entry Point  (Secured)
// ═══════════════════════════════════════════════════════════

// Step 1: Output buffer + suppress errors to users
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

// Step 2: Security & JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache, no-store');
// ── Restrict CORS to same origin only ──
$allowedOrigin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
// ── Hardening headers ──
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
// HSTS — always send early (even on HTTP, preloads for HTTPS-ready servers)
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(204);
    exit;
}

// Step 3: Load core files
define('WPSM_SECURE', true);
$base = dirname(__DIR__);

foreach ([
    '/config/config.php'    => 'config.php',
    '/includes/database.php'=> 'database.php',
    '/includes/security.php'=> 'security.php',
    '/includes/waf.php'     => 'waf.php',
    '/includes/functions.php'=> 'functions.php',
] as $path => $label) {
    if (!file_exists($base . $path)) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "{$label} not found"]);
        exit;
    }
    require_once $base . $path;
}

// Step 4: Session
startSecureSession();

// Step 4b: Timezone — set from DB settings or default to Asia/Dhaka
try {
    $tzRow = dbFetch("SELECT value FROM settings WHERE `key`='timezone' LIMIT 1");
    $tz = ($tzRow['value'] ?? 'Asia/Dhaka');
    if (!@date_default_timezone_set($tz)) date_default_timezone_set('Asia/Dhaka');
} catch (Exception $e) {
    date_default_timezone_set('Asia/Dhaka');
}

// Step 5: ── PHP WAF — scan every request ──
WAF::inspect();

// Step 6: Clean buffer, reset headers
while (ob_get_level()) ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-LiteSpeed-Cache-Control: no-cache, no-store');

// Step 7: Parse input (read ONCE, reuse everywhere)
$rawBody = @file_get_contents('php://input');
$input   = json_decode($rawBody, true) ?? [];
$action  = trim($_GET['action'] ?? $input['action'] ?? '');

// Step 8: ── Scan JSON body through WAF ──
// (GET/URI already scanned in WAF::inspect(); POST JSON body needs explicit scan)
if (!empty($input)) {
    WAF::scanBody($input);
}

// Step 9: Rate limit (skip for public actions)
$public = ['login', 'check_auth', 'init', 'init_ver', 'forgot_password_send',
           'forgot_password_verify', 'forgot_password_reset', 'portal_view'];
// Rate limit ALL requests — public and private
checkRateLimit();

// Step 10: Route
try {
    switch ($action) {

case 'init':
    // Allow without auth only on first install (empty admin table)
    $adminCount = 0;
    try { $adminCount = (int)(dbFetch("SELECT COUNT(*) cnt FROM admin_users")['cnt'] ?? 0); } catch (Throwable $e) {}
    if ($adminCount > 0) {
        $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
        requireRole($u, ['super_admin']);
    }
    initDatabase();
    jsonOk(['v' => APP_VERSION]);
    break;
case 'check_auth':
    $u = getCurrentUser();
    jsonOk($u
        ? ['logged_in' => true,  'user' => $u, 'csrf' => generateCSRFToken()]
        : ['logged_in' => false]
    );
    break;
case 'init_ver':
    // Public — version number only, no sensitive data
    jsonOk(['v' => APP_VERSION]);
    break;
case 'login':    handleLogin($input);
    break;
case '2fa_verify': handle2FAVerify($input);
    break;
case 'logout':   handleLogout();
    break;
case 'toggle_2fa':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(toggle2FA($input, $u));
    break;
case 'change_password':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    handleChangePassword($input, $u);
    break;

// ── DASHBOARD ──────────────────────────────────────────────────────────
case 'dashboard':      requireAuth(); jsonOk(getDashboard());
    break;
case 'get_forecast':   requireAuth(); jsonOk(getForecast());
    break;

// ── CLIENTS ────────────────────────────────────────────────────────────
case 'get_clients':
    requireAuth();
    jsonOk(['data' => getClients(sanitize($_GET['q'] ?? ''))]);
    break;
case 'get_client':
    requireAuth();
    jsonOk(getClientDetail((int)($_GET['id'] ?? 0)));
    break;
case 'add_client':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addClient($input, $u));
    break;
case 'update_client':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updateClient($input, $u));
    break;
case 'delete_client':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deleteClient((int)($input['id'] ?? 0), $u));
    break;

// ── PRODUCTS ───────────────────────────────────────────────────────────
case 'get_products':   requireAuth(); jsonOk(['data' => getProducts()]);
    break;
case 'add_product':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addProduct($input, $u));
    break;
case 'update_product':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updateProduct($input, $u));
    break;
case 'delete_product':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deleteProduct((int)($input['id'] ?? 0), $u));
    break;

// ── PROMO CODES ────────────────────────────────────────────────────────
case 'get_promos':    requireAuth(); jsonOk(['data' => getPromos()]);
    break;
case 'validate_promo':
    requireAuth();
    jsonOk(validatePromo(sanitize($input['code'] ?? ''), (float)($input['amount'] ?? 0)));
    break;
case 'add_promo':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addPromo($input, $u));
    break;
case 'update_promo':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updatePromo($input, $u));
    break;
case 'delete_promo':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deletePromo((int)($input['id'] ?? 0), $u));
    break;

// ── SALES ──────────────────────────────────────────────────────────────
case 'get_sales':      requireAuth(); jsonOk(['data' => getSales($_GET)]);
    break;
case 'get_sale':
    requireAuth();
    jsonOk(getSaleDetail((int)($_GET['id'] ?? 0)));
    break;
case 'get_invoice':
    requireAuth();
    jsonOk(getInvoiceData((int)($_GET['id'] ?? 0)));
case 'get_invoice_share':
    requireAuth();
    jsonOk(getInvoiceShareLink((int)($_GET['id'] ?? 0)));
case 'save_logo':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    $logo = $input['logo'] ?? '';
    if ($logo && strlen($logo) > 2 * 1024 * 1024) jsonError('Logo too large. Max 2MB.', 413);
    dbQuery("INSERT INTO settings (`key`,`value`) VALUES ('company_logo',?) ON DUPLICATE KEY UPDATE `value`=?", 'ss', [$logo, $logo]);
    jsonOk(['message' => 'Logo saved.']);
case 'add_sale':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addSale($input, $u));
case 'update_sale':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updateSale($input, $u));
case 'delete_sale':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deleteSale((int)($input['id'] ?? 0), $u));
case 'mark_renewed':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(markRenewed((int)($input['id'] ?? 0), $input, $u));
case 'regenerate_invoice':
    requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(regenerateInvoice((int)($input['id'] ?? 0)));
case 'get_expiring':   requireAuth(); jsonOk(getExpiring($_GET));

// ── PAYMENTS ───────────────────────────────────────────────────────────
case 'get_due_clients':
    requireAuth();
    jsonOk(getDueClients($_GET));
    break;
case 'get_payments':
    requireAuth();
    jsonOk(['data' => getPayments((int)($_GET['sale_id'] ?? 0))]);
    break;
case 'add_payment':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addPayment($input, $u));
    break;
case 'delete_payment':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deletePayment((int)($input['id'] ?? 0), $u));
    break;

// ── REMINDERS ──────────────────────────────────────────────────────────
case 'get_reminders':   requireAuth(); jsonOk(['data' => getReminderLog()]);
case 'preview_reminder':
    requireAuth();
    jsonOk(previewReminder($input));
case 'send_reminder':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(sendReminder($input, $u));

// ── TICKETS ────────────────────────────────────────────────────────────
case 'get_tickets':    requireAuth(); jsonOk(['data' => getTickets($_GET)]);
case 'get_ticket':
    requireAuth();
    jsonOk(getTicketDetail((int)($_GET['id'] ?? 0)));
case 'add_ticket':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addTicket($input, $u));
case 'reply_ticket':
case 'add_ticket_msg':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(replyTicket($input, $u));
case 'update_ticket_status':
case 'update_ticket':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updateTicketStatus($input, $u));

// ── TASKS ──────────────────────────────────────────────────────────────
case 'get_tasks':      requireAuth(); jsonOk(['data' => getTasks($_GET)]);
case 'add_task':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(addTask($input, $u));
case 'update_task':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(updateTask($input, $u));
case 'delete_task':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deleteTask((int)($input['id'] ?? 0), $u));
case 'toggle_task':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(toggleTask((int)($input['id'] ?? 0), $u));

// ── REPORTS ────────────────────────────────────────────────────────────
case 'get_report':     requireAuth(); jsonOk(getReport($_GET));
case 'global_search':
case 'search':
    requireAuth();
    jsonOk(['data' => globalSearch(sanitize($_GET['q'] ?? ''))]);

// ── SETTINGS ───────────────────────────────────────────────────────────
case 'get_my_2fa':
    $u = requireAuth();
    $row = dbFetch("SELECT twofa_enabled, twofa_method, email, phone FROM admin_users WHERE id=?", 'i', [$u['id']]);
    jsonOk([
        'enabled' => !empty($row['twofa_enabled']),
        'method'  => $row['twofa_method'] ?? 'email',
        'email'   => $row['email'] ?? '',
        'phone'   => $row['phone'] ?? '',
    ]);
case 'get_settings':
    requireAuth();
    $settings = getSettings();
    // Strip sensitive credentials — never expose to frontend
    $sensitiveKeys = ['smtp_pass', 'sms_api_key'];
    foreach ($sensitiveKeys as $k) {
        if (isset($settings[$k]) && $settings[$k] !== '') {
            $settings[$k] = '••••••••'; // Mask: indicate it's set without exposing value
        }
    }
    jsonOk(['data' => $settings]);
case 'save_settings':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(saveSettings($input, $u));

// ── ADMIN USERS ────────────────────────────────────────────────────────
case 'get_admins':     requireAuth(); jsonOk(['data' => getAdmins()]);
case 'add_admin':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(addAdmin($input, $u));
case 'update_admin':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(updateAdmin($input, $u));
case 'delete_admin':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(deleteAdmin((int)($input['id'] ?? 0), $u));
case 'toggle_admin':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(toggleAdmin((int)($input['id'] ?? 0), $u));
case 'get_sessions':
    requireAuth();
    jsonOk(['data' => getActiveSessions()]);
case 'kill_session':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    $sid = sanitize($input['session_id'] ?? '');
    // Admin can only kill their own session; super_admin can kill any session
    if ($u['role'] !== 'super_admin') {
        // Verify the session being killed belongs to the current user
        $sess = dbFetch("SELECT user_id FROM admin_sessions WHERE id=?", 's', [$sid]);
        if (!$sess || (int)$sess['user_id'] !== (int)$u['id']) {
            requireRole($u, ['super_admin']); // will deny
        }
    }
    jsonOk(killSession($sid, $u));
case 'get_audit_log':
    requireAuth();
    jsonOk(['data' => getAuditLog($_GET)]);

// ── PASSWORD RESET ─────────────────────────────────────────────────────
case 'forgot_password_send':
    jsonOk(handleForgotPasswordSend($input));
case 'forgot_password_verify':
    jsonOk(handleForgotPasswordVerify($input));
case 'forgot_password_reset':
    jsonOk(handleForgotPasswordReset($input));

// ── CLIENT PORTAL ──────────────────────────────────────────────────────
case 'portal_view':
    jsonOk(portalView(sanitize($_GET['token'] ?? '')));
case 'view_invoice_share':
    // Public invoice view — no auth required, token-based
    $token = sanitize($_GET['token'] ?? '');
    if (!$token) {
        http_response_code(400);
        die('<html><body style="font-family:sans-serif;text-align:center;padding:60px;background:#0f172a;color:#f1f5f9"><h2>❌ Invalid Link</h2><p>This invoice link is invalid.</p></body></html>');
    }
    $sale = dbFetch("SELECT id FROM sales WHERE share_token=?", 's', [$token]);
    if (!$sale) {
        http_response_code(404);
        die('<html><body style="font-family:sans-serif;text-align:center;padding:60px;background:#0f172a;color:#f1f5f9"><h2>❌ Link Not Found</h2><p>This invoice link is invalid or has expired.</p></body></html>');
    }
    $data = getInvoiceData((int)$sale['id']);
    // Build safe HTML invoice page for public view
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    $s = $data;
    $st = $data['settings'] ?? [];
    $company = htmlspecialchars($st['company_name'] ?? $st['site_title'] ?? 'Wp Theme Bazar');
    $invNo   = htmlspecialchars($s['invoice_no'] ?? '');
    $client  = htmlspecialchars($s['client_name'] ?? '—');
    $product = htmlspecialchars($s['product_name'] ?? '—');
    $price   = number_format((float)($s['price'] ?? 0), 2);
    $sym     = htmlspecialchars($st['currency_symbol'] ?? '৳');
    $saleDate= htmlspecialchars($s['sale_date'] ?? '');
    $expDate = htmlspecialchars($s['expiry_date'] ?? '');
    $payStatus = htmlspecialchars($s['payment_status'] ?? 'pending');
    $siteUrl = htmlspecialchars($s['site_url'] ?? '');
    $license = htmlspecialchars($s['license_type'] ?? '');
    $phone   = htmlspecialchars($st['company_phone'] ?? '');
    $address = htmlspecialchars($st['company_address'] ?? '');
    $bkash   = htmlspecialchars($st['bkash_number'] ?? '');
    $nagad   = htmlspecialchars($st['nagad_number'] ?? '');
    $cellfin = htmlspecialchars($st['cellfin_number'] ?? '');
    $rocket  = htmlspecialchars($st['rocket_number'] ?? '');
    $payColor = $payStatus === 'paid' ? '#10b981' : ($payStatus === 'partial' ? '#f59e0b' : '#ef4444');
    $payLabel = strtoupper($payStatus);
    echo '<!DOCTYPE html><html lang="bn"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice ' . $invNo . ' — ' . $company . '</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f1f5f9;padding:20px;color:#1e293b}
.inv{background:#fff;max-width:794px;margin:0 auto;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12)}
.inv-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);padding:32px 40px;color:#fff}
.inv-header h1{font-size:28px;opacity:.08;letter-spacing:6px;margin-bottom:16px}
.inv-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:20px}
.company-name{font-size:22px;font-weight:800}
.company-sub{font-size:11px;color:#94a3b8;margin-top:2px;letter-spacing:1px}
.company-info{font-size:12px;color:#94a3b8;margin-top:10px;line-height:1.8}
.inv-no{text-align:right}
.inv-no .num{font-size:24px;font-weight:800}
.inv-no .lbl{font-size:10px;color:#64748b;letter-spacing:1px;text-transform:uppercase}
.date-box{background:rgba(255,255,255,.08);border-radius:8px;padding:8px 14px;margin-top:8px;text-align:right}
.date-box .dl{font-size:9px;color:#64748b;letter-spacing:1px;text-transform:uppercase}
.date-box .dv{font-size:13px;font-weight:700}
.status-badge{display:inline-block;padding:6px 18px;border-radius:30px;font-size:12px;font-weight:800;letter-spacing:1px;margin-top:8px;color:#fff}
.inv-body{padding:32px 40px}
.section-title{font-size:9px;font-weight:800;color:#94a3b8;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.section-title::before,.section-title::after{content:"";flex:1;height:1px;background:#e2e8f0}
.bill-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:28px}
.bill-box h3{font-size:20px;font-weight:800;margin-bottom:8px}
.bill-box p{font-size:13px;color:#64748b;line-height:1.8}
.items-table{width:100%;border-collapse:collapse;margin-bottom:28px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0}
.items-table th{background:#f8fafc;padding:12px 16px;text-align:left;font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid #e2e8f0}
.items-table td{padding:14px 16px;font-size:13px;border-bottom:1px solid #f1f5f9}
.items-table tr:last-child td{border-bottom:none}
.totals{display:flex;justify-content:flex-end;margin-bottom:28px}
.totals-box{width:280px;background:#f8fafc;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0}
.totals-row{display:flex;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #e2e8f0;font-size:13px}
.totals-row:last-child{border-bottom:none}
.totals-total{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;padding:14px 16px;display:flex;justify-content:space-between;font-weight:800}
.pay-section{margin-bottom:28px}
.pay-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
.pay-card{border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px}
.pay-card.bkash{background:#fdf2f8;border:1.5px solid #f9a8d4}
.pay-card.nagad{background:#fff7ed;border:1.5px solid #fdba74}
.pay-card.cellfin{background:#eff6ff;border:1.5px solid #93c5fd}
.pay-card.rocket{background:#f5f3ff;border:1.5px solid #c4b5fd}
.pay-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;color:#fff;flex-shrink:0}
.pay-label{font-size:9px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;margin-bottom:2px}
.pay-num{font-size:14px;font-weight:900;font-family:monospace}
.inv-footer{background:#0f172a;padding:16px 40px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.inv-footer .fc{font-size:11px;color:#475569}
.inv-footer .fn{font-size:10px;color:#334155;background:rgba(255,255,255,.05);padding:4px 10px;border-radius:4px;font-family:monospace}
.thankyou{text-align:center;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border-radius:12px;padding:18px;margin-bottom:24px;border:1px solid #bfdbfe}
.thankyou h3{font-size:16px;font-weight:800;color:#1e3a5f;margin-bottom:4px}
.thankyou p{font-size:12px;color:#64748b}
@media(max-width:600px){.inv-top,.bill-grid{flex-direction:column}.inv-no{text-align:left}.inv-body{padding:20px}}
@media print{body{background:#fff;padding:0}.inv{box-shadow:none;border-radius:0}}
</style>
</head><body>
<div class="inv">
  <div class="inv-header">
    <div style="font-size:36px;font-weight:900;opacity:.06;letter-spacing:8px;margin-bottom:16px">INVOICE</div>
    <div class="inv-top">
      <div>
        <div class="company-name">' . $company . '</div>
        <div class="company-sub">WP THEME BAZAR</div>
        <div class="company-info">' .
          ($address ? '📍 ' . $address . '<br>' : '') .
          ($phone   ? '💬 ' . $phone . ' (8AM–11PM)<br>' : '') .
        '</div>
      </div>
      <div class="inv-no">
        <div class="lbl">Invoice</div>
        <div class="num">' . $invNo . '</div>
        <div class="date-box">
          <div class="dl">Issue Date</div>
          <div class="dv">' . $saleDate . '</div>
        </div>' .
        ($expDate ? '<div class="date-box"><div class="dl">Expires</div><div class="dv" style="color:#fbbf24">' . $expDate . '</div></div>' : '') . '
        <div style="text-align:right;margin-top:8px">
          <span class="status-badge" style="background:' . $payColor . '">' . $payLabel . '</span>
        </div>
      </div>
    </div>
  </div>

  <div class="inv-body">
    <div class="section-title">Bill To &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Service Details</div>
    <div class="bill-grid">
      <div class="bill-box">
        <h3>' . $client . '</h3>
      </div>
      <div class="bill-box">
        <p><b>Product:</b> ' . $product . '<br>
        ' . ($siteUrl ? '<b>Website:</b> <a href="' . $siteUrl . '" style="color:#6366f1">' . $siteUrl . '</a><br>' : '') . '
        <b>License:</b> ' . $license . '</p>
      </div>
    </div>

    <table class="items-table">
      <thead><tr>
        <th>Description</th>
        <th style="text-align:right">Amount</th>
      </tr></thead>
      <tbody>
        <tr>
          <td><b>' . $product . '</b>' . ($siteUrl ? '<br><span style="font-size:11px;color:#6366f1">' . $siteUrl . '</span>' : '') . '</td>
          <td style="text-align:right;font-weight:700">' . $sym . $price . '</td>
        </tr>
      </tbody>
    </table>

    <div class="totals">
      <div class="totals-box">
        <div class="totals-total">
          <span>GRAND TOTAL</span>
          <span>' . $sym . $price . '</span>
        </div>
        <div class="totals-row">
          <span style="color:#64748b">Payment Status</span>
          <span style="color:' . $payColor . ';font-weight:700">' . $payLabel . '</span>
        </div>
      </div>
    </div>';

    // Payment methods
    $payMethods = array_filter([
        $bkash   ? ['class'=>'bkash',  'color'=>'#E2136E','label'=>'বিকাশ Personal','num'=>$bkash]   : null,
        $nagad   ? ['class'=>'nagad',  'color'=>'#F7941D','label'=>'নগদ Personal',  'num'=>$nagad]   : null,
        $cellfin ? ['class'=>'cellfin','color'=>'#1B4F9B','label'=>'Cellfin',        'num'=>$cellfin] : null,
        $rocket  ? ['class'=>'rocket', 'color'=>'#8B2BE2','label'=>'Rocket',         'num'=>$rocket]  : null,
    ]);
    if (!empty($payMethods)) {
        echo '<div class="pay-section">
          <div class="section-title">পেমেন্ট করুন</div>
          <div class="pay-grid">';
        foreach ($payMethods as $pm) {
            $initials = ['bkash'=>'bK','nagad'=>'নগদ','cellfin'=>'CF','rocket'=>'🚀'][$pm['class']] ?? '?';
            echo '<div class="pay-card ' . $pm['class'] . '">
              <div class="pay-icon" style="background:' . $pm['color'] . '">' . $initials . '</div>
              <div>
                <div class="pay-label" style="color:' . $pm['color'] . '">' . $pm['label'] . '</div>
                <div class="pay-num" style="color:' . $pm['color'] . '">' . $pm['num'] . '</div>
              </div>
            </div>';
        }
        echo '<p style="margin-top:8px;font-size:11px;color:#94a3b8;text-align:center;grid-column:1/-1">💡 Send Money করার পর Transaction ID সহ জানান</p>
        </div></div>';
    }

    echo '<div class="thankyou">
      <h3>Thank you for choosing us! 🙏</h3>
      <p>We appreciate your trust and look forward to serving you again.</p>
    </div>
  </div>

  <div class="inv-footer">
    <div class="fc">' . $company . ' · Wp Theme Bazar - Joynal Abdin</div>
    <div class="fn">' . $invNo . '</div>
  </div>
</div>
<div style="text-align:center;padding:16px;font-size:11px;color:#94a3b8">
  <button onclick="window.print()" style="background:#6366f1;color:#fff;border:none;padding:8px 20px;border-radius:8px;cursor:pointer;font-size:13px;margin-right:8px">🖨️ Print</button>
</div>
</body></html>';
    exit;

// ── EXPORT ─────────────────────────────────────────────────────────────
case 'export_json':
    $u = requireAuth(); requireWrite($u); exportJSON();
case 'export_excel':
    $u = requireAuth(); requireWrite($u); exportExcel();
case 'export_clients_sheet':
    $u = requireAuth(); requireWrite($u); exportClientsSheet();
case 'export_yearly_plugins':
    $u = requireAuth(); requireWrite($u); exportYearlyPlugins();

// ── BACKUP ─────────────────────────────────────────────────────────────
case 'create_backup':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(createBackup($u));
case 'list_backups':
    requireAuth();
    jsonOk(listBackups());
case 'restore_backup':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(restoreBackup($input, $u));
case 'delete_backup':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin']);
    jsonOk(deleteBackup($input, $u));
case 'download_backup':
    $u = requireAuth(); requireWrite($u);
    downloadBackup($input);

// ── WAF Dashboard ──────────────────────────────────────────────────────
case 'waf_stats':
    requireAuth();
    $stats = WAF::getStats();
    jsonOk($stats);

case 'waf_attack_log':
    requireAuth();
    jsonOk(['log' => WAF::getAttackLog(200)]);

case 'waf_banned_ips':
    requireAuth();
    jsonOk(['ips' => WAF::getBannedIPs()]);

case 'waf_ban_ip':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin', 'admin']);
    $ip  = trim($input['ip'] ?? '');
    $dur = (int)($input['duration'] ?? 86400);
    $rsn = sanitize($input['reason'] ?? 'Manual ban');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) jsonError('Invalid IP address.', 422);
    WAF::banIP($ip, $dur, $rsn);
    auditLog($u['id'], $u['username'], 'WAF_BAN_IP', 'waf', null, null, ['ip'=>$ip,'reason'=>$rsn]);
    jsonOk(['message' => "{$ip} Blocked successfully."]);

case 'waf_unban_ip':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    requireRole($u, ['super_admin', 'admin']);
    $ip = trim($input['ip'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) jsonError('Invalid IP address.', 422);
    $ok = WAF::unbanIP($ip);
    if (!$ok) jsonError('IP Not found.', 404);
    auditLog($u['id'], $u['username'], 'WAF_UNBAN_IP', 'waf', null, null, ['ip'=>$ip]);
    jsonOk(['message' => "{$ip} Unbanned successfully."]);

case 'get_reminder_log':
    requireAuth(); jsonOk(['data' => getReminderLog()]);
case 'toggle_portal':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(togglePortal((int)($input['id'] ?? 0), $u));
case 'delete_ticket':
    $u = requireAuth(); requireWrite($u); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(deleteTicket((int)($input['id'] ?? 0), $u));
case 'send_sms':
    $u = requireAuth(); verifyCSRFToken($input['_csrf'] ?? '');
    jsonOk(sendSMS($input, $u));
case 'get_sms_log':
    requireAuth(); jsonOk(['data' => getSMSLog()]);
default:
    jsonError('Unknown action: ' . htmlspecialchars($action), 400);

    } // end switch
} catch (Throwable $e) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // Production: log internally, never expose stack trace to client
    error_log('[WPSM Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error'   => 'Server error occurred. Please try again.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

