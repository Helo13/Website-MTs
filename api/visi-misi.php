<?php
// api/visi-misi.php - GET/POST konten Visi & Misi Madrasah
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

const VISI_DEFAULTS = [
    'visi_utama'     => 'Berilmu, Terampil, Beramal, Santun Dalam Berperilaku Bertumpu Pada Iman Yang Kuat',
    'visi_deskripsi' => 'Visi ini diwujudkan dengan membentuk pribadi siswa yang kokoh dalam spiritualitas Islam, unggul akademis, serta santun bermasyarakat.',
    'misi_list'      => [
        'Pembelajaran PAIKEM berbasis religiusitas, kejujuran, dan kedisiplinan.',
        'Pengembangan bakat siswa lewat intrakurikuler & ekstrakurikuler.',
        'Pembekalan keterampilan bekal hidup & pengamalan ajaran Islam.',
    ],
];

/** Normalisasi nilai misi_list menjadi array string bersih. */
function normalize_misi($value): array {
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = [];
        $raw = trim((string)($value ?? ''));
        if ($raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $items = $parsed;
            } elseif (strpos($raw, ',') !== false) {
                $items = explode(',', $raw);
            } else {
                $items = [$raw];
            }
        }
    }
    $clean = [];
    foreach ($items as $item) {
        if (!is_string($item)) continue;
        $text = trim($item);
        if ($text !== '') $clean[] = $text;
    }
    return $clean;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = VISI_DEFAULTS;
    try {
        $q = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('visi_utama', 'visi_deskripsi', 'misi_list')");
        foreach ($q as $row) {
            $key = $row['setting_key'];
            if (!array_key_exists($key, $out)) continue;
            $value = (string)($row['setting_value'] ?? '');
            if ($key === 'misi_list') {
                $items = normalize_misi($value);
                if ($items) $out['misi_list'] = $items;
            } elseif (trim($value) !== '') {
                $out[$key] = $value;
            }
        }
    } catch (\Throwable $e) {
        // Tabel belum ada -> pakai nilai default.
    }
    json_out(['status' => 'ok', 'data' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Method tidak diizinkan'], 405);
}

$u = auth(['admin']);
$payload = body();

$visiUtama = trim((string)($payload['visi_utama'] ?? ''));
$visiDeskripsi = trim((string)($payload['visi_deskripsi'] ?? ''));
$misiList = normalize_misi($payload['misi_list'] ?? []);

if ($visiUtama === '') {
    json_out(['status' => 'error', 'message' => 'Teks visi utama wajib diisi.'], 422);
}
if (!$misiList) {
    json_out(['status' => 'error', 'message' => 'Minimal satu poin misi wajib diisi.'], 422);
}

$items = [
    'visi_utama'     => [$visiUtama, 'textarea'],
    'visi_deskripsi' => [$visiDeskripsi, 'textarea'],
    'misi_list'      => [json_encode(array_values($misiList), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json'],
];

try {
    $stmt = $pdo->prepare("INSERT INTO site_settings(setting_key, setting_value, setting_type) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type)");
    foreach ($items as $key => [$value, $type]) {
        $stmt->execute([$key, $value, $type]);
    }
    log_action($u['id'], 'visi_misi_saved', ['misi_count' => count($misiList)]);
} catch (\Throwable $e) {
    error_log('visi-misi.php: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => 'Gagal menyimpan Visi & Misi.'], 500);
}

json_out([
    'status'  => 'ok',
    'message' => 'Visi & Misi berhasil disimpan.',
    'data'    => [
        'visi_utama'     => $visiUtama,
        'visi_deskripsi' => $visiDeskripsi,
        'misi_list'      => array_values($misiList),
    ],
]);
