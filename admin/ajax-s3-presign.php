<?php
// admin/ajax-s3-presign.php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Validasi session
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (($_ENV['STORAGE_METHOD'] ?? 'local') !== 's3') {
    http_response_code(400);
    echo json_encode(['error' => 'S3 storage is not enabled']);
    exit;
}

$contentType = $_GET['type'] ?? 'image/webp';
$folder = $_GET['folder'] ?? 'lpj';

// Validasi folder
if (!in_array($folder, ['lpj', 'umum', 'ttd'])) {
    $folder = 'umum';
}

$ext = 'webp';
if ($contentType === 'application/pdf') {
    $ext = 'pdf';
}

try {
    $s3Client = getS3Client();
    $bucket = $_ENV['S3_BUCKET'];
    
    // Generate unique filename
    $newFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $key = $folder . '/' . $newFilename;
    
    $cmd = $s3Client->getCommand('PutObject', [
        'Bucket' => $bucket,
        'Key'    => $key,
        'ContentType' => $contentType,
    ]);

    $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
    $presignedUrl = (string)$request->getUri();
    
    echo json_encode([
        'presignedUrl' => $presignedUrl,
        'key' => $key
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
