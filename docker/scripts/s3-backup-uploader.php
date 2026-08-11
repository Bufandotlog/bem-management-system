<?php
// docker/scripts/s3-backup-uploader.php
// Helper CLI untuk mengunggah berkas backup terenkripsi ke S3 Storage

require_once __DIR__ . '/../../includes/functions.php';

if ($argc < 3) {
    echo "Penggunaan: php s3-backup-uploader.php <file_lokal> <key_s3>\n";
    exit(1);
}

$localFile = $argv[1];
$s3Key     = $argv[2];

if (!file_exists($localFile)) {
    echo "❌ Error: File '$localFile' tidak ditemukan.\n";
    exit(1);
}

$storageMethod = $_ENV['STORAGE_METHOD'] ?? 'local';
$s3Bucket      = $_ENV['S3_BUCKET'] ?? '';

if ($storageMethod !== 's3' || empty($s3Bucket)) {
    echo "ℹ️  Info: STORAGE_METHOD bukan 's3' atau S3_BUCKET belum dikonfigurasi. Pengunggahan ke S3 dilewati.\n";
    exit(0);
}

echo "Mengunggah '$localFile' ke S3 Storage [Bucket: $s3Bucket, Key: $s3Key]...\n";
$success = uploadToS3($localFile, $s3Key, 'application/octet-stream');

if ($success) {
    echo "✅ S3 Offsite Backup berhasil diunggah: $s3Key\n";
    exit(0);
} else {
    echo "❌ S3 Offsite Backup GAGAL: " . ($_SESSION['error'] ?? 'Unknown error') . "\n";
    exit(1);
}
