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

$files = [];
if (!empty($_POST['files']) && is_array($_POST['files'])) $files = $_POST['files'];
elseif (!empty($_POST['file'])) $files = [$_POST['file']];
else fail('No files provided');

$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
if (!$docRoot) fail('No DOCUMENT_ROOT', 500);

$allowedBase = realpath($docRoot . '/images/catalogo');
if (!$allowedBase) fail('Allowed base not found', 500);

$deleted = [];
$notFound = [];
$rejected = [];

foreach ($files as $f) {
  $f = trim((string)$f);
  if ($f === '') continue;

  $path = $f;

  // If URL → take path
  if (preg_match('~^https?://~i', $f)) {
    $u = parse_url($f);
    $path = $u['path'] ?? '';
  }

  $path = urldecode($path);
  if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');

  // Only /images/catalogo/
  if (strpos($path, '/images/catalogo/') !== 0) { $rejected[] = $f; continue; }
  if (strpos($path, '..') !== false) { $rejected[] = $f; continue; }

  $abs = realpath($docRoot . $path);
  if (!$abs) { $notFound[] = $f; continue; }

  if (strpos($abs, $allowedBase) !== 0) { $rejected[] = $f; continue; }
  if (!is_file($abs)) { $notFound[] = $f; continue; }

  if (@unlink($abs)) $deleted[] = $f;
  else $rejected[] = $f;
}

echo json_encode([
  'ok'=>true,
  'deleted'=>$deleted,
  'not_found'=>$notFound,
  'rejected'=>$rejected
], JSON_UNESCAPED_UNICODE);
