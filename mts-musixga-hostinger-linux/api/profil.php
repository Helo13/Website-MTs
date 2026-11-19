<?php
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

function profile_upload_image(): ?string {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) json_out(['status'=>'error','message'=>'Upload foto gagal.'], 422);
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) json_out(['status'=>'error','message'=>'Ukuran foto maksimal 5MB.'], 422);
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) json_out(['status'=>'error','message'=>'Format foto harus JPG, PNG, atau WEBP.'], 422);
    $dir = __DIR__ . '/../uploads/profil/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_out(['status'=>'error','message'=>'Folder upload profil tidak tersedia.'], 500);
    $name = 'profil_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . $name)) json_out(['status'=>'error','message'=>'Server gagal menyimpan foto.'], 500);
    return '/uploads/profil/' . $name;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $sections = $pdo->query('SELECT id,section_name,title,content_text,image_url,sort_order FROM profile_sections WHERE is_published=1 ORDER BY sort_order,id')->fetchAll();
        $teachers = $pdo->query('SELECT id,name,subject,photo_url,sort_order FROM profile_teachers WHERE is_published=1 ORDER BY sort_order,id')->fetchAll();
        $facilities = $pdo->query('SELECT id,name,description,photo_url,sort_order FROM profile_facilities WHERE is_published=1 ORDER BY sort_order,id')->fetchAll();
        $extras = $pdo->query('SELECT id,name,description,photo_url,sort_order FROM profile_extracurriculars WHERE is_published=1 ORDER BY sort_order,id')->fetchAll();
        foreach ($sections as &$section) {
            $section['image_url'] = absolute_url($section['image_url'] ?? '');
        }
        unset($section);
        foreach ($teachers as &$teacher) {
            $teacher['photo_url'] = absolute_url($teacher['photo_url'] ?? '');
        }
        unset($teacher);
        foreach ($facilities as &$facility) {
            $facility['photo_url'] = absolute_url($facility['photo_url'] ?? '');
        }
        unset($facility);
        foreach ($extras as &$extra) {
            $extra['photo_url'] = absolute_url($extra['photo_url'] ?? '');
        }
        unset($extra);
        json_out(['status'=>'ok','data'=>['sections'=>$sections,'teachers'=>$teachers,'facilities'=>$facilities,'extracurriculars'=>$extras]]);
    }

    $user = auth(['admin','teacher']);
    if ($method !== 'POST' || ($_GET['action'] ?? '') !== 'section') json_out(['status'=>'error','message'=>'Action tidak valid.'], 404);
    
    $sectionName = trim($_POST['section_name'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $content = (string)($_POST['content_text'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($sectionName === '' || $title === '') json_out(['status'=>'error','message'=>'Nama section dan judul wajib diisi.'], 422);
    
    $image = profile_upload_image();

    // Gunakan ON DUPLICATE KEY UPDATE berdasarkan section_name (UNIQUE)
    // Hindari duplikasi atau error duplicate key
    if ($image !== null) {
        $stmt = $pdo->prepare('INSERT INTO profile_sections(section_name, title, content_text, image_url, sort_order) 
                               VALUES(?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE title=VALUES(title), content_text=VALUES(content_text), image_url=VALUES(image_url), sort_order=VALUES(sort_order)');
        $stmt->execute([$sectionName, $title, $content, $image, $sortOrder]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO profile_sections(section_name, title, content_text, sort_order) 
                               VALUES(?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE title=VALUES(title), content_text=VALUES(content_text), sort_order=VALUES(sort_order)');
        $stmt->execute([$sectionName, $title, $content, $sortOrder]);
    }

    log_action($user['id'], 'profile_section_saved', ['section' => $sectionName]);
    json_out(['status' => 'ok', 'message' => 'Konten ' . $title . ' berhasil disimpan.']);
} catch (Throwable $e) {
    error_log('profil.php: ' . $e->getMessage());
    json_out(['status'=>'error','message'=>'Gagal menyimpan profil: ' . $e->getMessage()], 500);
}
