<?php
require_once __DIR__ . '/../config/helpers.php';
cors();

function normalize_work_status($value) {
    $s = strtolower(trim((string)$value));
    if (in_array($s, ['approved', 'published', 'active', '1'], true)) return 'approved';
    if (in_array($s, ['rejected', 'declined', '2'], true)) return 'rejected';
    return 'pending';
}

try {
    $type = $_GET['type'] ?? 'announcements';

    if ($type === 'detail') {
        $kind = $_GET['kind'] ?? 'berita';
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $source = strtolower(trim((string)($_GET['source'] ?? '')));
        if (!$id) {
            json_out(['status' => 'error', 'message' => 'ID tidak valid.'], 422);
        }

        if ($kind === 'karya') {
            ensure_class_column();
            $q = $pdo->prepare('SELECT w.id, w.title, w.description, w.media_url, w.category, w.created_at,
                                       w.status, u.name, u.class_name
                                FROM student_works w
                                LEFT JOIN users u ON u.id = w.user_id
                                WHERE w.id = ? AND w.status = "approved" LIMIT 1');
            $q->execute([$id]);
            $row = $q->fetch();
            if (!$row) {
                json_out(['status' => 'error', 'message' => 'Karya tidak ditemukan.'], 404);
            }
            $row['media_url'] = absolute_media_list($row['media_url'] ?? '');
            $row['status'] = normalize_work_status($row['status'] ?? '');
            $row['source'] = 'student';
            json_out(['status' => 'ok', 'data' => [$row]]);
        }

        // kind = berita
        $findOfficial = function ($newsId) use ($pdo) {
            ensure_news_columns();
            $q = $pdo->prepare('SELECT a.id, a.title, a.excerpt, a.content, a.image_url, a.published_at,
                                       COALESCE(a.author_role, "admin") AS author_role,
                                       COALESCE(u.name, a.author_name, "Humas Madrasah") AS author_name,
                                       NULL AS category, a.published
                                FROM announcements a
                                LEFT JOIN users u ON u.id = a.author_id
                                WHERE a.id = ? AND a.published = 1 LIMIT 1');
            $q->execute([$newsId]);
            $row = $q->fetch();
            if (!$row) return null;
            $row['image_url'] = absolute_url($row['image_url'] ?? '');
            $row['status'] = 'approved';
            $row['source'] = 'official';
            return $row;
        };

        $findStudent = function ($newsId) use ($pdo) {
            ensure_class_column();
            $q = $pdo->prepare('SELECT w.id, w.title, "" AS excerpt, w.description AS content,
                                       IFNULL(JSON_EXTRACT(w.media_url, "$[0]"), "") AS image_url,
                                       w.created_at AS published_at, "student" AS author_role,
                                       u.name AS author_name, w.category, w.status
                                FROM student_works w
                                LEFT JOIN users u ON u.id = w.user_id
                                WHERE w.id = ? AND w.status = "approved"
                                  AND (w.category LIKE "%Berita%" OR w.category LIKE "%Artikel%")
                                LIMIT 1');
            $q->execute([$newsId]);
            $row = $q->fetch();
            if (!$row) return null;
            $row['image_url'] = absolute_url($row['image_url'] ?? '');
            $row['status'] = normalize_work_status($row['status'] ?? '');
            $row['source'] = 'student';
            return $row;
        };

        if ($source === 'student') {
            $row = $findStudent($id);
        } elseif ($source === 'official') {
            $row = $findOfficial($id);
        } else {
            $row = $findOfficial($id);
            if (!$row) $row = $findStudent($id);
        }
        if (!$row) {
            json_out(['status' => 'error', 'message' => 'Berita tidak ditemukan.'], 404);
        }
        json_out(['status' => 'ok', 'data' => [$row]]);
    }

    if ($type === 'announcements') {
        ensure_news_columns();
        ensure_class_column();
        $q = $pdo->query('SELECT a.id, a.title, a.excerpt, a.content, a.image_url, a.published_at,
                                 COALESCE(a.author_role, "admin") AS author_role,
                                 COALESCE(u.name, a.author_name, "Humas Madrasah") AS author_name,
                                 NULL AS category,
                                 IF(a.published = 1, "published", "pending") AS status,
                                 "official" AS source
                          FROM announcements a
                          LEFT JOIN users u ON u.id = a.author_id
                          WHERE a.published = 1
                          UNION ALL
                          SELECT w.id, w.title, "", w.description,
                                 IFNULL(JSON_EXTRACT(w.media_url, "$[0]"), ""),
                                 w.created_at,
                                 "student" AS author_role,
                                 u.name AS author_name,
                                 w.category,
                                 "approved" AS status,
                                 "student" AS source
                          FROM student_works w
                          LEFT JOIN users u ON u.id = w.user_id
                          WHERE w.status = "approved"
                            AND (w.category LIKE "%Berita%" OR w.category LIKE "%Artikel%")
                          ORDER BY published_at DESC, id DESC');
        $rows = $q->fetchAll();
        foreach ($rows as &$row) {
            $row['image_url'] = absolute_url($row['image_url'] ?? '');
        }
        unset($row);
        json_out(['status' => 'ok', 'data' => $rows]);
    }

    if ($type === 'works') {
        ensure_class_column();
        $q = $pdo->query('SELECT w.id, w.title, w.description, w.media_url, w.category, w.created_at,
                                 w.status, u.name, u.class_name
                          FROM student_works w
                          JOIN users u ON u.id = w.user_id
                          WHERE w.status = "approved"
                          ORDER BY w.created_at DESC');
        $rows = $q->fetchAll();
        foreach ($rows as &$row) {
            $row['media_url'] = absolute_media_list($row['media_url'] ?? '');
            $row['status'] = normalize_work_status($row['status'] ?? '');
            $row['source'] = 'student';
        }
        unset($row);
        json_out(['status' => 'ok', 'data' => $rows]);
    }

    if ($type === 'social') {
        $q = $pdo->query('SELECT platform,embed_script FROM social_media WHERE enabled=1 ORDER BY platform');
        json_out(['status' => 'ok', 'data' => $q->fetchAll()]);
    }
    json_out(['status' => 'error', 'message' => 'Unknown resource'], 404);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['status' => 'error', 'message' => 'Gagal memuat data publik.'], 500);
}
