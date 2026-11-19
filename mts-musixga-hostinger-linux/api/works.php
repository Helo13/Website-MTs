<?php
require_once __DIR__ . '/../config/helpers.php';
cors();

function normalize_work_status($value) {
    $s = strtolower(trim((string)$value));
    if (in_array($s, ['approved', 'published', 'active', '1'], true)) return 'approved';
    if (in_array($s, ['rejected', 'declined', '2'], true)) return 'rejected';
    return 'pending';
}

session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    ini_set('display_errors', 0);
}

$u = auth(['admin', 'teacher', 'student']);
$userId = $u['id'] ?? null;

$type = $_GET['type'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'update_work' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (!$userId) {
        json_out(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.'], 401);
    }
    $d = body();
    $id = (int)($d['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
    $title = trim((string)($d['title'] ?? $_POST['title'] ?? ''));
    $category = trim((string)($d['category'] ?? $_POST['category'] ?? 'Artikel'));
    $description = trim((string)($d['description'] ?? $d['content'] ?? $d['isi'] ?? $_POST['description'] ?? $_POST['content'] ?? ''));

    if (!$id || $title === '' || $description === '') {
        json_out(['status' => 'error', 'message' => 'ID, judul, dan isi karya wajib diisi.'], 422);
    }

    $q = $pdo->prepare('SELECT user_id FROM student_works WHERE id = ?');
    $q->execute([$id]);
    $work = $q->fetch();
    if (!$work) {
        json_out(['status' => 'error', 'message' => 'Karya tidak ditemukan.'], 404);
    }

    $roleLower = strtolower((string)($u['role'] ?? ''));
    if (!in_array($roleLower, ['admin', 'teacher', 'guru'], true) && (int)$work['user_id'] !== (int)$userId) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 403);
    }

    // Optional new attachment (single or multiple files)
    $newMediaUrls = [];
    $uploadDir = __DIR__ . '/../uploads/works/';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
    if (!empty($_FILES['media'])) {
        $raw = $_FILES['media'];
        $files = [];
        if (is_array($raw['name'])) {
            $cnt = count($raw['name']);
            for ($i = 0; $i < $cnt; $i++) {
                $files[] = ['name' => $raw['name'][$i], 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i]];
            }
        } else {
            $files[] = ['name' => $raw['name'], 'tmp_name' => $raw['tmp_name'], 'error' => $raw['error'], 'size' => $raw['size']];
        }
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4'];
        foreach ($files as $f) {
            if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK || empty($f['tmp_name'])) { continue; }
            if ($f['size'] > 10 * 1024 * 1024) { continue; }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) { continue; }
            $newName = 'works_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['name']);
            if (move_uploaded_file($f['tmp_name'], $uploadDir . $newName)) {
                $newMediaUrls[] = '/uploads/works/' . $newName;
            }
        }
    }

    $cols = $pdo->query('SHOW COLUMNS FROM student_works')->fetchAll(PDO::FETCH_COLUMN);
    $hasStatus = in_array('status', $cols, true);
    if ($newMediaUrls) {
        $mediaJoined = implode(',', $newMediaUrls);
        if ($hasStatus) {
            $upd = $pdo->prepare('UPDATE student_works SET title = ?, category = ?, description = ?, media_url = ?, status = ? WHERE id = ?');
            $upd->execute([$title, $category, $description, $mediaJoined, 'pending', $id]);
        } else {
            $upd = $pdo->prepare('UPDATE student_works SET title = ?, category = ?, description = ?, media_url = ? WHERE id = ?');
            $upd->execute([$title, $category, $description, $mediaJoined, $id]);
        }
    } else {
        if ($hasStatus) {
            $upd = $pdo->prepare('UPDATE student_works SET title = ?, category = ?, description = ?, status = ? WHERE id = ?');
            $upd->execute([$title, $category, $description, 'pending', $id]);
        } else {
            $upd = $pdo->prepare('UPDATE student_works SET title = ?, category = ?, description = ? WHERE id = ?');
            $upd->execute([$title, $category, $description, $id]);
        }
    }
    json_out(['status' => 'success', 'message' => 'Karya berhasil diperbarui']);
}

if ($type === 'my_works' || $action === 'my_works') {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $reqNisn = trim((string)($_GET['nisn'] ?? ''));
    $reqUid = (int)($_GET['user_id'] ?? 0);

    if (!$uid && !$reqUid && $reqNisn === '') {
        // Coba ambil dari Bearer token jika session kosong
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!$h && function_exists('getallheaders')) {
            $h = getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+([A-Za-z0-9_\-\.\/]+)/i', $h, $m)) {
            $raw = base64_decode(strtr($m[1], '-_', '+/'));
            $p = explode('|', $raw ?: '');
            if (count($p) >= 1 && ctype_digit((string)$p[0])) {
                $uid = (int)$p[0];
            }
        }
    }
    
    $finalUid = $uid ?: $reqUid;
    
    try {
        $conds = [];
        $params = [];
        
        if ($finalUid > 0) {
            $conds[] = 'w.user_id = ?';
            $params[] = $finalUid;
        }
        
        if ($reqNisn !== '') {
            $conds[] = 'u.nisn = ?';
            $params[] = $reqNisn;
        }

        $where = count($conds) > 0 ? 'WHERE (' . implode(' OR ', $conds) . ')' : '';
        $sql = "SELECT w.*, u.name AS student_name, u.class_name AS student_class 
                FROM student_works w 
                LEFT JOIN users u ON u.id = w.user_id 
                $where 
                ORDER BY w.id DESC";
        
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll();
        json_out(['status' => 'success', 'works' => $rows, 'data' => $rows]);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && $userId) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_out(['status' => 'error', 'message' => 'ID tidak valid.'], 422);
    }
    $q = $pdo->prepare('SELECT user_id FROM student_works WHERE id = ?');
    $q->execute([$id]);
    $work = $q->fetch();
    if (!$work) {
        json_out(['status' => 'error', 'message' => 'Karya tidak ditemukan.'], 404);
    }
    $owner = (int)$work['user_id'];
    if ($owner === 0) { $owner = $userId; } // baris lama tanpa user_id, pakai session yang sedang login
    $stmt = $pdo->prepare('DELETE FROM student_works WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $owner]);
    if ($stmt->rowCount() === 0) {
        json_out(['status' => 'error', 'message' => 'Karya tidak ditemukan atau bukan milik Anda.'], 404);
    }
    json_out(['status' => 'ok', 'message' => 'Karya berhasil dihapus.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$userId) {
        json_out(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.'], 401);
    }

    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? 'Artikel');
    $description = trim($_POST['description'] ?? '');

    if ($title === '' || $description === '') {
        json_out(['status' => 'error', 'message' => 'Judul dan isi karya wajib diisi.'], 422);
    }

    $mediaUrl = '';
    $uploadDir = __DIR__ . '/../uploads/works/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $mediaFiles = [];
    if (!empty($_FILES['media'])) {
        $raw = $_FILES['media'];
        $files = [];
        // PHP may structure multi-file input as $_FILES['media']['name'][0] or $_FILES['media']['name'] as scalar for single
        if (is_array($raw['name'])) {
            $count = count($raw['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name' => $raw['name'][$i],
                    'tmp_name' => $raw['tmp_name'][$i],
                    'error' => $raw['error'][$i],
                    'size' => $raw['size'][$i],
                    'type' => $raw['type'][$i] ?? '',
                ];
            }
        } else {
            $files[] = [
                'name' => $raw['name'],
                'tmp_name' => $raw['tmp_name'],
                'error' => $raw['error'],
                'size' => $raw['size'],
                'type' => $raw['type'] ?? '',
            ];
        }

        foreach ($files as $f) {
            if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK || empty($f['tmp_name'])) {
                continue;
            }
            if ($f['size'] > 10 * 1024 * 1024) {
                json_out(['status' => 'error', 'message' => 'File ' . htmlspecialchars($f['name']) . ' melebihi 10MB.'], 422);
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4'];
            if (!in_array($ext, $allowedExts, true)) {
                json_out(['status' => 'error', 'message' => 'Format file ' . htmlspecialchars($f['name']) . ' tidak valid.'], 422);
            }
            $newFileName = 'works_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['name']);
            $destination = $uploadDir . $newFileName;
            if (!move_uploaded_file($f['tmp_name'], $destination)) {
                json_out(['status' => 'error', 'message' => 'Gagal menyimpan file ' . htmlspecialchars($f['name']) . '.'], 500);
            }
            $mediaFiles[] = '/uploads/works/' . $newFileName;
        }
    }

    if ($mediaFiles !== []) {
        $mediaUrl = json_encode($mediaFiles, JSON_UNESCAPED_UNICODE);
    }

    try {
        ensure_class_column();
        $q = $pdo->prepare('INSERT INTO student_works (user_id, title, category, description, media_url, status) VALUES (?, ?, ?, ?, ?, "pending")');
        $q->execute([$userId, $title, $category, $description, $mediaUrl]);
        json_out(['status' => 'ok', 'message' => 'Karya berhasil dikirim dan menunggu moderasi admin/guru.']);
    } catch (\Throwable $e) {
        json_out(['status' => 'error', 'message' => 'Gagal menyimpan karya: ' . $e->getMessage()], 500);
    }
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id <= 0) {
        json_out(['status' => 'error', 'message' => 'ID tidak valid.'], 422);
    }
    ensure_class_column();
    $q = $pdo->prepare('SELECT w.id, w.title, w.description, w.media_url, w.category, w.created_at,
                               w.status, u.name, u.class_name
                        FROM student_works w
                        LEFT JOIN users u ON u.id = w.user_id
                        WHERE w.id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        json_out(['status' => 'error', 'message' => 'Karya tidak ditemukan.'], 404);
    }
    $row['media_url'] = absolute_media_list($row['media_url'] ?? '');
    $row['status'] = normalize_work_status($row['status'] ?? '');
    json_out(['status' => 'ok', 'data' => [$row]]);
}

json_out(['status' => 'error', 'message' => 'Endpoint tidak valid'], 404);
