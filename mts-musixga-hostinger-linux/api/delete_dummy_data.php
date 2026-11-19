<?php
require_once __DIR__ . '/../config/helpers.php';
cors();
$u = auth(['admin']);

$tables = ['announcements', 'student_works', 'graduation'];
foreach ($tables as $table) {
    $pdo->exec("DELETE FROM {$table}");
}

// Hapus semua user non-admin (siswa/guru dummy), pegawai admin utama dipertahankan.
$pdo->prepare('DELETE FROM users WHERE role <> "admin"')->execute();
$pdo->prepare('DELETE FROM users WHERE role = "admin" AND email <> ?')->execute(['admin@mtsmuh6pbg.sch.id']);

log_action($u['id'], 'delete_dummy_data', ['tables' => $tables]);

json_out(['status' => 'ok', 'message' => 'Semua data dummy/development telah dihapus (pengumuman, karya siswa, kelulusan, pengguna non-admin). Akun admin utama dipertahankan.']);
