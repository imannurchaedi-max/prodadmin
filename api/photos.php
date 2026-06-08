<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok(mixed $d): void { echo json_encode(['success' => true, 'data' => $d]); exit; }
function fail(string $m, int $c = 200): void { http_response_code($c); echo json_encode(['success' => false, 'message' => $m]); exit; }

function body(): array {
    static $b = null;
    if ($b === null) { $raw = file_get_contents('php://input'); $b = json_decode($raw ?: '{}', true) ?? []; }
    return $b;
}

// H1: requireAuth() removed — use requireSession() from auth_helper.php

const MAX_UPLOAD_BYTES    = 5_242_880;  // H5: 5 MB per file
const MAX_FILES_PER_REQ   = 10;          // H5: max 10 files per request

// Serve foto (GET ?file=YYYY-MM/filename.jpg)
// H4: token required even for GET — read from ?token= query param or Authorization header
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db = getDb();
    requireSession($db);   // H4: auth gate — stops unauthenticated image access

    $file = trim((string)($_GET['file'] ?? ''));
    $file = ltrim(str_replace(['..', '\\'], '', $file), '/');
    if (!$file) fail('file param diperlukan.');

    $base = dirname(__DIR__) . '/uploads/labels/';
    $path = realpath($base . $file);

    if (!$path || !str_starts_with($path, realpath($base))) {
        http_response_code(404); exit;
    }

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: max-age=86400');
    readfile($path);
    exit;
}

// Upload foto (POST)
$db      = getDb();
$session = requireSession($db);   // H1: use shared requireSession (APCu-cached)
$b       = body();
$files   = $b['files'] ?? [];

if (!is_array($files) || empty($files)) fail('files wajib diisi (array).');
// H5: limit number of files per request
if (count($files) > MAX_FILES_PER_REQ) fail('Maksimal ' . MAX_FILES_PER_REQ . ' file per permintaan.');

$subdir = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m');
$dir    = dirname(__DIR__) . '/uploads/labels/' . $subdir;
if (!is_dir($dir)) mkdir($dir, 0755, true);

$fileIds = [];
foreach ($files as $f) {
    $b64  = (string)($f['base64'] ?? '');
    $name = (string)($f['name']   ?? 'photo.jpg');

    if (!$b64 || !str_contains($b64, 'base64,')) continue;

    $mime  = substr($b64, 5, strpos($b64, ';') - 5);
    $data  = base64_decode(substr($b64, strpos($b64, 'base64,') + 7));
    if (!$data) continue;

    // H5: reject oversized files before writing to disk
    if (strlen($data) > MAX_UPLOAD_BYTES) {
        fail('File terlalu besar. Maksimal ' . (MAX_UPLOAD_BYTES / 1048576) . ' MB per file.');
    }

    $ext   = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif',
        default      => 'jpg'
    };

    // H2: use cryptographically secure random bytes for filename (replaces mt_rand)
    $uid   = bin2hex(random_bytes(8));
    $fname = $uid . '.' . $ext;
    $fpath = $dir . '/' . $fname;

    file_put_contents($fpath, $data);
    $fileIds[] = $subdir . '/' . $fname; // relative path sebagai ID
}

if (empty($fileIds)) fail('Tidak ada file valid yang diupload.');
ok(['fileIds' => $fileIds]);
