<?php
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
session_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/helpers.php';
cors();

try {
    $d = body();
    $action = $_GET['action'] ?? 'login';

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        json_out(['status' => 'success', 'message' => 'Logged out']);
    }

    if ($action === 'register') {
        $name = trim((string)($d['name'] ?? ''));
        $nisn = trim((string)($d['nisn'] ?? ''));
        $email = trim((string)($d['email'] ?? ''));
        $class_name = trim((string)($d['class_name'] ?? ''));
        $password = (string)($d['password'] ?? '');
        $confirm = (string)($d['confirm_password'] ?? '');

        if ($name === '' || $nisn === '' || $email === '' || $password === '' || $confirm === '') {
            json_out(['status' => 'error', 'message' => 'Semua kolom pendaftaran (Nama, NISN, Email, Password, Konfirmasi) wajib diisi.'], 422);
        }
        if ($confirm !== '' && $password !== $confirm) {
            json_out(['status' => 'error', 'message' => 'Konfirmasi password tidak cocok.'], 422);
        }
        if (strlen($password) < 8) {
            json_out(['status' => 'error', 'message' => 'Password minimal 8 karakter.'], 422);
        }

        try {
            $q = $pdo->prepare(
                'INSERT INTO users(nisn, name, email, class_name, password_hash, role, status)
                 VALUES(?, ?, ?, ?, ?, "student", "pending")'
            );
            $q->execute([
                $nisn,
                $name,
                $email !== '' ? $email : null,
                $class_name !== '' ? $class_name : null,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            json_out([
                'status' => 'success',
                'message' => 'Registrasi berhasil. Akun menunggu persetujuan admin atau guru.',
            ]);
        } catch (PDOException $e) {
            json_out(['status' => 'error', 'message' => 'NISN atau email sudah terdaftar.'], 409);
        }
    }

    $identity = trim((string)($d['identity'] ?? ''));
    $password = (string)($d['password'] ?? '');

    if ($identity === '' || $password === '') {
        json_out(['status' => 'error', 'message' => 'Email/NISN dan password wajib diisi.'], 422);
    }

    $q = $pdo->prepare(
        'SELECT id, name, nisn, email, role, status, class_name, password_hash
         FROM users
         WHERE nisn = ? OR email = ?
         LIMIT 1'
    );
    $q->execute([$identity, $identity]);
    $u = $q->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        json_out(['status' => 'error', 'message' => 'Email/NISN atau password salah.'], 401);
    }

    if ($u['status'] !== 'active') {
        json_out([
            'status' => 'error',
            'message' => 'Akun belum aktif. Menunggu persetujuan admin atau guru.',
        ], 403);
    }

    if (!in_array($u['role'], ['student', 'teacher', 'admin'], true)) {
        json_out(['status' => 'error', 'message' => 'Role akun tidak valid.'], 403);
    }

    try {
        $pdo->prepare('UPDATE users SET last_login = UTC_TIMESTAMP() WHERE id = ?')->execute([$u['id']]);
    } catch (\Throwable $e) {}

    $roleLower = strtolower($u['role']);
    $token = base64_encode(
        $u['id'] . '|' . $roleLower . '|' . hash_hmac('sha256', $u['id'], 'mtsmusixga_secret_key')
    );

    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['role'] = $roleLower;
    $_SESSION['user'] = $u;
    $_SESSION['auth_token'] = $token;
    session_regenerate_id(true);

    try {
        log_action($u['id'], 'login');
    } catch (\Throwable $e2) {}

    $userData = [
        'id' => (int)$u['id'],
        'name' => $u['name'],
        'role' => $roleLower,
        'class_name' => $u['class_name'] ?? '',
        'nisn' => $u['nisn'] ?? '',
        'email' => $u['email'] ?? '',
    ];

    json_out([
        'status' => 'success',
        'message' => 'Login berhasil.',
        'user' => $userData,
        'token' => $token,
    ]);
} catch (\Throwable $err) {
    json_out(['status' => 'error', 'message' => 'Gagal memproses login: ' . $err->getMessage()], 500);
}

if ($action === 'update_profile') {
    $u = auth(['student', 'teacher', 'admin']);
    $name = trim((string)($d['name'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    $no_hp = trim((string)($d['no_hp'] ?? ''));
    $class_name = trim((string)($d['class_name'] ?? ''));
    $allowedClass = ['7A', '7B', '7C', '8A', '8B', '8C', '9A', '9B', '9C'];
    if ($class_name !== '' && !in_array($class_name, $allowedClass, true)) {
        $class_name = '';
    }
    if ($name === '') {
        json_out(['status' => 'error', 'message' => 'Nama lengkap harus valid.'], 422);
    }
    try {
        $pdo->prepare('UPDATE users SET name = ?, email = ?, class_name = ?, no_hp = ? WHERE id = ?')->execute([$name, $email !== '' ? $email : null, $class_name !== '' ? $class_name : null, $no_hp !== '' ? $no_hp : null, $u['id']]);
        $q = $pdo->prepare('SELECT id, name, nisn, email, class_name, role, status FROM users WHERE id = ?');
        $q->execute([$u['id']]);
        $updated = $q->fetch();
        json_out(['status' => 'ok', 'message' => 'Profil berhasil diperbarui.', 'user' => $updated]);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Gagal memperbarui profil: ' . $e->getMessage()], 500);
    }
}

if ($action === 'change_password') {
    $u = auth(['student']);
    $old = (string)($d['old_password'] ?? '');
    $new = (string)($d['new_password'] ?? '');
    if (strlen($old) < 6 || strlen($new) < 6) {
        json_out(['status' => 'error', 'message' => 'Password baru minimal 6 karakter.'], 422);
    }
    if ($old === $new) {
        json_out(['status' => 'error', 'message' => 'Password lama dan baru tidak boleh sama.'], 422);
    }
    if (!password_verify($old, $u['password_hash'])) {
        json_out(['status' => 'error', 'message' => 'Password lama salah.'], 422);
    }
    try {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        json_out(['status' => 'ok', 'message' => 'Password berhasil diubah.']);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Gagal mengganti password: ' . $e->getMessage()], 500);
    }
}
