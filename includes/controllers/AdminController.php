<?php
// ═══════════════════════════════════════════════════════
// AdminController — Admin Users, Sessions & WAF
// WP Sales Pro v5 — Module Index
// ═══════════════════════════════════════════════════════
// This file documents which functions belong to this module.
// Actual implementations are in includes/functions.php
// (kept there for single-file compatibility with cPanel)
//
// Functions in this module:
 *   - getAdmins()
 *   - addAdmin()
 *   - updateAdmin()
 *   - toggleAdmin()
 *   - deleteAdmin()
 *   - getActiveSessions()
 *   - killSession()
 *   - getAuditLog()
// ═══════════════════════════════════════════════════════
if (!defined('WPSM_SECURE')) { http_response_code(403); exit; }

// Module loaded — functions available from functions.php
