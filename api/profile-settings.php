<?php
// api/profile-settings.php - Pengelolaan Kontak, Alamat, & Link Google Maps
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
ini_set('display_errors', 0);
}

require_once __DIR__ . '/../config/helpers.php';
cors();

const CONTACT_KEYS = [
    'school_address',
    'school_email',
    'school_phone',
    'google_maps_url',
    'gmaps_embed',
];

const CONTACT_DEFAULTS = [
    'school_address' => 'Jl. Raya Karangreja, Kec. Kutasari, Kab. Purbalingga, Jawa Tengah 53361',
    'school_email'   => 'info@mtsmusixga.sch.id',
    'school_phone'   => '',
    'google_maps_url'=> 'https://maps.google.com/?q=MTs+Muhammadiyah+06+Purbalingga',
    'gmaps_embed'    => '',
];

/** Pastikan tabel site_settings ada */
function ensure_site_settings_table() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            setting_type ENUM('text','color','image','url','json','textarea') NOT NULL DEFAULT 'text',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {}
}

/** Autentikasi gabungan: token Bearer atau PHP Session */
function authenticate_admin_user() {
    // 1. Cek PHP Session
    if (!empty($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'teacher'], true)) {
        return $_SESSION['user'];
    }
    if (!empty($_SESSION['auth_role']) && in_array($_SESSION['auth_role'], ['admin', 'teacher'], true)) {
        return ['id' => $_SESSION['auth_id'] ?? 1, 'role' => $_SESSION['auth_role']];
    }
    // 2. Fallback ke Bearer Token via helpers.php auth()
    try {
        return auth(['admin', 'teacher']);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
}

try {
    ensure_site_settings_table();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $in = implode(',', array_fill(0, count(CONTACT_KEYS), '?'));
        $q = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
        $q->execute(CONTACT_KEYS);
        $data = array_fill_keys(CONTACT_KEYS, '');
        foreach ($q as $row) {
            $data[$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        // Isi default jika kolom masih kosong di DB
        foreach (CONTACT_DEFAULTS as $key => $def) {
            if (trim($data[$key]) === '') {
                $data[$key] = $def;
            }
        }
        json_out(['status' => 'ok', 'data' => $data]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
    }

    $user = authenticate_admin_user();
    $input = body();

    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)");

    $saved = 0;
    foreach (CONTACT_KEYS as $key) {
        if (!array_key_exists($key, $input)) continue;
        $value = trim((string)($input[$key] ?? ''));
        if ($key === 'google_maps_url' && $value !== '' && !preg_match('#^https?://#i', $value)) {
            json_out(['status' => 'error', 'message' => 'Link Google Maps harus dimulai dengan http:// atau https://.'], 422);
        }
        $type = $key === 'gmaps_embed' ? 'textarea' : ($key === 'google_maps_url' ? 'url' : 'text');
        $stmt->execute([$key, $value, $type]);
        $saved++;
    }

    if ($saved === 0) {
        json_out(['status' => 'error', 'message' => 'Tidak ada pengaturan kontak yang dikirim.'], 422);
    }

    log_action($user['id'] ?? 1, 'contact_settings_saved');
    json_out(['status' => 'ok', 'message' => 'Kontak dan alamat berhasil disimpan.']);
} catch (\Throwable $e) {
    error_log('profile-settings.php: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => 'Gagal menyimpan pengaturan kontak: ' . $e->getMessage()], 500);
}
