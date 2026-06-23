<?php
// =========================================
// admin/upload.php — 图片上传接口
// =========================================

// 第一步：把所有错误转成 JSON，防止 HTML 错误页破坏响应
@ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => "PHP错误($errno): $errstr in $errfile:$errline"], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // 清空之前可能输出的内容
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PHP致命错误: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']], JSON_UNESCAPED_UNICODE);
    }
});
ob_start(); // 缓冲输出，防止多余内容污染 JSON

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();

// 提升上传限制（在 php.ini 允许的范围内动态设置）
@ini_set('upload_max_filesize', '500M');
@ini_set('post_max_size', '500M');
@ini_set('max_execution_time', '600');

// 清掉 ob_start 捕获的任何杂散输出，然后设置正确的头
ob_end_clean();
ob_start();
header('Content-Type: application/json; charset=utf-8');

function err(string $msg, int $code = 400): void {
    if (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method Not Allowed', 405);
if (empty($_FILES['file'])) err('未收到文件');

$upload_type = $_POST['type'] ?? 'image';

// ---- 音乐上传分支 ----
if ($upload_type === 'music') {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) err('上传出错，code=' . $f['error']);
    if ($f['size'] > 100 * 1024 * 1024) err('文件超过 100MB 限制');

    $mime = mime_content_type($f['tmp_name']);
    $audio_allow = ['audio/mpeg','audio/mp3','audio/flac','audio/x-flac','audio/mp4','audio/m4a','audio/ogg','audio/wav','audio/x-wav','audio/aac'];
    if (!in_array($mime, $audio_allow)) err('仅支持 MP3 / FLAC / M4A / OGG / WAV / AAC');

    $ext_map = ['audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/flac'=>'flac','audio/x-flac'=>'flac',
                'audio/mp4'=>'m4a','audio/m4a'=>'m4a','audio/ogg'=>'ogg',
                'audio/wav'=>'wav','audio/x-wav'=>'wav','audio/aac'=>'aac'];
    $ext   = $ext_map[$mime] ?? 'mp3';
    // 保留原文件名（去掉非安全字符），避免乱码
    $orig  = pathinfo($f['name'], PATHINFO_FILENAME);
    $orig  = preg_replace('/[^\w\-\u4e00-\u9fa5]/u', '_', $orig);
    $orig  = trim($orig, '_') ?: 'music';
    $fname = $orig . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

    $upload_dir = __DIR__ . '/../uploads/music/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $dest = $upload_dir . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) err('文件保存失败');

    $doc_root     = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $project_root = rtrim(realpath(__DIR__ . '/..'), '/\\');
    $base_url = (strpos($project_root, $doc_root) === 0)
        ? str_replace('\\', '/', substr($project_root, strlen($doc_root)))
        : '';
    $url = $base_url . '/uploads/music/' . $fname;
    if (ob_get_level()) ob_end_clean();
    echo json_encode(['url' => $url, 'filename' => $fname], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 图片上传分支 ----
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) err('上传出错，code=' . $f['error']);
if ($f['size'] > 10 * 1024 * 1024) err('文件超过 10MB 限制');

// 检测真实类型
$mime = mime_content_type($f['tmp_name']);
$allow = ['image/jpeg','image/png','image/gif','image/webp'];
if (!in_array($mime, $allow)) err('仅支持 JPG / PNG / GIF / WEBP');

// 确保目录存在
$upload_dir = __DIR__ . '/../uploads/picture/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// 生成唯一文件名
$ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
$ext     = $ext_map[$mime];
$fname   = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest    = $upload_dir . $fname;

// GIF 直接原文件保存，跳过 GD 处理（GD 只能保留第一帧，会破坏动画）
if ($mime === 'image/gif') {
    if (!move_uploaded_file($f['tmp_name'], $dest)) err('图片保存失败');
} else {
    // 读取原图
    switch ($mime) {
        case 'image/jpeg': $src_img = imagecreatefromjpeg($f['tmp_name']); break;
        case 'image/png':  $src_img = imagecreatefrompng($f['tmp_name']);  break;
        case 'image/webp': $src_img = imagecreatefromwebp($f['tmp_name']); break;
        default:           $src_img = null;
    }
    if (!$src_img) err('图片解析失败');

    $src_w = imagesx($src_img);
    $src_h = imagesy($src_img);

    // 裁剪参数（比例 0~1）
    $cx = isset($_POST['crop_x']) ? (float)$_POST['crop_x'] : 0;
    $cy = isset($_POST['crop_y']) ? (float)$_POST['crop_y'] : 0;
    $cw = isset($_POST['crop_w']) ? (float)$_POST['crop_w'] : 1;
    $ch = isset($_POST['crop_h']) ? (float)$_POST['crop_h'] : 1;

    // 钳制到合法范围
    $cx = max(0, min(1, $cx));
    $cy = max(0, min(1, $cy));
    $cw = max(0.01, min(1 - $cx, $cw));
    $ch = max(0.01, min(1 - $cy, $ch));

    $crop_x = (int)round($cx * $src_w);
    $crop_y = (int)round($cy * $src_h);
    $crop_w = (int)round($cw * $src_w);
    $crop_h = (int)round($ch * $src_h);

    // 输出尺寸（保持裁剪比例，不超过 max_w/max_h）
    $max_w = max(100, min(4000, (int)($_POST['max_w'] ?? 1600)));
    $max_h = max(100, min(4000, (int)($_POST['max_h'] ?? 1200)));

    $scale = min($max_w / $crop_w, $max_h / $crop_h, 1); // 不放大
    $out_w = (int)round($crop_w * $scale);
    $out_h = (int)round($crop_h * $scale);

    $out_img = imagecreatetruecolor($out_w, $out_h);

    // 保留 PNG/WEBP 透明通道
    if (in_array($mime, ['image/png','image/webp'])) {
        imagealphablending($out_img, false);
        imagesavealpha($out_img, true);
        $transparent = imagecolorallocatealpha($out_img, 0, 0, 0, 127);
        imagefilledrectangle($out_img, 0, 0, $out_w, $out_h, $transparent);
        imagealphablending($out_img, true);
    }

    imagecopyresampled($out_img, $src_img, 0, 0, $crop_x, $crop_y, $out_w, $out_h, $crop_w, $crop_h);

    switch ($mime) {
        case 'image/jpeg': $ok = imagejpeg($out_img, $dest, 88); break;
        case 'image/png':  $ok = imagepng($out_img, $dest, 7);   break;
        case 'image/webp': $ok = imagewebp($out_img, $dest, 88); break;
        default:           $ok = false;
    }

    imagedestroy($src_img);
    imagedestroy($out_img);

    if (!$ok) err('图片保存失败');
}

// 返回可访问的 URL（绝对根路径，确保前端 <img> 能正确加载）
// 动态计算项目根目录相对于服务器 document root 的路径
$doc_root   = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$project_root = rtrim(realpath(__DIR__ . '/..'), '/\\');
// 如果项目在 document root 下，则构造 /子路径/uploads/picture/...
// 否则直接用 /uploads/picture/...
if (strpos($project_root, $doc_root) === 0) {
    $base_url = str_replace('\\', '/', substr($project_root, strlen($doc_root)));
} else {
    $base_url = '';
}
$url = $base_url . '/uploads/picture/' . $fname;
if (ob_get_level()) ob_end_clean();
// GIF 分支没有经过 GD，用 getimagesize 补充尺寸
if (!isset($out_w, $out_h)) {
    $sz    = getimagesize($dest);
    $out_w = $sz ? $sz[0] : 0;
    $out_h = $sz ? $sz[1] : 0;
}
echo json_encode(['url' => $url, 'width' => $out_w, 'height' => $out_h, 'filename' => $fname], JSON_UNESCAPED_UNICODE);
