<?php
// includes/functions.php
// VERSI: 4.3 - Tambah recordUserSession + TOTP replay prevention helpers
//   ADDED: recordUserSession() — catat sesi login ke tabel user_sessions
//   ADDED: updateUserTotpCounter() — update totp_last_counter di tabel users
//   ADDED: totpVerifyWithReplay() — wrapper verifikasi TOTP dengan replay protection
//   UNCHANGED: semua

// Fungsi-fungsi pembantu lainnya

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/path-detection.php';

// Load composer autoloader if available
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * Mendapatkan instance S3 Client secara aman
 */
function getS3Client() {
    static $s3 = null;
    if ($s3 === null) {
        if (!class_exists('Aws\S3\S3Client')) {
            throw new RuntimeException("Library AWS SDK PHP (S3Client) belum terinstal. Silakan jalankan 'composer require aws/aws-sdk-php'.");
        }
        $endpoint = $_ENV['S3_ENDPOINT'] ?? '';
        $region = $_ENV['S3_REGION'] ?? 'auto';
        $key = $_ENV['S3_ACCESS_KEY_ID'] ?? '';
        $secret = $_ENV['S3_SECRET_ACCESS_KEY'] ?? '';

        $config = [
            'version'     => 'latest',
            'region'      => $region,
            'endpoint'    => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ];
        $s3 = new Aws\S3\S3Client($config);
    }
    return $s3;
}

/**
 * Mengunggah file lokal ke S3
 */
function uploadToS3($localFile, $s3Key, $mimeType) {
    try {
        $s3 = getS3Client();
        $bucket = $_ENV['S3_BUCKET'] ?? '';
        
        $s3->putObject([
            'Bucket'      => $bucket,
            'Key'         => $s3Key,
            'SourceFile'  => $localFile,
            'ContentType' => $mimeType,
        ]);
        return true;
    } catch (Exception $e) {
        error_log("uploadToS3 Error: " . $e->getMessage());
        $_SESSION['error'] = "Gagal mengunggah ke Object Storage: " . $e->getMessage();
        return false;
    }
}

/**
 * Memastikan folder upload yang dibutuhkan tersedia.
 * Berguna saat baru deploy ke server baru.
 */
function ensureUploadFolders() {
    if (!defined('UPLOAD_PATH')) return;
    try {
        $folders = [
            UPLOAD_PATH,
            UPLOAD_PATH . '/ttd',
            UPLOAD_PATH . '/umum',
            UPLOAD_PATH . '/umum/lampiran'
        ];
        foreach ($folders as $folder) {
            if (!is_dir($folder)) {
                @mkdir($folder, 0777, true);
                if (!file_exists($folder . '/index.php')) {
                    @file_put_contents($folder . '/index.php', '<?php // Silence is golden');
                }
            }
        }
    } catch (Exception $e) {}
}
// Jalankan otomatis SETELAH UPLOAD_PATH didefinisikan
ensureUploadFolders();

// Auto-migration: Pastikan kolom footnote ada di tabel berita
try {
    dbQuery("SELECT footnote FROM berita LIMIT 1");
} catch (Exception $e) {
    try {
        dbQuery("ALTER TABLE berita ADD COLUMN footnote TEXT DEFAULT NULL");
    } catch (Exception $ex) {
        // Abaikan jika database belum siap
    }
}

// Auto-migration: Pastikan kolom fungsi ada di tabel kementerian
try {
    dbQuery("SELECT fungsi FROM kementerian LIMIT 1");
} catch (Exception $e) {
    try {
        dbQuery("ALTER TABLE kementerian ADD COLUMN fungsi TEXT DEFAULT NULL");
    } catch (Exception $ex) {
        // Abaikan jika database belum siap
    }
}

// Auto-migration: Pastikan tabel login_attempts_ip ada
try {
    dbQuery("SELECT 1 FROM login_attempts_ip LIMIT 1");
} catch (Exception $e) {
    try {
        dbQuery("CREATE TABLE IF NOT EXISTS login_attempts_ip (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            username VARCHAR(100) DEFAULT NULL,
            attempt_type ENUM('login_failed','turnstile_failed','lockout') NOT NULL DEFAULT 'login_failed',
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP(),
            PRIMARY KEY (id),
            KEY idx_ip_address (ip_address),
            KEY idx_created_at (created_at),
            KEY idx_ip_created (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $ex) {
        // Abaikan jika database belum siap
    }
}

// Auto-migration: Pastikan tabel arsip_berita_acara ada
try {
    dbQuery("SELECT 1 FROM arsip_berita_acara LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('CREATE TABLE "arsip_berita_acara" (
              "id" SERIAL PRIMARY KEY,
              "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
              "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
              "nomor_berita" VARCHAR(255) NOT NULL,
              "tanggal_kegiatan" VARCHAR(100),
              "nama_kegiatan" VARCHAR(255) NOT NULL,
              "tempat" VARCHAR(255),
              "waktu" VARCHAR(100),
              "konten_json" TEXT,
              "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } else {
            dbQuery("CREATE TABLE IF NOT EXISTS `arsip_berita_acara` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `periode_id` int(11) DEFAULT NULL,
              `created_by` int(11) DEFAULT NULL,
              `nomor_berita` varchar(255) NOT NULL,
              `tanggal_kegiatan` varchar(100) DEFAULT NULL,
              `nama_kegiatan` varchar(255) NOT NULL,
              `tempat` varchar(255) DEFAULT NULL,
              `waktu` varchar(100) DEFAULT NULL,
              `konten_json` mediumtext DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `fk_berita_acara_periode` (`periode_id`),
              KEY `fk_berita_acara_user` (`created_by`),
              CONSTRAINT `fk_berita_acara_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_berita_acara_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $ex) {
        // Abaikan jika database belum siap
    }
}

// ============================================
// FUNGSI IP-BASED LOGIN TRACKING
// ============================================

/**
 * Ambil IP address klien (mendukung proxy/Cloudflare).
 */
function getClientIp(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    return mb_substr(trim(explode(',', $ip)[0]), 0, 45);
}

/**
 * Catat percobaan login gagal berdasarkan IP ke database.
 *
 * @param string $type   'login_failed', 'turnstile_failed', atau 'lockout'
 * @param string|null $username Username yang dicoba (jika ada)
 */
function recordFailedAttempt(string $type = 'login_failed', ?string $username = null): void {
    try {
        $ip = getClientIp();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        dbQuery(
            "INSERT INTO login_attempts_ip (ip_address, username, attempt_type, user_agent) VALUES (?, ?, ?, ?)",
            [$ip, $username, $type, $ua], "ssss"
        );

        // Cleanup: hapus record > 24 jam (1% chance per request)
        if (rand(1, 100) === 1) {
            dbQuery("DELETE FROM login_attempts_ip WHERE created_at < NOW() - INTERVAL 24 HOUR");
        }
    } catch (Exception $e) {
        error_log("recordFailedAttempt: " . $e->getMessage());
    }
}

/**
 * Hitung jumlah percobaan login gagal dari IP tertentu dalam rentang waktu.
 *
 * @param int $windowMinutes Rentang waktu (menit)
 * @return int Jumlah percobaan gagal
 */
function countIpFailedAttempts(int $windowMinutes = 30): int {
    try {
        $ip = getClientIp();
        $row = dbFetchOne(
            "SELECT COUNT(*) AS cnt FROM login_attempts_ip
             WHERE ip_address = ? AND created_at > NOW() - INTERVAL ? MINUTE",
            [$ip, $windowMinutes], "si"
        );
        return (int)($row['cnt'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}


// ============================================
// FUNGSI HELPER PATH & URL (unchanged)
// ============================================

function uploadUrl($filename) {
    if (empty($filename)) return '';
    if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
        return $filename;
    }
    $filename = ltrim(str_replace('uploads/', '', ltrim($filename, '/')), '/');
    
    if (($_ENV['STORAGE_METHOD'] ?? 'local') === 's3') {
        $publicUrl = $_ENV['S3_PUBLIC_URL'] ?? '';
        return rtrim($publicUrl, '/') . '/' . $filename;
    }
    return rtrim(BASE_URL, '/') . '/uploads/' . $filename;
}

function uploadPath($filename) {
    if (empty($filename)) return '';
    $filename = ltrim(str_replace('uploads/', '', ltrim($filename, '/')), '/\\');
    return rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $filename;
}

function assetUrl($path) {
    return rtrim(ASSETS_URL, '/') . '/' . ltrim($path, '/');
}

function redirect($url, $message = null, $type = 'success') {
    if ($message) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    $url = ltrim($url, '/');
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        $requestedHost = parse_url($url, PHP_URL_HOST);
        $ownHost       = parse_url(BASE_URL, PHP_URL_HOST);
        if ($requestedHost !== $ownHost) {
            error_log("redirect(): Open redirect dicegah ke [{$url}]");
            $url = BASE_URL;
        }
        header("Location: {$url}");
    } else {
        header("Location: " . BASE_URL . $url);
    }
    exit;
}

function baseUrl($path = '') {
    return BASE_URL . ltrim($path, '/');
}

function imgTag($filename, $alt = '', $class = '', $fallback = 'assets/images/no-image.jpg') {
    $rawSrc    = !empty($filename) ? uploadUrl($filename) : assetUrl($fallback);
    $src       = htmlspecialchars($rawSrc, ENT_QUOTES, 'UTF-8');
    $classAttr = $class ? " class='" . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . "'" : '';
    $altAttr   = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
    return "<img src='{$src}' alt='{$altAttr}'{$classAttr}>";
}

// ============================================
// FUNGSI CSRF (unchanged)
// ============================================

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8')
         . '">';
}

function csrfVerify(): bool {
    $submitted = $_POST['csrf_token'] ?? '';
    $stored    = $_SESSION['csrf_token'] ?? '';

    if (empty($stored) || empty($submitted)) {
        return false;
    }

    return hash_equals($stored, $submitted);
}

// ============================================
// FUNGSI SANITASI INPUT (unchanged)
// ============================================

function sanitizeText(string $input, int $maxLen = 255): string {
    $input = strip_tags($input);
    $input = trim($input);
    $input = preg_replace('/\s+/', ' ', $input);
    return mb_substr($input, 0, $maxLen);
}

function sanitizeHtml(string $html): string {
    if (empty($html)) return '';

    $html = preg_replace('~<script[^>]*>.*?</script>~is', '', $html);
    $html = preg_replace('~<style[^>]*>.*?</style>~is',   '', $html);
    $html = preg_replace('~<iframe[^>]*>.*?</iframe>~is', '', $html);
    $html = preg_replace('~<(object|embed|applet|form|input|button|select|textarea)[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace('~<(object|embed|applet|form|input|button)[^>]*/?>~i', '', $html);

    $html = preg_replace('/\bon\w+\s*=\s*(["\']).*?\1/i',  '', $html);
    $html = preg_replace('/\bon\w+\s*=[^\s>]*/i',           '', $html);

    $html = preg_replace('/\b(href|src|action)\s*=\s*(["\'])\s*(javascript|vbscript):/i', '$1=$2#', $html);
    $html = preg_replace('/\b(href|src)\s*=\s*(["\'])\s*data:/i', '$1=$2#', $html);

    return trim($html);
}

function sanitizeInt($input, int $min = 0, int $max = PHP_INT_MAX): ?int {
    $val = filter_var($input, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    return $val !== false ? (int) $val : null;
}

function sanitizeUrl(string $url): string {
    $url = trim($url);
    if (empty($url)) return '';
    if (!preg_match('~^https?://~i', $url)) return '';
    $filtered = filter_var($url, FILTER_SANITIZE_URL);
    return $filtered ?: '';
}

// ============================================
// FUNGSI UPLOAD & HAPUS FILE (unchanged)
// ============================================

function uploadFile($file, $folder = 'umum') {
    if (!isset($file) || !is_array($file)) {
        error_log("uploadFile: Input tidak valid");
        $_SESSION['error'] = 'Tidak ada file yang diupload';
        return false;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE   => 'File melebihi ukuran maksimum yang diizinkan server',
            UPLOAD_ERR_FORM_SIZE  => 'File melebihi ukuran maksimum yang diizinkan form',
            UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diupload',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
            UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP',
        ];
        $msg = $errorMessages[$file['error']] ?? 'Upload error tidak dikenal';
        error_log("uploadFile: {$msg}");
        $_SESSION['error'] = $msg;
        return false;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        $maxMB = round(MAX_FILE_SIZE / 1024 / 1024, 2);
        $_SESSION['error'] = "Ukuran file terlalu besar. Maksimal {$maxMB}MB.";
        error_log("uploadFile: File terlalu besar - {$file['size']} bytes");
        return false;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        $allowed = implode(', ', ALLOWED_EXTENSIONS);
        $_SESSION['error'] = "Ekstensi tidak diizinkan. Gunakan: {$allowed}";
        error_log("uploadFile: Ekstensi tidak valid - {$ext}");
        return false;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);

        if (!in_array($mime, ALLOWED_MIME_TYPES)) {
            $_SESSION['error'] = 'Tipe file tidak valid (MIME mismatch)';
            error_log("uploadFile: MIME tidak valid - {$mime}");
            return false;
        }
    }

    // Hanya cek dimensi gambar jika file tersebut adalah gambar
    if ($ext !== 'pdf') {
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            $_SESSION['error'] = 'File bukan gambar yang valid.';
            error_log("uploadFile: getimagesize gagal - bukan gambar valid");
            return false;
        }

        if ($imageInfo[0] > 8000 || $imageInfo[1] > 8000) {
            $_SESSION['error'] = 'Dimensi gambar terlalu besar. Maksimal 8000x8000 pixel.';
            error_log("uploadFile: Dimensi gambar terlalu besar - {$imageInfo[0]}x{$imageInfo[1]}");
            return false;
        }
    }

    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    $newFilename = bin2hex(random_bytes(16)) . '.' . ($is_image ? 'webp' : $ext);
    $uploadDir   = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        $_SESSION['error'] = 'Gagal membuat folder upload';
        error_log("uploadFile: Gagal buat direktori - {$uploadDir}");
        return false;
    }

    if (!is_writable($uploadDir)) {
        $_SESSION['error'] = 'Folder upload tidak bisa ditulis';
        error_log("uploadFile: Direktori tidak writable - {$uploadDir}");
        return false;
    }

    $destination = $uploadDir . $newFilename;
    $relativePath = $folder . '/' . $newFilename;
    $success = false;

    if ($is_image && function_exists('imagewebp')) {
        $img = null;
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $img = @imagecreatefromjpeg($file['tmp_name']);
        } elseif ($ext === 'png') {
            $img = @imagecreatefrompng($file['tmp_name']);
        } elseif ($ext === 'gif') {
            $img = @imagecreatefromgif($file['tmp_name']);
        } elseif ($ext === 'webp') {
            $img = @imagecreatefromwebp($file['tmp_name']);
        }

        if ($img) {
            $width = imagesx($img);
            $height = imagesy($img);
            $max_dim = 2000;
            if ($width > $max_dim || $height > $max_dim) {
                if ($width > $height) {
                    $new_width = $max_dim;
                    $new_height = floor($height * ($max_dim / $width));
                } else {
                    $new_height = $max_dim;
                    $new_width = floor($width * ($max_dim / $height));
                }
                
                $resized = imagecreatetruecolor($new_width, $new_height);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                imagedestroy($img);
                $img = $resized;
            } else {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            if (@imagewebp($img, $destination, 75)) {
                imagedestroy($img);
                chmod($destination, 0644);
                $success = true;
                error_log("uploadFile: SUKSES (Image converted to WebP) - {$destination} | path: {$relativePath}");
            } else {
                if ($img) imagedestroy($img);
            }
        }
    }

    if (!$success) {
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $_SESSION['error'] = 'Gagal menyimpan file';
            error_log("uploadFile: Gagal memindahkan file ke {$destination}");
            return false;
        }
        chmod($destination, 0644);
        $success = true;
        error_log("uploadFile: SUKSES - {$destination} | path: {$relativePath}");
    }

    // Jika menggunakan Object Storage, unggah ke S3 dan hapus lokal
    if (($_ENV['STORAGE_METHOD'] ?? 'local') === 's3') {
        $mimeType = $is_image ? 'image/webp' : ($file['type'] ?? 'application/octet-stream');
        if (uploadToS3($destination, $relativePath, $mimeType)) {
            if (file_exists($destination)) {
                @unlink($destination);
            }
            return $relativePath;
        } else {
            // Jika upload S3 gagal, hapus file lokal dan return false
            if (file_exists($destination)) {
                @unlink($destination);
            }
            return false;
        }
    }

    return $relativePath;
}

function deleteFile($filePath) {
    if (empty($filePath)) return false;

    $filePath = str_replace(['../', '..\\', './', '.\\'], '', $filePath);
    $filePath = ltrim(str_replace('uploads/', '', $filePath), '/\\');

    // Hapus dari Object Storage jika aktif
    if (($_ENV['STORAGE_METHOD'] ?? 'local') === 's3') {
        try {
            $s3 = getS3Client();
            $bucket = $_ENV['S3_BUCKET'] ?? '';
            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $filePath,
            ]);
            error_log("deleteFile (S3): Berhasil dihapus - {$filePath}");
            return true;
        } catch (Exception $e) {
            error_log("deleteFile (S3) Error: " . $e->getMessage());
            return false;
        }
    }

    $fullPath   = rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $filePath;
    $realPath   = realpath($fullPath);
    $uploadBase = realpath(UPLOAD_PATH);

    if ($realPath === false || $uploadBase === false || strpos($realPath, $uploadBase) !== 0) {
        error_log("deleteFile: Potensi path traversal atau file tidak ada - {$filePath}");
        return false;
    }

    if (!is_file($realPath)) {
        error_log("deleteFile: File tidak ditemukan - {$realPath}");
        return false;
    }

    $result = unlink($realPath);
    error_log($result
        ? "deleteFile: Berhasil dihapus - {$realPath}"
        : "deleteFile: Gagal menghapus - {$realPath}"
    );
    return $result;
}

// ============================================
// FUNGSI FORMAT DATA (unchanged)
// ============================================

function createSlug($text, int $maxLen = 200): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = strtolower(trim($text, '-'));
    $text = preg_replace('~-+~', '-', $text);
    $text = rtrim(substr($text, 0, $maxLen), '-');
    return empty($text) ? 'n-a' : $text;
}

function formatTanggal($date, $withTime = false) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    if ($timestamp === false) return $date;

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $hasil = date('j', $timestamp) . ' '
           . $bulan[(int)date('n', $timestamp)] . ' '
           . date('Y', $timestamp);

    if ($withTime) {
        $hasil .= ' pukul ' . date('H:i', $timestamp) . ' WIB';
    }
    return $hasil;
}

function formatTanggalDb($date) {
    if (empty($date)) return null;
    return date('Y-m-d', strtotime($date));
}

/**
 * Format tanggal ke Bahasa Indonesia: "3 Mei 2026"
 * Bisa dipakai untuk tanggal sekarang (tanpa argumen) atau timestamp tertentu.
 *
 * @param int|null $timestamp  Unix timestamp, null = sekarang
 * @param bool     $withDay    Sertakan nama hari? ("Sabtu, 3 Mei 2026")
 * @return string
 */
function tanggalIndonesia(?int $timestamp = null, bool $withDay = false): string {
    if ($timestamp === null) $timestamp = time();

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    $hasil = date('j', $timestamp) . ' '
           . $bulan[(int)date('n', $timestamp)] . ' '
           . date('Y', $timestamp);

    if ($withDay) {
        $hasil = $hari[(int)date('w', $timestamp)] . ', ' . $hasil;
    }

    return $hasil;
}

/**
 * Konversi nama bulan Inggris → Indonesia dalam sebuah string.
 * Berguna untuk data lama yang tersimpan di DB dengan bulan Inggris.
 * Contoh: "Majalengka, 3 May 2026" → "Majalengka, 3 Mei 2026"
 *
 * @param string $text
 * @return string
 */
function convertBulanKeIndonesia(string $text): string {
    $en = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    $id = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return str_ireplace($en, $id, $text);
}

// ============================================
// FUNGSI NAVIGASI & SESSION
// ============================================

function flashMessage() {
    if (!isset($_SESSION['flash'])) return;

    $flash = $_SESSION['flash'];
    $type  = $flash['type']    ?? 'info';
    $msg   = $flash['message'] ?? '';

    $classMap = ['success'=>'alert-success','error'=>'alert-error','warning'=>'alert-warning','info'=>'alert-info'];
    $iconMap  = ['success'=>'✓','error'=>'✗','warning'=>'⚠','info'=>'ℹ'];

    $class = $classMap[$type] ?? 'alert-info';
    $icon  = $iconMap[$type]  ?? '•';

    echo "<div class='flash-message {$class}'>"
       . "<span class='flash-icon'>{$icon}</span>"
       . "<span class='flash-text'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</span>"
       . "</div>";

    unset($_SESSION['flash']);
}

function isLoggedIn() {
    if (!isset($_SESSION['admin_logged_in'])
        || $_SESSION['admin_logged_in'] !== true
        || empty($_SESSION['admin_id'])) {
        return false;
    }

    if (isset($_SESSION['_last_activity'])) {
        if (time() - $_SESSION['_last_activity'] > 1800) {
            session_unset();
            session_destroy();
            return false;
        }
    }

    $checkInterval = 300;
    $lastCheck     = $_SESSION['_auth_last_check'] ?? 0;

    if (time() - $lastCheck > $checkInterval) {
        $user = dbFetchOne(
            "SELECT id, is_active FROM users WHERE id = ? AND is_active = 1",
            [(int) $_SESSION['admin_id']], "i"
        );

        if (!$user) {
            error_log("isLoggedIn(): User ID {$_SESSION['admin_id']} tidak aktif — session dihancurkan");
            session_unset();
            session_destroy();
            return false;
        }

        $token = $_SESSION['session_token'] ?? '';
        if (!empty($token)) {
            $sesi = dbFetchOne(
                "SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?",
                [$token, (int) $_SESSION['admin_id']], "si"
            );
            if (!$sesi) {
                error_log("isLoggedIn(): Session token dicabut untuk user {$_SESSION['admin_id']} — paksa logout");
                session_unset();
                session_destroy();
                return false;
            }
            dbQuery(
                "UPDATE user_sessions SET last_active = now() WHERE session_token = ? AND user_id = ?",
                [$token, (int) $_SESSION['admin_id']], "si"
            );
        }

        $_SESSION['_auth_last_check'] = time();
    }

    return true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        // Jika tidak login dan tidak punya cookie gate, sembunyikan total (kembalikan 404)
        if (!isset($_COOKIE['admin_access']) || $_COOKIE['admin_access'] !== '1') {
            header("HTTP/1.1 404 Not Found");
            if (file_exists(__DIR__ . '/../404.html')) {
                include __DIR__ . '/../404.html';
            } else {
                echo "<h1>404 Not Found</h1>The requested URL was not found on this server.";
            }
            exit();
        }

        if (!headers_sent()) {
            redirect('astawidya/bem.php', 'Silakan login terlebih dahulu', 'error');
        }
        exit();
    }
}

function isSekretaris() {
    $role = $_SESSION['admin_role'] ?? '';
    return $role === 'sekretaris' || $role === 'admin' || $role === 'superadmin' || !empty($_SESSION['admin_can_access_all']);
}

function requireSekretaris() {
    if (!isSekretaris()) {
        redirect('admin/dashboard.php', 'Akses ditolak: Hanya Sekretaris atau Superadmin yang diizinkan untuk mengelola Modul Surat.', 'error');
    }
}

function logout() {
    $uid   = isset($_SESSION['admin_id'])       ? (int)$_SESSION['admin_id']       : null;
    $uname = isset($_SESSION['admin_username']) ? $_SESSION['admin_username']       : null;
    $token = $_SESSION['session_token']         ?? null;

    if ($uid) {
        if ($token) {
            dbQuery("DELETE FROM user_sessions WHERE session_token = ? AND user_id = ?",
                    [$token, $uid], "si");
        } else {
            dbQuery("DELETE FROM user_sessions WHERE user_id = ?", [$uid], "i");
        }
    }

    if ($uid) {
        auditLog('LOGOUT', 'users', $uid, 'Logout: ' . ($uname ?? ''));
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    redirect('astawidya/bem.php', 'Anda telah logout', 'info');
    exit();
}

// ============================================
// FUNGSI SESSION & TOTP HELPERS — BARU v4.3
// ============================================

/**
 * Catat sesi login ke tabel user_sessions.
 * Dipanggil setelah verifikasi 2FA berhasil.
 *
 * @param int $userId
 */
function recordUserSession(int $userId): void {
    $token = bin2hex(random_bytes(32));

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $deviceInfo = mb_substr($ua, 0, 255);

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    $ip = mb_substr($ip, 0, 45);

    dbQuery(
        "INSERT INTO user_sessions (user_id, session_token, device_info, ip_address)
         VALUES (?, ?, ?, ?)",
        [$userId, $token, $deviceInfo, $ip], "isss"
    );

    $_SESSION['session_token'] = $token;
}

/**
 * Update kolom totp_last_counter untuk user setelah verifikasi TOTP berhasil.
 * Mencegah replay attack dengan menyimpan counter terakhir yang digunakan.
 *
 * @param int $userId
 * @param int $counter
 */
function updateUserTotpCounter(int $userId, int $counter): void {
    dbQuery(
        "UPDATE users SET totp_last_counter = ? WHERE id = ?",
        [$counter, $userId], "ii"
    );
}

/**
 * Wrapper verifikasi TOTP dengan replay protection.
 * Memerlukan kolom totp_last_counter di tabel users (default 0).
 *
 * @param string $secret
 * @param string $code
 * @param int    $userId
 * @param int    $window
 * @return bool
 */
function totpVerifyWithReplay(string $secret, string $code, int $userId, int $window = 1): bool {
    // Ambil counter terakhir dari DB
    $user = dbFetchOne(
        "SELECT totp_last_counter FROM users WHERE id = ?",
        [$userId], "i"
    );
    $lastCounter = (int)($user['totp_last_counter'] ?? 0);

    require_once __DIR__ . '/totp.php';
    $counter = totpVerify($secret, $code, $window, $lastCounter);

    if ($counter !== false) {
        // Update counter
        updateUserTotpCounter($userId, $counter);
        return true;
    }

    return false;
}

// ============================================
// FUNGSI AMBIL DATA DARI DATABASE (unchanged)
// ============================================

function getKabinet() {
    return dbFetchOne("SELECT * FROM kabinet WHERE id = 1");
}

function getVisiMisi() {
    $data = dbFetchOne("SELECT * FROM visi_misi WHERE id = 1");
    if ($data) $data['misi'] = json_decode($data['misi'], true) ?? [];
    return $data;
}

function getKontak() {
    $data = dbFetchOne("SELECT * FROM kontak WHERE id = 1");
    if ($data) {
        $data['telepon']      = json_decode($data['telepon'],      true) ?? [];
        $data['jam_kerja']    = json_decode($data['jam_kerja'],    true) ?? [];
        $data['sosial_media'] = json_decode($data['sosial_media'], true) ?? [];
    }
    return $data;
}

function getKetua($periode_id = null) {
    if ($periode_id) {
        return dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?", [$periode_id], "i");
    }
    return dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'ketua'");
}

function getWakilKetua($periode_id = null) {
    if ($periode_id) {
        return dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'wakil_ketua' AND periode_id = ?", [$periode_id], "i");
    }
    return dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'wakil_ketua'");
}

function getSekretarisUmum($periode_id = null) {
    if ($periode_id) {
        $data = dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'sekretaris_umum' AND periode_id = ?", [$periode_id], "i");
    } else {
        $data = dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'sekretaris_umum'");
    }
    if ($data) {
        $params = $periode_id ? [$data['id'], $periode_id] : [$data['id']];
        $types  = $periode_id ? "ii" : "i";
        $sql    = $periode_id
            ? "SELECT * FROM anggota_bph WHERE bph_id = ? AND periode_id = ? ORDER BY urutan"
            : "SELECT * FROM anggota_bph WHERE bph_id = ? ORDER BY urutan";
        $data['anggota'] = dbFetchAll($sql, $params, $types);
        $data['tugas']   = json_decode($data['tugas'],  true) ?? [];
        $data['proker']  = json_decode($data['proker'], true) ?? [];
    }
    return $data;
}

function getBendaharaUmum($periode_id = null) {
    if ($periode_id) {
        $data = dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'bendahara_umum' AND periode_id = ?", [$periode_id], "i");
    } else {
        $data = dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'bendahara_umum'");
    }
    if ($data) {
        $params = $periode_id ? [$data['id'], $periode_id] : [$data['id']];
        $types  = $periode_id ? "ii" : "i";
        $sql    = $periode_id
            ? "SELECT * FROM anggota_bph WHERE bph_id = ? AND periode_id = ? ORDER BY urutan"
            : "SELECT * FROM anggota_bph WHERE bph_id = ? ORDER BY urutan";
        $data['anggota'] = dbFetchAll($sql, $params, $types);
        $data['tugas']   = json_decode($data['tugas'],  true) ?? [];
        $data['proker']  = json_decode($data['proker'], true) ?? [];
    }
    return $data;
}

function getAllKementerian($periode_id = null) {
    if ($periode_id) {
        $kementerian = dbFetchAll("SELECT * FROM kementerian WHERE periode_id = ? ORDER BY urutan", [$periode_id], "i");
    } else {
        $kementerian = dbFetchAll("SELECT * FROM kementerian ORDER BY urutan");
    }
    foreach ($kementerian as &$k) {
        $params = $periode_id ? [$k['id'], $periode_id] : [$k['id']];
        $types  = $periode_id ? "ii" : "i";
        $sql    = $periode_id
            ? "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan"
            : "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? ORDER BY urutan";
        $k['anggota'] = dbFetchAll($sql, $params, $types);
        $k['tugas']   = json_decode($k['tugas'],  true) ?? [];
        $k['proker']  = json_decode($k['proker'], true) ?? [];
        $k['fungsi']  = json_decode($k['fungsi'] ?? '', true) ?? [];
    }
    return $kementerian;
}

function getKementerianBySlug($slug, $periode_id = null) {
    if ($periode_id) {
        $data = dbFetchOne("SELECT * FROM kementerian WHERE slug = ? AND periode_id = ?", [$slug, $periode_id], "si");
    } else {
        $data = dbFetchOne("SELECT * FROM kementerian WHERE slug = ?", [$slug], "s");
    }
    if ($data) {
        $params = $periode_id ? [$data['id'], $periode_id] : [$data['id']];
        $types  = $periode_id ? "ii" : "i";
        $sql    = $periode_id
            ? "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan"
            : "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? ORDER BY urutan";
        $data['anggota'] = dbFetchAll($sql, $params, $types);
        $data['tugas']   = json_decode($data['tugas'],  true) ?? [];
        $data['proker']  = json_decode($data['proker'], true) ?? [];
        $data['fungsi']  = json_decode($data['fungsi'] ?? '', true) ?? [];
    }
    return $data;
}

function getAllBerita($limit = null, $offset = 0) {
    if ($limit) {
        return dbFetchAll(
            "SELECT * FROM berita WHERE status = 'published' ORDER BY tanggal DESC LIMIT ? OFFSET ?",
            [$limit, $offset], "ii"
        );
    }
    return dbFetchAll("SELECT * FROM berita WHERE status = 'published' ORDER BY tanggal DESC");
}

function getBeritaBySlug($slug) {
    return dbFetchOne("SELECT * FROM berita WHERE slug = ? AND status = 'published'", [$slug], "s");
}

function getBeritaTerbaru($limit = 3) {
    return dbFetchAll(
        "SELECT * FROM berita WHERE status = 'published' ORDER BY tanggal DESC LIMIT ?",
        [$limit], "i"
    );
}

// ============================================
// ALIAS FUNGSI DATABASE (unchanged)
// ============================================

function dbGetAll($sql, $params = [], $types = "") {
    return dbFetchAll($sql, $params, $types);
}

function dbGetOne($sql, $params = [], $types = "") {
    return dbFetchOne($sql, $params, $types);
}

// ============================================
// FUNGSI UTILITY (unchanged)
// ============================================

function generateRandomString($length = 10) {
    $chars  = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = '';
    $max    = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }
    return $result;
}

function formatRupiah($number) {
    return 'Rp ' . number_format($number, 0, ',', '.');
}

function truncateText($text, $length = 100, $suffix = '...') {
    if (mb_strlen($text) <= $length) return $text;
    $truncated = mb_substr($text, 0, $length);
    $lastSpace = mb_strrpos($truncated, ' ');
    if ($lastSpace !== false) {
        $truncated = mb_substr($truncated, 0, $lastSpace);
    }
    return $truncated . $suffix;
}

// ============================================
// FUNGSI AUDIT LOG (unchanged)
// ============================================

function auditLog(string $action, ?string $targetTable = null, ?int $targetId = null, ?string $deskripsi = null): void {
    $userId   = isset($_SESSION['admin_id'])   ? (int)$_SESSION['admin_id']   : null;
    $username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : null;

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    $ip = trim(explode(',', $ip)[0]);
    $ip = mb_substr($ip, 0, 45);

    if (rand(1, 100) === 1) {
        try {
            // Pembersihan berkala (Audit log > 30 hari) - Hybrid Syntax
            $isMysql = (defined('DB_DRIVER') && DB_DRIVER === 'mysql') || (isset($GLOBALS['db_driver']) && $GLOBALS['db_driver'] === 'mysql');
            if (function_exists('dbGetDriver')) { $isMysql = (dbGetDriver() === 'mysql'); }
            
            $cleanupSql = $isMysql ? "NOW() - INTERVAL 30 DAY" : "now() - INTERVAL '30 days'";
            dbQuery("DELETE FROM audit_log WHERE created_at < $cleanupSql");
        } catch (Exception $e) {
            error_log("auditLog cleanup: " . $e->getMessage());
        }
    }

    try {
        dbQuery(
            "INSERT INTO audit_log (user_id, username, action, target_table, target_id, deskripsi, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$userId, $username, strtoupper($action), $targetTable, $targetId,
             $deskripsi ? mb_substr($deskripsi, 0, 500) : null, $ip],
            "isssiss"
        );
    } catch (Exception $e) {
        error_log("auditLog INSERT gagal: " . $e->getMessage());
    }
}

function debugVar($data, $die = false) {
    if (!defined('APP_ENV') || APP_ENV !== 'development') return;
    echo '<pre style="background:#1a1a2e;color:#e0e0e0;padding:12px 16px;border:1px solid #444;border-radius:6px;margin:10px;font-size:13px;">';
    print_r($data);
    echo '</pre>';
    if ($die) die('<b style="color:red;">--- DEBUG STOP ---</b>');
}