<?php
/**
 * Image upload endpoint for the article editor.
 *
 * Accepts POST with multipart file field "file" (or "image").
 * Returns {ok, url} on success or {ok:false, error}.
 *
 * Security: editor role + CSRF + type/size limits + random filenames.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('editor');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

$file = $_FILES['file'] ?? $_FILES['image'] ?? null;
if (!$file || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'لم يتم إرسال ملف']);
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'خطأ في الرفع: ' . $file['error']]);
    exit;
}

$maxSize = 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'error' => 'حجم الملف كبير جداً (الحد 10 ميجا)']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    echo json_encode(['ok' => false, 'error' => 'نوع ملف غير مدعوم (JPG/PNG/WebP/GIF فقط)']);
    exit;
}

$imgInfo = @getimagesize($file['tmp_name']);
if (!$imgInfo || $imgInfo[0] < 10 || $imgInfo[1] < 10) {
    echo json_encode(['ok' => false, 'error' => 'الملف ليس صورة صالحة']);
    exit;
}

$uploadsBase = __DIR__ . '/../../uploads';
$datePath = date('Y/m');
$targetDir = $uploadsBase . '/' . $datePath;

if (!is_dir($targetDir)) {
    if (!@mkdir($targetDir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'تعذّر إنشاء مجلد الرفع']);
        exit;
    }
}

$ext = $allowed[$mime];
$basename = bin2hex(random_bytes(8)) . '-' . time() . '.' . $ext;
$target = $targetDir . '/' . $basename;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    echo json_encode(['ok' => false, 'error' => 'فشل حفظ الملف']);
    exit;
}

@chmod($target, 0644);

$publicUrl = '/uploads/' . $datePath . '/' . $basename;

echo json_encode([
    'ok'       => true,
    'url'      => $publicUrl,
    'width'    => $imgInfo[0],
    'height'   => $imgInfo[1],
    'size'     => $file['size'],
    'filename' => $basename
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
