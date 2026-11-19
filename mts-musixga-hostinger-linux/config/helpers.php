<?php
require_once __DIR__ . '/db.php';
function json_out($data, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function cors() { header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With'); header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS'); header('Cache-Control: no-store'); if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; } }
function body() { $raw = file_get_contents('php://input'); $json = json_decode($raw ?: '', true); return is_array($json) ? $json : $_POST; }
function token($user) { $payload = $user['id'].'|'.(time()+43200); return rtrim(strtr(base64_encode($payload.'|'.hash_hmac('sha256',$payload,SECRET_KEY)),'+/','-_'),'='); }
function auth($roles = null) {
    global $pdo;
    $allowed = ['admin', 'guru', 'teacher'];
    if (is_array($roles)) {
        $allowed = array_map('strtolower', array_filter($roles, 'is_string'));
    } elseif ($roles === true) {
        $allowed = ['admin'];
    } elseif (is_string($roles) && $roles !== '') {
        $allowed = [strtolower($roles)];
    }

    // 1) Cek PHP Session (auth.php menyimpan $_SESSION['user_id'] + $_SESSION['role'])
    if (!empty($_SESSION['user_id'])) {
        $q = $pdo->prepare('SELECT id, name, nisn, email, role, status, class_name FROM users WHERE id = ? AND status = "active"');
        $q->execute([(int)$_SESSION['user_id']]);
        $u = $q->fetch();
        if ($u && in_array(strtolower($u['role'] ?? ''), $allowed, true)) {
            return $u;
        }
    }

    // 2) Bearer token: format userId|role|signature / userId|expiry|signature
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $h = getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '';
    }
    if (!$h && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!preg_match('/Bearer\s+([A-Za-z0-9_-]+)/i', $h, $m)) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    $raw = base64_decode(strtr($m[1], '-_', '+/'));
    if (!$raw) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    $p = explode('|', $raw);
    $userId = null;
    $role = null;
    $signature = '';
    if (count($p) >= 3 && ctype_digit((string)$p[0])) {
        $userId = (int)$p[0];
        $role = $p[1];
        $signature = (string)end($p);
        $payload = $userId . '|' . $role;
        $sig1 = hash_hmac('sha256', $payload, 'mtsmusixga_secret_key');
        $sig2 = defined('SECRET_KEY') ? hash_hmac('sha256', $payload, SECRET_KEY) : '';
        // auth.php login format: userId|role|hash_hmac('sha256', userId, 'mtsmusixga_secret_key')
        $sig3 = hash_hmac('sha256', (string)$userId, 'mtsmusixga_secret_key');
        if (!hash_equals($sig1, $signature) && !($sig2 && hash_equals($sig2, $signature)) && !hash_equals($sig3, $signature)) {
            json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }
        if (ctype_digit((string)$role) && (int)$role > time()) {
            // Legacy token format userId|expiry|signature: role = timestamp.
            $role = 'student';
        }
    } else {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    if ($userId === null) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    $q = $pdo->prepare('SELECT id, name, nisn, email, role, status, class_name FROM users WHERE id = ? AND status = "active"');
    $q->execute([$userId]);
    $u = $q->fetch();
    if (!$u) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    if (empty($allowed) || in_array(strtolower($u['role'] ?? ''), $allowed, true)) {
        return $u;
    }
    json_out(['status' => 'error', 'message' => 'Forbidden'], 403);
}
function require_admin($user) { if($user['role']!=='admin') json_out(['status'=>'error','message'=>'Admin permission required'],403); }
function log_action($uid,$action,$meta=[]) {
    try {
        $GLOBALS['pdo']->prepare('INSERT INTO audit_logs(user_id,action,ip_address,metadata) VALUES(?,?,?,?)')->execute([$uid,$action,$_SERVER['REMOTE_ADDR']??'',json_encode($meta)]);
    } catch (\Throwable $e) {}
}

function ensure_class_column() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'class_name'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE users ADD COLUMN class_name VARCHAR(10) NULL");
        }
    } catch (\Throwable $e) {}
}

function ensure_news_columns() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'author_role'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE announcements 
                ADD COLUMN author_id INT NULL, 
                ADD COLUMN author_role VARCHAR(20) DEFAULT 'admin', 
                ADD COLUMN author_name VARCHAR(100) NULL, 
                ADD COLUMN work_id INT NULL");
        }
    } catch (\Throwable $e) {}
}

/**
 * Basis URL aplikasi (tanpa trailing slash) untuk membangun URL absolut.
 * Menggunakan BASE_URL dari config.php, atau host request bila BASE_URL kosong.
 */
function base_url(): string {
    static $base = null;
    if ($base !== null) return $base;
    $configured = defined('BASE_URL') ? trim((string)BASE_URL) : '';
    if ($configured !== '') {
        $base = rtrim($configured, '/');
        return $base;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = ($https ? 'https://' : 'http://') . $host;
    return $base;
}

/**
 * Ubah path gambar relatif (mis. /uploads/karya/x.jpg) menjadi URL absolut.
 * URL yang sudah http/https/data dibiarkan apa adanya.
 */
function absolute_url($path): string {
    $raw = trim((string)($path ?? ''));
    if ($raw === '') return '';

    // Handle JSON array string (often from student_works copied to announcements)
    // Supports both pure "[...]" and embedded ".../[...]"
    $bStart = strpos($raw, '[');
    $bEnd = strrpos($raw, ']');
    if ($bStart !== false && $bEnd !== false && $bEnd > $bStart) {
        $jsonStr = substr($raw, $bStart, $bEnd - $bStart + 1);
        $parsed = json_decode($jsonStr, true);
        if (is_array($parsed) && count($parsed) > 0) {
            $raw = trim((string)$parsed[0]);
        }
    }

    $raw = trim($raw, " \"'");
    if ($raw === '') return '';
    $raw = preg_replace('#^https?://testing\.mtsmusixga\.sch\.id#i', base_url(), $raw);
    if (preg_match('#^(https?:)?//#i', $raw) || preg_match('#^(data|blob):#i', $raw)) {
        return $raw;
    }
    if (strpos($raw, '/') === 0) {
        return base_url() . $raw;
    }
    return base_url() . '/' . $raw;
}

/**
 * Normalisasi media_url (JSON array multi-file atau string tunggal) menjadi
 * array berisi URL absolut.
 */
function absolute_media_list($value): array {
    if ($value === null || $value === '') return [];
    if (is_array($value)) {
        return array_values(array_filter(array_map('absolute_url', $value), static fn($v) => $v !== ''));
    }
    $raw = trim((string)$value);
    if ($raw === '') return [];
    if (strpos($raw, '[') === 0) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            return array_values(array_filter(array_map('absolute_url', $parsed), static fn($v) => $v !== ''));
        }
    }
    $single = absolute_url($raw);
    return $single !== '' ? [$single] : [];
}
