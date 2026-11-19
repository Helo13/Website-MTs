-- MTs Muhammadiyah 06 Purbalingga
-- Seeder dummy. Import setelah schema.sql.
-- Database target dipilih dari phpMyAdmin. Tidak ada USE database.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    setting_type ENUM('text','color','image','url','json','textarea') NOT NULL DEFAULT 'text',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmb_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    quota INT NOT NULL DEFAULT 0,
    registration_status ENUM('open','closed','coming_soon') NOT NULL DEFAULT 'open',
    requirements TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (nisn, name, email, password_hash, role, status)
VALUES (
    'admin-hendri',
    'Hendri Susilo',
    'admin@mtsmuh6pbg.sch.id',
    '$2y$10$9403c6y1MytgRFUWfGxFiut3BY.INfof.IsfQ74BPWGA/yElPrfd2',
    'admin',
    'active'
)
ON DUPLICATE KEY UPDATE
    name=VALUES(name),
    password_hash=VALUES(password_hash),
    role='admin',
    status='active';

INSERT INTO site_settings (setting_key, setting_value, setting_type) VALUES
('site_name', 'MTs Muhammadiyah 06 Purbalingga', 'text'),
('site_short_name', 'MTs Musixga', 'text'),
('primary_color', '#1E3A8A', 'color'),
('secondary_color', '#FACC15', 'color'),
('deep_color', '#0F1B4C', 'color'),
('accent_color', '#047857', 'color'),
('font_family', 'Inter, sans-serif', 'text'),
('logo_url', 'https://placehold.co/256x256/1E3A8A/FACC15?text=MTs+06', 'image'),
('hero_image_url', 'https://picsum.photos/seed/mts-building/1600/900', 'image'),
('hero_overline', 'MADRASAH MANDIRI BERPRESTASI', 'text'),
('hero_title', 'Mencetak Generasi Berilmu & Berakhlak', 'text'),
('hero_subtitle', 'Portal resmi MTs Muhammadiyah 06 Purbalingga untuk informasi madrasah, PPDB, karya siswa, berita, dan layanan digital.', 'textarea'),
('hero_primary_cta', 'Daftar PPDB Online', 'text'),
('hero_secondary_cta', 'Kenali Madrasah', 'text'),
('school_address', 'Karangreja, Kutasari, Purbalingga, Jawa Tengah', 'text'),
('school_phone', '0858 6622 8225', 'text'),
('school_email', 'admin@mtsmuh6pbg.sch.id', 'text'),
('instagram_url', 'https://www.instagram.com/mts_musixga/', 'url'),
('youtube_url', 'https://www.youtube.com/@mtsmuhammadiyah06purbalingga', 'url'),
('facebook_url', 'https://www.facebook.com/mtsmusixga/', 'url'),
('tiktok_url', 'https://www.tiktok.com/@mtsmusixga', 'url'),
('google_maps_url', 'https://maps.google.com/?q=MTs+Muhammadiyah+06+Purbalingga', 'url'),
('graduation_release_note', 'Pengumuman kelulusan dapat diakses setelah waktu rilis.', 'textarea')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_type=VALUES(setting_type);

INSERT INTO pmb_settings (academic_year, quota, registration_status, requirements)
SELECT '2027/2028', 150, 'open',
'1. Fotokopi Kartu Keluarga (FC KK)\n2. Fotokopi Akta Kelahiran\n3. Fotokopi ijazah atau surat keterangan lulus\n4. Pas foto terbaru 3x4'
WHERE NOT EXISTS (SELECT 1 FROM pmb_settings);

INSERT INTO announcements (title, excerpt, content, image_url, published, published_at) VALUES
('Masa Pengenalan Lingkungan Madrasah', 'Kegiatan penyambutan siswa baru berlangsung dengan tertib dan penuh semangat.', 'Lorem ipsum dolor sit amet. Siswa mengikuti pengenalan lingkungan madrasah, budaya belajar, tata tertib, dan kegiatan keagamaan.', 'https://picsum.photos/seed/mpls-mts/900/600', 1, UTC_TIMESTAMP()),
('Prestasi Siswa MTs Muhammadiyah 06', 'Siswa madrasah meraih prestasi dalam ajang akademik dan nonakademik tingkat kabupaten.', 'Kegiatan pembinaan bakat dan minat terus dikembangkan melalui program intrakurikuler, kokurikuler, dan ekstrakurikuler.', 'https://picsum.photos/seed/prestasi-mts/900/600', 1, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)),
('Pendaftaran Peserta Didik Baru Dibuka', 'Pendaftaran siswa baru tahun ajaran 2027/2028 kini tersedia secara online.', 'Calon siswa dan orang tua dapat mengisi formulir pendaftaran melalui halaman PPDB. Panitia akan menghubungi nomor yang terdaftar.', 'https://picsum.photos/seed/ppdb-mts/900/600', 1, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 DAY)),
('Gerakan Madrasah Bersih dan Peduli Lingkungan', 'Warga madrasah bergotong royong menciptakan lingkungan belajar yang nyaman.', 'Kegiatan kebersihan lingkungan menjadi bagian pembiasaan karakter disiplin, tanggung jawab, dan kepedulian sosial.', 'https://picsum.photos/seed/green-school/900/600', 1, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY));

INSERT INTO social_media (platform, embed_script, enabled) VALUES
('instagram', '#', 1),
('youtube', '#', 1),
('facebook', '#', 1),
('tiktok', '#', 1)
ON DUPLICATE KEY UPDATE embed_script=VALUES(embed_script), enabled=VALUES(enabled);

INSERT INTO users (nisn, name, email, class_name, password_hash, role, status) VALUES
('dummy-siswa-001', 'Budi Santoso', 'budi@example.com', '8A', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'student', 'active'),
('dummy-siswa-002', 'Siti Aminah', 'siti@example.com', '8B', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'student', 'active'),
('dummy-siswa-003', 'Joko Pratama', 'joko@example.com', '9A', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'student', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name), class_name=VALUES(class_name), status='active';

INSERT INTO student_works (user_id, title, description, media_url, category, status)
SELECT u.id, 'Poster Semangat Belajar', 'Karya poster digital dummy siswa.', 'https://picsum.photos/seed/student-work-1/900/600', 'Seni', 'approved'
FROM users u WHERE u.nisn='dummy-siswa-001'
AND NOT EXISTS (SELECT 1 FROM student_works WHERE title='Poster Semangat Belajar');

INSERT INTO student_works (user_id, title, description, media_url, category, status)
SELECT u.id, 'Proyek Literasi Madrasah', 'Tulisan dan dokumentasi literasi siswa.', 'https://picsum.photos/seed/student-work-2/900/600', 'Literasi', 'approved'
FROM users u WHERE u.nisn='dummy-siswa-002'
AND NOT EXISTS (SELECT 1 FROM student_works WHERE title='Proyek Literasi Madrasah');

INSERT INTO graduation (nisn, name, pin_hash, status, skl_path) VALUES
('111', 'Budi', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'lulus', NULL),
('222', 'Siti', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'lulus', NULL),
('333', 'Joko', '$2y$10$C7RMKpof8fxu8YmQ65wkXOxM27JADAY0dow.Klll3SOu4E2v60eam', 'tidak_lulus', NULL)
ON DUPLICATE KEY UPDATE name=VALUES(name), pin_hash=VALUES(pin_hash), status=VALUES(status);

-- Rilis besok pukul 09:00 UTC. Sesuaikan di dashboard admin.
INSERT INTO graduation_settings (start_datetime, end_datetime)
SELECT UTC_TIMESTAMP(), DATE_ADD(DATE_ADD(DATE(UTC_TIMESTAMP()), INTERVAL 1 DAY), INTERVAL 9 HOUR)
WHERE NOT EXISTS (SELECT 1 FROM graduation_settings);

CREATE INDEX idx_announcements_published ON announcements (published, published_at);
CREATE INDEX idx_student_works_status ON student_works (status, created_at);
CREATE INDEX idx_users_role_status ON users (role, status);
