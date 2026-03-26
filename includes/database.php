<?php
if (!defined('WPSM_SECURE')) { http_response_code(403); exit('Access Denied'); }

function getDB(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset('utf8mb4');
            $conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        } catch (mysqli_sql_exception $e) {
            http_response_code(503);
            error_log('DB: ' . $e->getMessage());
            if (ob_get_level() > 0) ob_end_clean(); die(json_encode(['success'=>false,'error' => 'Service temporarily unavailable.'], JSON_UNESCAPED_UNICODE));
        }
    }
    return $conn;
}

function dbQuery(string $sql, string $types = '', array $params = []): mysqli_result|bool {
    $db = getDB(); $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $db->error);
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute(); $result = $stmt->get_result(); $stmt->close();
    return $result ?: true;
}
function dbFetch(string $sql, string $types = '', array $params = []): ?array {
    $r = dbQuery($sql, $types, $params); return ($r instanceof mysqli_result) ? $r->fetch_assoc() : null;
}
function dbFetchAll(string $sql, string $types = '', array $params = []): array {
    $r = dbQuery($sql, $types, $params); return ($r instanceof mysqli_result) ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function dbInsertId(): int { return getDB()->insert_id; }

function initDatabase(): void {
    $db = getDB();
    $tables = [
"CREATE TABLE IF NOT EXISTS admin_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', phone VARCHAR(30) DEFAULT '', role ENUM('super_admin','admin','viewer') DEFAULT 'admin', is_active TINYINT(1) DEFAULT 1, twofa_enabled TINYINT(1) DEFAULT 0, twofa_method ENUM('email','sms') DEFAULT 'email', last_login DATETIME, last_ip VARCHAR(45), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), ip_address VARCHAR(45) NOT NULL, attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP, success TINYINT(1) DEFAULT 0, INDEX idx_ip(ip_address), INDEX idx_u(username), INDEX idx_t(attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS admin_sessions (id VARCHAR(128) PRIMARY KEY, user_id INT NOT NULL, ip_address VARCHAR(45), user_agent VARCHAR(255), last_activity DATETIME DEFAULT CURRENT_TIMESTAMP, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE, INDEX idx_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS audit_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, username VARCHAR(50), action VARCHAR(100) NOT NULL, table_name VARCHAR(50), record_id INT, old_data JSON, new_data JSON, ip_address VARCHAR(45), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_u(user_id), INDEX idx_a(action), INDEX idx_t(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS clients (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(150), phone VARCHAR(30), whatsapp VARCHAR(30), facebook VARCHAR(255), location VARCHAR(100), note TEXT, portal_token VARCHAR(64), portal_active TINYINT(1) DEFAULT 0, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, type ENUM('Theme','Plugin','Service','Other') NOT NULL DEFAULT 'Theme', price DECIMAL(10,2) NOT NULL DEFAULT 0, version VARCHAR(20) DEFAULT '1.0.0', description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS promo_codes (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, type ENUM('percent','fixed') DEFAULT 'percent', value DECIMAL(10,2) NOT NULL, min_amount DECIMAL(10,2) DEFAULT 0, max_uses INT DEFAULT NULL, used_count INT DEFAULT 0, valid_from DATE, valid_until DATE, is_active TINYINT(1) DEFAULT 1, description TEXT, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS sales (id INT AUTO_INCREMENT PRIMARY KEY, sale_date DATE NOT NULL, activated_at DATE, expiry_date DATE, client_id INT NOT NULL, product_id INT NOT NULL, price DECIMAL(10,2) NOT NULL, original_price DECIMAL(10,2) DEFAULT 0, discount_amount DECIMAL(10,2) DEFAULT 0, promo_code_id INT DEFAULT NULL, site_url VARCHAR(255) DEFAULT '', license_type ENUM('Single Site','5 Sites','Unlimited') DEFAULT 'Single Site', payment_status ENUM('paid','partial','pending') DEFAULT 'pending', amount_paid DECIMAL(10,2) DEFAULT 0, renewal_status ENUM('active','expired','renewed','stale') DEFAULT 'active', share_token VARCHAR(64) DEFAULT NULL, expired_at DATE, note TEXT, invoice_no VARCHAR(30), created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE, INDEX idx_renewal(renewal_status), INDEX idx_expiry(expiry_date), INDEX idx_client(client_id), INDEX idx_payment_status(payment_status), INDEX idx_sale_date(sale_date), INDEX idx_invoice_no(invoice_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS payments (id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, method ENUM('bKash Personal','Nagad Personal','Rocket','Upay','bKash Payment','Cellfin','Bank','Other') DEFAULT 'bKash Personal', trx_id VARCHAR(100), note TEXT, paid_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INT, FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE, INDEX idx_sale(sale_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS reminders (id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT, client_id INT NOT NULL, type ENUM('renewal','followup','custom') DEFAULT 'renewal', channel ENUM('whatsapp','email','sms','manual') DEFAULT 'whatsapp', message TEXT, scheduled_at DATETIME NOT NULL, sent_at DATETIME, status ENUM('pending','sent','failed','skipped') DEFAULT 'pending', created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE, FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE, INDEX idx_sched(scheduled_at), INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tickets (id INT AUTO_INCREMENT PRIMARY KEY, ticket_no VARCHAR(20) UNIQUE, client_id INT NOT NULL, sale_id INT, subject VARCHAR(255) NOT NULL, priority ENUM('low','medium','high','urgent') DEFAULT 'medium', status ENUM('open','in_progress','waiting','resolved','closed') DEFAULT 'open', assigned_to INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, resolved_at DATETIME, FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE, INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS ticket_replies (id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, user_id INT, sender ENUM('admin','client') DEFAULT 'admin', message TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS tasks (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT, client_id INT, sale_id INT, priority ENUM('low','medium','high') DEFAULT 'medium', status ENUM('pending','in_progress','done','cancelled') DEFAULT 'pending', due_date DATETIME, assigned_to INT, completed_at DATETIME, created_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_status(status), INDEX idx_due(due_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS settings (\`key\` VARCHAR(100) PRIMARY KEY, \`value\` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS password_reset_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, otp_hash VARCHAR(255) NOT NULL, reset_token VARCHAR(100) DEFAULT NULL, method ENUM('email','sms') DEFAULT 'email', attempts TINYINT DEFAULT 0, used TINYINT DEFAULT 0, ip_address VARCHAR(45), expires_at DATETIME NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_user(user_id), INDEX idx_expires(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS sms_log (id INT AUTO_INCREMENT PRIMARY KEY, client_id INT, phone VARCHAR(30), message TEXT, status ENUM('sent','failed','pending') DEFAULT 'pending', provider VARCHAR(30), response TEXT, sent_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_client(client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS api_requests (id INT AUTO_INCREMENT PRIMARY KEY, ip_address VARCHAR(45) NOT NULL, requested_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ip(ip_address), INDEX idx_t(requested_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->query($sql); } catch (Exception $e) { error_log($e->getMessage()); } }

    $migs = [
        "ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0" => "SHOW COLUMNS FROM sales LIKE 'discount_amount'",
        "ALTER TABLE sales ADD COLUMN promo_code_id INT DEFAULT NULL" => "SHOW COLUMNS FROM sales LIKE 'promo_code_id'",
        "ALTER TABLE sales ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0" => "SHOW COLUMNS FROM sales LIKE 'amount_paid'",
        "ALTER TABLE sales ADD COLUMN invoice_no VARCHAR(30)" => "SHOW COLUMNS FROM sales LIKE 'invoice_no'",
        "ALTER TABLE clients ADD COLUMN portal_token VARCHAR(64)" => "SHOW COLUMNS FROM clients LIKE 'portal_token'",
        "ALTER TABLE clients ADD COLUMN portal_active TINYINT(1) DEFAULT 0" => "SHOW COLUMNS FROM clients LIKE 'portal_active'",
        "ALTER TABLE sales ADD COLUMN original_price DECIMAL(10,2) DEFAULT 0" => "SHOW COLUMNS FROM sales LIKE 'original_price'",
        "ALTER TABLE sales ADD COLUMN expiry_date DATE" => "SHOW COLUMNS FROM sales LIKE 'expiry_date'",
        "ALTER TABLE sales ADD COLUMN renewal_status ENUM('active','expired','renewed','stale') DEFAULT 'active'" => "SHOW COLUMNS FROM sales LIKE 'renewal_status'",
        "ALTER TABLE clients ADD COLUMN whatsapp VARCHAR(30)" => "SHOW COLUMNS FROM clients LIKE 'whatsapp'",
        "ALTER TABLE clients ADD COLUMN facebook VARCHAR(255)" => "SHOW COLUMNS FROM clients LIKE 'facebook'",
        "ALTER TABLE clients ADD COLUMN created_by INT" => "SHOW COLUMNS FROM clients LIKE 'created_by'",
        "ALTER TABLE sales ADD COLUMN created_by INT" => "SHOW COLUMNS FROM sales LIKE 'created_by'",
        "ALTER TABLE sales ADD COLUMN activated_at DATE" => "SHOW COLUMNS FROM sales LIKE 'activated_at'",
        "ALTER TABLE sales ADD COLUMN expired_at DATE" => "SHOW COLUMNS FROM sales LIKE 'expired_at'",
        "ALTER TABLE sales MODIFY COLUMN site_url VARCHAR(255) DEFAULT ''" => "SHOW COLUMNS FROM sales LIKE 'site_url'",
        "ALTER TABLE admin_users ADD COLUMN email VARCHAR(150) DEFAULT ''" => "SHOW COLUMNS FROM admin_users LIKE 'email'",
        "ALTER TABLE admin_users ADD COLUMN phone VARCHAR(30) DEFAULT ''" => "SHOW COLUMNS FROM admin_users LIKE 'phone'",
        "ALTER TABLE sales ADD COLUMN share_token VARCHAR(64) DEFAULT NULL" => "SHOW COLUMNS FROM sales LIKE 'share_token'",
        "ALTER TABLE sales ADD INDEX idx_payment_status (payment_status)" => "SHOW INDEX FROM sales WHERE Key_name='idx_payment_status'",
        "ALTER TABLE sales ADD INDEX idx_sale_date (sale_date)" => "SHOW INDEX FROM sales WHERE Key_name='idx_sale_date'",
        "ALTER TABLE sales ADD INDEX idx_invoice_no (invoice_no)" => "SHOW INDEX FROM sales WHERE Key_name='idx_invoice_no'",
        // 2FA columns
        "ALTER TABLE admin_users ADD COLUMN twofa_enabled TINYINT(1) DEFAULT 0" => "SHOW COLUMNS FROM admin_users LIKE 'twofa_enabled'",
        "ALTER TABLE admin_users ADD COLUMN twofa_method ENUM('email','sms') DEFAULT 'email'" => "SHOW COLUMNS FROM admin_users LIKE 'twofa_method'",
        // Purpose column to separate 2FA tokens from password reset tokens
        "ALTER TABLE password_reset_tokens ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'password_reset'" => "SHOW COLUMNS FROM password_reset_tokens LIKE 'purpose'",
    ];
    // Trusted devices table (2FA skip for known devices)
    dbQuery("CREATE TABLE IF NOT EXISTS trusted_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_hash VARCHAR(64) NOT NULL,
        device_label VARCHAR(100) DEFAULT '',
        ip_address VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uniq_device(user_id, device_hash),
        INDEX idx_user(user_id),
        INDEX idx_hash(device_hash),
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // IP ban table for 2FA brute force
    dbQuery("CREATE TABLE IF NOT EXISTS twofa_ip_ban (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL UNIQUE,
        failed_attempts TINYINT DEFAULT 0,
        banned_until DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ip(ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // ENUM fixes — always run (idempotent, safe on existing DBs)
    foreach ([
        "ALTER TABLE payments MODIFY COLUMN method ENUM('bKash Personal','Nagad Personal','Rocket','Upay','bKash Payment','Cellfin','Bank','Other') DEFAULT 'bKash Personal'",
        "ALTER TABLE products MODIFY COLUMN type ENUM('Theme','Plugin','Service','Other') NOT NULL DEFAULT 'Theme'",
        "ALTER TABLE tickets MODIFY COLUMN status ENUM('open','in_progress','waiting','resolved','closed') DEFAULT 'open'",
    ] as $sql) { try { $db->query($sql); } catch (Exception $e) { error_log('enum-fix: '.$e->getMessage()); } }
    foreach ($migs as $alter => $check) {
        try { if ($db->query($check)->num_rows === 0) $db->query($alter); } catch (Exception $e) {}
    }

    $existing = $db->query("SELECT id FROM admin_users LIMIT 1");
    if ($existing->num_rows === 0) {
        // ⚠️ DEPLOY: Change default password immediately after first login.
        // See deployment guide for default credentials.
        // Generate a random secure password on first install
        $randomPass = 'Admin@' . strtoupper(bin2hex(random_bytes(4)));
        $hash = password_hash($randomPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        error_log('WP Sales Pro — First install admin password: ' . $randomPass . ' (change immediately!)');
        $db->query("INSERT INTO admin_users (username,password_hash,full_name,email,phone,role) VALUES ('admin','$hash','Administrator','','','super_admin')");
    }

    // ── Default Settings ──────────────────────────────────
    $settingExists = $db->query("SELECT COUNT(*) cnt FROM settings")->fetch_assoc()['cnt'] ?? 0;
    if($settingExists == 0) {
        $defaultSettings = [
            'company_name'    => 'Wp Theme Bazar - Joynal Abdin',
            'company_email'   => '',
            'company_phone'   => '',
            'company_address' => 'Nonni, Nalithabari, Sherpur',
            'invoice_prefix'  => 'INV',
            'invoice_next'    => '1001',
            'currency'        => 'BDT',
            'currency_symbol' => '৳',
            'remind_days'     => '7',
            'sms_provider'    => 'ssl',
            'site_title'      => 'Wp Theme Bazar - Joynal Abdin',
            'bkash_number'    => '01619052413',
            'nagad_number'    => '01619052413',
            'cellfin_number'  => '01919052411',
            'rocket_number'   => '01919052410',
            'whatsapp_msg_template' => 'Dear {name},\n\nYour {product} license expires on {expiry}. Please contact us to renew.\n\n— {company}',
        ];
        $stmt = $db->prepare("INSERT IGNORE INTO settings(`key`,`value`) VALUES(?,?)");
        foreach($defaultSettings as $k => $v) {
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $stmt->close();
    }

    try { $db->query("UPDATE sales SET invoice_no = CONCAT('INV-', YEAR(sale_date), '-', LPAD(id,4,'0')) WHERE invoice_no IS NULL OR invoice_no = ''"); } catch (Exception $e) {}

    // ── Auto-set expiry for plugins (1 year from activated_at or sale_date) ──
    try {
        $db->query("UPDATE sales s JOIN products p ON s.product_id=p.id 
            SET s.expiry_date = DATE_ADD(COALESCE(s.activated_at, s.sale_date), INTERVAL 365 DAY),
                s.activated_at = COALESCE(s.activated_at, s.sale_date)
            WHERE p.type='Plugin' AND s.expiry_date IS NULL AND s.renewal_status='active'");
    } catch (Exception $e) { error_log('auto-expiry-plugin: '.$e->getMessage()); }

    // ── Auto-expire: mark active → expired when past expiry_date ──────
    try {
        $db->query("UPDATE sales SET renewal_status='expired', expired_at=expiry_date 
            WHERE renewal_status='active' 
            AND expiry_date IS NOT NULL 
            AND expiry_date < CURDATE()
            AND expired_at IS NULL");
    } catch (Exception $e) { error_log('auto-expire: '.$e->getMessage()); }

    // ── Auto-stale: expired 90+ days without renewal → stale (hide from dashboard) ──
    try {
        $db->query("ALTER TABLE sales MODIFY COLUMN renewal_status ENUM('active','expired','renewed','stale') DEFAULT 'active'");
    } catch (Exception $e) {}
    try {
        $db->query("UPDATE sales SET renewal_status='stale'
            WHERE renewal_status='expired'
            AND expired_at IS NOT NULL
            AND expired_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
    } catch (Exception $e) { error_log('auto-stale: '.$e->getMessage()); }
}
