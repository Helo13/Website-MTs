<?php
ini_set('display_errors', 0);
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
session_start();

require_once __DIR__ . '/../config/helpers.php';
cors();

function verifyAdminAccess() {
    global $pdo;
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    $sessionUserId = $_SESSION['user_id'] ?? null;
    if ($sessionUserId && in_array($sessionRole, ['admin', 'guru', 'teacher'], true)) {
        $q = $pdo->prepare('SELECT id, name, nisn, email, role, status, class_name FROM users WHERE id = ? AND status = "active"');
        $q->execute([(int)$sessionUserId]);
        $u = $q->fetch();
        if ($u) return $u;
    }

    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $h = getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '';
    }
    if (!preg_match('/Bearer\s+([A-Za-z0-9_\-\.\/]+)/i', $h, $m)) {
        json_out(['status' => 'error', 'message' => 'Unauthorized - No Token'], 401);
    }
    $raw = base64_decode(strtr($m[1], '-_', '+/'));
    $p = explode('|', $raw ?: '');
    if (count($p) >= 3 && ctype_digit((string)$p[0])) {
        $userId = (int)$p[0];
        $q = $pdo->prepare('SELECT id, name, nisn, email, role, status, class_name FROM users WHERE id = ? AND status = "active"');
        $q->execute([$userId]);
        $u = $q->fetch();
        if ($u && in_array(strtolower($u['role'] ?? ''), ['admin', 'guru', 'teacher'], true)) return $u;
    }
    json_out(['status' => 'error', 'message' => 'Unauthorized - Invalid Access'], 401);
}

$u = verifyAdminAccess();
$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';
$d = body();

// --- 1. Dashboard Stats ---
if ($action === 'dashboard') {
    json_out(['status' => 'ok', 'data' => [
        'applications' => (int)$pdo->query('SELECT COUNT(*) FROM pmb_applications')->fetchColumn(),
        'works_pending' => (int)$pdo->query('SELECT COUNT(*) FROM student_works WHERE status="pending"')->fetchColumn(),
        'students' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn(),
    ]]);
}

// --- 2. List Berita & Moderasi Karya ---
if ($action === 'announcements') {
    $q = $pdo->query('SELECT a.id, a.title, a.excerpt, a.content, a.image_url, a.published_at,
                             COALESCE(a.author_role, "admin") AS author_role,
                             COALESCE(u.name, a.author_name, "Humas Madrasah") AS author_name,
                             IF(a.published = 1, "approved", "pending") AS status,
                             "official" AS source
                      FROM announcements a LEFT JOIN users u ON u.id = a.author_id
                      UNION ALL
                      SELECT w.id, w.title, "" as excerpt, w.description, 
                             w.media_url as image_url, w.created_at,
                             "student" as author_role, u.name as author_name,
                             w.status, "karya" as source
                      FROM student_works w LEFT JOIN users u ON u.id = w.user_id
                      WHERE w.category LIKE "%Berita%" OR w.category LIKE "%Artikel%"
                      ORDER BY published_at DESC');
    json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
}

if ($action === 'works') {
    $q = $pdo->query('SELECT w.*, u.name, u.class_name FROM student_works w LEFT JOIN users u ON u.id = w.user_id ORDER BY w.id DESC');
    json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
}

// --- 3. Tambah & Update Berita / Karya ---
if ($action === 'add_news' || $action === 'announcement') {
    $title = trim($_POST['title'] ?? $d['title'] ?? '');
    $content = trim($_POST['content'] ?? $_POST['isi'] ?? $d['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? $d['excerpt'] ?? '');

    if ($title === '' || $content === '') {
        json_out(['status' => 'error', 'message' => 'Judul dan konten berita wajib diisi.'], 422);
    }

    if ($excerpt === '') {
        $excerpt = mb_substr(strip_tags($content), 0, 150) . '...';
    }

    $imageUrl = '';
    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowedExts, true)) {
            $newName = 'berita_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = __DIR__ . '/../uploads/berita/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . $newName)) {
                $imageUrl = '/uploads/berita/' . $newName;
            }
        }
    } else {
        $imageUrl = trim($_POST['image_url'] ?? $d['image_url'] ?? '');
    }

    ensure_news_columns();
    $q = $pdo->prepare('INSERT INTO announcements(title, excerpt, content, image_url, author_id, author_role, author_name, published) VALUES(?, ?, ?, ?, ?, ?, ?, 1)');
    $q->execute([$title, $excerpt, $content, $imageUrl, $u['id'], $u['role'] ?? 'admin', $u['name'] ?? 'Humas Madrasah']);

    json_out(['status' => 'ok', 'message' => 'Berita berhasil diterbitkan', 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update_news') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $sourceType = strtolower(trim($d['source_type'] ?? $_POST['source_type'] ?? 'official'));

    if ($sourceType === 'official' || $sourceType === 'berita') {
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) json_out(['status' => 'error', 'message' => 'Berita tidak ditemukan.'], 404);

        $title = trim($_POST['title'] ?? $d['title'] ?? $existing['title']);
        $content = trim($_POST['content'] ?? $_POST['isi'] ?? $d['content'] ?? $existing['content']);
        $excerpt = trim($_POST['excerpt'] ?? $d['excerpt'] ?? $existing['excerpt']);
        $imageUrl = $existing['image_url'];

        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION));
            $newName = 'berita_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = __DIR__ . '/../uploads/berita/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $uploadDir . $newName)) {
                $imageUrl = '/uploads/berita/' . $newName;
            }
        }

        $upd = $pdo->prepare('UPDATE announcements SET title = ?, excerpt = ?, content = ?, image_url = ? WHERE id = ?');
        $upd->execute([$title, $excerpt, $content, $imageUrl, $id]);
        json_out(['status' => 'ok', 'message' => 'Berita berhasil diperbarui']);
    } else {
        // Update Karya Siswa dari Admin
        $title = trim($_POST['title'] ?? $d['title'] ?? '');
        $desc = trim($_POST['content'] ?? $_POST['description'] ?? $d['content'] ?? '');
        $cat = trim($_POST['category'] ?? $d['category'] ?? 'Artikel');
        
        $upd = $pdo->prepare('UPDATE student_works SET title = ?, description = ?, category = ? WHERE id = ?');
        $upd->execute([$title, $desc, $cat, $id]);
        json_out(['status' => 'ok', 'message' => 'Karya berhasil diperbarui']);
    }
}

// --- 3. Manajemen User ---
if ($action === 'list_users') {
    $q = $pdo->query('SELECT id, name, nisn, email, role, status, class_name FROM users ORDER BY id DESC');
    json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
}

if ($action === 'approve_user') {
    $pdo->prepare('UPDATE users SET status = "active" WHERE id = ?')->execute([(int)$d['id']]);
    json_out(['status' => 'ok', 'message' => 'User berhasil disetujui']);
}

// --- 4. Kelulusan ---
if ($action === 'graduation_list') {
    $q = $pdo->query('SELECT * FROM graduation ORDER BY id DESC');
    json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
}

if ($action === 'graduation_settings') {
    $endDt = str_replace('T', ' ', $d['end_datetime'] ?? '');
    $pdo->query('DELETE FROM graduation_settings');
    $pdo->prepare('INSERT INTO graduation_settings (start_datetime, end_datetime) VALUES (?, ?)')->execute([$endDt, $endDt]);
    json_out(['status' => 'ok', 'message' => 'Jadwal disimpan']);
}

if ($action === 'graduation_settings_get') {
    $q = $pdo->query('SELECT end_datetime FROM graduation_settings ORDER BY id DESC LIMIT 1');
    $row = $q->fetch();
    json_out(['status' => 'ok', 'data' => ['end_datetime' => $row['end_datetime'] ?? '']]);
}

if ($action === 'graduation_delete') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM graduation_settings WHERE id = ?')->execute([$id]);
    json_out(['status' => 'ok', 'message' => 'Jadwal kelulusan dihapus']);
}

// --- 4. Moderasi Status Karya & Manajemen ---
if ($action === 'update_work_status') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $status = trim(strtolower($d['status'] ?? $_GET['status'] ?? ''));
    if (!in_array($status, ['approved', 'rejected', 'pending'])) {
        json_out(['status' => 'error', 'message' => 'Status tidak valid.'], 422);
    }
    $upd = $pdo->prepare('UPDATE student_works SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $upd->execute([$status, $id]);
    json_out(['status' => 'ok', 'success' => true, 'message' => 'Status karya berhasil diperbarui']);
}

if ($action === 'delete_work') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM student_works WHERE id = ?')->execute([$id]);
    json_out(['status' => 'ok', 'message' => 'Karya berhasil dihapus']);
}

if ($action === 'delete_news' || $action === 'delete_announcement') {
    $ids = $d['id'] ?? $_GET['id'] ?? $d['ids'] ?? null;
    $idList = is_array($ids) ? $ids : (is_numeric($ids) ? [(int)$ids] : []);
    foreach ($idList as $id) {
        if (is_numeric($id)) {
            $stmt = $pdo->prepare('SELECT image_url FROM announcements WHERE id = ?');
            $stmt->execute([(int)$id]);
            $row = $stmt->fetch();
            if ($row && $row['image_url'] && strpos($row['image_url'], '/uploads/') === 0) {
                $p = __DIR__ . '/..' . $row['image_url'];
                if (file_exists($p)) @unlink($p);
            }
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([(int)$id]);
        }
    }
    json_out(['status' => 'ok', 'message' => 'Berita berhasil dihapus']);
}

if ($action === 'create_user' || $action === 'add_teacher') {
    $name = trim($d['name'] ?? $_POST['name'] ?? '');
    $nisn = trim($d['nisn'] ?? $_POST['nisn'] ?? '');
    $email = trim($d['email'] ?? $_POST['email'] ?? '');
    $password = trim($d['password'] ?? $_POST['password'] ?? '');
    
    if ($name === '' || $nisn === '' || $password === '') {
        json_out(['status' => 'error', 'message' => 'Nama, NISN, dan Password wajib diisi.'], 422);
    }
    
    $pdo->prepare('INSERT INTO users(nisn, name, email, password_hash, role, status) VALUES(?,?,?,?,?,?)')
        ->execute([$nisn, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'teacher', 'active']);
    json_out(['status' => 'ok', 'message' => 'Akun guru berhasil dibuat']);
}

if ($action === 'delete_user') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    json_out(['status' => 'ok', 'message' => 'Pengguna berhasil dihapus']);
}

if ($action === 'reset_password') {
    $id = (int)($d['id'] ?? $_GET['id'] ?? 0);
    $newPass = 'Musixga123!';
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
    json_out(['status' => 'ok', 'message' => 'Password berhasil direset. Password baru: ' . $newPass]);
}

// --- 5. Kelulusan & Pengaturan PMB ---
if ($action === 'pmb_settings') {
    foreach ($d as $key => $val) {
        $pdo->prepare('INSERT INTO site_settings(setting_key, setting_value) VALUES(?,?) 
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $val]);
    }
    json_out(['status' => 'ok', 'message' => 'Pengaturan PMB disimpan']);
}

if ($action === 'graduation_import') {
    $imported = 0;
    if (!empty($_FILES['file']['tmp_name'])) {
        $rows = [];
        $zip = new ZipArchive();
        if ($zip->open($_FILES['file']['tmp_name']) !== true) json_out(['status' => 'error', 'message' => 'File XLSX tidak valid'], 422);
        $ssXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $sharedStrings = [];
        if ($ssXml !== '') {
            $ss = simplexml_load_string($ssXml);
            foreach ($ss->si as $si) { $sharedStrings[] = (string)$si->t; }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $ws = simplexml_load_string($sheetXml);
        $first = true;
        foreach ($ws->sheetData->row as $row) {
            if ($first) { $first = false; continue; }
            $vals = [];
            foreach ($row->c as $c) {
                $attr = (string)$c['r'];
                $col = preg_replace('/[^A-Za-z]/', '', $attr);
                $v = (string)$c->v;
                $vals[$col] = (isset($c['t']) && (string)$c['t'] === 's') ? ($sharedStrings[(int)$v] ?? '') : $v;
            }
            if (!empty($vals['A'])) {
                $pdo->prepare('INSERT INTO graduation(nisn, name, pin_hash, status, skl_path) VALUES(?,?,?,?,?) 
                               ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status)')
                    ->execute([
                        $vals['A'], $vals['B'] ?? '', 
                        password_hash($vals['C'] ?? '', PASSWORD_DEFAULT),
                        'pending', $vals['E'] ?? null
                    ]);
                $imported++;
            }
        }
    }
    json_out(['status' => 'ok', 'message' => 'Berhasil import ' . $imported . ' data kelulusan']);
}

json_out(['status' => 'error', 'message' => 'Unknown action: ' . $action], 404);
