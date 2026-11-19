-- MTs Muhammadiyah 06 Purbalingga
-- Migrasi: tambah kolom class_name (kelas siswa) pada tabel users.
-- Jalankan melalui phpMyAdmin (pilih database target, TIDAK perlu USE database).
-- Script aman dijalankan berulang kali (idempotent).

SET NAMES utf8mb4;

-- 1. Tambah kolom class_name bila belum ada
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'class_name'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN class_name VARCHAR(10) NULL AFTER status',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Pastikan index pendukung untuk pencarian/filter kelas
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_class_name'
);

SET @sql2 := IF(
    @idx_exists = 0,
    'CREATE INDEX idx_users_class_name ON users (class_name)',
    'DO 0'
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Backfill kelas untuk siswa lama yang belum punya data kelas
UPDATE users SET class_name = '9A' WHERE role = 'student' AND (class_name IS NULL OR class_name = '');

-- 4. Sanitasi nilai kelas agar sesuai daftar resmi (7A-7C, 8A-8C, 9A-9C)
UPDATE users SET class_name = NULL WHERE class_name IS NOT NULL
  AND class_name NOT IN ('7A','7B','7C','8A','8B','8C','9A','9B','9C');

-- 5. Sinkronkan nama penulis pada berita karya siswa agar memuat kelas
UPDATE announcements a
  JOIN users u ON u.id = a.author_id
  SET a.author_name = CONCAT(u.name, ' - ', u.class_name)
  WHERE a.author_role = 'student'
    AND u.class_name IS NOT NULL
    AND u.class_name <> ''
    AND (a.author_name IS NULL OR a.author_name NOT LIKE CONCAT('%- ', u.class_name));

OPTIMIZE TABLE users;
