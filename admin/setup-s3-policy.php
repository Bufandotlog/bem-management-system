<?php
/**
 * setup-s3-policy.php - Script untuk mengatur Bucket Policy (Public Read)
 * Jalankan via CLI.
 */
require_once __DIR__ . '/../includes/functions.php';

if (php_sapi_name() !== 'cli') {
    die("Harus dari CLI");
}

try {
    $s3 = getS3Client();
    $bucket = $_ENV['S3_BUCKET'] ?? '';

    $policy = json_encode([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Sid' => 'PublicReadGetObject',
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => "arn:aws:s3:::{$bucket}/*"
            ]
        ]
    ]);

    $s3->putBucketPolicy([
        'Bucket' => $bucket,
        'Policy' => $policy,
    ]);

    echo "✅ Bucket Policy berhasil diset menjadi PUBLIC READ untuk: {$bucket}\n";

} catch (Exception $e) {
    echo "❌ Gagal: " . $e->getMessage() . "\n";
}
