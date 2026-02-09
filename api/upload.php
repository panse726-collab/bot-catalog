<?php
require_once __DIR__ . '/../bot.config.php';

header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Only POST', 405);

$key = $_POST['upload_key'] ?? '';
if (!$key || !hash_equals(UPLOAD_KEY, $key)) fail('Forbidden', 403);

$category = $_POST['category'] ?? '';
$sub      = $_POST['sub'] ?? '';

if (!in_array($category, ['jewelry','bags','watches','accessories'], true)) fail('Bad category');

$mapKey = $category;
if ($category === 'jewelry') {
  if (!$sub) $sub = 'rings';
  $allowed = ['rings','bracelets','pendants','necklaces','earrings'];
  if (!in_array($sub, $allowed, true)) fail('Bad jewelry sub');
  $mapKey = "jewelry:{$sub}";
}

$targetDir = UPLOAD_DIRS[$mapKey] ?? null;
if (!$targetDir) fail('No upload dir configured');

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) fail('Cannot create upload dir', 500);

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('No file');

$file = $_FILES['file'];

if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_BYTES) fail('File too large');

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$extMap = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];
if (!isset($extMap[$mime])) fail('Unsupported file type');

$ext = $extMap[$mime];

$stamp = date('Ymd_His');
$rand  = bin2hex(random_bytes(6));
$filename = "{$stamp}_{$rand}.{$ext}";
$dest = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) fail('Failed to save file', 500);

// ---- public url from DOCUMENT_ROOT ----
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$realDest = realpath($dest);

if (!$docRoot || !$realDest) fail('Path error', 500);
if (strpos($realDest, $docRoot) !== 0) fail('Bad path', 500);

$publicPath = str_replace('\\', '/', substr($realDest, strlen($docRoot))); // "/images/..."
$publicUrl  = rtrim(BASE_URL, '/') . $publicPath;

echo json_encode(['ok'=>true,'url'=>$publicUrl], JSON_UNESCAPED_UNICODE);
