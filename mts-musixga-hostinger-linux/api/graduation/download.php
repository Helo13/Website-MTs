<?php
// backend/api/graduation/download.php
// - Download SKL (PDF), dilindungi token + waktu
//
// URL contoh:
//   /api/graduation/download.php?nisn=1234567890&token=<token>

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

$token = $_GET['token'] ?? '';
$nisn  = $_GET['nisn']  ?? '';
$parts = explode('.', $token);

if (count($parts) !== 2) {
    http_response_code(400);
    echo 'Token tidak valid.';
    exit;
}

$iat = (int)$parts[0];
$sig = $parts[1];

// Verifikasi tanda tangan HMAC
$expected = hash_hmac('sha256', $nisn . '|' . $iat, SECRET_KEY);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo 'Token tidak sah.';
    exit;
}

// Token hanya valid mulai saat diterbitkan dan maksimal 1 jam.
if ($iat > time() || time() - $iat > 3600) {
    http_response_code(403);
    echo 'Token kadaluarsa.';
    exit;
}

// Pastikan waktu pengumuman sudah lewat (server time)
$stmt = $pdo->query(
    'SELECT end_datetime FROM graduation_settings ORDER BY id DESC LIMIT 1'
);
$setting = $stmt->fetch();

if (!$setting || time() < strtotime($setting['end_datetime'])) {
    http_response_code(403);
    echo 'Pengumuman belum dimulai.';
    exit;
}

// Ambil path SKL hanya jika status = lulus
$stmt = $pdo->prepare(
    'SELECT skl_path FROM graduation
      WHERE nisn = ? AND status = "lulus"
      LIMIT 1'
);
$stmt->execute([$nisn]);
$g = $stmt->fetch();

if (!$g) {
    http_response_code(404);
    echo 'File SKL tidak tersedia.';
    exit;
}

// Path file PDF relatif terhadap folder project
$filePath = dirname(__DIR__, 3) . '/skl/' . basename($g['skl_path']);

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'File tidak ditemukan.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="SKL-MTs06-' . $nisn . '.pdf"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
?>
