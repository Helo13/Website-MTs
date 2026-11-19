<?php
/**
 * Sinkronkan kolom class_name pada users dan nama penulis berita karya siswa.
 * Aman dijalankan berulang kali (idempotent).
 * Membutuhkan role admin atau guru.
 */
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

try {
    $u = auth(['admin', 'teacher']);
    ensure_class_column();
    ensure_news_columns();

    // 1. Backfill kelas untuk siswa lama yang belum punya data kelas.
    $pdo->exec("UPDATE users SET class_name = '9A' WHERE role = 'student' AND (class_name IS NULL OR class_name = '')");

    // 2. Sanitasi nilai kelas agar sesuai daftar resmi 7A-9C.
    $pdo->exec("UPDATE users SET class_name = NULL WHERE class_name IS NOT NULL AND class_name NOT IN ('7A','7B','7C','8A','8B','8C','9A','9B','9C')");

    // 3. Samakan author_name berita karya siswa menjadi "Nama - Kelas".
    $stmt = $pdo->prepare(
        'UPDATE announcements a
         JOIN users u ON u.id = a.author_id
         SET a.author_name = CONCAT(u.name, " - ", u.class_name)
         WHERE a.author_role = "student"
           AND u.class_name IS NOT NULL
           AND u.class_name <> ""
           AND (a.author_name IS NULL OR a.author_name NOT LIKE CONCAT("%- ", u.class_name))'
    );
    $stmt->execute();

    $students = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="student" AND class_name IS NOT NULL')->fetchColumn();
    $news = (int)$pdo->query('SELECT COUNT(*) FROM announcements a JOIN users u ON u.id=a.author_id WHERE a.author_role="student" AND u.class_name IS NOT NULL')->fetchColumn();

    log_action($u['id'], 'sync_class_name', ['students' => $students, 'news' => $news]);

    json_out([
        'status' => 'ok',
        'message' => "Sinkronisasi kelas selesai. {$students} siswa terdata kelas, {$news} berita karya diperbarui.",
        'data' => ['students_with_class' => $students, 'news_synced' => $news],
    ]);
} catch (Throwable $e) {
    error_log('sync_class_name.php: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => 'Gagal sinkronisasi kelas: ' . $e->getMessage()], 500);
}
