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

function pmb_computed_status(PDO $pdo): array {
    $q = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('pmb_status','pmb_quota','pmb_start_date','pmb_end_date')");
    $data = ['pmb_status' => 'Buka', 'pmb_quota' => 126, 'pmb_start_date' => '', 'pmb_end_date' => ''];
    foreach ($q as $row) {
        $k = $row['setting_key'];
        $v = $row['setting_value'];
        $data[$k] = $k === 'pmb_quota' ? (int)$v : $v;
    }
    $now = time();
    $start = !empty($data['pmb_start_date']) ? strtotime($data['pmb_start_date']) : null;
    $end = !empty($data['pmb_end_date']) ? strtotime($data['pmb_end_date']) : null;
    $isClosed = ($data['pmb_status'] === 'Tutup') || ($start && $now < $start) || ($end && $now > $end);
    $data['is_open'] = !$isClosed;
    $data['effective_status'] = $isClosed ? 'Tutup' : 'Buka';
    return $data;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'public_status' && $method === 'GET') {
    json_out(['status' => 'ok', 'data' => pmb_computed_status($pdo)]);
}

$user = auth(['admin','teacher']);

if ($action === 'settings' && $method === 'GET') {
    json_out(['status' => 'ok', 'data' => pmb_computed_status($pdo)]);
}

if ($action === 'settings' && $method === 'POST') {
    require_admin($user);
    $d = body();
    $status = trim((string)($d['pmb_status'] ?? 'Buka')) === 'Tutup' ? 'Tutup' : 'Buka';
    $quota = max(0, (int)($d['pmb_quota'] ?? 126));
    $start = trim((string)($d['pmb_start_date'] ?? ''));
    $end = trim((string)($d['pmb_end_date'] ?? ''));
    $stmt = $pdo->prepare("INSERT INTO site_settings(setting_key, setting_value, setting_type) VALUES(?, ?, 'text') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $stmt->execute(['pmb_status', $status]);
    $stmt->execute(['pmb_quota', (string)$quota]);
    $stmt->execute(['pmb_start_date', $start]);
    $stmt->execute(['pmb_end_date', $end]);
    log_action($user['id'], 'pmb_settings_updated', ['status' => $status, 'quota' => $quota]);
    json_out(['status' => 'ok', 'message' => 'Pengaturan gelombang PMB tersimpan.']);
}

if ($action === 'list' && $method === 'GET') {
    $q = $pdo->query("SELECT id, registration_no, name, nisn, origin_school, phone, email, address, status, created_at FROM pmb_applications ORDER BY created_at DESC");
    json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
}

if ($action === 'update_status' && $method === 'POST') {
    $d = body();
    $id = filter_var($d['id'] ?? null, FILTER_VALIDATE_INT);
    $status = trim((string)($d['status'] ?? ''));
    $valid = ['new', 'reviewed', 'accepted', 'rejected'];
    if (!$id || !in_array($status, $valid, true)) json_out(['status' => 'error', 'message' => 'Status atau ID tidak valid.'], 422);
    $pdo->prepare("UPDATE pmb_applications SET status = ? WHERE id = ?")->execute([$status, $id]);
    log_action($user['id'], 'pmb_status_updated', ['id' => $id, 'status' => $status]);
    json_out(['status' => 'ok', 'message' => 'Status pendaftar diperbarui.']);
}

if ($action === 'delete') {
    require_admin($user);
    $id = filter_var($_GET['id'] ?? (body()['id'] ?? null), FILTER_VALIDATE_INT);
    if (!$id) json_out(['status' => 'error', 'message' => 'ID wajib diisi.'], 422);
    $pdo->prepare("DELETE FROM pmb_applications WHERE id = ?")->execute([$id]);
    log_action($user['id'], 'pmb_application_deleted', ['id' => $id]);
    json_out(['status' => 'ok', 'message' => 'Data pendaftar berhasil dihapus.']);
}

json_out(['status' => 'error', 'message' => 'Action PMB tidak dikenal.'], 404);
