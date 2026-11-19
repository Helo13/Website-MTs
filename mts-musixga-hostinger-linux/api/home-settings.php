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

function home_image(): ?string {
    if (!isset($_FILES['hero_image']) || $_FILES['hero_image']['error'] !== UPLOAD_ERR_OK) return null;
    if ($_FILES['hero_image']['size'] > 8 * 1024 * 1024) json_out(['status'=>'error','message'=>'Gambar maksimal 8MB.'], 422);
    $ext = strtolower(pathinfo($_FILES['hero_image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) json_out(['status'=>'error','message'=>'Format gambar tidak valid.'], 422);
    $dir = __DIR__ . '/../uploads/beranda/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'hero_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['hero_image']['tmp_name'], $dir . $name)) json_out(['status'=>'error','message'=>'Upload gagal.'], 500);
    return '/uploads/beranda/' . $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('hero_badge', 'hero_title', 'hero_subtitle', 'hero_image_url')");
    $out = [
        'hero_badge' => 'Madrasah Mandiri Berprestasi',
        'hero_title' => 'Mencetak Generasi Agamis, Modern, & Berprestasi',
        'hero_subtitle' => 'Portal resmi MTs Muhammadiyah 06 Purbalingga untuk Informasi Madrasah, PMB, Karya Siswa, Berita, dan Layanan Digital.',
        'hero_image_url' => '',
    ];
    foreach ($q as $r) {
        $out[$r['setting_key']] = $r['setting_value'];
    }
    $out['hero_image_url'] = absolute_url($out['hero_image_url'] ?? '');
    json_out(['status' => 'ok', 'data' => $out]);
}

$u = auth(['admin', 'teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['status' => 'error', 'message' => 'Method tidak diizinkan'], 405);

$badge = trim($_POST['hero_badge'] ?? 'Madrasah Mandiri Berprestasi');
$title = trim($_POST['hero_title'] ?? '');
$subtitle = trim($_POST['hero_subtitle'] ?? '');

if (!$title || !$subtitle) json_out(['status' => 'error', 'message' => 'Judul dan subtitle wajib diisi'], 422);

$image = home_image();
$items = [
    'hero_badge' => $badge,
    'hero_title' => $title,
    'hero_subtitle' => $subtitle,
];
if ($image) $items['hero_image_url'] = $image;

foreach ($items as $key => $value) {
    $q = $pdo->prepare("INSERT INTO site_settings(setting_key, setting_value, setting_type) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)");
    $q->execute([$key, $value, $key === 'hero_image_url' ? 'image' : ($key === 'hero_subtitle' ? 'textarea' : 'text')]);
}

json_out([
    'status' => 'ok',
    'message' => 'Pengaturan beranda tersimpan',
    'image_url' => $image,
]);
