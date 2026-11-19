<?php
// backend/api/berita.php - API Tambah, Ambil, Update & Hapus Berita
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    ensure_news_columns();
    ensure_class_column();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($id) {
        $q = $pdo->prepare('SELECT a.id, a.title, a.excerpt, a.content, a.image_url, a.published_at, a.published,
                                 COALESCE(a.author_role, "admin") AS author_role,
                                 COALESCE(u.name, a.author_name, "Humas Madrasah") AS author_name,
                                 u.class_name AS author_class
                           FROM announcements a
                           LEFT JOIN users u ON u.id = a.author_id
                           WHERE a.id = ?
                           ORDER BY a.published_at DESC');
        $q->execute([$id]);
        $rows = $q->fetchAll();
    } else {
        $sort = in_array($_GET['sort'] ?? '', ['published_at', 'id', 'title', 'author_name'], true) ? $_GET['sort'] : 'published_at';
        $order = in_array(strtolower($_GET['order'] ?? ''), ['asc', 'desc'], true) ? strtolower($_GET['order']) : 'desc';
        $role = $_GET['role'] ?? '';
        $published = $_GET['published'] ?? '';
        $search = trim((string)($_GET['search'] ?? ''));

        $sql = 'SELECT a.id, a.title, a.excerpt, a.content, a.image_url, a.published_at, a.published,
                       COALESCE(a.author_role, "admin") AS author_role,
                       COALESCE(u.name, a.author_name, "Humas Madrasah") AS author_name,
                       u.class_name AS author_class
                FROM announcements a
                LEFT JOIN users u ON u.id = a.author_id
                WHERE 1=1';
        $params = [];

        if ($published !== '' && in_array($published, ['0', '1'], true)) {
            $sql .= ' AND a.published = ?';
            $params[] = (int)$published;
        }
        if ($role !== '' && $role !== 'all') {
            $sql .= ' AND a.author_role = ?';
            $params[] = $role;
        }
        if ($search !== '') {
            $sql .= ' AND (a.title LIKE ? OR COALESCE(u.name, a.author_name) LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $sql .= ' ORDER BY ' . $sort . ' ' . $order;
        $q = $pdo->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll();
    }
    foreach ($rows as &$row) {
        $row['image_url'] = absolute_url($row['image_url'] ?? '');
    }
    unset($row);
    json_out(['status' => 'ok', 'data' => $rows]);
}

if ($method === 'POST') {
    $u = auth(['admin', 'teacher']);

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');

    if ($title === '' || $content === '') {
        json_out(['status' => 'error', 'message' => 'Judul dan konten berita wajib diisi.'], 422);
    }

    if ($excerpt === '') {
        $excerpt = mb_substr(strip_tags($content), 0, 150) . '...';
    }

    $imageUrl = '';

    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['thumbnail']['tmp_name'];
        $fileName = $_FILES['thumbnail']['name'];
        $fileSize = $_FILES['thumbnail']['size'];

        if ($fileSize > 5 * 1024 * 1024) {
            json_out(['status' => 'error', 'message' => 'Ukuran thumbnail maksimal 5MB.'], 422);
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedExts, true)) {
            json_out(['status' => 'error', 'message' => 'Format file thumbnail tidak valid. Gunakan JPG, PNG, atau WEBP.'], 422);
        }

        $uploadDir = __DIR__ . '/../uploads/berita/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $newFileName = 'berita_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destination = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmp, $destination)) {
            $imageUrl = '/uploads/berita/' . $newFileName;
        } else {
            json_out(['status' => 'error', 'message' => 'Gagal menyimpan thumbnail di server.'], 500);
        }
    } else {
        $imageUrl = trim($_POST['image_url'] ?? '');
        if ($imageUrl !== '' && !preg_match('#^(https?:)?//#i', $imageUrl) && strpos($imageUrl, '/') !== 0) {
            $imageUrl = '/' . ltrim($imageUrl, '/');
        }
    }

    ensure_news_columns();
    $q = $pdo->prepare('INSERT INTO announcements(title, excerpt, content, image_url, author_id, author_role, author_name, published) VALUES(?, ?, ?, ?, ?, ?, ?, 1)');
    $q->execute([$title, $excerpt, $content, $imageUrl, $u['id'], $u['role'], $u['name']]);

    log_action($u['id'], 'berita_created', ['title' => $title]);

    json_out([
        'status' => 'ok',
        'message' => 'Berita berhasil diterbitkan.',
        'id' => $pdo->lastInsertId()
    ]);
}

if ($method === 'PUT' || (isset($_GET['id']) && $method === 'POST' && (($_POST['_method'] ?? '') === 'PUT'))) {
    $u = auth(['admin', 'teacher']);
    $id = filter_var($_GET['id'] ?? body()['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_out(['status' => 'error', 'message' => 'ID berita tidak valid.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        json_out(['status' => 'error', 'message' => 'Berita tidak ditemukan.'], 404);
    }

    $d = body();
    $title = trim($d['title'] ?? $_POST['title'] ?? $existing['title']);
    $content = trim($d['content'] ?? $_POST['content'] ?? $existing['content']);
    $excerpt = trim($d['excerpt'] ?? $_POST['excerpt'] ?? $existing['excerpt']);

    if ($excerpt === '') {
        $excerpt = mb_substr(strip_tags($content), 0, 150) . '...';
    }

    $imageUrl = $existing['image_url'];

    if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['thumbnail']['tmp_name'];
        $fileName = $_FILES['thumbnail']['name'];
        $fileSize = $_FILES['thumbnail']['size'];

        if ($fileSize <= 5 * 1024 * 1024) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $uploadDir = __DIR__ . '/../uploads/berita/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newFileName = 'berita_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($fileTmp, $uploadDir . $newFileName)) {
                    // Hapus gambar lama jika ada dan lokal
                    if ($existing['image_url'] && strpos($existing['image_url'], '/uploads/') === 0) {
                        $oldPath = __DIR__ . '/..' . $existing['image_url'];
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }
                    $imageUrl = '/uploads/berita/' . $newFileName;
                }
            }
        }
    }

    $upd = $pdo->prepare('UPDATE announcements SET title = ?, excerpt = ?, content = ?, image_url = ? WHERE id = ?');
    $upd->execute([$title, $excerpt, $content, $imageUrl, $id]);

    log_action($u['id'], 'berita_updated', ['id' => $id, 'title' => $title]);
    json_out(['status' => 'ok', 'message' => 'Berita berhasil diperbarui.']);
}

if ($method === 'DELETE') {
    $u = auth(['admin', 'teacher']);
    $ids = $_GET['id'] ?? body()['id'] ?? null;
    $idList = is_array($ids) ? array_filter(array_map('intval', $ids), 'is_int') : (is_numeric($ids) ? [(int)$ids] : []);

    if (empty($idList)) {
        json_out(['status' => 'error', 'message' => 'ID berita tidak valid.'], 422);
    }

    // Hapus satu per satu: DB + file lokal
    foreach ($idList as $id) {
        $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($existing['image_url'] && strpos($existing['image_url'], '/uploads/') === 0) {
                $oldPath = __DIR__ . '/..' . $existing['image_url'];
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
            log_action($u['id'], 'berita_deleted', ['id' => $id]);
        }
    }

    json_out(['status' => 'ok', 'message' => count($idList) === 1 ? 'Berita berhasil dihapus.' : count($idList) . ' berita berhasil dihapus.']);
}

json_out(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
