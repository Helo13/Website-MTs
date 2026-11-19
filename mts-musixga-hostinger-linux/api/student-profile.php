<?php
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/helpers.php';
cors();
$u = auth(['student', 'teacher']);
$d = body();

$action = $_GET['action'] ?? '';

if ($action === 'update_profile') {
    $id = (int)$u['id'];
    $name = trim((string)($d['name'] ?? ''));
    $class_name = trim((string)($d['class_name'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    $no_hp = trim((string)($d['no_hp'] ?? ''));

    if ($name === '') json_out(['status' => 'error', 'message' => 'Nama lengkap wajib diisi.'], 422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['status' => 'error', 'message' => 'Format email tidak valid.'], 422);
    if (strlen($class_name) > 10) json_out(['status' => 'error', 'message' => 'Format kelas tidak valid.'], 422);

    try {
        $sql = "UPDATE users SET name=?, email=?, no_hp=?, class_name=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $email !== '' ? $email : null, $no_hp !== '' ? $no_hp : null, $class_name !== '' ? $class_name : null, $id]);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Gagal menyimpan profil.'], 500);
    }

    // Sinkronkan salinan nama/kelas penulis di berita siswa (jika ada)
    try {
        $authorDisplay = $name . ' - ' . ($class_name !== '' ? $class_name : '-');
        $pdo->prepare('UPDATE announcements SET author_name = ? WHERE author_id = ? AND author_role = "student"')
            ->execute([$authorDisplay, $id]);
    } catch (\Throwable $e) {}

    log_action($id, 'profile_updated');
    json_out(['status' => 'ok', 'message' => 'Profil berhasil diperbarui.']);
}

if ($action === 'get_profile') {
    $id = (int)$u['id'];
    $q = $pdo->prepare('SELECT id, nisn, name, email, class_name, no_hp FROM users WHERE id=?');
    $q->execute([$id]);
    $u = $q->fetch();

    if (!$u) {
        json_out(['status' => 'error', 'message' => 'Data siswa tidak ditemukan.'], 404);
    }
    json_out(['status' => 'ok', 'data' => [
        'nisn' => $u['nisn'],
        'name' => $u['name'],
        'email' => $u['email'],
        'class_name' => $u['class_name'] ?? '',
        'no_hp' => $u['no_hp'] ?? '',
    ]]);
}

if ($action === 'change_password') {
    $id = (int)$u['id'];
    $oldPassword = trim((string)($d['old_password'] ?? ''));
    $newPassword = trim((string)($d['new_password'] ?? ''));
    $confirm = trim((string)($d['confirm_password'] ?? ''));

    if ($oldPassword === '') json_out(['status' => 'error', 'message' => 'Password lama wajib diisi.'], 422);
    if ($newPassword === '' || strlen($newPassword) < 6) json_out(['status' => 'error', 'message' => 'Password minimal 6 karakter.'], 422);
    if ($newPassword !== $confirm) json_out(['status' => 'error', 'message' => 'Konfirmasi password tidak cocok.'], 422);

    $q = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
    $q->execute([$id]);
    $target = $q->fetch();

    if (!$target || !password_verify($oldPassword, $target['password_hash'])) {
        json_out(['status' => 'error', 'message' => 'Password lama tidak cocok.'], 401);
    }

    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
    log_action($id, 'password_changed');
    json_out(['status' => 'ok', 'message' => 'Password berhasil diperbarui.']);
}

json_out(['status' => 'error', 'message' => 'Aksi tidak valid.'], 422);
