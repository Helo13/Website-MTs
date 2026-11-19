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
try {
    $user = auth(['admin','teacher']);
    $type = $_GET['type'] ?? '';
    $tables = ['teacher'=>'profile_teachers','facility'=>'profile_facilities','extracurricular'=>'profile_extracurriculars'];
    if (!isset($tables[$type])) json_out(['status'=>'error','message'=>'Tipe tidak valid.'],404);
    $table = $tables[$type];
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') { $q=$pdo->query("SELECT * FROM $table ORDER BY sort_order,id"); json_out(['status'=>'ok','data'=>$q->fetchAll()]); }
    if ($method === 'DELETE') { $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT); if(!$id)json_out(['status'=>'error','message'=>'ID wajib.'],422); $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]); json_out(['status'=>'ok','message'=>'Data dihapus.']); }
    if ($method === 'PUT') {
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
        if(!$id) json_out(['status'=>'error','message'=>'ID wajib.'],422);
        $d=body(); $name=trim((string)($d['name']??'')); $text=trim((string)($d['description']??$d['subject']??''));
        if(!$name) json_out(['status'=>'error','message'=>'Nama wajib diisi.'],422);
        $textColumn = $type === 'teacher' ? 'subject' : 'description';
        $stmt=$pdo->prepare("UPDATE $table SET name=?, $textColumn=?, sort_order=? WHERE id=?");
        $stmt->execute([$name,$text,(int)($d['sort_order']??0),$id]);
        json_out(['status'=>'ok','message'=>'Data berhasil diperbarui.']);
    }
    if ($method === 'POST') {
        $name=trim((string)($_POST['name']??'')); $text=trim((string)($_POST['description']??$_POST['subject']??''));
        if(!$name) json_out(['status'=>'error','message'=>'Nama wajib diisi.'],422);
        $photo=null;
        if(isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK){
            if($_FILES['photo']['size']>5*1024*1024) json_out(['status'=>'error','message'=>'Foto maksimal 5MB.'],422);
            $ext=strtolower(pathinfo($_FILES['photo']['name'],PATHINFO_EXTENSION));
            if(!in_array($ext,['jpg','jpeg','png','webp'],true)) json_out(['status'=>'error','message'=>'Format foto tidak valid.'],422);
            $dir=__DIR__.'/../uploads/profil/'; if(!is_dir($dir)) mkdir($dir,0755,true);
            $file='item_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
            if(!move_uploaded_file($_FILES['photo']['tmp_name'],$dir.$file)) json_out(['status'=>'error','message'=>'Upload foto gagal.'],500);
            $photo='/uploads/profil/'.$file;
        }
        $textColumn=$type==='teacher'?'subject':'description';
        $stmt=$pdo->prepare("INSERT INTO $table (name,$textColumn,photo_url,sort_order) VALUES(?,?,?,?)");
        $stmt->execute([$name,$text,$photo,(int)($_POST['sort_order']??0)]);
        json_out(['status'=>'ok','message'=>'Data berhasil ditambahkan.','id'=>$pdo->lastInsertId()]);
    }
    json_out(['status'=>'error','message'=>'Method tidak diizinkan.'],405);
} catch (Throwable $e) { error_log('profil-crud.php: '.$e->getMessage()); json_out(['status'=>'error','message'=>'Operasi data profil gagal.'],500); }
