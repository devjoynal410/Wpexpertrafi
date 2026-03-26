<?php
if (!defined('WPSM_SECURE')) { http_response_code(403); exit; }

// ══════════════════════════════════════════════════════════════════
// MODULE SYSTEM — Domain controllers in includes/controllers/
// See each controller file for the function list it owns.
// Functions are implemented here for single-file cPanel compatibility.
// ══════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════════
// SERVER-SIDE CONFIG — validation constants (mirrors JS _CFG)
// ══════════════════════════════════════════════════════════════════
const VALID_PAYMENT_STATUS      = ['paid','partial','pending'];
const VALID_LICENSE_TYPES       = ['Single Site','5 Sites','Unlimited'];
const VALID_PRODUCT_TYPES       = ['Plugin','Theme','Service','Other'];
const VALID_PRIORITIES          = ['low','medium','high','urgent'];
const VALID_TASK_PRIORITIES     = ['low','medium','high'];
const VALID_TICKET_STATUSES     = ['open','in_progress','resolved','closed'];
const VALID_TICKET_STATUSES_EXT = ['open','in_progress','waiting','resolved','closed'];
const VALID_ROLES               = ['super_admin','admin','viewer'];
const VALID_REMINDER_CH         = ['whatsapp','email','sms','manual'];
const VALID_PROMO_TYPES         = ['percent','fixed'];
const VALID_RENEWAL_STATUS      = ['active','expired','renewed','stale'];
const VALID_PAYMENT_METHODS     = ['bKash Personal','Nagad Personal','Rocket','Upay','bKash Payment','Cellfin','Bank','Other'];
const VALID_OTP_METHODS         = ['email','sms'];

function generateTicketNo(): string {
    $db = getDB();
    // Advisory lock prevents race condition on concurrent ticket creation
    $db->query("SELECT GET_LOCK('ticket_no_lock', 5)");
    try {
        $last = dbFetch("SELECT ticket_no FROM tickets ORDER BY id DESC LIMIT 1");
        $n    = $last ? (int)substr($last['ticket_no'], 3) + 1 : 1;
        $no   = 'TKT' . str_pad($n, 4, '0', STR_PAD_LEFT);
    } finally {
        $db->query("SELECT RELEASE_LOCK('ticket_no_lock')");
    }
    return $no;
}


function getDeviceHash(): string {
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $ip     = getClientIP();
    // IPv4: use first 3 octets (x.x.x.*), IPv6: use first 4 groups
    if (str_contains($ip, ':')) {
        // IPv6 — take first 4 groups
        $parts = explode(':', $ip);
        $ipPrefix = implode(':', array_slice($parts, 0, 4));
    } else {
        // IPv4 — take first 3 octets
        $dotPos = strrpos($ip, '.');
        $ipPrefix = $dotPos !== false ? substr($ip, 0, $dotPos) : $ip;
    }
    return hash('sha256', $ua . $accept . $ipPrefix);
}

function isTrustedDevice(int $userId): bool {
    $hash = getDeviceHash();
    $row  = dbFetch(
        "SELECT id FROM trusted_devices WHERE user_id=? AND device_hash=? AND expires_at > NOW()",
        'is', [$userId, $hash]
    );
    if ($row) {
        // Refresh last_seen
        dbQuery("UPDATE trusted_devices SET last_seen=NOW() WHERE id=?", 'i', [$row['id']]);
        return true;
    }
    return false;
}

function trustThisDevice(int $userId): void {
    $hash  = getDeviceHash();
    $label = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 80);
    $ip    = getClientIP();
    // Trust for 30 days
    dbQuery(
        "INSERT INTO trusted_devices(user_id,device_hash,device_label,ip_address,expires_at)
         VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))
         ON DUPLICATE KEY UPDATE last_seen=NOW(), expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY)",
        'isss', [$userId, $hash, $label, $ip]
    );
}

function check2FAIPBan(): void {
    $ip  = getClientIP();
    $row = dbFetch("SELECT failed_attempts, banned_until FROM twofa_ip_ban WHERE ip_address=?", 's', [$ip]);
    if (!$row) return;
    if ($row['banned_until'] && strtotime($row['banned_until']) > time()) {
        $hours = ceil((strtotime($row['banned_until']) - time()) / 3600);
        jsonError("এই ডিভাইস থেকে ২৪ ঘন্টার জন্য block করা হয়েছে। {$hours} ঘন্টা পর চেষ্টা করুন।", 429);
    }
}

function record2FAFail(): void {
    $ip = getClientIP();
    dbQuery(
        "INSERT INTO twofa_ip_ban(ip_address, failed_attempts)
         VALUES(?, 1)
         ON DUPLICATE KEY UPDATE
           failed_attempts = failed_attempts + 1,
           banned_until = IF(failed_attempts + 1 >= 3, DATE_ADD(NOW(), INTERVAL 24 HOUR), banned_until),
           updated_at = NOW()",
        's', [$ip]
    );
    // Check if now banned
    $row = dbFetch("SELECT failed_attempts, banned_until FROM twofa_ip_ban WHERE ip_address=?", 's', [$ip]);
    if ($row && $row['failed_attempts'] >= 3 && !empty($row['banned_until'])) {
        jsonError("পরপর ৩ বার ভুল OTP। এই ডিভাইস ২৪ ঘন্টার জন্য block।", 429);
    }
}

function reset2FAFail(): void {
    $ip = getClientIP();
    dbQuery("DELETE FROM twofa_ip_ban WHERE ip_address=?", 's', [$ip]);
}

function handleLogin(array $d): never {
    $u=sanitize($d['username']??''); $p=$d['password']??'';
    if(!$u||!$p) jsonError('Please enter username and password.',422);
    if(strlen($u)>64) jsonError('Invalid username.',422);
    if(strlen($p)>128) jsonError('Invalid password.',422);
    checkLoginAttempts($u);
    $user=dbFetch("SELECT * FROM admin_users WHERE username=? LIMIT 1",'s',[$u]);
    if(!$user||!password_verify($p,$user['password_hash'])){
        recordLoginAttempt($u,false); usleep(random_int(100000,300000));
        jsonError('Incorrect username or password.',401);
    }
    if(!$user['is_active']) jsonError('Account is inactive.',403);
    recordLoginAttempt($u,true);

    // ── 2FA Check ──────────────────────────────────────────────
    if (!empty($user['twofa_enabled'])) {
        // Skip 2FA if this is a trusted device
        if (isTrustedDevice($user['id'])) {
            // Trusted device — login directly
            session_regenerate_id(true);
            $ip=getClientIP(); $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,255);
            $_SESSION['user_id']=$user['id']; $_SESSION['username']=$user['username'];
            $_SESSION['role']=$user['role']; $_SESSION['last_activity']=time();
            $_SESSION['user_agent']=$ua; $_SESSION['fingerprint']=hash('sha256',$ip.$ua);
            $_SESSION['session_db_id']=session_id();
            dbQuery("INSERT INTO admin_sessions(id,user_id,ip_address,user_agent) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_activity=NOW()",'siss',[session_id(),$user['id'],$ip,$ua]);
            dbQuery("UPDATE admin_users SET last_login=NOW(),last_ip=? WHERE id=?",'si',[$ip,$user['id']]);
            auditLog($user['id'],$user['username'],'LOGIN_TRUSTED_DEVICE',null,null,null,['ip'=>$ip]);
            jsonOk(['user'=>['id'=>$user['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'role'=>$user['role']],'csrf'=>generateCSRFToken(),'message'=>'Login successful.','trusted'=>true]);
        }

        // New device — require OTP
        check2FAIPBan(); // Check if IP is banned before sending

        // Email only
        if (empty($user['email'])) {
            jsonError('2FA চালু কিন্তু email সেট নেই। Admin এর সাথে যোগাযোগ করুন।', 422);
        }
        $otp  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 8]);
        $ip   = getClientIP();
        // Store pending 2FA — 5 minute expiry (separate from password_reset tokens via purpose)
        dbQuery("DELETE FROM password_reset_tokens WHERE user_id=? AND purpose='2fa'", 'i', [$user['id']]);
        dbQuery("INSERT INTO password_reset_tokens(user_id,otp_hash,method,purpose,expires_at,ip_address) VALUES(?,?,?,'2fa',DATE_ADD(NOW(),INTERVAL 5 MINUTE),?)",
            'isss', [$user['id'], $hash, 'email', $ip]);
        // Store pending user_id in session
        session_regenerate_id(true);
        $_SESSION['twofa_pending_user_id'] = $user['id'];
        $_SESSION['twofa_pending_at']      = time();
        // Send email OTP
        $sent = sendResetEmail($user, $user['email'], $otp);
        $masked = preg_replace('/(?<=.).(?=[^@]*?.@)/u', '*', $user['email']);
        jsonOk([
            'twofa_required' => true,
            'method'  => 'email',
            'masked'  => $masked,
            'sent'    => $sent,
            'message' => "OTP পাঠানো হয়েছে: {$masked} — ৫ মিনিটের মধ্যে দিন",
        ]);
    }

    // ── Normal login (no 2FA) ───────────────────────────────────
    session_regenerate_id(true);
    $ip=getClientIP(); $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,255);
    $_SESSION['user_id']=$user['id']; $_SESSION['username']=$user['username'];
    $_SESSION['role']=$user['role']; $_SESSION['last_activity']=time();
    $_SESSION['user_agent']=$ua; $_SESSION['fingerprint']=hash('sha256',$ip.$ua);
    $_SESSION['session_db_id']=session_id();
    dbQuery("INSERT INTO admin_sessions(id,user_id,ip_address,user_agent) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_activity=NOW()",'siss',[session_id(),$user['id'],$ip,$ua]);
    dbQuery("UPDATE admin_users SET last_login=NOW(),last_ip=? WHERE id=?",'si',[$ip,$user['id']]);
    auditLog($user['id'],$user['username'],'LOGIN',null,null,null,['ip'=>$ip]);
    jsonOk(['user'=>['id'=>$user['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'role'=>$user['role']],'csrf'=>generateCSRFToken(),'message'=>'Login successful.']);
}


function handle2FAVerify(array $d): never {
    check2FAIPBan(); // Block if IP is already banned

    $code = preg_replace('/[^0-9]/', '', $d['code'] ?? '');
    if (strlen($code) !== 6) jsonError('৬ সংখ্যার OTP দিন।', 422);

    $userId = $_SESSION['twofa_pending_user_id'] ?? 0;
    if (!$userId) jsonError('Session expired। আবার login করুন।', 401);

    // 5-minute window
    if ((time() - ($_SESSION['twofa_pending_at'] ?? 0)) > 300) {
        unset($_SESSION['twofa_pending_user_id'], $_SESSION['twofa_pending_at']);
        jsonError('OTP-এর ৫ মিনিট মেয়াদ শেষ হয়ে গেছে। আবার login করুন।', 401);
    }

    $token = dbFetch(
        "SELECT * FROM password_reset_tokens WHERE user_id=? AND purpose='2fa' AND used=0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1",
        'i', [$userId]
    );
    if (!$token) {
        record2FAFail();
        jsonError('OTP expired বা ব্যবহার হয়ে গেছে।', 401);
    }

    if (!password_verify($code, $token['otp_hash'])) {
        record2FAFail(); // Counts toward 3-attempt IP ban
        dbQuery("UPDATE password_reset_tokens SET attempts=attempts+1 WHERE id=?", 'i', [$token['id']]);
        $row = dbFetch("SELECT failed_attempts FROM twofa_ip_ban WHERE ip_address=?", 's', [getClientIP()]);
        $left = max(0, 3 - (int)($row['failed_attempts'] ?? 0));
        jsonError("ভুল OTP।" . ($left > 0 ? " আরও {$left} বার ভুল করলে ২৪ ঘন্টার জন্য block হবে।" : ''), 422);
    }

    // ✅ OTP correct — mark used, clear fail counter
    dbQuery("UPDATE password_reset_tokens SET used=1 WHERE id=?", 'i', [$token['id']]);
    reset2FAFail();
    unset($_SESSION['twofa_pending_user_id'], $_SESSION['twofa_pending_at']);

    // Trust this device for 30 days
    trustThisDevice($userId);

    // Complete login
    $user = dbFetch("SELECT * FROM admin_users WHERE id=? AND is_active=1", 'i', [$userId]);
    if (!$user) jsonError('Account not found.', 404);

    session_regenerate_id(true);
    $ip = getClientIP(); $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $_SESSION['user_id']=$user['id']; $_SESSION['username']=$user['username'];
    $_SESSION['role']=$user['role']; $_SESSION['last_activity']=time();
    $_SESSION['user_agent']=$ua; $_SESSION['fingerprint']=hash('sha256',$ip.$ua);
    $_SESSION['session_db_id']=session_id();
    dbQuery("INSERT INTO admin_sessions(id,user_id,ip_address,user_agent) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_activity=NOW()",'siss',[session_id(),$user['id'],$ip,$ua]);
    dbQuery("UPDATE admin_users SET last_login=NOW(),last_ip=? WHERE id=?",'si',[$ip,$user['id']]);
    auditLog($user['id'],$user['username'],'LOGIN_2FA',null,null,null,['ip'=>$ip,'trusted'=>true]);
    jsonOk([
        'user'    => ['id'=>$user['id'],'username'=>$user['username'],'full_name'=>$user['full_name'],'role'=>$user['role']],
        'csrf'    => generateCSRFToken(),
        'message' => 'Login successful.',
        'device_trusted' => true,
    ]);
}


function toggle2FA(array $d, array $u): array {
    $enable = !empty($d['enable']);
    $method = 'email'; // Email only
    // Security: always require current password to change 2FA setting
    $row = dbFetch("SELECT password_hash FROM admin_users WHERE id=?", 'i', [$u['id']]);
    if (empty($d['confirm_password']) || !$row || !password_verify($d['confirm_password'], $row['password_hash'])) {
        jsonError('Current password ভুল। 2FA পরিবর্তন করতে সঠিক password দিন।', 403);
    }
    if ($enable) {
        $user = dbFetch("SELECT email FROM admin_users WHERE id=?", 'i', [$u['id']]);
        if (empty($user['email']))
            jsonError('2FA চালু করতে আগে Settings-এ আপনার email যোগ করুন।', 422);
    }
    dbQuery("UPDATE admin_users SET twofa_enabled=?, twofa_method=? WHERE id=?",
        'isi', [$enable ? 1 : 0, $method, $u['id']]);
    // If disabling, clear trusted devices for this user
    if (!$enable) {
        dbQuery("DELETE FROM trusted_devices WHERE user_id=?", 'i', [$u['id']]);
    }
    auditLog($u['id'], $u['username'], $enable ? '2FA_ENABLED' : '2FA_DISABLED', 'admin_users', $u['id'], null, ['method' => $method]);
    return ['message' => $enable ? '2FA চালু হয়েছে (Email OTP)' : '2FA বন্ধ হয়েছে'];
}

function handleLogout(): never { $u=getCurrentUser(); if($u) auditLog($u['id'],$u['username'],'LOGOUT'); destroySession(); jsonOk(['message'=>'Logout successful.']); }
function handleChangePassword(array $d, array $u): never {
    $cur=$d['current_password']??''; $new=$d['new_password']??''; $con=$d['confirm_password']??'';
    if(!$cur||!$new) jsonError('Please fill in all fields.',422);
    if($new!==$con) jsonError('Passwords do not match.',422);
    validatePasswordStrength($new);
    $row=dbFetch("SELECT password_hash FROM admin_users WHERE id=?",'i',[$u['id']]);
    if(!password_verify($cur,$row['password_hash'])) jsonError('Current password is incorrect.',401);
    $h=password_hash($new,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]);
    dbQuery("UPDATE admin_users SET password_hash=? WHERE id=?",'si',[$h,$u['id']]);
    auditLog($u['id'],$u['username'],'CHANGE_PASSWORD');
    jsonOk(['message'=>'Password changed successfully.']);
}

// ══════════════════════════════════════════════════════════════════════
// SETTINGS
// ══════════════════════════════════════════════════════════════════════
function getSettings(): array {
    $rows = dbFetchAll("SELECT `key`, `value` FROM settings");
    $out  = [];
    foreach ($rows as $r) $out[$r['key']] = $r['value'];
    // Merge defaults
    $defaults = [
        'site_title'          => 'WP Sales Manager Pro',
        'invoice_prefix'      => 'INV',
        'invoice_next'        => '1001',
        'currency'            => 'BDT',
        'currency_symbol'     => '৳',
        'date_format'         => 'd/m/Y',
        'company_name'        => '',
        'company_email'       => '',
        'company_phone'       => '',
        'company_address'     => '',
        'company_logo'        => '',   // base64 or URL
        'bkash_number'        => '01619052413',
        'nagad_number'        => '01619052413',
        'cellfin_number'      => '01919052411',
        'rocket_number'       => '01919052410',
        'invoice_theme'       => 'indigo', // indigo|emerald|rose|amber|slate
        'invoice_due_days'    => '7',  // payment due after N days
        'invoice_tax_label'   => '',   // e.g. "VAT 15%"
        'invoice_tax_pct'     => '0',  // percentage
        'invoice_terms'       => '',   // Terms & conditions text
        'invoice_footer_note' => 'ধন্যবাদ আমাদের সেবা গ্রহণ করার জন্য!',
        'smtp_host'           => '',
        'smtp_port'           => '587',
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'smtp_from'           => '',
        'sms_provider'        => 'ssl',
        'sms_api_key'         => '',
        'sms_sender_id'       => '',
        'reminder_days'       => '7,30',
        'timezone'            => 'Asia/Dhaka',
    ];
    return array_merge($defaults, $out);
}
function saveSettings(array $d, array $u): array {
    // All allowed settings keys
            // Logo MIME validation — only allow safe image formats
        if (!empty($d['company_logo']) && str_starts_with($d['company_logo'], 'data:')) {
            if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $d['company_logo'])) {
                $d['company_logo'] = ''; // Reject SVG and other non-image types
            }
        }
        $allowed = [
        'site_title','company_name','company_email','company_phone','company_address',
        'company_logo','invoice_theme','invoice_due_days',
        'invoice_tax_label','invoice_tax_pct','invoice_terms','invoice_footer_note',
        'invoice_prefix','currency','currency_symbol','date_format','timezone',
        'remind_days','reminder_days',
        'bkash_number','nagad_number','cellfin_number','rocket_number',
        'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from',
        'sms_provider','sms_api_key','sms_sender_id',
        'whatsapp_msg_template','email_subject_template','email_body_template',
        'portal_message','login_logo','brand_color',
    ];
    $saved = [];
    $maskedKeys = ['smtp_pass', 'sms_api_key']; // these show •••••• in frontend
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $d)) continue;
        $val = sanitize($d[$k]);
        // Skip masked placeholder — user didn't change it, don't overwrite real value
        if (in_array($k, $maskedKeys) && $val === '••••••••') continue;
        dbQuery(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?",
            'sss', [$k, $val, $val]
        );
        $saved[] = $k;
    }
    auditLog($u['id'], $u['username'], 'SAVE_SETTINGS', null, null, null, ['keys' => $saved]);
    return ['message' => 'Settings saved.', 'saved' => count($saved)];
}

// ══════════════════════════════════════════════════════════════════════
// DASHBOARD
// ══════════════════════════════════════════════════════════════════════
function getDashboard(): array {
    $warn=EXPIRY_WARN_DAYS; // 7 days
    // Active clients with their products (exclude stale = expired 90+ days ago)
    $activeClients=dbFetchAll("SELECT c.id,c.name,c.phone,c.whatsapp,c.facebook,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR '||') products,
        GROUP_CONCAT(DISTINCT p.type ORDER BY p.name SEPARATOR '||') product_types,
        GROUP_CONCAT(DISTINCT s.site_url ORDER BY s.id SEPARATOR '||') sites,
        GROUP_CONCAT(DISTINCT s.expiry_date ORDER BY s.id SEPARATOR '||') expiry_dates,
        GROUP_CONCAT(DISTINCT s.id ORDER BY s.id SEPARATOR '||') sale_ids,
        COUNT(s.id) product_count,
        MIN(s.expiry_date) next_expiry,
        MIN(DATEDIFF(s.expiry_date,CURDATE())) min_days_left
        FROM clients c
        INNER JOIN sales s ON c.id=s.client_id AND s.renewal_status='active'
        LEFT JOIN products p ON s.product_id=p.id
        GROUP BY c.id ORDER BY MIN(s.expiry_date) ASC LIMIT 50");
    
    // Plugin 7-day final notice
    $pluginWarnings=dbFetchAll("SELECT s.id,s.expiry_date,s.site_url,s.invoice_no,
        c.name client_name,c.phone,c.whatsapp,c.facebook,
        p.name product_name,p.type,
        DATEDIFF(s.expiry_date,CURDATE()) days_left
        FROM sales s
        LEFT JOIN clients c ON s.client_id=c.id
        LEFT JOIN products p ON s.product_id=p.id
        WHERE p.type='Plugin' AND s.renewal_status='active'
        AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
        ORDER BY s.expiry_date ASC");

    return [
        'total_revenue'   =>dbFetch("SELECT COALESCE(SUM(price),0) v FROM sales WHERE payment_status='paid'")['v'],
        'partial_revenue' =>dbFetch("SELECT COALESCE(SUM(py.amount),0) v FROM payments py INNER JOIN sales s ON py.sale_id=s.id WHERE s.payment_status='partial'")['v'],
        'total_orders'    =>dbFetch("SELECT COUNT(*) v FROM sales WHERE renewal_status NOT IN('stale')")['v'],
        'total_clients'   =>dbFetch("SELECT COUNT(*) v FROM clients")['v'],
        'month_revenue'   =>dbFetch("SELECT COALESCE(SUM(price),0) v FROM sales WHERE payment_status='paid' AND DATE_FORMAT(sale_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")['v'],
        'pending_amt'     =>dbFetch("SELECT COALESCE(SUM(price),0) v FROM sales WHERE payment_status='pending' AND renewal_status NOT IN('stale')")['v'],
        'partial_sales'   =>dbFetch("SELECT COUNT(*) v FROM sales WHERE payment_status='partial' AND renewal_status NOT IN('stale')")['v'],
        'expiring_soon'   =>dbFetch("SELECT COUNT(*) v FROM sales WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY) AND renewal_status='active'",'i',[$warn])['v'],
        'plugin_warnings' =>count($pluginWarnings),
        'already_expired' =>dbFetch("SELECT COUNT(*) v FROM sales WHERE renewal_status='expired'")['v'],
        'stale_count'     =>dbFetch("SELECT COUNT(*) v FROM sales WHERE renewal_status='stale'")['v'],
        'open_tickets'    =>dbFetch("SELECT COUNT(*) v FROM tickets WHERE status NOT IN('resolved','closed')")['v'],
        'pending_tasks'   =>dbFetch("SELECT COUNT(*) v FROM tasks WHERE status!='done' AND (due_date IS NULL OR due_date>=NOW())")['v'],
        'overdue_tasks'   =>dbFetch("SELECT COUNT(*) v FROM tasks WHERE status!='done' AND due_date<NOW()")['v'],
        'due_count'       =>dbFetch("SELECT COUNT(*) v FROM sales WHERE payment_status IN('pending','partial') AND renewal_status NOT IN('stale')")['v'],
        'due_amount'      =>dbFetch("SELECT COALESCE(SUM(price - COALESCE((SELECT SUM(amount) FROM payments WHERE sale_id=s.id),0)),0) v FROM sales s WHERE payment_status IN('pending','partial') AND renewal_status NOT IN('stale')")['v'],
        'active_clients'  =>$activeClients,
        'plugin_final_notice'=>$pluginWarnings,
        'recent_sales'    =>dbFetchAll("SELECT s.*,c.name client_name,p.name product_name,p.type product_type FROM sales s LEFT JOIN clients c ON s.client_id=c.id LEFT JOIN products p ON s.product_id=p.id WHERE s.renewal_status NOT IN('stale') ORDER BY s.created_at DESC LIMIT 6"),
        'recent_tickets'  =>dbFetchAll("SELECT t.*,c.name client_name FROM tickets t LEFT JOIN clients c ON t.client_id=c.id WHERE t.status NOT IN('resolved','closed') ORDER BY t.created_at DESC LIMIT 4"),
        'due_tasks'       =>dbFetchAll("SELECT t.*,c.name client_name,u.full_name assigned_name FROM tasks t LEFT JOIN clients c ON t.client_id=c.id LEFT JOIN admin_users u ON t.assigned_to=u.id WHERE t.status!='done' ORDER BY t.due_date ASC LIMIT 5"),
    ];
}

// ══════════════════════════════════════════════════════════════════════
// FORECAST / ANALYTICS
// ══════════════════════════════════════════════════════════════════════
function getForecast(): array {
    // Sales expiring in the next 3 months (Estimated revenue)
    $upcoming=dbFetchAll("SELECT DATE_FORMAT(expiry_date,'%Y-%m') ym, COUNT(*) cnt, SUM(price) potential
        FROM sales WHERE renewal_status='active' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)
        GROUP BY ym ORDER BY ym");
    // Renewal rate — among sales that expired in the last 6 months (correlated queries)
    $total   = dbFetch("SELECT COUNT(*) v FROM sales WHERE expiry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND expiry_date < CURDATE()")['v'] ?? 0;
    $renewed = dbFetch("SELECT COUNT(*) v FROM sales WHERE expiry_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND expiry_date < CURDATE() AND renewal_status='renewed'")['v'] ?? 0;
    $renewRate = $total > 0 ? round(($renewed / $total) * 100, 1) : 0;
    // Monthly revenue trend (Last 12 months)
    $trend=dbFetchAll("SELECT DATE_FORMAT(sale_date,'%Y-%m') ym,COALESCE(SUM(CASE WHEN payment_status='paid' THEN price ELSE 0 END),0) revenue,COUNT(*) orders
        FROM sales WHERE sale_date>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) GROUP BY ym ORDER BY ym");
    // Client retention — count clients who made more than one purchase
    $multiPurchase=dbFetch("SELECT COUNT(*) v FROM (SELECT client_id FROM sales GROUP BY client_id HAVING COUNT(*)>1) t")['v']??0;
    $totalClients=(int)(dbFetch("SELECT COUNT(*) v FROM clients")['v']??0);
    // Next month forecast (Average of last 3 months)
    $avg=dbFetch("SELECT AVG(monthly) v FROM (SELECT SUM(price) monthly FROM sales WHERE payment_status='paid' AND sale_date>=DATE_SUB(CURDATE(),INTERVAL 3 MONTH) GROUP BY DATE_FORMAT(sale_date,'%Y-%m')) t")['v']??0;

    return ['upcoming_renewals'=>$upcoming,'renew_rate'=>$renewRate,'trend'=>$trend,'next_month_forecast'=>round((float)$avg),'multi_purchase_clients'=>$multiPurchase,'retention_rate'=>$totalClients>0?round(($multiPurchase/$totalClients)*100,1):0];
}

// ══════════════════════════════════════════════════════════════════════
// EXPIRY
// ══════════════════════════════════════════════════════════════════════
function getExpiring(array $p = []): array {
    $warn=EXPIRY_WARN_DAYS;
    $cols="s.id,s.sale_date,s.activated_at,s.expiry_date,s.site_url,s.license_type,s.renewal_status,s.expired_at,s.price,s.note,s.invoice_no,c.id client_id,c.name client_name,c.email client_email,c.phone client_phone,c.whatsapp client_whatsapp,c.facebook client_facebook,p.name product_name,p.type product_type,DATEDIFF(s.expiry_date,CURDATE()) days_left";
    $base="FROM sales s LEFT JOIN clients c ON s.client_id=c.id LEFT JOIN products p ON s.product_id=p.id WHERE s.expiry_date IS NOT NULL";
    return [
        'expiring_soon'     =>dbFetchAll("SELECT $cols $base AND s.renewal_status='active' AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY) ORDER BY s.expiry_date",'i',[$warn]),
        'plugin_7day'       =>dbFetchAll("SELECT $cols $base AND p.type='Plugin' AND s.renewal_status='active' AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY s.expiry_date"),
        'already_expired'   =>dbFetchAll("SELECT $cols $base AND s.renewal_status='expired' ORDER BY s.expired_at DESC LIMIT 100"),
        'stale'             =>dbFetchAll("SELECT $cols $base AND s.renewal_status='stale' ORDER BY s.expired_at DESC LIMIT 50"),
    ];
}
function markRenewed(int $id, array $d, array $u): array {
    $old=dbFetch("SELECT * FROM sales WHERE id=?",'i',[$id]);
    if(!$old) jsonError('Sale not found.',404);
    $newExpiry=sanitize($d['new_expiry']??'','date'); $newPrice=sanitize($d['new_price']??0,'float');
    dbQuery("UPDATE sales SET renewal_status='renewed' WHERE id=?",'i',[$id]);
    $newId=null;
    if($newExpiry&&$newPrice>0){
        $inv=generateInvoiceNo();
        dbQuery("INSERT INTO sales(sale_date,activated_at,expiry_date,client_id,product_id,price,site_url,license_type,payment_status,renewal_status,invoice_no,note,created_by) VALUES(CURDATE(),CURDATE(),?,?,?,?,?,?,'pending','active',?,'Renewed successfully',?)",'siidsssi',[$newExpiry,(int)$old['client_id'],(int)$old['product_id'],(float)$newPrice,$old['site_url']??'',$old['license_type']??'Single Site',$inv,$u['id']]);
        $newId=dbInsertId();
    }
    auditLog($u['id'],$u['username'],'RENEW_SALE','sales',$id,$old,['new_sale_id'=>$newId]);
    return ['message'=>'Marked as renewed.','new_sale_id'=>$newId];
}

// ══════════════════════════════════════════════════════════════════════
// PROMO CODES
// ══════════════════════════════════════════════════════════════════════
function getPromos(): array { return dbFetchAll("SELECT * FROM promo_codes ORDER BY created_at DESC"); }
function validatePromo(string $code, float $amount): array {
    if(!$code) return ['valid'=>false,'message'=>'Please enter a code.'];
    $p=dbFetch("SELECT * FROM promo_codes WHERE code=? AND is_active=1",'s',[strtoupper($code)]);
    if(!$p) return ['valid'=>false,'message'=>'Invalid code.'];
    if($p['valid_from']&&date('Y-m-d')<$p['valid_from']) return ['valid'=>false,'message'=>'Code is not active yet.'];
    if($p['valid_until']&&date('Y-m-d')>$p['valid_until']) return ['valid'=>false,'message'=>'Code has expired.'];
    if($p['max_uses']>0&&$p['used_count']>=$p['max_uses']) return ['valid'=>false,'message'=>'Code usage limit reached.'];
    if($p['min_amount']>0&&$amount<$p['min_amount']){
        $sym=getSettings()['currency_symbol']??'৳';
        return ['valid'=>false,'message'=>"Minimum order amount of {$sym}".number_format((float)$p['min_amount'],2)." required for this code."];
    }
    $discount=$p['type']==='percent'?round($amount*$p['value']/100,2):min($p['value'],$amount);
    $sym=getSettings()['currency_symbol']??'৳';
    return ['valid'=>true,'promo'=>$p,'discount'=>$discount,'final_price'=>$amount-$discount,'message'=>"✅ Discount: {$sym}".number_format($discount,2)];
}
function addPromo(array $d, array $u): array {
    $code=strtoupper(trim($d['code']??''));
    if(!$code) jsonError('Code is required.',422);
    if(strlen($code)>30) jsonError('Code must be 30 characters or less.',422);
    $type = in_array($d['type']??'', VALID_PROMO_TYPES) ? $d['type'] : 'percent';
    dbQuery("INSERT INTO promo_codes(code,type,value,max_uses,min_amount,valid_from,valid_until,is_active,description,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)",
        'ssdidssisi',[$code,$type,(float)($d['value']??0),(int)($d['max_uses']??0),(float)($d['min_amount']??0),$d['valid_from']??null,$d['valid_until']??null,(int)($d['is_active']??1),sanitize($d['description']??''),$u['id']]);
    $id=dbInsertId(); auditLog($u['id'],$u['username'],'CREATE_PROMO','promo_codes',$id);
    return ['message'=>'Promo code created.','id'=>$id];
}
function updatePromo(array $d, array $u): array {
    $id=(int)($d['id']??0); if(!$id) jsonError('ID Not found.',422);
    dbQuery("UPDATE promo_codes SET type=?,value=?,max_uses=?,min_amount=?,valid_from=?,valid_until=?,is_active=?,description=? WHERE id=?",
        'sdidssisi',[$d['type']??'percent',(float)($d['value']??0),(int)($d['max_uses']??0),(float)($d['min_amount']??0),$d['valid_from']??null,$d['valid_until']??null,(int)($d['is_active']??1),sanitize($d['description']??''),$id]);
    auditLog($u['id'],$u['username'],'UPDATE_PROMO','promo_codes',$id);
    return ['message'=>'Updated successfully.'];
}

// ══════════════════════════════════════════════════════════════════════
// CLIENTS
// ══════════════════════════════════════════════════════════════════════
function getClients(string $q=''): array {
    $base="SELECT c.*,
        COUNT(s.id) total_purchases,
        COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) total_spent,
        SUM(CASE WHEN s.renewal_status='active' AND (s.expiry_date IS NULL OR s.expiry_date>=CURDATE()) THEN 1 ELSE 0 END) active_sites
        FROM clients c LEFT JOIN sales s ON c.id=s.client_id";
    if($q){
        $like='%' . addcslashes($q, '%_\\') . '%';
        return dbFetchAll("$base WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.whatsapp LIKE ? OR c.facebook LIKE ? GROUP BY c.id ORDER BY c.created_at DESC",'sssss',[$like,$like,$like,$like,$like]);
    }
    return dbFetchAll("$base GROUP BY c.id ORDER BY c.created_at DESC");
}
function getClientDetail(int $id): array {
    $c=dbFetch("SELECT * FROM clients WHERE id=?",'i',[$id]); if(!$c) jsonError('Not found.',404);
    $c['sales']=dbFetchAll("SELECT s.*,p.name product_name,p.type product_type,DATEDIFF(s.expiry_date,CURDATE()) days_left FROM sales s LEFT JOIN products p ON s.product_id=p.id WHERE s.client_id=? ORDER BY s.sale_date DESC",'i',[$id]);
    $c['tickets']=dbFetchAll("SELECT * FROM tickets WHERE client_id=? ORDER BY created_at DESC LIMIT 5",'i',[$id]);
    $c['tasks']=dbFetchAll("SELECT * FROM tasks WHERE client_id=? AND status!='done' ORDER BY due_date ASC",'i',[$id]);
    return $c;
}
function checkClientDuplicate(string $phone, string $whatsapp, string $email, string $facebook, ?int $excludeId = null): void {
    $checks = [];

    // Phone duplicate check (শুধু যদি দেওয়া হয়)
    if ($phone !== '') {
        $row = $excludeId
            ? dbFetch("SELECT id,name FROM clients WHERE phone=? AND id!=? LIMIT 1", 'si', [$phone, $excludeId])
            : dbFetch("SELECT id,name FROM clients WHERE phone=? LIMIT 1", 's', [$phone]);
        if ($row) $checks[] = "Phone নম্বর ({$phone}) ইতিমধ্যে \"{$row['name']}\" ক্লায়েন্টের সাথে যুক্ত।";
    }

    // WhatsApp duplicate check
    if ($whatsapp !== '') {
        $row = $excludeId
            ? dbFetch("SELECT id,name FROM clients WHERE whatsapp=? AND id!=? LIMIT 1", 'si', [$whatsapp, $excludeId])
            : dbFetch("SELECT id,name FROM clients WHERE whatsapp=? LIMIT 1", 's', [$whatsapp]);
        if ($row) $checks[] = "WhatsApp নম্বর ({$whatsapp}) ইতিমধ্যে \"{$row['name']}\" ক্লায়েন্টের সাথে যুক্ত।";
    }

    // Email duplicate check
    if ($email !== '') {
        $row = $excludeId
            ? dbFetch("SELECT id,name FROM clients WHERE email=? AND id!=? LIMIT 1", 'si', [$email, $excludeId])
            : dbFetch("SELECT id,name FROM clients WHERE email=? LIMIT 1", 's', [$email]);
        if ($row) $checks[] = "Email ({$email}) ইতিমধ্যে \"{$row['name']}\" ক্লায়েন্টের সাথে যুক্ত।";
    }

    // Facebook duplicate check
    if ($facebook !== '') {
        $row = $excludeId
            ? dbFetch("SELECT id,name FROM clients WHERE facebook=? AND id!=? LIMIT 1", 'si', [$facebook, $excludeId])
            : dbFetch("SELECT id,name FROM clients WHERE facebook=? LIMIT 1", 's', [$facebook]);
        if ($row) $checks[] = "Facebook লিংক ইতিমধ্যে \"{$row['name']}\" ক্লায়েন্টের সাথে যুক্ত।";
    }

    if (!empty($checks)) {
        jsonError('ডুপ্লিকেট তথ্য পাওয়া গেছে: ' . implode(' | ', $checks), 409);
    }
}

function addClient(array $d, array $u): array {
    validateRequired($d, ['name']);
    $phone    = sanitize($d['phone']    ?? '');
    $whatsapp = sanitize($d['whatsapp'] ?? '');
    $email    = sanitize($d['email']    ?? '', 'email');
    $facebook = sanitize($d['facebook'] ?? '');

    checkClientDuplicate($phone, $whatsapp, $email, $facebook);

    dbQuery(
        "INSERT INTO clients(name,email,phone,whatsapp,facebook,location,note,created_by) VALUES(?,?,?,?,?,?,?,?)",
        'sssssssi',
        [sanitize($d['name']), $email, $phone, $whatsapp, $facebook, sanitize($d['location'] ?? ''), sanitize($d['note'] ?? ''), $u['id']]
    );
    $id = dbInsertId();
    auditLog($u['id'], $u['username'], 'CREATE_CLIENT', 'clients', $id);
    return ['message' => 'Client added.', 'id' => $id];
}

function updateClient(array $d, array $u): array {
    $id = (int)($d['id'] ?? 0);
    if (!$id) jsonError('ID Not found.', 422);
    $old = dbFetch("SELECT * FROM clients WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Client not found.', 404);

    $phone    = sanitize($d['phone']    ?? '');
    $whatsapp = sanitize($d['whatsapp'] ?? '');
    $email    = sanitize($d['email']    ?? '', 'email');
    $facebook = sanitize($d['facebook'] ?? '');

    checkClientDuplicate($phone, $whatsapp, $email, $facebook, $id);

    dbQuery(
        "UPDATE clients SET name=?,email=?,phone=?,whatsapp=?,facebook=?,location=?,note=? WHERE id=?",
        'sssssssi',
        [sanitize($d['name']), $email, $phone, $whatsapp, $facebook, sanitize($d['location'] ?? ''), sanitize($d['note'] ?? ''), $id]
    );
    auditLog($u['id'], $u['username'], 'UPDATE_CLIENT', 'clients', $id, $old, $d);
    return ['message' => 'Updated successfully.'];
}
function togglePortal(int $id, array $u): array {
    $c=dbFetch("SELECT portal_active,portal_token FROM clients WHERE id=?",'i',[$id]); if(!$c) jsonError('Not found.',404);
    $newActive=$c['portal_active']?0:1;
    $token=$c['portal_token']??bin2hex(random_bytes(32));
    dbQuery("UPDATE clients SET portal_active=?,portal_token=? WHERE id=?",'isi',[$newActive,$token,$id]);
    auditLog($u['id'],$u['username'],'TOGGLE_PORTAL','clients',$id);
    return ['message'=>$newActive?'Portal activated.':'Portal deactivated.','active'=>$newActive,'token'=>$newActive?$token:null];
}

// ══════════════════════════════════════════════════════════════════════
// PRODUCTS
// ══════════════════════════════════════════════════════════════════════
function getProducts(): array { return dbFetchAll("SELECT p.*,COUNT(s.id) sales_count,COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) total_revenue FROM products p LEFT JOIN sales s ON p.id=s.product_id GROUP BY p.id ORDER BY p.created_at DESC"); }
function addProduct(array $d, array $u): array {
    validateRequired($d,['name','price']); $type=in_array($d['type']??'',VALID_PRODUCT_TYPES)?$d['type']:'Theme';
    dbQuery("INSERT INTO products(name,type,price,version,description) VALUES(?,?,?,?,?)",'ssdss',[sanitize($d['name']),$type,(float)($d['price']??0),sanitize($d['version']??'1.0.0'),sanitize($d['description']??'')]);
    $id=dbInsertId(); auditLog($u['id'],$u['username'],'CREATE_PRODUCT','products',$id); return ['message'=>'Product added.','id'=>$id];
}
function updateProduct(array $d, array $u): array {
    $id=(int)($d['id']??0); if(!$id) jsonError('ID Not found.',422); $type=in_array($d['type']??'',VALID_PRODUCT_TYPES)?$d['type']:'Theme';
    $old=dbFetch("SELECT * FROM products WHERE id=?",'i',[$id]);
    dbQuery("UPDATE products SET name=?,type=?,price=?,version=?,description=? WHERE id=?",'ssdss'.'i',[sanitize($d['name']),$type,(float)($d['price']??0),sanitize($d['version']??'1.0.0'),sanitize($d['description']??''),$id]);
    auditLog($u['id'],$u['username'],'UPDATE_PRODUCT','products',$id,$old,$d); return ['message'=>'Updated successfully.'];
}

// ══════════════════════════════════════════════════════════════════════
// SALES
// ══════════════════════════════════════════════════════════════════════
function generateInvoiceNo(): string {
    $db   = getDB();
    // Advisory lock prevents race condition on concurrent requests
    $db->query("SELECT GET_LOCK('invoice_no_lock', 5)");
    try {
        $s      = getSettings();
        $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', $s['invoice_prefix'] ?? 'INV');
        $next   = max((int)($s['invoice_next'] ?? 1001), 1);
        $inv    = $prefix . '-' . $next;
        $newVal = (string)($next + 1);
        dbQuery(
            "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?",
            'sss', ['invoice_next', $newVal, $newVal]
        );
    } finally {
        $db->query("SELECT RELEASE_LOCK('invoice_no_lock')");
    }
    return $inv;
}
function getSales(array $p): array {
    // B02: Explicit columns — no SELECT s.* to prevent share_token exposure
    $sql="SELECT s.id,s.sale_date,s.activated_at,s.expiry_date,s.expired_at,s.client_id,s.product_id,
          s.price,s.original_price,s.discount_amount,s.promo_code_id,s.site_url,s.license_type,
          s.payment_status,s.renewal_status,s.invoice_no,s.note,s.amount_paid,s.created_at,s.updated_at,
          c.name client_name,c.phone client_phone,c.whatsapp client_whatsapp,c.facebook client_facebook,
          c.email client_email,p.name product_name,p.type product_type,
          DATEDIFF(s.expiry_date,CURDATE()) days_left,pc.code promo_code
          FROM sales s
          LEFT JOIN clients c ON s.client_id=c.id
          LEFT JOIN products p ON s.product_id=p.id
          LEFT JOIN promo_codes pc ON s.promo_code_id=pc.id";
    $where=[];$types='';$params=[];
    // B03: Exclude stale (90+ day expired) by default unless explicitly requested
    if(empty($p['renewal'])) { $where[]="s.renewal_status != 'stale'"; }
    if(!empty($p['q'])){$where[]="(c.name LIKE ? OR p.name LIKE ? OR s.site_url LIKE ? OR s.invoice_no LIKE ?)";$q='%'.addcslashes(sanitize($p['q']),'%_\\').'%';$types.='ssss';$params=array_merge($params,[$q,$q,$q,$q]);}
    if(!empty($p['type'])){$where[]="p.type=?";$types.='s';$params[]=sanitize($p['type']);}
    if(!empty($p['status'])){$where[]="s.payment_status=?";$types.='s';$params[]=sanitize($p['status']);}
    if(!empty($p['month'])){$where[]="DATE_FORMAT(s.sale_date,'%Y-%m')=?";$types.='s';$params[]=sanitize($p['month']);}
    if(!empty($p['client_id'])){$where[]="s.client_id=?";$types.='i';$params[]=(int)$p['client_id'];}
    if(!empty($p['year'])){$where[]="YEAR(s.sale_date)=?";$types.='i';$params[]=(int)$p['year'];}
    if(!empty($p['renewal'])){$where[]="s.renewal_status=?";$types.='s';$params[]=sanitize($p['renewal']);}
    if(!empty($p['license'])){$where[]="s.license_type=?";$types.='s';$params[]=sanitize($p['license']);}
    if(isset($p['expiry_days'])&&$p['expiry_days']!==''){
        $days=(int)$p['expiry_days'];
        if($days===0) $where[]="s.expiry_date<CURDATE()";
        elseif($days>0){
            $where[]="s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY)";
            $types.='i'; $params[]=$days;
        }
    }
    if($where) $sql.=' WHERE '.implode(' AND ',$where);
    $sql.=' ORDER BY s.sale_date DESC,s.created_at DESC';
    return dbFetchAll($sql,$types,$params);
}
function getSaleDetail(int $id): array {
    $r=dbFetch("SELECT s.id,s.sale_date,s.activated_at,s.expiry_date,s.expired_at,s.client_id,s.product_id,s.price,s.original_price,s.discount_amount,s.promo_code_id,s.site_url,s.license_type,s.payment_status,s.renewal_status,s.invoice_no,s.note,s.amount_paid,s.created_at,s.updated_at,c.name client_name,c.email client_email,c.phone client_phone,c.whatsapp client_whatsapp,c.facebook client_facebook,p.name product_name,p.type product_type,pc.code promo_code FROM sales s LEFT JOIN clients c ON s.client_id=c.id LEFT JOIN products p ON s.product_id=p.id LEFT JOIN promo_codes pc ON s.promo_code_id=pc.id WHERE s.id=?",'i',[$id]);
    if(!$r) jsonError('Not found.',404);
    $r['payments']=dbFetchAll("SELECT * FROM payments WHERE sale_id=? ORDER BY paid_at ASC",'i',[$id]);
    $r['total_paid']=array_sum(array_column($r['payments'],'amount'));
    $r['remaining'] = max(0, (float)$r['price'] - (float)$r['total_paid']);
    return $r;
}
function addSale(array $d, array $u, int $_depth = 0): array {
    validateRequired($d, ['sale_date', 'client_id', 'price']);

    // Multiple products support (max 1 level deep — no recursive products)
    $products = $d['products'] ?? null;
    if ($products && is_array($products) && count($products) > 0 && $_depth === 0) {
        if (count($products) > 20) jsonError('You can add up to 20 products at a time.', 422);
        $results = [];
        $skipped = 0;
        foreach ($products as $i => $prod) {
            $singleData = array_merge($d, [
                'product_id'     => (int)($prod['product_id'] ?? 0),
                'price'          => (float)($prod['price'] ?? 0) * (int)($prod['qty']??1),
                'original_price' => (float)($prod['price'] ?? 0),
                'note'           => ($d['note']??'') . ($i>0?' [Multi-sale '.$i.']':''),
            ]);
            unset($singleData['products']); // prevent accidental nesting
            if (!($singleData['product_id'] ?? 0)) { $skipped++; continue; }
            $results[] = addSale($singleData, $u, 1); // depth=1, no further recursion
        }
        $msg = count($results) . ' sale' . (count($results) !== 1 ? 's' : '') . ' added.';
        if ($skipped > 0) $msg .= " {$skipped} skipped (no product selected).";
        return ['message' => $msg, 'sales' => $results, 'skipped' => $skipped];
    }

    validateRequired($d, ['product_id']);
    $lic=in_array($d['license_type']??'', VALID_LICENSE_TYPES)?$d['license_type']:'Single Site';
    $status=in_array($d['payment_status']??'', VALID_PAYMENT_STATUS)?$d['payment_status']:'pending';
    $expiry=sanitize($d['expiry_date']??'','date');
    $origPrice=(float)($d['original_price']??$d['price']??0);
    $discount=max(0, (float)($d['discount_amount']??0)); // negative discount not allowed
    $price=(float)($d['price']??0);
    if($discount > $origPrice) $discount = $origPrice; // discount can't exceed original price
    $promoId=($d['promo_code_id']??null)?((int)$d['promo_code_id']):null;
    $promoIdBind=$promoId ?? 0; // bind as 0 if null — DB stores NULL via IFNULL
    $siteUrl=sanitize($d['site_url']??'');
    $inv=generateInvoiceNo();
    // Auto-set activated_at and expiry for plugins (1 year license)
    $activatedAt=sanitize($d['activated_at']??$d['sale_date']??'','date');
    $prod=dbFetch("SELECT type FROM products WHERE id=?",'i',[(int)$d['product_id']]);
    if(($prod['type']??'')==='Plugin' && !$expiry && $activatedAt){
        $expiry=date('Y-m-d',strtotime($activatedAt.' +365 days'));
    }
    dbQuery(
        "INSERT INTO sales(sale_date,activated_at,expiry_date,client_id,product_id,discount_amount,promo_code_id,price,site_url,license_type,payment_status,invoice_no,note,created_by) VALUES(?,?,?,?,?,?,NULLIF(?,0),?,?,?,?,?,?,?)",
        'sssiididsssssi',
        [sanitize($d['sale_date'],'date'), $activatedAt?:null, $expiry?:null,
         (int)$d['client_id'], (int)$d['product_id'],
         $discount, $promoIdBind, (float)$d['price'],
         $siteUrl, $lic, $status, $inv,
         sanitize($d['note']??''), $u['id']]
    );
    $id=dbInsertId();
    if($promoId) dbQuery("UPDATE promo_codes SET used_count=used_count+1 WHERE id=?",'i',[$promoId]);
    if($status==='paid') {
        $method = in_array($d['payment_method'] ?? '', VALID_PAYMENT_METHODS) ? $d['payment_method'] : 'bKash Personal';
        dbQuery("INSERT INTO payments(sale_id,amount,method,note,created_by) VALUES(?,?,?,?,?)",'idssi',[$id,(float)$d['price'],$method,'Fully paid',$u['id']]);
    }
    auditLog($u['id'],$u['username'],'CREATE_SALE','sales',$id,null,['invoice'=>$inv]);
    return ['message'=>'Sale added.','id'=>$id,'invoice_no'=>$inv];
}
function updateSale(array $d, array $u): array {
    $id=(int)($d['id']??0); if(!$id) jsonError('ID Not found.',422);
    validateRequired($d, ['sale_date','client_id','product_id','price']);
    $old=dbFetch("SELECT * FROM sales WHERE id=?", 'i', [$id]);
    if(!$old) jsonError('Sale not found.',404);
    $lic=in_array($d['license_type']??'', VALID_LICENSE_TYPES)?$d['license_type']:'Single Site';
    $status=in_array($d['payment_status']??'', VALID_PAYMENT_STATUS)?$d['payment_status']:'pending';
    $renewal=in_array($d['renewal_status']??'',VALID_RENEWAL_STATUS)?$d['renewal_status']:'active';
    $expiry=sanitize($d['expiry_date']??'','date');
    $siteUrl2 = sanitize($d['site_url']??'');
    $activatedAt2=sanitize($d['activated_at']??'','date');
    // Auto-calc expiry for plugins on update
    $prod2=dbFetch("SELECT type FROM products WHERE id=?",'i',[(int)$d['product_id']]);
    if(($prod2['type']??'')==='Plugin' && $activatedAt2 && empty($d['expiry_date'])){
        $expiry=date('Y-m-d',strtotime($activatedAt2.' +365 days'));
    }
    $origPrice2 = (float)($d['original_price'] ?? $d['price'] ?? 0);
    $discount2  = max(0, (float)($d['discount_amount'] ?? 0));
    dbQuery("UPDATE sales SET sale_date=?,activated_at=?,expiry_date=?,client_id=?,product_id=?,price=?,original_price=?,discount_amount=?,site_url=?,license_type=?,payment_status=?,renewal_status=?,note=? WHERE id=?",
        'sssiidddsssssi',[sanitize($d['sale_date'],'date'),$activatedAt2?:null,$expiry?:null,(int)$d['client_id'],(int)$d['product_id'],(float)$d['price'],$origPrice2,$discount2,$siteUrl2,$lic,$status,$renewal,sanitize($d['note']??''),$id]);
    // Recalculate payment_status based on actual payments vs new price
    $newPrice    = (float)$d['price'];
    $totalPaid   = dbFetch("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE sale_id=?", 'i', [$id])['v'] ?? 0;
    $newStatus   = $totalPaid >= $newPrice ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending');
    // Only override if user didn't explicitly set a status, or if payments clearly contradict it
    if ($newStatus === 'paid' && $status !== 'paid') {
        dbQuery("UPDATE sales SET payment_status='paid' WHERE id=?", 'i', [$id]);
    } elseif ($newStatus !== 'paid' && $status === 'paid' && $totalPaid < $newPrice) {
        dbQuery("UPDATE sales SET payment_status=? WHERE id=?", 'si', [$newStatus, $id]);
    }
    auditLog($u['id'],$u['username'],'UPDATE_SALE','sales',$id,$old,$d);
    return ['message'=>'Updated successfully.'];
}
function getInvoiceData(int $id): array {
    $s = getSaleDetail($id);
    $settings = getSettings();
    // Remove sensitive fields from settings — never expose to client
    $sensitiveKeys = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from',
                      'sms_api_key','sms_sender_id','sms_provider',
                      'whatsapp_msg_template','email_subject_template','email_body_template',
                      'portal_message','login_logo','brand_color','reminder_days',
                      'invoice_next'];
    foreach ($sensitiveKeys as $k) unset($settings[$k]);
    // Remove sensitive fields from sale data
    unset($s['share_token'], $s['portal_token'], $s['created_by']);
    $s['settings'] = $settings;
    return $s;
}
function getInvoiceShareLink(int $id): array {
    if (!$id) jsonError('Sale ID required.', 422);

    // Ensure share_token column exists (auto-migrate if missing)
    try {
        $check = getDB()->query("SHOW COLUMNS FROM sales LIKE 'share_token'");
        if ($check->num_rows === 0) {
            getDB()->query("ALTER TABLE sales ADD COLUMN share_token VARCHAR(64) DEFAULT NULL");
        }
    } catch (Exception $e) {
        error_log('share_token migration: ' . $e->getMessage());
    }

    $sale = dbFetch("SELECT id, invoice_no, share_token FROM sales WHERE id=?", 'i', [$id]);
    if (!$sale) jsonError('Sale not found.', 404);

    $token = $sale['share_token'] ?? '';
    if (!$token) {
        $token = bin2hex(random_bytes(20));
        dbQuery("UPDATE sales SET share_token=? WHERE id=?", 'si', [$token, $id]);
    }
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
               . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $link = $baseUrl . '/api/index.php?action=view_invoice_share&token=' . $token;
    return ['token' => $token, 'link' => $link, 'invoice_no' => $sale['invoice_no'] ?? ''];
}
function regenerateInvoice(int $id): array {
    $inv=generateInvoiceNo(); dbQuery("UPDATE sales SET invoice_no=? WHERE id=?",'si',[$inv,$id]);
    return ['message'=>'Invoice number regenerated.','invoice_no'=>$inv];
}

// ══════════════════════════════════════════════════════════════════════
// PARTIAL PAYMENTS
// ══════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════
// DUE CLIENTS
// ══════════════════════════════════════════════════════════════════════
function getDueClients(array $p = []): array {
    $status = in_array($p['status'] ?? '', ['pending','partial']) ? $p['status'] : '';
    $search = sanitize($p['q'] ?? '');
    $sort   = ($p['sort'] ?? '') === 'asc' ? 'ASC' : 'DESC';

    $where = "s.payment_status IN ('pending','partial') AND s.renewal_status NOT IN ('stale')";
    $args  = [];
    $types = '';

    if ($status) {
        $where .= " AND s.payment_status = ?";
        $types .= 's'; $args[] = $status;
    }
    if ($search) {
        $where .= " AND (c.name LIKE ? OR p.name LIKE ? OR s.site_url LIKE ? OR s.invoice_no LIKE ?)";
        $types .= 'ssss';
        $like = "%$search%";
        $args = array_merge($args, [$like,$like,$like,$like]);
    }

    $rows = dbFetchAll(
        "SELECT s.id, s.invoice_no, s.sale_date, s.price, s.payment_status,
                s.site_url, s.expiry_date, s.note, s.renewal_status,
                c.id client_id, c.name client_name, c.phone client_phone,
                c.whatsapp client_whatsapp, c.facebook client_facebook,
                p.name product_name, p.type product_type,
                COALESCE(pay.total_paid,0) total_paid
         FROM sales s
         LEFT JOIN clients c ON s.client_id=c.id
         LEFT JOIN products p ON s.product_id=p.id
         LEFT JOIN (SELECT sale_id, SUM(amount) total_paid FROM payments GROUP BY sale_id) pay ON pay.sale_id=s.id
         WHERE $where
         ORDER BY (s.price - COALESCE(pay.total_paid,0)) $sort",
        $types ?: '', $args ?: []
    );

    foreach ($rows as &$r) {
        $r['remaining'] = (float)$r['price'] - (float)$r['total_paid'];
    }
    unset($r); // PHP reference fix

    $totalDue     = array_sum(array_column($rows, 'remaining'));
    $pendingCount = count(array_filter($rows, fn($r) => $r['payment_status'] === 'pending'));
    $partialCount = count(array_filter($rows, fn($r) => $r['payment_status'] === 'partial'));

    return [
        'data'          => $rows,
        'total_due'     => $totalDue,
        'pending_count' => $pendingCount,
        'partial_count' => $partialCount,
        'total_count'   => count($rows),
    ];
}

function getPayments(int $saleId): array { return dbFetchAll("SELECT * FROM payments WHERE sale_id=? ORDER BY paid_at ASC",'i',[$saleId]); }
function addPayment(array $d, array $u): array {
    $saleId=(int)($d['sale_id']??0); $amount=(float)($d['amount']??0);
    if(!$saleId||!$amount) jsonError('Sale ID and amount are required.',422);
    if($amount <= 0) jsonError('Amount must be greater than zero.',422);
    $sale=dbFetch("SELECT * FROM sales WHERE id=?",'i',[$saleId]); if(!$sale) jsonError('Sale not found.',404);
    // Overpayment protection
    $alreadyPaid=(float)(dbFetch("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE sale_id=?",'i',[$saleId])['v']??0);
    $remaining=(float)$sale['price'] - $alreadyPaid;
    if($amount > $remaining + 0.01) jsonError('পরিমাণ বেশি হয়ে গেছে। বাকি আছে: '.number_format($remaining,2).' টাকা।',422);
    $method=in_array($d['method']??'',VALID_PAYMENT_METHODS)?$d['method']:'bKash Personal';
    // Accept Y-m-d or Y-m-d H:i:s datetime — sanitize 'date' only accepts Y-m-d so validate directly
    $rawPaidAt = trim($d['paid_at'] ?? '');
    if ($rawPaidAt && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $rawPaidAt)) {
        $paidAt = $rawPaidAt;
    } else {
        $paidAt = date('Y-m-d H:i:s');
    }
    dbQuery("INSERT INTO payments(sale_id,amount,method,trx_id,paid_at,note,created_by) VALUES(?,?,?,?,?,?,?)",'idssssi',[$saleId,$amount,$method,sanitize($d['trx_id']??''),$paidAt,sanitize($d['note']??''),$u['id']]);
    $pid=dbInsertId();
    $totalPaid=$alreadyPaid+$amount;
    $newStatus=$totalPaid>=$sale['price']?'paid':($totalPaid>0?'partial':'pending');
    dbQuery("UPDATE sales SET payment_status=? WHERE id=?",'si',[$newStatus,$saleId]);
    auditLog($u['id'],$u['username'],'ADD_PAYMENT','payments',$pid,null,['amount'=>$amount,'method'=>$method]);
    return ['message'=>'Payment added.','id'=>$pid,'new_status'=>$newStatus,'total_paid'=>$totalPaid,'remaining'=>max(0,$sale['price']-$totalPaid)];
}
function deletePayment(int $id, array $u): array {
    $p=dbFetch("SELECT * FROM payments WHERE id=?",'i',[$id]); if(!$p) jsonError('Not found.',404);
    dbQuery("DELETE FROM payments WHERE id=?",'i',[$id]);
    // Recalculate status
    $sale=dbFetch("SELECT price FROM sales WHERE id=?",'i',[$p['sale_id']]);
    $totalPaid=dbFetch("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE sale_id=?",'i',[$p['sale_id']])['v'];
    $newStatus=$totalPaid>=$sale['price']?'paid':($totalPaid>0?'partial':'pending');
    dbQuery("UPDATE sales SET payment_status=? WHERE id=?",'si',[$newStatus,$p['sale_id']]);
    auditLog($u['id'],$u['username'],'DELETE_PAYMENT','payments',$id,$p);
    return ['message'=>'Deleted.','new_status'=>$newStatus];
}

// ══════════════════════════════════════════════════════════════════════
// REMINDERS
// ══════════════════════════════════════════════════════════════════════
function previewReminder(array $d): array {
    $saleId=(int)($d['sale_id']??0); $channel=$d['channel']??'whatsapp';
    $sale=getSaleDetail($saleId);
    $settings=getSettings(); $tpl=$settings['whatsapp_msg_template']??'';
    $msg=str_replace(['{name}','{site}','{product}','{expiry}','{company}'],[$sale['client_name'],$sale['site_url'],$sale['product_name'],$sale['expiry_date']??'—',$settings['company_name']??''],$tpl);
    return ['message'=>$msg,'client_name'=>$sale['client_name'],'channel'=>$channel,'whatsapp'=>$sale['client_whatsapp'],'email'=>$sale['client_email']];
}
function sendReminder(array $d, array $u): array {
    $saleId=(int)($d['sale_id']??0); $channel=$d['channel']??'whatsapp';
    $preview=previewReminder($d);
    // Log this (actual sending handled from frontend via WhatsApp/Email link)
    $clientId = dbFetch("SELECT client_id FROM sales WHERE id=?", 'i', [$saleId])['client_id'] ?? 0;
    dbQuery(
        "INSERT INTO reminders(sale_id,client_id,channel,status,message,scheduled_at,type,created_by) VALUES(?,?,?,?,?,NOW(),'renewal',?)",
        'iisssi',
        [$saleId, (int)$clientId, $channel, 'sent', $preview['message'], (int)$u['id']]
    );
    auditLog($u['id'],$u['username'],'SEND_REMINDER','sales',$saleId);
    return array_merge($preview,['message_text'=>$preview['message'],'logged'=>true,'message'=>'Reminder logged.']);
}
function getReminderLog(): array {
    return dbFetchAll("SELECT r.*,c.name client_name FROM reminders r LEFT JOIN clients c ON r.client_id=c.id ORDER BY r.created_at DESC LIMIT 100");
}
function getTickets(array $p = []): array {
    $where = []; $types = ''; $params = [];
    if (!empty($p['status']))    { $where[] = "t.status=?";    $types .= 's'; $params[] = sanitize($p['status']); }
    if (!empty($p['priority']))  { $where[] = "t.priority=?";  $types .= 's'; $params[] = sanitize($p['priority']); }
    if (!empty($p['client_id'])) { $where[] = "t.client_id=?"; $types .= 'i'; $params[] = (int)$p['client_id']; }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT t.*,c.name client_name,u.full_name assigned_name,
            (SELECT COUNT(*) FROM ticket_replies m WHERE m.ticket_id=t.id) msg_count
            FROM tickets t
            LEFT JOIN clients c ON t.client_id=c.id
            LEFT JOIN admin_users u ON t.assigned_to=u.id
            $ws ORDER BY FIELD(t.priority,'urgent','high','medium','low'),t.created_at DESC";
    return $types ? dbFetchAll($sql, $types, $params) : dbFetchAll($sql);
}
function getTicketDetail(int $id): array {
    $t=dbFetch("SELECT t.*,c.name client_name,c.email client_email,c.whatsapp client_whatsapp FROM tickets t LEFT JOIN clients c ON t.client_id=c.id WHERE t.id=?",'i',[$id]);
    if(!$t) jsonError('Ticket not found.',404);
    $t['messages']=dbFetchAll("SELECT m.*,u.full_name FROM ticket_replies m LEFT JOIN admin_users u ON m.user_id=u.id WHERE m.ticket_id=? ORDER BY m.created_at ASC",'i',[$id]);
    return $t;
}
function addTicket(array $d, array $u): array {
    validateRequired($d,['client_id','subject']);
    $no=generateTicketNo(); $priority=in_array($d['priority']??'', VALID_PRIORITIES)?$d['priority']:'medium';
    
    $assignedTo = isset($d['assigned_to']) && $d['assigned_to'] ? (int)$d['assigned_to'] : null;
    dbQuery(
        "INSERT INTO tickets(ticket_no,client_id,sale_id,subject,priority,assigned_to,status) VALUES(?,?,?,?,?,?,'open')",
        'siissi',
        [
            $no,
            (int)$d['client_id'],
            isset($d['sale_id']) && $d['sale_id'] ? (int)$d['sale_id'] : null,
            sanitize($d['subject']),
            $priority,
            $assignedTo,
        ]
    );
    $id=dbInsertId();
    if(!empty($d['message'])) dbQuery("INSERT INTO ticket_replies(ticket_id,sender,user_id,message) VALUES(?,?,?,?)",'isis',[$id,'admin',$u['id'],sanitize($d['message'])]);
    auditLog($u['id'],$u['username'],'CREATE_TICKET','tickets',$id);
    return ['message'=>'Ticket created.','id'=>$id,'ticket_no'=>$no];
}
function updateTicketStatus(array $d, array $u): array {
    $id=(int)($d['id']??0); if(!$id) jsonError('ID Not found.',422);
    $status=in_array($d['status']??'',VALID_TICKET_STATUSES_EXT)?$d['status']:'open';
    $priority=in_array($d['priority']??'', VALID_PRIORITIES)?$d['priority']:'medium';
    $resolved=$status==='resolved'||$status==='closed'?date('Y-m-d H:i:s'):null;
    $subject = !empty($d['subject']) ? sanitize($d['subject']) : null;
    if ($subject) {
        dbQuery("UPDATE tickets SET status=?,priority=?,assigned_to=?,resolved_at=?,subject=? WHERE id=?",
            'ssi'.'sis', [$status,$priority,isset($d['assigned_to'])&&$d['assigned_to']?(int)$d['assigned_to']:null,$resolved,$subject,$id]);
    } else {
        dbQuery("UPDATE tickets SET status=?,priority=?,assigned_to=?,resolved_at=? WHERE id=?",
            'ssi'.'si', [$status,$priority,isset($d['assigned_to'])&&$d['assigned_to']?(int)$d['assigned_to']:null,$resolved,$id]);
    }
    auditLog($u['id'],$u['username'],'UPDATE_TICKET','tickets',$id);
    return ['message'=>'Updated successfully.'];
}
function replyTicket(array $d, array $u): array {
    $id=(int)($d['ticket_id']??0); $msg=sanitize($d['message']??'');
    if(!$id||!$msg) jsonError('Ticket ID and message are required.',422);
    dbQuery("INSERT INTO ticket_replies(ticket_id,sender,user_id,message) VALUES(?,?,?,?)",'isis',[$id,'admin',$u['id'],$msg]);
    $rid = dbInsertId(); // must capture before UPDATE query overwrites last insert ID
    dbQuery("UPDATE tickets SET updated_at=NOW(),status=CASE WHEN status='waiting' THEN 'in_progress' ELSE status END WHERE id=?",'i',[$id]);
    auditLog($u['id'], $u['username'], 'REPLY_TICKET', 'tickets', $id);
    return ['message' => 'Message added.', 'id' => $rid];
}

// ══════════════════════════════════════════════════════════════════════
// TASKS
// ══════════════════════════════════════════════════════════════════════
function getTasks(array $p): array {
    $where=[];$types='';$params=[];
    if(!empty($p['status'])){$where[]="t.status=?";$types.='s';$params[]=sanitize($p['status']);}
    if(!empty($p['assigned_to'])){$where[]="t.assigned_to=?";$types.='i';$params[]=(int)$p['assigned_to'];}
    if(!empty($p['overdue'])&&$p['overdue']==='1'){$where[]="t.due_date<NOW() AND t.status!='done'";}
    $ws=$where?'WHERE '.implode(' AND ',$where):'';
    return dbFetchAll("SELECT t.*,c.name client_name,u.full_name assigned_name FROM tasks t LEFT JOIN clients c ON t.client_id=c.id LEFT JOIN admin_users u ON t.assigned_to=u.id $ws ORDER BY CASE t.priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, ISNULL(t.due_date), t.due_date ASC",$types,$params);
}
function addTask(array $d, array $u): array {
    validateRequired($d,['title']);
    $priority = in_array($d['priority']??'', VALID_TASK_PRIORITIES) ? $d['priority'] : 'medium';
    $status   = 'pending'; // always start as pending
    dbQuery("INSERT INTO tasks(title,description,client_id,sale_id,priority,status,due_date,assigned_to,created_by) VALUES(?,?,?,?,?,?,?,?,?)",
        'ssiisssii',[sanitize($d['title']),sanitize($d['description']??''),($d['client_id']??null)?((int)$d['client_id']):null,($d['sale_id']??null)?((int)$d['sale_id']):null,$priority,$status,$d['due_date']??null,($d['assigned_to']??null)?((int)$d['assigned_to']):null,$u['id']]);
    $tid = dbInsertId();
    auditLog($u['id'], $u['username'], 'CREATE_TASK', 'tasks', $tid);
    return ['message' => 'Task created.', 'id' => $tid];
}
function updateTask(array $d, array $u): array {
    $id=(int)($d['id']??0); if(!$id) jsonError('ID Not found.',422);
    $cur = dbFetch("SELECT status FROM tasks WHERE id=?", 'i', [$id]);
    if(!$cur) jsonError('Task not found.', 404);
    $priority = in_array($d['priority']??'', VALID_TASK_PRIORITIES) ? $d['priority'] : 'medium';
    $validStatuses = ['pending','in_progress','done','cancelled'];
    $status = (isset($d['status']) && in_array($d['status'], $validStatuses)) ? $d['status'] : $cur['status'];
    dbQuery("UPDATE tasks SET title=?,description=?,priority=?,status=?,due_date=?,assigned_to=? WHERE id=?",
        'sssssii',[sanitize($d['title']),sanitize($d['description']??''),$priority,$status,$d['due_date']??null,($d['assigned_to']??null)?((int)$d['assigned_to']):null,$id]);
    auditLog($u['id'], $u['username'], 'UPDATE_TASK', 'tasks', $id);
    return ['message' => 'Updated successfully.'];
}
function toggleTask(int $id, array $u): array {
    $t=dbFetch("SELECT status FROM tasks WHERE id=?",'i',[$id]); if(!$t) jsonError('Not found.',404);
    $new = $t['status'] === 'done' ? 'pending' : 'done';
    dbQuery("UPDATE tasks SET status=? WHERE id=?", 'si', [$new, $id]);
    auditLog($u['id'], $u['username'], 'TOGGLE_TASK', 'tasks', $id, null, ['status' => $new]);
    return ['message' => $new === 'done' ? '✅ Marked as completed.' : '↩️ Reopened.', 'status' => $new];
}

// ══════════════════════════════════════════════════════════════════════
// SMS
// ══════════════════════════════════════════════════════════════════════
function sendSMS(array $d, array $u): array {
    $phone    = preg_replace('/[^0-9+]/', '', $d['phone'] ?? '');
    $msg      = sanitize($d['message'] ?? '');
    $clientId = (int)($d['client_id'] ?? 0);
    if (!$phone || !$msg)        jsonError('Phone number and message are required.', 422);
    if (strlen($phone) > 20)     jsonError('Invalid Phone number.', 422);
    if (strlen($msg) > 640)      jsonError('Message must be 640 characters or less.', 422);
    $settings = getSettings();
    $apiKey   = $settings['sms_api_key']  ?? '';
    $senderId = $settings['sms_sender_id'] ?? '';
    $status   = 'failed';
    $response = 'API Key Setting not found.';
    if ($apiKey && $senderId) {
        // SSL Wireless — fixed, whitelisted base URL (no user-controlled URL)
        $baseUrl = 'https://sms.sslwireless.com/pushapi/dynamic/server.php';
        $params  = http_build_query([
            'apikey'  => $apiKey,
            'sid'     => $senderId,
            'msisdn'  => $phone,
            'msg'     => $msg,
            'csmsid'  => uniqid('sms_', true),
        ]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $baseUrl . '?' . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,   // enforce SSL cert check
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,  // no redirects (SSRF guard)
            CURLOPT_MAXREDIRS      => 0,
        ]);
        $response = (string)curl_exec($ch);
        curl_close($ch);
        $status = (str_contains($response, 'success') || str_contains($response, 'SENT')) ? 'sent' : 'failed';
    }
    dbQuery(
        "INSERT IGNORE INTO sms_log(client_id,phone,message,status,provider,response,sent_by) VALUES(?,?,?,?,?,?,?)",
        'isssssi',
        [$clientId, $phone, $msg, $status, $settings['sms_provider'] ?? 'ssl_wireless', substr($response,0,500), $u['id']]
    );
    auditLog($u['id'], $u['username'], 'SEND_SMS', null, $clientId, null, ['phone'=>$phone,'status'=>$status]);
    return ['message' => $status==='sent' ? 'SMS Sent.' : 'SMS Send failed.', 'status'=>$status];
}
function getSMSLog(): array { return dbFetchAll("SELECT s.*,c.name client_name FROM sms_log s LEFT JOIN clients c ON s.client_id=c.id ORDER BY s.sent_at DESC LIMIT 100"); }

// ══════════════════════════════════════════════════════════════════════
// BACKUP / EXPORT
// ══════════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════════
// GLOBAL SEARCH (AJAX)
// ══════════════════════════════════════════════════════════════════════
function globalSearch(string $q): array {
    if(strlen($q)<2) return ['clients'=>[],'sales'=>[],'products'=>[]];
    $like='%' . addcslashes($q, '%_\\') . '%';
    $clients=dbFetchAll("SELECT id,name,phone,whatsapp,email,facebook FROM clients WHERE name LIKE ? OR phone LIKE ? OR email LIKE ? OR whatsapp LIKE ? LIMIT 8",'ssss',[$like,$like,$like,$like]);
    $sales=dbFetchAll("SELECT s.id,s.invoice_no,s.site_url,s.price,s.payment_status,s.expiry_date,c.name client_name,p.name product_name FROM sales s LEFT JOIN clients c ON s.client_id=c.id LEFT JOIN products p ON s.product_id=p.id WHERE c.name LIKE ? OR s.site_url LIKE ? OR s.invoice_no LIKE ? OR p.name LIKE ? LIMIT 8",'ssss',[$like,$like,$like,$like]);
    $products=dbFetchAll("SELECT id,name,type,price FROM products WHERE name LIKE ? LIMIT 5",'s',[$like]);
    return compact('clients','sales','products');
}

// ══════════════════════════════════════════════════════════════════════
// EXPORT: CLIENT SHEET (Google Sheets compatible CSV)
// ══════════════════════════════════════════════════════════════════════
function exportClientsSheet(): never {
    @set_time_limit(60); @ini_set('memory_limit','256M');
    $rows=dbFetchAll("SELECT 
        c.name,c.phone,c.whatsapp,c.email,c.facebook,c.location,
        COUNT(s.id) total_sales,
        SUM(CASE WHEN s.renewal_status='active' AND (s.expiry_date IS NULL OR s.expiry_date>=CURDATE()) THEN 1 ELSE 0 END) active_sites,
        GROUP_CONCAT(CASE WHEN s.renewal_status='active' AND (s.expiry_date IS NULL OR s.expiry_date>=CURDATE()) THEN s.site_url ELSE NULL END SEPARATOR ', ') active_site_urls,
        GROUP_CONCAT(CASE WHEN s.renewal_status='active' AND (s.expiry_date IS NULL OR s.expiry_date>=CURDATE()) THEN p.name ELSE NULL END SEPARATOR ', ') active_products,
        GROUP_CONCAT(CASE WHEN s.renewal_status='active' AND (s.expiry_date IS NULL OR s.expiry_date>=CURDATE()) THEN s.expiry_date ELSE NULL END SEPARATOR ', ') expiry_dates,
        COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) total_spent
        FROM clients c LEFT JOIN sales s ON c.id=s.client_id LEFT JOIN products p ON s.product_id=p.id
        GROUP BY c.id ORDER BY c.name ASC");
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clients-sheet-'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out=fopen('php://output','w');
    fputcsv($out,['Name','Phone','WhatsApp','Email','Facebook','Location','Total Sales','Active Sites','Active Site URLs','Active Products','Expiry Dates','Total Spent ($)']);
    foreach($rows as $r) fputcsv($out,array_values($r));
    fclose($out); exit;
}

// ══════════════════════════════════════════════════════════════════════
// EXPORT: YEARLY PLUGIN REPORT
// ══════════════════════════════════════════════════════════════════════
function exportYearlyPlugins(): never {
    @set_time_limit(60); @ini_set('memory_limit','256M');
    $year = (int)(($_GET['year'] ?? date('Y')));
    if ($year < 2000 || $year > 2100) $year = (int)date('Y'); // sanity check
    $rows=dbFetchAll("SELECT 
        c.name client_name, c.phone, c.whatsapp, c.facebook,
        p.name product_name, p.type product_type,
        s.invoice_no, s.site_url, s.sale_date, s.expiry_date,
        s.price, s.payment_status, s.renewal_status, s.license_type,
        DATEDIFF(s.expiry_date, CURDATE()) days_left
        FROM sales s
        LEFT JOIN clients c ON s.client_id=c.id
        LEFT JOIN products p ON s.product_id=p.id
        WHERE p.type='Plugin' AND YEAR(s.sale_date)=?
        ORDER BY s.expiry_date ASC, c.name ASC", 'i', [$year]);
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="yearly-plugins-'.$year.'.csv"');
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');
    fputcsv($out,['Client','Phone','WhatsApp','Facebook','Plugin','Invoice','Site URL','Purchase Date','Expiry Date','Price ($)','Payment Status','Renewal Status','License','Days Remaining']);
    foreach($rows as $r) fputcsv($out,array_values($r));
    fclose($out); exit;
}

function exportJSON(): never {
    @set_time_limit(60); @ini_set('memory_limit','256M');
    $data=[
        'clients'  =>dbFetchAll("SELECT id,name,email,phone,whatsapp,facebook,location,note,created_at FROM clients"),
        'products' =>dbFetchAll("SELECT id,name,type,price,version,description,created_at FROM products"),
        'sales'    =>dbFetchAll("SELECT id,invoice_no,sale_date,activated_at,expiry_date,client_id,product_id,price,original_price,discount_amount,payment_status,renewal_status,site_url,license_type,note,created_at FROM sales"),
        'payments' =>dbFetchAll("SELECT id,sale_id,amount,method,trx_id,paid_at,note FROM payments"),
        'tickets'  =>dbFetchAll("SELECT id,client_id,subject,status,priority,created_at,updated_at FROM tickets"),
        'tasks'    =>dbFetchAll("SELECT id,client_id,title,status,priority,due_date,created_at FROM tasks"),
        'exported_at'=>date('Y-m-d H:i:s'),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="wp-sales-backup-'.date('Y-m-d').'.json"');
    echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}
function exportExcel(): never {
    @set_time_limit(60); @ini_set('memory_limit','256M');
    $sales=dbFetchAll("SELECT s.invoice_no,s.sale_date,s.expiry_date,c.name client,c.email,c.phone,p.name product,p.type,s.price original_price,s.discount_amount,(s.price-COALESCE(s.discount_amount,0)) final_price,s.payment_status,s.renewal_status,s.site_url,s.license_type FROM sales s LEFT JOIN clients c ON s.client_id=c.id LEFT JOIN products p ON s.product_id=p.id ORDER BY s.sale_date DESC");
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wp-sales-'.date('Y-m-d').'.xls"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
    echo "<table border=1><tr><th>Invoice</th><th>Date</th><th>Expiry Date</th><th>Client</th><th>Email</th><th>Phone</th><th>Product</th><th>Type</th><th>Base Price</th><th>Discount</th><th>Price</th><th>Payment</th><th>Renewal</th><th>Site</th><th>License</th></tr>";
    foreach($sales as $r) echo "<tr>".implode('',array_map(fn($v)=>"<td>".htmlspecialchars($v??'')."</td>",$r))."</tr>";
    echo "</table>"; exit;
}

// ══════════════════════════════════════════════════════════════════════
// CLIENT PORTAL (Public)
// ══════════════════════════════════════════════════════════════════════
function portalView(string $token): array {
    if(!$token) jsonError('Token not found.',400);
    $c=dbFetch("SELECT * FROM clients WHERE portal_token=? AND portal_active=1",'s',[$token]);
    if(!$c) jsonError('Portal link is invalid or inactive.',404);
    $sales=dbFetchAll("SELECT s.*,p.name product_name,p.type product_type,DATEDIFF(s.expiry_date,CURDATE()) days_left FROM sales s LEFT JOIN products p ON s.product_id=p.id WHERE s.client_id=? ORDER BY s.sale_date DESC",'i',[$c['id']]);
    $payments=dbFetchAll("SELECT py.*,s.site_url,s.invoice_no FROM payments py LEFT JOIN sales s ON py.sale_id=s.id WHERE s.client_id=? ORDER BY py.paid_at DESC",'i',[$c['id']]);
    return ['client'=>['name'=>$c['name'],'email'=>$c['email'],'location'=>$c['location']],'sales'=>$sales,'payments'=>$payments,'company'=>(getSettings()['company_name']??'')];
}

// ══════════════════════════════════════════════════════════════════════
// REPORT
// ══════════════════════════════════════════════════════════════════════
function getReport(array $p): array {
    $year=(int)($p['year']??date('Y'));
    $monthly=dbFetchAll("SELECT DATE_FORMAT(sale_date,'%m') month,COALESCE(SUM(CASE WHEN payment_status='paid' THEN price ELSE 0 END),0) revenue,COUNT(*) orders,COALESCE(SUM(discount_amount),0) discounts FROM sales WHERE YEAR(sale_date)=? GROUP BY month ORDER BY month",'i',[$year]);
    $top_products=dbFetchAll("SELECT p.name,p.type,COUNT(s.id) sales_count,COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) revenue FROM products p LEFT JOIN sales s ON p.id=s.product_id GROUP BY p.id ORDER BY revenue DESC LIMIT 5");
    $top_clients=dbFetchAll("SELECT c.name,COUNT(s.id) purchases,COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) spent FROM clients c LEFT JOIN sales s ON c.id=s.client_id GROUP BY c.id ORDER BY spent DESC LIMIT 5");
    $type_stats=[];
    foreach(dbFetchAll("SELECT p.type,COUNT(s.id) cnt,COALESCE(SUM(CASE WHEN s.payment_status='paid' THEN s.price ELSE 0 END),0) revenue FROM sales s LEFT JOIN products p ON s.product_id=p.id GROUP BY p.type") as $r) $type_stats[$r['type']]=$r;
    $promo_stats=dbFetchAll("SELECT pc.code,pc.type,pc.value,pc.used_count,COALESCE(SUM(s.discount_amount),0) total_discount FROM promo_codes pc LEFT JOIN sales s ON s.promo_code_id=pc.id GROUP BY pc.id ORDER BY total_discount DESC LIMIT 5");
    return compact('monthly','top_products','top_clients','type_stats','promo_stats');
}

// ══════════════════════════════════════════════════════════════════════
// ADMIN MANAGEMENT
// ══════════════════════════════════════════════════════════════════════
function getAdmins(): array { return dbFetchAll("SELECT id,username,full_name,role,is_active,last_login,last_ip,created_at FROM admin_users ORDER BY created_at DESC"); }
function addAdmin(array $d, array $u): array {
    $un=sanitize($d['username']??''); $pw=$d['password']??''; $fn=sanitize($d['full_name']??'');
    $role=in_array($d['role']??'',VALID_ROLES)?$d['role']:'admin';
    if(!$un||!$pw) jsonError('Username and password are required.',422);
    if(strlen($un)>64) jsonError('Username must be 64 characters or less.',422);
    validatePasswordStrength($pw);
    if(dbFetch("SELECT id FROM admin_users WHERE username=?",'s',[$un])) jsonError('Username already exists.',409);
    $h=password_hash($pw,PASSWORD_BCRYPT,['cost'=>BCRYPT_COST]);
    dbQuery("INSERT INTO admin_users(username,password_hash,full_name,role) VALUES(?,?,?,?)",'ssss',[$un,$h,$fn,$role]);
    $id=dbInsertId(); auditLog($u['id'],$u['username'],'CREATE_ADMIN','admin_users',$id);
    return ['message'=>'Admin created.','id'=>$id];
}
function updateAdmin(array $d, array $u): array {
    $id=(int)($d['id']??0);
    $fn=sanitize($d['full_name']??'');
    $role=in_array($d['role']??'',VALID_ROLES)?$d['role']:'admin';
    $email=sanitize($d['email']??'','email');
    $phone=sanitize($d['phone']??'');
    if($id===$u['id']&&$role!=='super_admin') jsonError('You cannot change your own role.',403);
    dbQuery("UPDATE admin_users SET full_name=?,role=?,email=?,phone=? WHERE id=?",'ssssi',[$fn,$role,$email,$phone,$id]);
    auditLog($u['id'],$u['username'],'UPDATE_ADMIN','admin_users',$id);
    return ['message'=>'Updated successfully.'];
}
function toggleAdmin(int $id, array $u): array {
    if($id===$u['id']) jsonError('You cannot deactivate yourself.',403);
    $row=dbFetch("SELECT is_active FROM admin_users WHERE id=?",'i',[$id]); if(!$row) jsonError('Not found.',404);
    $new=$row['is_active']?0:1; dbQuery("UPDATE admin_users SET is_active=? WHERE id=?",'ii',[$new,$id]);
    if(!$new) dbQuery("DELETE FROM admin_sessions WHERE user_id=?",'i',[$id]);
    auditLog($u['id'],$u['username'],$new?'ACTIVATE_ADMIN':'DEACTIVATE_ADMIN','admin_users',$id);
    return ['message'=> $new ? 'Admin activated.' : 'Admin deactivated.', 'is_active'=>$new];
}
function deleteAdmin(int $id, array $u): array {
    if ($id === $u['id']) jsonError('You cannot delete yourself.', 403);
    $row = dbFetch("SELECT username,role FROM admin_users WHERE id=?", 'i', [$id]);
    if (!$row) jsonError('Not found.', 404);
    // Prevent deleting the last super_admin — would lock out the system
    if ($row['role'] === 'super_admin') {
        $superCount = dbFetch("SELECT COUNT(*) cnt FROM admin_users WHERE role='super_admin' AND is_active=1")['cnt'] ?? 0;
        if ((int)$superCount <= 1) jsonError('Cannot delete the last super_admin account — this would lock everyone out of the system.', 403);
    }
    dbQuery("DELETE FROM admin_users WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_ADMIN', 'admin_users', $id, $row);
    return ['message' => 'Admin deleted.'];
}
function getActiveSessions(): array {
    dbQuery("DELETE FROM admin_sessions WHERE last_activity<DATE_SUB(NOW(),INTERVAL ? SECOND)",'i',[SESSION_LIFETIME]);
    return dbFetchAll("SELECT s.*,u.username,u.full_name,u.role FROM admin_sessions s LEFT JOIN admin_users u ON s.user_id=u.id ORDER BY s.last_activity DESC");
}
function killSession(string $sid, array $u): array {
    dbQuery("DELETE FROM admin_sessions WHERE id=?",'s',[$sid]);
    auditLog($u['id'],$u['username'],'KILL_SESSION');
    return ['message'=>'Session terminated.'];
}
function getAuditLog(array $p): array {
    $limit  = min(max((int)($p['limit']  ?? 50), 1), 200);
    $offset = max((int)($p['offset'] ?? 0), 0);
    $filter = sanitize($p['action'] ?? '');
    if ($filter) {
        return dbFetchAll(
            "SELECT * FROM audit_log WHERE action LIKE ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            'sii', ['%' . $filter . '%', $limit, $offset]
        );
    }
    return dbFetchAll(
        "SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?",
        'ii', [$limit, $offset]
    );
}
// ══════════════════════════════════════════════════════════════════════
// PASSWORD RESET SYSTEM
// ══════════════════════════════════════════════════════════════════════

function handleForgotPasswordSend(array $d): array {
    $username = sanitize($d['username'] ?? '');
    $email    = sanitize($d['email']   ?? '', 'email');

    if (!$username) jsonError('Please enter username or email.', 422);
    if (!$email)    jsonError('Please enter your email address.', 422);

    // Check user exists by username OR email
    $user = dbFetch("SELECT id,username,email FROM admin_users WHERE (username=? OR email=?) AND is_active=1 LIMIT 1", 'ss', [$username, $email]);
    if (!$user) jsonError('Username or email not found.', 404);

    // Validate email matches
    if ($user['email'] !== $email) jsonError('Email address does not match.', 422);

    // Rate limit: max 5 OTP per hour per user
    $recent = dbFetch("SELECT COUNT(*) cnt FROM password_reset_tokens WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", 'i', [$user['id']]);
    if (($recent['cnt'] ?? 0) >= 5) jsonError('Too many attempts. Please try again after 1 hour.', 429);

    // Generate 6-digit OTP
    $otp     = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $hash    = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Delete old password-reset tokens only — never touch 2FA tokens
    dbQuery("DELETE FROM password_reset_tokens WHERE user_id=? AND purpose='password_reset'", 'i', [$user['id']]);

    // Store token
    dbQuery("INSERT INTO password_reset_tokens(user_id,otp_hash,method,purpose,expires_at,ip_address) VALUES(?,?,?,'password_reset',?,?)",
        'issss', [$user['id'], $hash, 'email', $expires, getClientIP()]);

    // Store pending session
    $_SESSION['reset_user_id'] = $user['id'];
    $_SESSION['reset_method']  = 'email';

    $sent = sendResetEmail($user, $email, $otp);

    // Security: wipe OTP from memory immediately after use
    unset($otp);

    $settings = getSettings();
    $noSmtp   = empty($settings['smtp_host'] ?? '') || empty($settings['smtp_user'] ?? '');

    if (!$sent) {
        error_log("[WPS_OTP] Email delivery failed for user={$user['username']}" . ($noSmtp ? " (SMTP not configured)" : ""));
    }

    return [
        'message'    => $sent
            ? 'OTP sent successfully. Please check your email.'
            : ($noSmtp ? 'Email is not configured on this server. Please contact the administrator.' : 'Failed to send OTP. Please try again.'),
        'expires_in' => 3600,
        'sent'       => $sent,
    ];

}

function handleForgotPasswordVerify(array $d): array {
    $code = preg_replace('/[^0-9]/', '', $d['code'] ?? '');
    if (strlen($code) !== 6) jsonError('Please enter the 6-digit numeric code.', 422);

    $userId = $_SESSION['reset_user_id'] ?? 0;
    if (!$userId) jsonError('Session expired. Please start again.', 401);

    $token = dbFetch("SELECT * FROM password_reset_tokens WHERE user_id=? AND purpose='password_reset' AND used=0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1", 'i', [$userId]);
    if (!$token) jsonError('OTP has expired or has already been used.', 401);

    // Check attempts
    if (($token['attempts'] ?? 0) >= 5) {
        dbQuery("UPDATE password_reset_tokens SET used=1 WHERE id=?", 'i', [$token['id']]);
        jsonError('Too many wrong codes entered. Please request a new OTP.', 429);
    }

    if (!password_verify($code, $token['otp_hash'])) {
        dbQuery("UPDATE password_reset_tokens SET attempts=attempts+1 WHERE id=?", 'i', [$token['id']]);
        $left = max(0, 4 - (int)($token['attempts'] ?? 0));  // attempts already incremented above
        $msg  = $left > 0 ? "Wrong code. You have {$left} attempt(s) remaining." : 'Wrong code. This was your last attempt — please request a new OTP.';
        jsonError($msg, 422);
    }

    // Generate secure reset token — store server-side only, never return to client
    $resetToken = bin2hex(random_bytes(32));
    dbQuery("UPDATE password_reset_tokens SET used=1,reset_token=? WHERE id=?", 'si', [$resetToken, $token['id']]);
    // Token stays in session only — not returned in response body
    $_SESSION['reset_token']       = $resetToken;
    $_SESSION['reset_verified_at'] = time();

    return ['message' => 'Code verified! Please enter your new password.'];
}

function handleForgotPasswordReset(array $d): array {
    $newPassword = $d['new_password'] ?? '';
    validatePasswordStrength($newPassword);

    // Verify session token (timing-safe comparison)
    $sessionToken = $_SESSION['reset_token'] ?? '';
    $verifiedAt   = $_SESSION['reset_verified_at'] ?? 0;
    $userId       = $_SESSION['reset_user_id'] ?? 0;

    if (!$sessionToken || !$userId) jsonError('Session expired. Please start again.', 401);
    if ((time() - $verifiedAt) > 1800) jsonError('30 minutes has passed. Please start the password reset process again.', 401);

    // Verify reset token in DB with timing-safe hash comparison
    $tokenRow = dbFetch("SELECT reset_token FROM password_reset_tokens WHERE user_id=? AND used=1 AND reset_token IS NOT NULL ORDER BY created_at DESC LIMIT 1", 'i', [$userId]);
    if (!$tokenRow || !hash_equals($tokenRow['reset_token'], $sessionToken))
        jsonError('Invalid or expired request.', 401);

    // Update password
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    dbQuery("UPDATE admin_users SET password_hash=? WHERE id=?", 'si', [$hash, $userId]);

    // Invalidate all sessions and tokens for this user
    dbQuery("DELETE FROM admin_sessions WHERE user_id=?", 'i', [$userId]);
    dbQuery("DELETE FROM password_reset_tokens WHERE user_id=?", 'i', [$userId]);

    // Clear reset session data
    unset($_SESSION['reset_user_id'], $_SESSION['reset_token'], $_SESSION['reset_method'], $_SESSION['reset_verified_at']);

    // Regenerate session ID to prevent session fixation after privilege change
    session_regenerate_id(true);

    $user = dbFetch("SELECT username FROM admin_users WHERE id=?", 'i', [$userId]);
    auditLog($userId, $user['username'] ?? 'unknown', 'PASSWORD_RESET_SUCCESS', null, $userId, null, ['ip' => getClientIP()]);

    return ['message' => 'Password changed successfully! Please log in with your new password.'];
}

// ─── Email Sender ──────────────────────────────────────────────────────
function sendResetEmail(array $user, string $email, string $otp): bool {
    $settings  = getSettings();
    $company   = $settings['site_title']   ?? $settings['company_name'] ?? 'WP Sales Manager Pro';
    $fromEmail = $settings['smtp_from']    ?? $settings['company_email'] ?? '';
    $smtpHost  = $settings['smtp_host']    ?? '';
    $smtpPort  = (int)($settings['smtp_port'] ?? 587);
    $smtpUser  = $settings['smtp_user']    ?? '';
    $smtpPass  = $settings['smtp_pass']    ?? '';

    $subject = "Password Reset Code — {$company}";
    $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;background:#0d1320;color:#f0f4f8;padding:20px'>
<div style='max-width:500px;margin:0 auto;background:#111827;border-radius:16px;overflow:hidden'>
  <div style='background:linear-gradient(135deg,#4299e1,#38b2ac);padding:24px;text-align:center'>
    <h1 style='margin:0;font-size:20px;color:#fff'>🔐 {$company}</h1>
    <p style='margin:6px 0 0;color:rgba(255,255,255,0.8);font-size:14px'>Password Reset</p>
  </div>
  <div style='padding:32px'>
    <p style='margin:0 0 16px;color:#a0b3c6'>Dear <b style='color:#f0f4f8'>{$user['username']}</b>,</p>
    <p style='margin:0 0 24px;color:#a0b3c6'>A password reset request was received for your account. Use the code below:</p>
    <div style='background:#1a2235;border:2px dashed rgba(99,179,237,0.3);border-radius:12px;padding:20px;text-align:center;margin:0 0 24px'>
      <div style='font-size:40px;font-weight:bold;letter-spacing:12px;color:#63b3ed;font-family:monospace'>{$otp}</div>
      <div style='color:#5c7a94;font-size:12px;margin-top:8px'>This code is valid for 1 hour</div>
    </div>
    <p style='margin:0 0 8px;color:#5c7a94;font-size:12px'>⚠️ Do not share this code with anyone.</p>
    <p style='margin:0;color:#5c7a94;font-size:12px'>If you did not request this, please ignore this email.</p>
  </div>
  <div style='background:#0d1320;padding:16px;text-align:center'>
    <p style='margin:0;color:#5c7a94;font-size:11px'>{$company} · This is an automated message</p>
  </div>
</div></body></html>";

    $sent = false;

    // ── Method 1: SMTP via socket (no PHPMailer needed) ──
    if ($smtpHost && $smtpUser && $smtpPass) {
        $sent = sendEmailViaSmtp($smtpHost, $smtpPort, $smtpUser, $smtpPass,
            $fromEmail ?: $smtpUser, $company, $email, $subject, $body);
    }

    // ── Method 2: PHP mail() fallback ──
    if (!$sent) {
        $from = $fromEmail ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $headers  = "From: {$company} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
        $sent = @mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers);
    }

    if (!$sent) {
        // SECURITY: Never log plaintext OTP — mask it
        error_log("[WPS_OTP] FAILED to send email to={$email} user={$user['username']} smtp={$smtpHost}");
    }
    return $sent;
}

function sendEmailViaSmtp(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to, string $subject, string $htmlBody
): bool {
    // Validate email addresses to prevent header injection
    if (!filter_var($to,   FILTER_VALIDATE_EMAIL)) return false;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = $user;
    // Strip any newlines from subject (header injection guard)
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $errno = 0; $errstr = '';
    $tls   = ($port === 465);
    $prefix = $tls ? 'ssl://' : '';

    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$socket) return false;

    $read = function() use ($socket) {
        $r = '';
        while ($line = fgets($socket, 512)) {
            $r .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $r;
    };
    $send = function(string $cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };

    $r = $read();
    if (strpos($r, '220') !== 0) { fclose($socket); return false; }

    $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $ehlo = $read();

    // STARTTLS for port 587
    if ($port === 587 && strpos($ehlo, 'STARTTLS') !== false) {
        $send('STARTTLS');
        $read();
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket); return false;
        }
        $send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $read();
    }

    $send('AUTH LOGIN');
    $read();
    $send(base64_encode($user));
    $read();
    $send(base64_encode($pass));
    $authResp = $read();
    if (strpos($authResp, '235') !== 0) { fclose($socket); return false; }

    $send("MAIL FROM:<{$from}>");
    $read();
    $send("RCPT TO:<{$to}>");
    $rcptResp = $read();
    if (strpos($rcptResp, '250') !== 0) { fclose($socket); return false; }

    $send('DATA');
    $read();

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $boundary = md5(uniqid());
    $msg  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: {$subjectEncoded}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= chunk_split(base64_encode($htmlBody));
    $msg .= "\r\n.";

    fwrite($socket, $msg . "\r\n");
    $dataResp = $read();
    $send('QUIT');
    fclose($socket);

    return strpos($dataResp, '250') === 0;
}


// ─── SMS Sender ───────────────────────────────────────────────────────
function sendResetSMS(array $user, string $phone, string $otp): bool {
    $settings = getSettings();
    $apiKey   = $settings['sms_api_key'] ?? '';
    $senderId = $settings['sms_sender_id'] ?? 'WPSALES';
    $company  = $settings['company_name'] ?? 'WP Sales Manager';
    $msg = "Your password reset code: {$otp}\nExpires in: 1 hour\n— {$company}";

    if (!$apiKey) {
        error_log("[WPS_OTP_SMS] FAILED: no API key configured for user={$user['username']} to={$phone}");
        return false;
    }

    $baseUrl = 'https://sms.sslwireless.com/pushapi/dynamic/server.php';
    $params  = http_build_query(['apikey'=>$apiKey,'sid'=>$senderId,'msisdn'=>$phone,'sms'=>$msg,'csmsid'=>time()]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '?' . $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,   // SSL cert must be valid
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,  // no redirects (SSRF guard)
        CURLOPT_MAXREDIRS      => 0,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return (bool)$resp && (str_contains($resp,'success') || str_contains($resp,'SENT'));
}

function deletePromo(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM promo_codes WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Promo code not found.', 404);
    dbQuery("DELETE FROM promo_codes WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_PROMO', 'promo_codes', $id, $old);
    return ['message' => 'Promo code deleted.'];
}

function deleteProduct(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM products WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Product not found.', 404);
    $salesCount = dbFetch("SELECT COUNT(*) cnt FROM sales WHERE product_id=?", 'i', [$id])['cnt'] ?? 0;
    if ((int)$salesCount > 0) {
        jsonError("Cannot delete product — {$salesCount} sales are linked to it.", 409);
    }
    dbQuery("DELETE FROM products WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_PRODUCT', 'products', $id, $old);
    return ['message' => 'Product deleted.'];
}

function deleteClient(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM clients WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Client not found.', 404);
    $salesCount  = (int)(dbFetch("SELECT COUNT(*) cnt FROM sales WHERE client_id=?", 'i', [$id])['cnt'] ?? 0);
    $ticketCount = (int)(dbFetch("SELECT COUNT(*) cnt FROM tickets WHERE client_id=?", 'i', [$id])['cnt'] ?? 0);
    if ($salesCount > 0 || $ticketCount > 0) {
        jsonError("Cannot delete — {$salesCount} sales and {$ticketCount} tickets are linked to this client.", 409);
    }
    dbQuery("DELETE FROM clients WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_CLIENT', 'clients', $id, $old);
    return ['message' => 'Client deleted.'];
}

function deleteTask(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM tasks WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Task not found.', 404);
    dbQuery("DELETE FROM tasks WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_TASK', 'tasks', $id, $old);
    return ['message' => 'Task deleted.'];
}

function deleteSale(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM sales WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Sale not found.', 404);
    dbQuery("DELETE FROM sales WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_SALE', 'sales', $id, $old);
    return ['message' => 'Sale deleted.'];
}

function createBackup(array $u): array {
    @ini_set('memory_limit', '256M'); // Large dataset protection
    @set_time_limit(120); // 2 min timeout for large backups
    $dir  = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $db   = getDB();
    $meta = ['version'=>APP_VERSION,'created_at'=>date('Y-m-d H:i:s'),'created_by'=>$u['username'],'db_name'=>DB_NAME];
    $tables = ['clients','products','sales','payments','reminders','tickets',
               'ticket_replies','tasks','promo_codes','settings'];
    // NOTE: admin_users (password hashes) and admin_sessions are intentionally
    // excluded from backup — they contain sensitive security data
    $data = ['meta'=>$meta,'tables'=>[]];
    foreach ($tables as $tbl) {
        $r = $db->query("SELECT * FROM `$tbl`");
        $data['tables'][$tbl] = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
    $type     = 'manual';
    $filename = "backup_{$type}_" . date('Y-m-d_H-i-s') . '.json';
    $json     = json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents("$dir/$filename", $json);
    // Store SHA-256 hash alongside backup for integrity verification on restore
    $hash = hash('sha256', $json);
    file_put_contents("$dir/$filename.sha256", $hash);
    auditLog($u['id'], $u['username'], 'CREATE_BACKUP', null, null, null, ['file'=>$filename]);
    return ['message'=>'Backup created.','filename'=>$filename,'size'=>filesize("$dir/$filename")];
}

function listBackups(): array {
    $dir  = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) return ['backups'=>[],'total'=>0];
    $files = glob("$dir/backup_*.json") ?: [];
    $list  = [];
    foreach ($files as $f) {
        $age = (int)((time() - filemtime($f)) / 86400);
        $list[] = [
            'filename'   => basename($f),
            'size'       => formatBytes(filesize($f)),
            'created_at' => date('Y-m-d H:i:s', filemtime($f)),
            'age_days'   => $age,
            'type'       => preg_match('/backup_(\w+)_/', basename($f), $m) ? $m[1] : 'manual',
        ];
    }
    usort($list, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
    $totalSize = array_sum(array_map('filesize', $files));
    return ['backups'=>$list,'total'=>count($list),'dir_size'=>formatBytes($totalSize)];
}

// ── Allowed tables for restore (whitelist) ──
const RESTORE_ALLOWED_TABLES = ['clients','products','sales','payments','reminders',
    'tickets','ticket_replies','tasks','promo_codes','settings'];

function _safeBackupPath(string $filename): string {
    $dir      = realpath(dirname(__DIR__) . '/backups');
    $filename = basename($filename); // strip any path components
    // Must be a backup JSON file only
    if (!preg_match('/^backup_[a-z]+_[\d\-_]+\.json$/', $filename))
        jsonError('Invalid Backup filename.', 422);
    $path = $dir . '/' . $filename;
    // Ensure resolved path stays inside backups dir (symlink/traversal guard)
    $real = realpath($path);
    if ($real === false || strpos($real, $dir) !== 0)
        jsonError('Invalid File path.', 403);
    return $real;
}

function restoreBackup(array $d, array $u): array {
    $path = _safeBackupPath($d['filename'] ?? '');
    if (!file_exists($path)) jsonError('Backup file not found.', 404);
    $raw  = file_get_contents($path);
    // Integrity check — verify SHA-256 hash if hash file exists
    $hashFile = $path . '.sha256';
    if (file_exists($hashFile)) {
        $storedHash   = trim(file_get_contents($hashFile));
        $computedHash = hash('sha256', $raw);
        if (!hash_equals($storedHash, $computedHash)) {
            auditLog($u['id'], $u['username'], 'RESTORE_INTEGRITY_FAIL', null, null, null, ['file'=>basename($path)]);
            jsonError('Backup file integrity check failed. The file may have been tampered with.', 422);
        }
    }
    $data = json_decode($raw, true);
    if (!$data || !isset($data['tables'])) jsonError('Backup file is corrupt or invalid.', 422);
    $db = getDB();
    $db->begin_transaction();
    $restored = [];
    try {
        foreach ($data['tables'] as $tbl => $rows) {
            // Whitelist: only restore known safe tables, never admin_users/audit_log/sessions
            if (!in_array($tbl, RESTORE_ALLOWED_TABLES, true)) continue;
            $db->query("DELETE FROM `$tbl`");
            $cnt = 0;
            foreach ($rows as $row) {
                $cols  = implode(',', array_map(fn($k)=>"`".addslashes($k)."`", array_keys($row)));
                $vals  = implode(',', array_fill(0, count($row), '?'));
                // Build proper type string from PHP native types (json_decode preserves int/float/string)
                $types = '';
                foreach (array_values($row) as $v) {
                    if (is_int($v))   $types .= 'i';
                    elseif (is_float($v)) $types .= 'd';
                    else $types .= 's';
                }
                $stmt  = $db->prepare("INSERT INTO `$tbl` ($cols) VALUES ($vals)");
                if (!$stmt) continue;
                $stmt->bind_param($types, ...array_values($row));
                $stmt->execute();
                $stmt->close();
                $cnt++;
            }
            $restored[$tbl] = $cnt;
        }
        $db->commit();
    } catch(Throwable $e) {
        $db->rollback();
        error_log('[WPSM Restore] ' . $e->getMessage());
        jsonError('Restore operation failed.', 500);
    }
    auditLog($u['id'], $u['username'], 'RESTORE_BACKUP', null, null, null, ['file'=>basename($path)]);
    return ['message'=>'Backup restored.','restored'=>$restored];
}

function deleteBackup(array $d, array $u): array {
    $path = _safeBackupPath($d['filename'] ?? '');
    if (!file_exists($path)) jsonError('File not found.', 404);
    unlink($path);
    // Remove hash file if it exists
    if (file_exists($path . '.sha256')) unlink($path . '.sha256');
    auditLog($u['id'], $u['username'], 'DELETE_BACKUP', null, null, null, ['file'=>basename($path)]);
    return ['message'=>'Backup deleted.'];
}

function downloadBackup(array $d): never {
    $path = _safeBackupPath($_GET['filename'] ?? $d['filename'] ?? '');
    if (!file_exists($path)) { http_response_code(404); exit(json_encode(['success'=>false,'error'=>'Not found'])); }
    $file = basename($path);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($file) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024, 1) . ' KB';
    return $bytes . ' B';
}

function deleteTicket(int $id, array $u): array {
    $old = dbFetch("SELECT * FROM tickets WHERE id=?", 'i', [$id]);
    if (!$old) jsonError('Ticket not found.', 404);
    dbQuery("DELETE FROM ticket_replies WHERE ticket_id=?", 'i', [$id]);
    dbQuery("DELETE FROM tickets WHERE id=?", 'i', [$id]);
    auditLog($u['id'], $u['username'], 'DELETE_TICKET', 'tickets', $id, $old);
    return ['message' => 'Ticket deleted.'];
}
