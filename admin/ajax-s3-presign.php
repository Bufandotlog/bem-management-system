<?php
// admin/ajax-s3-presign.php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Validasi session
if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (($_ENV['STORAGE_METHOD'] ?? 'local') !== 's3') {
    http_response_code(400);
    echo json_encode(['error' => 'S3 storage is not enabled']);
    exit;
}

// Rate limiting: maks 30 presign per 5 menit per user
$rateLimitKey = 's3_presign_count';
$rateLimitWindow = 300; // 5 menit
$rateLimitMax = 30;

if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'start' => time()];
}

$rl = &$_SESSION[$rateLimitKey];
if (time() - $rl['start'] > $rateLimitWindow) {
    $rl = ['count' => 0, 'start' => time()]; // Reset window
}

$rl['count']++;
if ($rl['count'] > $rateLimitMax) {
    http_response_code(429);
    echo json_encode(['error' => 'Terlalu banyak permintaan upload. Coba lagi dalam beberapa menit.']);
    exit;
}

$contentType = $_GET['type'] ?? 'image/webp';
$folder = $_GET['folder'] ?? 'lpj';

// Validasi folder (whitelist ketat)
$allowedFolders = ['lpj', 'umum', 'ttd'];
if (!in_array($folder, $allowedFolders, true)) {
    $folder = 'umum';
}

// Validasi content-type (whitelist ketat — hanya gambar dan PDF)
$allowedTypes = [
    'image/webp'  => 'webp',
    'image/jpeg'  => 'jpg',
    'image/png'   => 'png',
    'image/gif'   => 'gif',
    'application/pdf' => 'pdf',
];

if (!isset($allowedTypes[$contentType])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipe file tidak diizinkan: ' . $contentType]);
    exit;
}

$ext = $allowedTypes[$contentType];

try {
    $s3Client = getS3Client();
    $bucket = $_ENV['S3_BUCKET'];
    
    // Generate unique filename (hash random, tidak bisa ditebak)
    $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $key = $folder . '/' . $newFilename;
    
    $cmd = $s3Client->getCommand('PutObject', [
        'Bucket'        => $bucket,
        'Key'           => $key,
        'ContentType'   => $contentType,
        'ContentLength' => 10 * 1024 * 1024, // Maks 10MB per file
    ]);

    $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
    $presignedUrl = (string)$request->getUri();
    
    echo json_encode([
        'presignedUrl' => $presignedUrl,
        'key' => $key
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal membuat signature: ' . $e->getMessage()]);
}

