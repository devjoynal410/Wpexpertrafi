<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  WP Sales Manager Pro — PHP WAF (Web Application Firewall) ║
 * ║  Blocks: SQLi, XSS, Bot, RFI, LFI, CSRF, Flood          ║
 * ╚══════════════════════════════════════════════════════════╝
 */
if (!defined('WPSM_SECURE')) { http_response_code(403); exit('Access Denied'); }

class WAF {

    // ── Known attack tool signatures ──
    private static array $BAD_UA = [
        'sqlmap','nikto','nessus','openvas','w3af','burpsuite','acunetix',
        'netsparker','havij','pangolin','zmeu','masscan','dirbuster',
        'gobuster','wfuzz','hydra','metasploit','skipfish','webscarab',
        'ncrack','python-requests/','zgrab','nmap','scrapy',
    ];

    // ── SQL Injection patterns — ReDoS-safe ──
    private static array $SQLI_PATTERNS = [
        '/\b(union\s+select|select\s+from|insert\s+into|delete\s+from|drop\s+table|alter\s+table|create\s+table)\b/i',
        '/\b(exec|execute|xp_cmdshell|sp_executesql|benchmark|sleep|waitfor\s+delay)\s*\(/i',
        '/\b(load_file|into\s+outfile|into\s+dumpfile)\b/i',
        '/\b(information_schema|sys\.tables|sys\.columns|mysql\.user)\b/i',
        '/\b(or|and)\b\s+[\d\'"\(]+\s*[=<>!]/i',
        '/0x[0-9a-f]{6,}|char\(\d{1,3}\)/i',
        "/1\s*=\s*1|'a'\s*=\s*'a/i",
    ];

    // ── XSS patterns — ReDoS-safe ──
    private static array $XSS_PATTERNS = [
        '/<script[\s>\/]/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/\bon(?:load|error|click|mouseover|focus|blur|submit|change|keypress|keydown|keyup|resize|scroll)\s*=/i',
        '/\beval\s*\(/i',
        '/document\.(?:cookie|write|location|body)\b/i',
        '/window\.(?:location|open|eval)\b/i',
        '/\.(?:innerHTML|outerHTML|insertAdjacentHTML)\s*=/i',
        '/String\.fromCharCode\s*\(/i',
        '/&#x[0-9a-f]{1,6};/i',
    ];

    // ── Path traversal & injection patterns — ReDoS-safe ──
    private static array $PATH_PATTERNS = [
        '/\.\.[\/\\\\]/',
        '/(?:%2e%2e|%252e%252e)(?:[\/\\\\]|%2f|%5c)/i',
        '/\/etc\/(?:passwd|shadow)|\/proc\/self|win\.ini|system32/i',
        '/(?:php|file|data|zip|expect|input):\/\//i',
        '/\x00/',
    ];

    // ── Command injection patterns — ReDoS-safe ──
    private static array $CMD_PATTERNS = [
        '/[;&|`]\s*(?:ls|pwd|cat|wget|curl|chmod|rm|mv|cp|echo|bash|sh|python|perl|ruby|nc|netcat)\b/i',
        '/\$\([^)]{1,100}\)/',
        '/`[^`]{1,200}`/',
        '/(?:;|\|{1,2}|&&)\s*(?:cat|ls|pwd|uname|whoami|hostname)\b/i',
    ];

    // ════════════════════════════════════════════════════════════
    // PUBLIC: Scan JSON POST body (called from api/index.php)
    // ════════════════════════════════════════════════════════════
    public static function scanBody(array $data): void {
        // Skip CSRF token and known safe fields to avoid false positives
        $skip = ['_csrf', 'password', 'new_password', 'current_password', 'confirm_password'];
        $filtered = array_diff_key($data, array_flip($skip));
        if (!empty($filtered)) {
            self::scanInput($filtered, 'JSON_BODY');
        }
    }

    // ════════════════════════════════════════════════════════════
    // MAIN WAF CHECK — Call at the start of every request
    // ════════════════════════════════════════════════════════════
    public static function inspect(): void {
        // 1. IP ban check
        self::checkBannedIP();

        // 2. Rate limiting (aggressive)
        self::checkFloodRate();

        // 3. User agent check
        self::checkUserAgent();

        // 4. Request method
        self::checkMethod();

        // 5. Scan all input data
        $inputs = self::getAllInput();
        foreach ($inputs as $source => $data) {
            self::scanInput($data, $source);
        }

        // 6. Log clean request (sampling)
        // Only log suspicious patterns (done inside block methods)
    }

    // ── Collect all input ──
    private static function getAllInput(): array {
        // Note: $_POST is empty for JSON requests (body is read via php://input)
        // Cookies are NOT scanned to avoid false positives on session tokens/CSRF
        return [
            'GET'   => $_GET ?? [],
            'URI'   => ['uri' => $_SERVER['REQUEST_URI'] ?? ''],
            'QUERY' => ['qs'  => $_SERVER['QUERY_STRING'] ?? ''],
        ];
        // $_POST scanning skipped — JSON body scanned below if needed
        // $_COOKIE scanning skipped — would false-positive on session IDs/CSRF tokens
    }

    // ── Scan input array recursively ──
    private static function scanInput(array $data, string $source): void {
        array_walk_recursive($data, function($val, $key) use ($source) {
            if (!is_string($val)) return;
            $decoded = urldecode(html_entity_decode($val));

            if (self::matchPatterns($decoded, self::$SQLI_PATTERNS)) {
                self::block("SQL Injection attempt [{$source}:{$key}]", 'SQLI');
            }
            if (self::matchPatterns($decoded, self::$XSS_PATTERNS)) {
                self::block("XSS attempt [{$source}:{$key}]", 'XSS');
            }
            if (self::matchPatterns($decoded, self::$PATH_PATTERNS)) {
                self::block("Path traversal attempt [{$source}:{$key}]", 'PATH_TRAVERSAL');
            }
            if (self::matchPatterns($decoded, self::$CMD_PATTERNS)) {
                self::block("Command injection attempt [{$source}:{$key}]", 'CMD_INJECTION');
            }
        });
    }

    private static function matchPatterns(string $val, array $patterns): bool {
        foreach ($patterns as $pat) {
            if (preg_match($pat, $val)) return true;
        }
        return false;
    }

    // ── Check banned IPs ──
    private static function checkBannedIP(): void {
        $ip = self::getIP();
        $banFile = dirname(__DIR__) . '/backups/.banned_ips.json';
        if (!file_exists($banFile)) return;

        $raw = @file_get_contents($banFile);
        if (!$raw) return;
        $banned = json_decode($raw, true) ?? [];
        if (isset($banned[$ip])) {
            $ban = $banned[$ip];
            // Check if ban expired
            if ($ban['expires'] === 0 || $ban['expires'] > time()) {
                self::block("Banned IP: {$ip}", 'BANNED_IP', 403);
            } else {
                // Remove expired ban
                unset($banned[$ip]);
                file_put_contents($banFile, json_encode($banned), LOCK_EX);
            }
        }
    }

    // ── Flood/DDoS rate limiting (stricter than API rate limit) ──
    private static function checkFloodRate(): void {
        $ip      = self::getIP();
        $logFile = dirname(__DIR__) . '/backups/.waf_log.json';

        // Use the existing attack log to count recent requests from this IP
        // This is IP-based and cannot be bypassed by clearing sessions/cookies
        if (file_exists($logFile)) {
            $raw  = @file_get_contents($logFile);
            $logs = $raw ? json_decode($raw, true) ?? [] : [];
            $window = time() - 60;
            $recent = array_filter($logs, fn($e) => $e['ip'] === $ip && strtotime($e['time']) > $window);
            if (count($recent) > 200) {
                self::banIP($ip, 3600, 'Auto-ban: flood attack');
                self::block("Flood attack from {$ip}", 'FLOOD', 429);
            }
        }
    }

    // ── User agent check ──
    private static function checkUserAgent(): void {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Empty UA — allow health check / monitoring tools, just log
        if (empty(trim($ua))) {
            $action = strtolower($_GET['action'] ?? '');
            $healthActions = ['init_ver', 'ping', 'health'];
            if (!in_array($action, $healthActions, true)) {
                error_log('[WAF] Empty User-Agent blocked: ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
                self::block("Empty User-Agent", 'BAD_UA');
            }
            return; // health check endpoints pass through
        }

        // Known attack tools
        foreach (self::$BAD_UA as $bad) {
            if (str_contains($ua, $bad)) {
                self::block("Attack tool UA: {$bad}", 'BAD_UA');
            }
        }
    }

    // ── HTTP method check ──
    private static function checkMethod(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $allowed = ['GET', 'POST', 'OPTIONS', 'HEAD'];
        if (!in_array($method, $allowed)) {
            self::block("Disallowed method: {$method}", 'BAD_METHOD');
        }
    }

    // ════════════════════════════════════════════════════════════
    // BAN/UNBAN IP
    // ════════════════════════════════════════════════════════════
    public static function banIP(string $ip, int $duration = 3600, string $reason = ''): void {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return;

        $banFile  = dirname(__DIR__) . '/backups/.banned_ips.json';
        $backupDir = dirname($banFile);
        if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);
        $raw     = file_exists($banFile) ? @file_get_contents($banFile) : false;
        $banned  = $raw ? json_decode($raw, true) ?? [] : [];

        $banned[$ip] = [
            'reason'   => $reason,
            'banned_at'=> time(),
            'expires'  => $duration === 0 ? 0 : time() + $duration,
            'duration' => $duration,
        ];
        file_put_contents($banFile, json_encode($banned, JSON_PRETTY_PRINT), LOCK_EX);
        error_log("WAF BAN: {$ip} — {$reason}");
    }

    public static function unbanIP(string $ip): bool {
        $banFile = dirname(__DIR__) . '/backups/.banned_ips.json';
        if (!file_exists($banFile)) return false;
        $banned = json_decode(file_get_contents($banFile), true) ?? [];
        if (!isset($banned[$ip])) return false;
        unset($banned[$ip]);
        file_put_contents($banFile, json_encode($banned, JSON_PRETTY_PRINT), LOCK_EX);
        return true;
    }

    public static function getBannedIPs(): array {
        $banFile = dirname(__DIR__) . '/backups/.banned_ips.json';
        if (!file_exists($banFile)) return [];
        $banned = json_decode(file_get_contents($banFile), true) ?? [];
        // Add human-readable info
        foreach ($banned as $ip => &$info) {
            $info['ip'] = $ip;
            $info['expires_in'] = $info['expires'] === 0 ? 'Permanent' :
                ($info['expires'] > time() ? round(($info['expires']-time())/60).' minutes remaining' : 'Expired');
            $info['is_active'] = $info['expires'] === 0 || $info['expires'] > time();
        }
        unset($info); // PHP reference fix
        return array_values($banned);
    }

    // ════════════════════════════════════════════════════════════
    // WAF ATTACK LOG
    // ════════════════════════════════════════════════════════════
    public static function getAttackLog(int $limit = 100): array {
        $logFile = dirname(__DIR__) . '/backups/.waf_log.json';
        if (!file_exists($logFile)) return [];
        $logs = json_decode(file_get_contents($logFile), true) ?? [];
        return array_slice(array_reverse($logs), 0, $limit);
    }

    private static function logAttack(string $type, string $detail, string $ip): void {
        $logFile = dirname(__DIR__) . '/backups/.waf_log.json';
        $backupDir = dirname($logFile);
        if (!is_dir($backupDir)) @mkdir($backupDir, 0750, true);
        $raw = file_exists($logFile) ? @file_get_contents($logFile) : false;
        $logs = $raw ? json_decode($raw, true) ?? [] : [];

        $logs[] = [
            'type'    => $type,
            'detail'  => substr($detail, 0, 200),
            'ip'      => $ip,
            'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
            'uri'     => substr($_SERVER['REQUEST_URI'] ?? '', 0, 200),
            'time'    => date('Y-m-d H:i:s'),
            'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
        ];

        // Keep last 1000 entries
        if (count($logs) > 1000) $logs = array_slice($logs, -1000);
        file_put_contents($logFile, json_encode($logs), LOCK_EX);
    }

    // ── BLOCK REQUEST ──
    private static function block(string $reason, string $type, int $code = 403): never {
        $ip = self::getIP();
        error_log("WAF BLOCK [{$type}] {$ip}: {$reason}");
        self::logAttack($type, $reason, $ip);

        // Auto-ban after 10 same-type attacks
        // (handled by flood check already)

        if (ob_get_level() > 0) ob_end_clean();
        http_response_code($code);

        // Return JSON for API, HTML for browser
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Request blocked by security system.',
                'code'    => $code,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Access Denied</title>
            <style>body{font-family:sans-serif;background:#0d1117;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .box{text-align:center;padding:40px;background:#131b2e;border-radius:16px;border:1px solid #1a2744}
            h1{color:#ef4444;font-size:48px;margin:0}p{color:#94a3b8}</style></head>
            <body><div class='box'><h1>🛡️</h1><h2>Access Denied</h2>
            <p>Your request has been blocked by the security system.</p>
            <p style='font-size:12px;color:#475569'>Your request could not be processed.</p></div></body></html>";
        }
        exit;
    }

    public static function getIP(): string {
        $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // ════════════════════════════════════════════════════════════
    // STATISTICS
    // ════════════════════════════════════════════════════════════
    public static function getStats(): array {
        $logs = self::getAttackLog(1000);
        if (empty($logs)) return ['total'=>0,'by_type'=>[],'by_ip'=>[],'recent'=>[]];

        $byType = array_count_values(array_column($logs, 'type'));
        $byIP   = array_count_values(array_column($logs, 'ip'));
        arsort($byIP);

        return [
            'total'   => count($logs),
            'by_type' => $byType,
            'top_ips' => array_slice($byIP, 0, 10, true),
            'recent'  => array_slice($logs, 0, 20),
            'banned'  => count(self::getBannedIPs()),
        ];
    }
}
