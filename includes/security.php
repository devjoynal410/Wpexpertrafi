<?php
if (!defined('WPSM_SECURE')) { http_response_code(403); exit('Access Denied'); }

// ══════════════════════════════════════════════════════════
// SESSION MANAGER — Hostinger/cPanel compatible
// ══════════════════════════════════════════════════════════
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // ── HSTS header (force HTTPS on browser level) ──
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',  // Strict → Lax (proxy compat)
    ]);
    session_name(SESSION_NAME);
    if (!@session_start()) {
        error_log('[WPSM] session_start() failed');
    }

    // Regenerate session ID on first use to prevent session fixation
    if (empty($_SESSION['_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['_regenerated'] = true;
    }

    // Session lifetime # check (IP fingerprint removed — proxy/CDN compat)
    if (!empty($_SESSION['user_id'])) {
        if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_LIFETIME) {
            destroySession();
            jsonError('Session expired. Please log in again.', 401);
        }
        $_SESSION['last_activity'] = time();
    }
}

function destroySession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['session_db_id'])) {
            try { dbQuery("DELETE FROM admin_sessions WHERE id = ?", 's', [$_SESSION['session_db_id']]); } catch (Exception $e) {}
        }
        session_write_close(); // Prevent race condition on concurrent requests
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

// ══════════════════════════════════════════════════════════
// AUTHENTICATION
// ══════════════════════════════════════════════════════════
function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return dbFetch(
        "SELECT id, username, full_name, role, is_active FROM admin_users WHERE id = ? AND is_active = 1",
        'i', [$_SESSION['user_id']]
    );
}

function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) jsonError('Unauthorized access. Please log in.', 401);
    return $user;
}

function requireWrite(array $user): void {
    if ($user['role'] === 'viewer') {
        auditLog($user['id'], $user['username'], 'VIEWER_WRITE_ATTEMPT');
        jsonError('This account does not have write permission.', 403);
    }
}

function requireRole(array $user, array $roles): void {
    if (!in_array($user['role'], $roles)) {
        auditLog($user['id'], $user['username'], 'UNAUTHORIZED_ACCESS', null, null);
        jsonError('You do not have permission for this action.', 403);
    }
}

// ══════════════════════════════════════════════════════════
// BRUTE FORCE PROTECTION
// ══════════════════════════════════════════════════════════
function checkLoginAttempts(string $username): void {
    $ip     = getClientIP();
    $window = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_TIME);

    $row = dbFetch(
        "SELECT COUNT(*) cnt FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > ?",
        'ss', [$ip, $window]
    );
    if (($row['cnt'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        $mins = ceil(LOGIN_LOCKOUT_TIME / 60);
        jsonError("Too many failed attempts. Please try again in {$mins} minutes.", 429);
    }

    $row2 = dbFetch(
        "SELECT COUNT(*) cnt FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?",
        'ss', [$username, $window]
    );
    if (($row2['cnt'] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        jsonError("This account is temporarily locked. Please try again later.", 429);
    }
}

function recordLoginAttempt(string $username, bool $success): void {
    $ip = getClientIP();
    try {
        dbQuery("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)",
            'ssi', [$username, $ip, $success ? 1 : 0]);
        dbQuery("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Exception $e) {}
}

function checkRateLimit(): void {
    // IP-based API rate limiting — uses dedicated api_requests table, separate from login tracking
    $ip     = getClientIP();
    $window = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);
    try {
        $row   = dbFetch("SELECT COUNT(*) cnt FROM api_requests WHERE ip_address = ? AND requested_at > ?", 'ss', [$ip, $window]);
        $count = (int)($row['cnt'] ?? 0);
        // Log this request and prune old records periodically
        dbQuery("INSERT INTO api_requests (ip_address) VALUES (?)", 's', [$ip]);
        if (rand(1, 50) === 1) {
            dbQuery("DELETE FROM api_requests WHERE requested_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (Exception $e) {
        // DB unavailable — fall back to session counter as last resort
        $key = 'rl_' . md5($ip);
        if (!isset($_SESSION[$key]) || time() > ($_SESSION[$key]['r'] ?? 0)) {
            $_SESSION[$key] = ['c' => 0, 'r' => time() + RATE_LIMIT_WINDOW];
        }
        $count = ++$_SESSION[$key]['c'];
    }
    if ($count > RATE_LIMIT_MAX) {
        http_response_code(429);
        header('Retry-After: ' . RATE_LIMIT_WINDOW);
        if (ob_get_level() > 0) ob_end_clean();
        die(json_encode(['success' => false, 'error' => 'Too many requests. Please wait a moment.'], JSON_UNESCAPED_UNICODE));
    }
}

// ══════════════════════════════════════════════════════════
// CSRF
// ══════════════════════════════════════════════════════════
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_expire']) || time() > $_SESSION['csrf_expire']) {
        $_SESSION['csrf_token']  = bin2hex(random_bytes((int)(CSRF_TOKEN_LENGTH / 2)));
        $_SESSION['csrf_expire'] = time() + SESSION_LIFETIME;
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken(string $token): void {
    if (empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token) ||
        time() > ($_SESSION['csrf_expire'] ?? 0)) {
        auditLog($_SESSION['user_id'] ?? null, $_SESSION['username'] ?? 'unknown', 'CSRF_VIOLATION');
        jsonError('Security token has expired. Please refresh the page and try again.', 403);
    }
}

// ══════════════════════════════════════════════════════════
// AUDIT LOG
// ══════════════════════════════════════════════════════════
function auditLog(?int $userId, ?string $username, string $action,
                  ?string $table = null, ?int $recordId = null,
                  $oldData = null, $newData = null): void {
    try {
        $ip      = getClientIP();
        $oldJson = $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
        dbQuery(
            "INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_data, new_data, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            'isssiiss',
            [$userId, $username, $action, $table, $recordId, $oldJson, $newJson, $ip]
        );
    } catch (Exception $e) {
        error_log('Audit: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════
// SANITIZATION
// ══════════════════════════════════════════════════════════
function sanitize(mixed $val, string $type = 'string'): mixed {
    return match($type) {
        'int'   => (int) $val,
        'float' => (float) $val,
        'bool'  => (bool) $val,
        'email' => filter_var(trim((string)($val ?? '')), FILTER_SANITIZE_EMAIL),
        'url'   => filter_var(trim((string)($val ?? '')), FILTER_SANITIZE_URL),
        'date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($val ?? '')) ? $val : null,
        default => substr(strip_tags(trim((string)($val ?? ''))), 0, 65535),
    };
}

function validateRequired(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '' || $data[$f] === null) {
            jsonError("'{$f}' field is required.", 422);
        }
    }
}

// ══════════════════════════════════════════════════════════
// PASSWORD STRENGTH VALIDATOR
// ══════════════════════════════════════════════════════════
function validatePasswordStrength(string $password): void {
    if (strlen($password) < 8)
        jsonError('Password must be at least 8 characters.', 422);
    if (!preg_match('/[A-Z]/', $password))
        jsonError('Password must contain at least one uppercase letter (A-Z).', 422);
    if (!preg_match('/[0-9]/', $password))
        jsonError('Password must contain at least one number (0-9).', 422);
    if (!preg_match('/[^A-Za-z0-9]/', $password))
        jsonError('Password must contain at least one special character (!@#$%^&*).', 422);
    if (strlen($password) > 128)
        jsonError('Password must not exceed 128 characters.', 422);
}

// ══════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════
function getClientIP(): string {
    // Priority: Cloudflare real IP > X-Real-IP (trusted proxy) > REMOTE_ADDR
    // Note: HTTP_X_FORWARDED_FOR is spoofable — only trust if behind known proxy
    // For shared hosting/cPanel without Cloudflare, REMOTE_ADDR is most reliable
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    // Fallback: REMOTE_ADDR (always reliable, cannot be spoofed)
    return filter_var($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', FILTER_VALIDATE_IP)
        ? $_SERVER['REMOTE_ADDR']
        : '127.0.0.1';
}

function jsonError(string $message, int $code = 400): never {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonOk(array $data = []): never {
    if (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
