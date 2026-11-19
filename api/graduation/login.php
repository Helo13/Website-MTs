<?php
// backend/api/graduation/login.php
// - Validasi NISN + PIN siswa kelulusan
// - Mencegah akses data status kelulusan sebelum waktu pengumuman
// - Time check memakai SERVER UTC (bukan jam klien)

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Rate limit per IP, one attempt every 3 seconds. Temporary file is not web-accessible.
$ipKey = 'rl_grad_login_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $ipKey;
$last = (int) @file_get_contents($rateFile);
if ($last && time() - $last < 3) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Terlalu banyak permintaan.']);
    exit;
}
@file_put_contents($rateFile, (string) time(), LOCK_EX);

// Parse body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

$nisn = trim((string)($data['nisn'] ?? ''));
$pin  = trim((string)($data['pin'] ?? ''));

if ($nisn === '' || $pin === '') {
    echo json_encode(['status' => 'error', 'message' => 'NISN dan PIN wajib diisi.']);
    exit;
}

// Ambil pengaturan countdown (UTC)
$stmt = $pdo->query(
    'SELECT start_datetime, end_datetime
       FROM graduation_settings
      ORDER BY id DESC LIMIT 1'
);
$setting = $stmt->fetch();

if (!$setting) {
    echo json_encode(['status' => 'error', 'message' => 'Pengaturan kelulusan belum tersedia.']);
    exit;
}

// --- Waktu validasi di SERVER (UTC), bukan klien ---
$now = new DateTime('now', new DateTimeZone('UTC'));
$end = new DateTime($setting['end_datetime'], new DateTimeZone('UTC'));

if ($now < $end) {
    // Waktu belum tiba: kembalikan hanya countdown (jangan validasi PIN / data status)
    $remaining = $end->getTimestamp() - $now->getTimestamp();
    echo json_encode([
        'status'        => 'countdown',
        'end_timestamp' => $end->getTimestamp(),
        'remaining'     => $remaining,
    ]);
    exit;
}

// --- Waktu sudah lewat: validasi PIN & kirim status ---
$stmt = $pdo->prepare(
    'SELECT id, name, pin_hash, status, skl_path
       FROM graduation
      WHERE nisn = ?
      LIMIT 1'
);
$stmt->execute([$nisn]);
$g = $stmt->fetch();

if (!$g) {
    echo json_encode(['status' => 'error', 'message' => 'NISN tidak ditemukan.']);
    exit;
}

if (!password_verify($pin, $g['pin_hash'])) {
    echo json_encode(['status' => 'error', 'message' => 'PIN salah.']);
    exit;
}

// Generate token HMAC yang valid selama 1 jam
$iat     = (int)floor(time() / 3600) * 3600;
$payload = $nisn . '|' . $iat;
$token   = $iat . '.' . hash_hmac('sha256', $payload, SECRET_KEY);

echo json_encode([
    'status'  => ($g['status'] === 'lulus') ? 'lulus' : 'tidak_lulus',
    'nisn'    => $nisn,
    'name'    => $g['name'],
    'token'   => $token,
    'skl_url' => $g['skl_path']
        ? BASE_URL . '/skl/' . basename($g['skl_path'])
        : '',
]);
?>
