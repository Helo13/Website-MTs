-- Schema CMS Profil. Import setelah schema.sql.
CREATE TABLE IF NOT EXISTS profile_sections (
 id INT AUTO_INCREMENT PRIMARY KEY,
 section_name VARCHAR(60) NOT NULL UNIQUE,
 title VARCHAR(200) NOT NULL,
 content_text MEDIUMTEXT NOT NULL,
 image_url VARCHAR(255) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profile_teachers (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 subject VARCHAR(120) NULL,
 photo_url VARCHAR(255) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profile_facilities (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 description TEXT NULL,
 photo_url VARCHAR(255) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profile_extracurriculars (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 description TEXT NULL,
 photo_url VARCHAR(255) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO profile_sections(section_name,title,content_text,sort_order) VALUES
('sambutan','Sambutan Kepala Madrasah','<p>Assalamu’alaikum warahmatullahi wabarakatuh.</p><p>Selamat datang di portal resmi MTs Muhammadiyah 06 Purbalingga. Kami berkomitmen menghadirkan pendidikan Islam yang berilmu, terampil, beramal, dan santun.</p><p><strong>Hendri Susilo</strong><br>Kepala Madrasah</p>',10),
('sejarah','Sejarah Singkat MTs','<p>MTs Muhammadiyah 06 Purbalingga hadir sebagai lembaga pendidikan Islam yang mendampingi generasi muda melalui pembelajaran akademik, keagamaan, keterampilan, dan pembentukan karakter.</p>',20),
('visi_misi','Visi, Misi, dan Tujuan','<h3>Visi</h3><p>Berilmu, Terampil, Beramal, Santun Dalam Berperilaku Bertumpu Pada Iman Yang Kuat.</p><h3>Misi</h3><ul><li>Melaksanakan pembelajaran PAIKEM berbasis religiusitas, kejujuran, dan kedisiplinan.</li><li>Mengembangkan bakat dan minat siswa.</li><li>Membimbing siswa memiliki keterampilan untuk bekal hidup.</li><li>Menumbuhkan pengamalan agama dan iman yang kuat.</li></ul>',30)
ON DUPLICATE KEY UPDATE title=VALUES(title),content_text=VALUES(content_text);

INSERT INTO profile_teachers(name,subject,sort_order) VALUES ('Hendri Susilo','Kepala Madrasah',10),('Siti Aminah','Bahasa Indonesia',20),('Budi Santoso','Matematika',30);
INSERT INTO profile_facilities(name,description,sort_order) VALUES ('Ruang Kelas','Ruang belajar nyaman dan mendukung pembelajaran aktif.',10),('Laboratorium Komputer','Fasilitas teknologi untuk pembelajaran digital.',20),('Mushola','Sarana pembinaan ibadah dan kegiatan keagamaan.',30),('Lapangan Olahraga','Lapangan untuk olahraga dan kegiatan siswa.',40);
INSERT INTO profile_extracurriculars(name,description,sort_order) VALUES ('Tapak Suci','Pembinaan bela diri, disiplin, dan keberanian.',10),('Pramuka','Kegiatan kepemimpinan dan keterampilan hidup.',20),('Hizbul Wathan','Kaderisasi dan kegiatan kepanduan Muhammadiyah.',30),('Seni dan Literasi','Pengembangan kreativitas, seni, dan budaya membaca.',40);
