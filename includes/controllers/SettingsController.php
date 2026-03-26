<?php
// ═══════════════════════════════════════════════════════
// SettingsController — Settings, Export & Backup
// WP Sales Pro v5 — Module Index
// ═══════════════════════════════════════════════════════
// This file documents which functions belong to this module.
// Actual implementations are in includes/functions.php
// (kept there for single-file compatibility with cPanel)
//
// Functions in this module:
 *   - getSettings()
 *   - saveSettings()
 *   - exportJSON()
 *   - exportExcel()
 *   - exportClientsSheet()
 *   - exportYearlyPlugins()
 *   - createBackup()
 *   - downloadBackup()
 *   - listBackups()
 *   - deleteBackup()
 *   - restoreBackup()
 *   - _safeBackupPath()
 *   - formatBytes()
// ═══════════════════════════════════════════════════════
if (!defined('WPSM_SECURE')) { http_response_code(403); exit; }

// Module loaded — functions available from functions.php
