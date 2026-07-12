<?php
/**
 * setup-s3-private.php - Hapus Public Bucket Policy → kembalikan ke PRIVATE
 * Setelah dijalankan, file hanya bisa diakses via Presigned URL.
 * Jalankan via CLI: docker exec bem_app php admin/setup-s3-private.php
 */
require_once __DIR__ . '/../includes/functions.php';

if (php_sapi_name() !== 'cli') {
    die("Harus dari CLI");
}

try {
    $s3 = getS3Client();
    $bucket = $_ENV['S3_BUCKET'] ?? '';

    // Hapus bucket policy (mengembalikan ke private default)
    $s3->deleteBucketPolicy([
        'Bucket' => $bucket,
    ]);

    echo "✅ Bucket Policy berhasil DIHAPUS untuk: {$bucket}\n";
    echo "   Bucket sekarang PRIVATE — file hanya bisa diakses via Presigned URL.\n";

    // Verifikasi
    try {
        $result = $s3->getBucketPolicy(['Bucket' => $bucket]);
        echo "\n⚠️  Masih ada policy tersisa:\n" . $result['Policy'] . "\n";
    } catch (Exception $e) {
        echo "\n✅ Verifikasi: Tidak ada bucket policy (PRIVATE).\n";
    }

} catch (Exception $e) {
    echo "❌ Gagal: " . $e->getMessage() . "\n";
}
