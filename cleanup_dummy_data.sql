-- MTs Muhammadiyah 06 Purbalingga
-- Penghapusan data dummy / development.
-- Jalankan melalui phpMyAdmin (pilih database target, TIDAK perlu USE database).
-- PERINGATAN: script ini MENGHAPUS seluruh data konten & akun siswa/guru.

SET NAMES utf8mb4;

-- 1. Hapus semua pengumuman / berita dummy
DELETE FROM announcements;

-- 2. Hapus semua karya siswa dummy
DELETE FROM student_works;

-- 3. Hapus semua data kelulusan dummy
DELETE FROM graduation;

-- 4. Hapus semua akun siswa & guru (akun admin utama tetap dipertahankan)
DELETE FROM users WHERE role <> 'admin';
DELETE FROM users WHERE role = 'admin' AND email <> 'admin@mtsmuh6pbg.sch.id';

-- 5. Bersihkan cache tabel agar perubahan langsung terbaca
OPTIMIZE TABLE announcements;
OPTIMIZE TABLE student_works;
OPTIMIZE TABLE graduation;
OPTIMIZE TABLE users;
