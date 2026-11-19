<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$stmt = $pdo->query('SELECT end_datetime FROM graduation_settings ORDER BY id DESC LIMIT 1');
$setting = $stmt->fetch();

if (!$setting || empty($setting['end_datetime'])) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Jadwal pengumuman belum tersedia.']);
    exit;
}

$now = new DateTime('now', new DateTimeZone('UTC'));
$release = new DateTime($setting['end_datetime'], new DateTimeZone('UTC'));
$remaining = max(0, $release->getTimestamp() - $now->getTimestamp());

echo json_encode([
    'status' => 'ok',
    'remaining' => $remaining,
    'end_timestamp' => $release->getTimestamp(),
]);
