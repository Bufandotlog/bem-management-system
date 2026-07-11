<?php
/**
 * setup-s3-cors.php - Script sekali pakai untuk mengatur CORS di bucket S3
 * Jalankan sekali, lalu HAPUS file ini.
 * 
 * Akses: https://bembudiutomo.my.id/admin/setup-s3-cors.php
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');

// Hanya boleh dijalankan via CLI
if (php_sapi_name() !== 'cli') {
    echo "ERROR: Script ini hanya boleh dijalankan via CLI.\n";
    exit;
}

if (($_ENV['STORAGE_METHOD'] ?? 'local') !== 's3') {
    echo "ERROR: STORAGE_METHOD bukan 's3'. Periksa .env\n";
    exit;
}

try {
    $s3 = getS3Client();
    $bucket = $_ENV['S3_BUCKET'] ?? 'bucket-bem';

    // Set CORS configuration
    $s3->putBucketCors([
        'Bucket' => $bucket,
        'CORSConfiguration' => [
            'CORSRules' => [
                [
                    'AllowedHeaders' => ['*'],
                    'AllowedMethods' => ['GET', 'PUT', 'POST', 'HEAD'],
                    'AllowedOrigins' => [
                        'https://bembudiutomo.my.id',
                        'https://www.bembudiutomo.my.id',
                        'http://localhost:*',
                    ],
                    'ExposeHeaders'  => ['ETag', 'x-amz-request-id'],
                    'MaxAgeSeconds'  => 3600,
                ],
            ],
        ],
    ]);

    echo "✅ CORS berhasil dikonfigurasi untuk bucket: {$bucket}\n\n";

    // Verifikasi
    $cors = $s3->getBucketCors(['Bucket' => $bucket]);
    echo "Verifikasi CORS Rules:\n";
    echo "======================\n";
    foreach ($cors['CORSRules'] as $i => $rule) {
        echo "Rule #{$i}:\n";
        echo "  AllowedOrigins: " . implode(', ', $rule['AllowedOrigins']) . "\n";
        echo "  AllowedMethods: " . implode(', ', $rule['AllowedMethods']) . "\n";
        echo "  AllowedHeaders: " . implode(', ', $rule['AllowedHeaders'] ?? []) . "\n";
        echo "  MaxAgeSeconds:  " . ($rule['MaxAgeSeconds'] ?? 'N/A') . "\n";
    }

    echo "\n⚠️  PENTING: Hapus file ini setelah selesai!\n";
    echo "   rm admin/setup-s3-cors.php\n";

} catch (Exception $e) {
    echo "❌ GAGAL mengatur CORS: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
