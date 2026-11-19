<?php
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

// Kunci embed yang didukung (nama baru dan nama lama untuk kompatibilitas).
$embedKeys = [
    'instagram_embed', 'youtube_embed', 'tiktok_embed',
    'social_instagram_embed', 'social_youtube_embed', 'social_tiktok_embed',
];
$linkKeys = ['instagram_url', 'facebook_url', 'youtube_url', 'tiktok_url'];
$keys = array_merge($embedKeys, $linkKeys);

/**
 * Bersihkan kode embed: buang <script>, event handler, dan javascript:.
 * <iframe> (YouTube/TikTok) dan <blockquote class="instagram-media"> tetap utuh.
 * Jangan pakai htmlspecialchars() atau strip_tags() — keduanya merusak embed.
 */
function sanitize_embed(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<script\b[^>]*/?>#is', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
    $html = preg_replace('#(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2#i', '$1=$2#$2', $html);
    return trim($html);
}

function sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    return mb_substr($url, 0, 500);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $in = implode(',', array_fill(0, count($keys), '?'));
        $q = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
        $q->execute($keys);
        $data = array_fill_keys($keys, '');
        foreach ($q as $row) {
            $data[$row['setting_key']] = $row['setting_value'];
        }
        // Sinkronkan nama kunci agar konsisten: pastikan semua kunci versi baru & lama selalu ada.
        $out = [];
        foreach ($embedKeys as $k) {
            $out[$k] = $data[$k] ?? '';
        }
        foreach ($linkKeys as $k) {
            $out[$k] = $data[$k] ?? '';
        }
        json_out(['status' => 'ok', 'data' => $out]);
    }

    $user = auth(['admin', 'teacher']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
    }

    $input = body();
    // Hanya key yang dikirim yang diperbarui, agar form link dan form embed tidak saling menimpa.
    $stmt = $pdo->prepare("INSERT INTO site_settings(setting_key, setting_value, setting_type) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)");

    $saved = 0;
    foreach ($embedKeys as $key) {
        if (!array_key_exists($key, $input)) continue;
        $stmt->execute([$key, sanitize_embed((string)$input[$key]), 'textarea']);
        $saved++;
    }
    foreach ($linkKeys as $key) {
        if (!array_key_exists($key, $input)) continue;
        $stmt->execute([$key, sanitize_url((string)$input[$key]), 'url']);
        $saved++;
    }

    if ($saved === 0) {
        json_out(['status' => 'error', 'message' => 'Tidak ada pengaturan yang dikirim.'], 422);
    }

    log_action($user['id'], 'social_settings_saved');
    json_out(['status' => 'ok', 'message' => 'Pengaturan media sosial tersimpan.']);
} catch (Throwable $e) {
    error_log('social-settings.php: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => 'Gagal memproses pengaturan media sosial.'], 500);
}
