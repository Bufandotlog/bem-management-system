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
        if (!file_exists($localFile) || !is_readable($localFile)) {
            $_SESSION['error'] = "Gagal membaca file (File tidak ada atau permissions salah).";
            return false;
        }
        
        clearstatcache(true, $localFile);
        $size = filesize($localFile);
        if ($size === false || $size === 0) {
            $_SESSION['error'] = "File kosong atau gagal membaca ukuran file.";
            return false;
        }

        $s3 = getS3Client();
        $bucket = $_ENV['S3_BUCKET'] ?? '';
        
        $stream = fopen($localFile, 'r');
        if (!$stream) {
            $_SESSION['error'] = "Gagal membuka stream file lokal.";
            return false;
        }

        $s3->putObject([
            'Bucket'        => $bucket,
            'Key'           => $s3Key,
            'Body'          => $stream,
            'ContentLength' => (int)$size,
            'ContentType'   => $mimeType,
        ]);
        
        if (is_resource($stream)) {
            fclose($stream);
        }
        return true;
    } catch (Exception $e) {
        error_log("uploadToS3 Error: " . $e->getMessage());
        $_SESSION['error'] = "Gagal mengunggah ke Object Storage: " . $e->getMessage();
        return false;
    }
}

/**
 * Download file dari S3 ke path lokal sementara (bypass Cloudflare).
 * Menggunakan AWS SDK langsung sehingga tidak terkena WAF/bot protection.
 *
 * @param string $s3Key   Key objek di S3 (contoh: "lpj/abc123.webp")
 * @param string $destPath Path tujuan lokal untuk menyimpan file
 * @return bool
 */
function downloadFromS3($s3Key, $destPath) {
    try {
        $s3 = getS3Client();
        $bucket = $_ENV['S3_BUCKET'] ?? '';
        
        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $s3Key,
            'SaveAs' => $destPath,
        ]);
        return file_exists($destPath) && filesize($destPath) > 0;
    } catch (Exception $e) {
        error_log("downloadFromS3 Error [{$s3Key}]: " . $e->getMessage());
        return false;
    }
}

/**
 * Pre-fetch semua gambar dari S3 ke folder temp lokal untuk keperluan DOCX generation.
 * Mengembalikan array mapping [original_path => local_temp_path] dan list file temp untuk cleanup.
 *
 * @param array &$configData Data konfigurasi LPJ (akan dimodifikasi in-place)
 * @return array List path file temp yang harus dihapus setelah selesai
 */
function prefetchS3ImagesForDocx(&$configData) {
    $tempFiles = [];
    $storageMethod = $_ENV['STORAGE_METHOD'] ?? 'local';
    
    if ($storageMethod !== 's3') {
        return $tempFiles;
    }
    
    $tempDir = sys_get_temp_dir() . '/lpj_images_' . uniqid();
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    $tempFiles[] = $tempDir; // Track dir for cleanup
    
    // Process proker_terlaksana -> dokumentasi
    if (isset($configData['proker_terlaksana']) && is_array($configData['proker_terlaksana'])) {
        foreach ($configData['proker_terlaksana'] as &$pk) {
            if (isset($pk['dokumentasi']) && is_array($pk['dokumentasi'])) {
                foreach ($pk['dokumentasi'] as &$dok) {
                    $tempPath = _prefetchSingleImage($dok['file_path'] ?? '', $tempDir);
                    if ($tempPath) {
                        $dok['file_path'] = $tempPath;
                        $tempFiles[] = $tempPath;
                    }
                }
                unset($dok);
            }
            // Process nota_belanja images
            if (isset($pk['nota_belanja']) && is_array($pk['nota_belanja'])) {
                foreach ($pk['nota_belanja'] as &$nota) {
                    $tempPath = _prefetchSingleImage($nota['file_path'] ?? '', $tempDir);
                    if ($tempPath) {
                        $nota['file_path'] = $tempPath;
                        $tempFiles[] = $tempPath;
                    }
                }
                unset($nota);
            }
        }
        unset($pk);
    }
    
    return $tempFiles;
}

/**
 * Helper internal: download satu gambar dari S3 ke temp dir.
 * @return string|null Path lokal jika berhasil, null jika skip/gagal
 */
function _prefetchSingleImage($filePath, $tempDir) {
    if (empty($filePath)) return null;
    
    // Sudah URL http/https → skip (biarkan Python handle)
    if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
        return null;
    }
    
    // Cek apakah file sudah ada di lokal (legacy)
    $relPath = get_relative_upload_path($filePath);
    $localPath = uploadPath($relPath);
    if (file_exists($localPath) && filesize($localPath) > 0) {
        return $localPath; // File lokal ada, gunakan langsung
    }
    
    // File tidak ada di lokal → download dari S3
    $ext = pathinfo($relPath, PATHINFO_EXTENSION) ?: 'webp';
    $tempPath = $tempDir . '/' . md5($relPath) . '.' . $ext;
    
    if (downloadFromS3($relPath, $tempPath)) {
        return $tempPath;
    }
    
    return null;
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

// Auto-migration: Pastikan kolom waktu dan tempat ada di tabel kegiatan
try {
    dbQuery("SELECT waktu_pelaksanaan, tempat_pelaksanaan FROM kegiatan LIMIT 1");
} catch (Exception $e) {
    try {
        dbQuery("ALTER TABLE kegiatan ADD COLUMN waktu_pelaksanaan VARCHAR(100) DEFAULT NULL, ADD COLUMN tempat_pelaksanaan TEXT DEFAULT NULL");
    } catch (Exception $ex) {}
}

// Auto-migration: Pastikan kolom file_ttd ada di tabel users dan pendaftaran_anggota
try {
    dbQuery("SELECT file_ttd FROM users LIMIT 1");
} catch (Exception $e) {
    try { dbQuery("ALTER TABLE users ADD COLUMN file_ttd VARCHAR(255) DEFAULT NULL"); } catch (Exception $ex) {}
}
try {
    dbQuery("SELECT file_ttd FROM pendaftaran_anggota LIMIT 1");
} catch (Exception $e) {
    try { dbQuery("ALTER TABLE pendaftaran_anggota ADD COLUMN file_ttd VARCHAR(255) DEFAULT NULL"); } catch (Exception $ex) {}
}

// Auto-migration: Pastikan kolom google_id, google_email, google_linked_at ada di tabel users
try {
    dbQuery("SELECT google_id FROM users LIMIT 1");
} catch (Exception $e) {
    try {
        dbQuery("ALTER TABLE users ADD COLUMN google_id VARCHAR(255) DEFAULT NULL, ADD COLUMN google_email VARCHAR(255) DEFAULT NULL, ADD COLUMN google_linked_at TIMESTAMP NULL DEFAULT NULL");
    } catch (Exception $ex) {}
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

// Auto-migration: Pastikan tabel fcm_tokens ada
try {
    dbQuery("SELECT 1 FROM fcm_tokens LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('CREATE TABLE "fcm_tokens" (
              "id" SERIAL PRIMARY KEY,
              "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
              "fcm_token" VARCHAR(255) NOT NULL UNIQUE,
              "device_type" VARCHAR(50) DEFAULT \'android\',
              "app_version" VARCHAR(20) DEFAULT NULL,
              "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } else {
            dbQuery("CREATE TABLE IF NOT EXISTS `fcm_tokens` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `fcm_token` varchar(255) NOT NULL,
              `device_type` varchar(50) DEFAULT 'android',
              `app_version` varchar(20) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_fcm_token` (`fcm_token`),
              KEY `idx_fcm_user` (`user_id`),
              CONSTRAINT `fk_fcm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $ex) {}
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

// Auto-migration: Pastikan tabel notifikasi ada dan memiliki kolom judul
try {
    dbQuery("SELECT 1 FROM notifikasi LIMIT 1");
    try {
        dbQuery("SELECT judul FROM notifikasi LIMIT 1");
    } catch (Exception $eCol) {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('ALTER TABLE "notifikasi" ADD COLUMN "judul" VARCHAR(255) DEFAULT NULL');
        } else {
            dbQuery("ALTER TABLE `notifikasi` ADD COLUMN `judul` varchar(255) DEFAULT NULL AFTER `user_id`");
        }
    }
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('CREATE TABLE "notifikasi" (
              "id" SERIAL PRIMARY KEY,
              "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
              "judul" VARCHAR(255) DEFAULT NULL,
              "tipe" VARCHAR(50) DEFAULT \'info\',
              "pesan" TEXT NOT NULL,
              "link" VARCHAR(255) DEFAULT NULL,
              "is_read" SMALLINT DEFAULT 0,
              "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } else {
            dbQuery("CREATE TABLE IF NOT EXISTS `notifikasi` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `judul` varchar(255) DEFAULT NULL,
              `tipe` varchar(50) DEFAULT 'info',
              `pesan` text NOT NULL,
              `link` varchar(255) DEFAULT NULL,
              `is_read` tinyint(1) DEFAULT 0,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `idx_notif_user` (`user_id`),
              KEY `idx_notif_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $ex) {}
}

// Auto-migration: Update role enum in users table
try {
    $db_type = DB_CONNECTION;
    if ($db_type === 'pgsql') {
        dbQuery("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        dbQuery("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('superadmin', 'admin', 'kominfo', 'sekretaris', 'anggota'))");
        dbQuery("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'anggota'");
    } else {
        dbQuery("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','kominfo','sekretaris','anggota') NOT NULL DEFAULT 'anggota'");
    }
} catch (Exception $e) {
    // Abaikan
}

// Auto-migration: Pastikan tabel kegiatan ada
try {
    dbQuery("SELECT 1 FROM kegiatan LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('CREATE TABLE "kegiatan" (
              "id" SERIAL PRIMARY KEY,
              "periode_id" INTEGER NOT NULL REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
              "nama_kegiatan" VARCHAR(255) NOT NULL,
              "deskripsi" TEXT,
              "tanggal_mulai" DATE,
              "tanggal_selesai" DATE,
              "status" VARCHAR(20) DEFAULT \'persiapan\' CHECK ("status" IN (\'persiapan\', \'berjalan\', \'selesai\')),
              "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
              "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } else {
            dbQuery("CREATE TABLE IF NOT EXISTS `kegiatan` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `periode_id` int(11) NOT NULL,
              `nama_kegiatan` varchar(255) NOT NULL,
              `deskripsi` text DEFAULT NULL,
              `tanggal_mulai` date DEFAULT NULL,
              `tanggal_selesai` date DEFAULT NULL,
              `status` enum('persiapan','berjalan','selesai') DEFAULT 'persiapan',
              `created_by` int(11) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `fk_kegiatan_periode` (`periode_id`),
              KEY `fk_kegiatan_user` (`created_by`),
              CONSTRAINT `fk_kegiatan_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_kegiatan_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $ex) {}
}

// Auto-migration: Pastikan tabel kegiatan_panitia ada
try {
    dbQuery("SELECT 1 FROM kegiatan_panitia LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery('CREATE TABLE "kegiatan_panitia" (
              "id" SERIAL PRIMARY KEY,
              "kegiatan_id" INTEGER NOT NULL REFERENCES "kegiatan"("id") ON DELETE CASCADE,
              "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
              "event_role" VARCHAR(50) NOT NULL CHECK ("event_role" IN (\'ketuplat\', \'sekretaris_panitia\', \'sie_acara\', \'sie_logistik\', \'sie_humas\', \'sie_konsumsi\', \'anggota_panitia\')),
              "ditunjuk_oleh" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
              "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        } else {
            dbQuery("CREATE TABLE IF NOT EXISTS `kegiatan_panitia` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `kegiatan_id` int(11) NOT NULL,
              `user_id` int(11) NOT NULL,
              `event_role` enum('ketuplat','sekretaris_panitia','sie_acara','sie_logistik','sie_humas','sie_konsumsi','anggota_panitia') NOT NULL,
              `ditunjuk_oleh` int(11) DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `fk_panitia_kegiatan` (`kegiatan_id`),
              KEY `fk_panitia_user` (`user_id`),
              KEY `fk_panitia_ditunjuk_oleh` (`ditunjuk_oleh`),
              CONSTRAINT `fk_panitia_kegiatan` FOREIGN KEY (`kegiatan_id`) REFERENCES `kegiatan` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_panitia_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_panitia_ditunjuk_oleh` FOREIGN KEY (`ditunjuk_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $ex) {}
}

// Auto-migration: Pastikan kolom kegiatan_id dan status_arsip ada di tabel arsip_surat
try {
    dbQuery("SELECT kegiatan_id FROM arsip_surat LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery("ALTER TABLE arsip_surat ADD COLUMN kegiatan_id INTEGER DEFAULT NULL");
        } else {
            dbQuery("ALTER TABLE arsip_surat ADD COLUMN kegiatan_id INT(11) DEFAULT NULL AFTER periode_id");
        }
    } catch (Exception $ex) {}
}

try {
    dbQuery("SELECT status_arsip FROM arsip_surat LIMIT 1");
} catch (Exception $e) {
    try {
        $db_type = DB_CONNECTION;
        if ($db_type === 'pgsql') {
            dbQuery("ALTER TABLE arsip_surat ADD COLUMN status_arsip VARCHAR(20) DEFAULT 'archived'");
        } else {
            dbQuery("ALTER TABLE arsip_surat ADD COLUMN status_arsip ENUM('staging','archived') NOT NULL DEFAULT 'archived'");
        }
    } catch (Exception $ex) {}
}


// ============================================
// FUNGSI SECURITY & CSP NONCE GENERATOR
// ============================================

/**
 * Menghasilkan token Nonce cryptographically secure per-request untuk Content-Security-Policy (CSP).
 */
function getCspNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Mengirim header Content-Security-Policy berbasis Nonce (Strict CSP).
 */
function sendCspHeader(): void {
    if (headers_sent()) return;
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://static.cloudflareinsights.com https://cdnjs.cloudflare.com; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
           "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
           "img-src 'self' data: http: https:; " .
           "frame-src 'self' https://challenges.cloudflare.com https://www.google.com; " .
           "connect-src 'self' https://challenges.cloudflare.com https://*.nevaobjects.id https://*.s3.nevaobjects.id;";
    header("Content-Security-Policy: " . $csp);
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

/**
 * Memeriksa apakah percobaan aksi sensitif melebihi batas rate limit berbasis DB/IP.
 *
 * @param string      $action       Tipe aksi (mis: 'login_failed', '2fa_failed', 'pwd_change_failed')
 * @param int         $maxAttempts  Batas maksimum percobaan gagal (default: 5)
 * @param int         $windowMins   Jendela waktu evaluasi dalam menit (default: 15)
 * @param string|null $username     Username atau identifier target (opsional)
 * @return bool True jika MELEBIHI batas (terblokir), False jika MASIH DIIZINKAN
 */
function isRateLimited(string $action = 'login_failed', int $maxAttempts = 5, int $windowMins = 15, ?string $username = null): bool {
    try {
        $ip = getClientIp();
        $sql = "SELECT COUNT(*) AS cnt FROM login_attempts_ip 
                WHERE ip_address = ? AND attempt_type = ? AND created_at > NOW() - INTERVAL ? MINUTE";
        $params = [$ip, $action, $windowMins];
        $types = "ssi";

        if (!empty($username)) {
            $sql .= " AND username = ?";
            $params[] = $username;
            $types .= "s";
        }

        $row = dbFetchOne($sql, $params, $types);
        $count = (int)($row['cnt'] ?? 0);

        return $count >= $maxAttempts;
    } catch (Exception $e) {
        error_log("isRateLimited error: " . $e->getMessage());
        return false;
    }
}



// ============================================
// FUNGSI HELPER PATH & URL (unchanged)
// ============================================

function get_relative_upload_path($filename) {
    if (empty($filename)) return '';
    $filename = str_replace('\\', '/', $filename);
    $uploadPath = str_replace('\\', '/', rtrim(UPLOAD_PATH, '/'));
    
    if (str_starts_with($filename, $uploadPath . '/')) {
        $filename = substr($filename, strlen($uploadPath) + 1);
    } else {
        $pos = strpos($filename, '/uploads/');
        if ($pos !== false) {
            $filename = substr($filename, $pos + 9);
        } elseif (str_starts_with($filename, 'uploads/')) {
            $filename = substr($filename, 8);
        }
    }
    return ltrim($filename, '/');
}

function uploadUrl($filename) {
    if (empty($filename)) return '';
    if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
        return $filename;
    }
    
    $relPath = get_relative_upload_path($filename);
    
    // Fallback: Jika file ada di server lokal, prioritaskan URL lokal
    $localPath = uploadPath($relPath);
    if (!empty($localPath) && file_exists($localPath)) {
        return rtrim(BASE_URL, '/') . '/uploads/' . $relPath;
    }
    
    if (($_ENV['STORAGE_METHOD'] ?? 'local') === 's3') {
        return getS3SignedUrl($relPath);
    }
    return rtrim(BASE_URL, '/') . '/uploads/' . $relPath;
}

/**
 * Generate Presigned GET URL untuk mengakses file S3 secara aman.
 * Bucket tidak perlu public — hanya URL yang ditandatangani yang bisa mengakses file.
 * URL di-cache per-request agar tidak generate signature berulang untuk file yang sama.
 *
 * @param string $s3Key  Key objek di S3 (contoh: "lpj/abc123.webp")
 * @param int    $expiry Durasi berlaku URL dalam detik (default: 2 jam)
 * @return string Presigned URL
 */
function getS3SignedUrl($s3Key, $expiry = 7200) {
    // Cache per-request agar tidak re-generate untuk file yang sama
    static $urlCache = [];
    if (isset($urlCache[$s3Key])) {
        return $urlCache[$s3Key];
    }
    
    try {
        $s3 = getS3Client();
        $bucket = $_ENV['S3_BUCKET'] ?? '';
        
        $cmd = $s3->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => $s3Key,
        ]);
        
        $request = $s3->createPresignedRequest($cmd, "+{$expiry} seconds");
        $url = (string) $request->getUri();
        
        $urlCache[$s3Key] = $url;
        return $url;
    } catch (Exception $e) {
        error_log("getS3SignedUrl Error [{$s3Key}]: " . $e->getMessage());
        // Fallback ke public URL jika signing gagal
        $publicUrl = $_ENV['S3_PUBLIC_URL'] ?? '';
        return rtrim($publicUrl, '/') . '/' . $s3Key;
    }
}

function uploadPath($filename) {
    if (empty($filename)) return '';
    if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
        return $filename;
    }
    $relPath = get_relative_upload_path($filename);
    return rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
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

function csrfVerify(?string $token = null): bool {
    $submitted = $token ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
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
                @imagedestroy($img);
                $img = $resized;
            } else {
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            if (@imagewebp($img, $destination, 75)) {
                @imagedestroy($img);
                chmod($destination, 0644);
                $success = true;
                error_log("uploadFile: SUKSES (Image converted to WebP) - {$destination} | path: {$relativePath}");
            } else {
                if ($img) @imagedestroy($img);
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

    // Fallback if image conversion left a 0-byte file despite returning true
    if ($success && file_exists($destination) && filesize($destination) === 0) {
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            // just in case tmp_name is gone, we can't do much
            error_log("uploadFile: imagewebp created 0-byte file, fallback move_uploaded_file failed.");
        } else {
            error_log("uploadFile: imagewebp created 0-byte file, successfully recovered using move_uploaded_file.");
            chmod($destination, 0644);
        }
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
            // if (file_exists($destination)) {
            //     @unlink($destination);
            // }
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
        redirect('admin/core/dashboard.php', 'Akses ditolak: Hanya Sekretaris atau Superadmin yang diizinkan untuk mengelola Modul Surat.', 'error');
    }
}

function isKetuplat() {
    $role = $_SESSION['admin_role'] ?? '';
    if (in_array($role, ['superadmin', 'admin', 'sekretaris']) || !empty($_SESSION['admin_can_access_all'])) {
        return true;
    }
    $user_id = $_SESSION['admin_id'] ?? 0;
    if (!$user_id) return false;

    $row = dbFetchOne(
        "SELECT 1 FROM kegiatan_panitia WHERE user_id = ? AND event_role = 'ketuplat' LIMIT 1",
        [(int)$user_id], "i"
    );
    return !empty($row);
}


function isLogistik() {
    $role = $_SESSION['admin_role'] ?? '';
    if (in_array($role, ['superadmin', 'admin', 'sekretaris']) || !empty($_SESSION['admin_can_access_all'])) {
        return true;
    }
    $user_id = $_SESSION['admin_id'] ?? 0;
    if (!$user_id) return false;

    $row = dbFetchOne(
        "SELECT 1 FROM kegiatan_panitia WHERE user_id = ? AND event_role IN ('sie_logistik', 'ketuplat') LIMIT 1",
        [(int)$user_id], "i"
    );
    return !empty($row);
}

function requireLogistik() {
    if (!isLogistik()) {
        redirect('admin/core/dashboard.php', 'Akses ditolak: Hanya Sie Logistik, Sekretaris, atau Admin yang diizinkan untuk mengelola Master Barang & Tempat.', 'error');
    }
}

// ============================================
// FUNGSI HYBRID RBAC (EVENT-LEVEL ROLE)
// ============================================

/**
 * Mendapatkan Event-Role dari user yang sedang login untuk kegiatan tertentu
 */
function getEventRole($user_id, $kegiatan_id) {
    if (!$user_id || !$kegiatan_id) return null;
    $row = dbFetchOne("SELECT event_role FROM kegiatan_panitia WHERE user_id = ? AND kegiatan_id = ?", [(int)$user_id, (int)$kegiatan_id], "ii");
    return $row ? $row['event_role'] : null;
}

/**
 * Middleware validasi akses khusus per-kegiatan (Event-Level)
 */
function requireEventAccess($kegiatan_id, $allowed_event_roles = []) {
    $system_role = $_SESSION['admin_role'] ?? '';
    
    // Bypass untuk System-Level Role yang berwenang memonitor seluruh kegiatan
    if (in_array($system_role, ['superadmin', 'admin', 'sekretaris']) || !empty($_SESSION['admin_can_access_all'])) {
        return true; 
    }
    
    // Jika bukan admin, cek status kegiatan. Jika selesai, blokir akses.
    $status_row = dbFetchOne("SELECT status FROM kegiatan WHERE id = ?", [(int)$kegiatan_id], "i");
    if ($status_row && $status_row['status'] === 'selesai') {
        redirect('admin/core/dashboard.php', 'Akses ditolak: Kegiatan ini sudah selesai dan diarsipkan.', 'error');
        exit();
    }
    
    $event_role = getEventRole($_SESSION['admin_id'] ?? 0, $kegiatan_id);
    // Ketuplat (Ketua Pelaksana) berhak mengakses seluruh divisi/workspace pada kegiatan yang dipimpinnya
    if (!$event_role || ($event_role !== 'ketuplat' && !in_array($event_role, $allowed_event_roles))) {
        redirect('admin/core/dashboard.php', 'Akses ditolak: Divisi Anda tidak diizinkan mengakses menu kepanitiaan ini.', 'error');
        exit();
    }
    return true;
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
        $data['tugas']   = json_decode($data['tugas'] ?? '[]',  true) ?? [];
        $data['proker']  = json_decode($data['proker'] ?? '[]', true) ?? [];
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
        $data['tugas']   = json_decode($data['tugas'] ?? '[]',  true) ?? [];
        $data['proker']  = json_decode($data['proker'] ?? '[]', true) ?? [];
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

if (!function_exists('getDefaultPassword')) {
    /**
     * Ambil password default pendaftaran untuk periode tertentu.
     *
     * @param  int|null $periode_id
     * @return string
     */
    function getDefaultPassword($periode_id = null) {
        if ($periode_id === null) {
            $periode_id = function_exists('getUserPeriode') ? getUserPeriode() : 1;
        }
        $periode_id = (int) $periode_id;

        $row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = ?", ['default_password_periode_' . $periode_id]);
        if ($row && !empty($row['nilai'])) {
            return $row['nilai'];
        }

        $global_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'default_password'");
        if ($global_row && !empty($global_row['nilai'])) {
            return $global_row['nilai'];
        }

        return 'Bem2026!';
    }
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

        // Otomatis sinkronkan ke tabel `notifikasi` untuk aktivitas non-login/logout agar tidak membanjiri notifikasi pengurus
        $actionUpper = strtoupper($action);
        $isRoutineAuth = in_array($actionUpper, ['LOGIN_SUCCESS', 'LOGOUT', 'LOGIN_FAILED', '2FA_BYPASSED', '2FA_VERIFIED']);
        
        if (!empty($deskripsi) && !$isRoutineAuth) {
            $titleMap = [
                'users' => 'Manajemen User & Keamanan',
                'arsip_surat' => 'Arsip Surat & Dokumen',
                'lpj_dokumen' => 'Dokumen LPJ',
                'berita' => 'Publikasi Berita',
                'kegiatan' => 'Kegiatan & Proker',
                'periode_kepengurusan' => 'Periode Kepengurusan',
                'app_release' => 'Unduhan Mobile APK',
                'audit_log' => 'Audit System'
            ];
            $notifTitle = $titleMap[$targetTable ?? ''] ?? ('Aktivitas: ' . ucfirst(strtolower($action)));

            $notifType = 'info';
            if (strpos($actionUpper, 'DELETE') !== false || strpos($actionUpper, 'FAIL') !== false) {
                $notifType = 'danger';
            } elseif (strpos($actionUpper, 'CREATE') !== false || strpos($actionUpper, 'INSERT') !== false || strpos($actionUpper, 'DOWNLOAD') !== false) {
                $notifType = 'success';
            } elseif (strpos($actionUpper, 'UPDATE') !== false) {
                $notifType = 'warning';
            }

            $linkMap = [
                'users' => function_exists('baseUrl') ? baseUrl('admin/system/kelola-admin.php') : '/admin/system/kelola-admin.php',
                'arsip_surat' => function_exists('baseUrl') ? baseUrl('admin/surat/arsip.php') : '/admin/surat/arsip.php',
                'lpj_dokumen' => function_exists('baseUrl') ? baseUrl('admin/lpj/lpj.php') : '/admin/lpj/lpj.php',
                'berita' => function_exists('baseUrl') ? baseUrl('admin/konten/berita.php') : '/admin/konten/berita.php',
                'kegiatan' => function_exists('baseUrl') ? baseUrl('admin/kegiatan/kegiatan.php') : '/admin/kegiatan/kegiatan.php',
                'periode_kepengurusan' => function_exists('baseUrl') ? baseUrl('admin/system/periode-kepengurusan.php') : '/admin/system/periode-kepengurusan.php',
                'app_release' => function_exists('baseUrl') ? baseUrl('admin/download_app.php') : '/admin/download_app.php'
            ];
            $notifLink = $linkMap[$targetTable ?? ''] ?? (function_exists('baseUrl') ? baseUrl('admin/system/audit-log.php') : '/admin/system/audit-log.php');

            $targetRolesMap = [
                'users'                 => ['superadmin'],
                'user_sessions'         => ['superadmin'],
                'audit_log'             => ['superadmin'],
                'database'              => ['superadmin'],
                'periode_kepengurusan'  => ['superadmin', 'admin'],
                'arsip_surat'           => ['superadmin', 'admin', 'sekretaris'],
                'arsip_berita_acara'    => ['superadmin', 'admin', 'sekretaris'],
                'lpj_dokumen'           => ['superadmin', 'admin', 'sekretaris'],
                'berita'                => ['superadmin', 'admin', 'kominfo'],
                'struktur_organisasi'   => ['superadmin', 'admin', 'kominfo'],
                'kegiatan'              => ['superadmin', 'admin', 'sekretaris'],
                'barang_master'         => ['superadmin', 'admin', 'sekretaris'],
                'tempat_master'         => ['superadmin', 'admin', 'sekretaris']
            ];

            $targetSystemRoles = $targetRolesMap[$targetTable ?? ''] ?? ['superadmin'];
            $targetUserIds = getTargetUserIdsByRole($targetSystemRoles);

            foreach ($targetUserIds as $targetId) {
                dbQuery(
                    "INSERT INTO notifikasi (user_id, judul, pesan, link, tipe) VALUES (?, ?, ?, ?, ?)",
                    [$targetId, $notifTitle, $deskripsi, $notifLink, $notifType]
                );
            }
        }
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
function syncTamuUndanganLetters($kegiatan_id, $periode_id) {
    // 1. Ambil data rundown jika ada
    $rundown = dbFetchOne("SELECT id, rundown_json FROM arsip_rundown WHERE kegiatan_id = ? AND periode_id = ? LIMIT 1", [$kegiatan_id, $periode_id]);

    $kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id]);
    if (!$kegiatan) return;

    $ketuplat_nama = '';
    $sekretaris_nama = '';
    $panitia_inti = dbFetchAll("SELECT u.nama, p.event_role FROM kegiatan_panitia p JOIN users u ON p.user_id = u.id WHERE p.kegiatan_id = ? AND p.event_role IN ('ketuplat', 'ketua_pelaksana', 'sekretaris', 'sekretaris_panitia')", [$kegiatan_id]);
    foreach ($panitia_inti as $p) {
        if (in_array($p['event_role'], ['sekretaris', 'sekretaris_panitia'])) $sekretaris_nama = $p['nama'];
        else $ketuplat_nama = $p['nama'];
    }

    // Tentukan target default BPM
    $bpm_target = dbFetchOne(
        "SELECT isi_teks FROM surat_templates WHERE jenis = 'tujuan' AND LOWER(TRIM(label)) = 'bpm' AND (periode_id = ? OR periode_id IS NULL) LIMIT 1",
        [$periode_id]
    );
    $bpm_nama = ($bpm_target && !empty($bpm_target['isi_teks'])) ? trim($bpm_target['isi_teks']) : "Badan Perwakilan Mahasiswa\nINSTBUNAS Majalengka";

    $rapat_perihals = [
        'Undangan Rapat Persiapan',
        'Undangan Rapat Pemantapan',
        'Undangan Rapat Final'
    ];

    // Item Rapat BPM otomatis (Persiapan, Pemantapan, Final)
    $rapat_items = [];
    foreach ($rapat_perihals as $r_perihal) {
        $rapat_items[] = [
            'nama' => $bpm_nama,
            'perihal' => $r_perihal,
            'kategori' => 'D'
        ];
    }

    $tamu_val = $kegiatan['tamu_undangan'] ?? '';
    $clean_items = json_decode($tamu_val, true) ?: [];
    
    // Gabungkan item rapat di posisi terawal agar selalu memiliki nomor urut paling awal
    $clean_items = array_merge($rapat_items, $clean_items);

    // Daftar Pihak Wajib yang Otomatis Mendapatkan Surat Pemberitahuan Kegiatan Jika Tidak Diundang
    $mandatory_notif_targets = [
        [
            'regex' => '/\b(bpm|badan perwakilan mahasiswa)\b/i',
            'label' => 'BPM',
            'default_nama' => "Badan Perwakilan Mahasiswa\nINSTBUNAS Majalengka"
        ],
        [
            'regex' => '/\b(warek\s*i\b|warek\s*1\b|wakil\s*rektor\s*i\b|wakil\s*rektor\s*1\b|anto\s*herianto)/i',
            'label' => 'WAREK I',
            'default_nama' => "Bapak Anto Herianto, SE. MM.\nWAREK I\nBid. Akademik"
        ],
        [
            'regex' => '/\b(warek\s*ii\b|warek\s*2\b|wakil\s*rektor\s*ii\b|wakil\s*rektor\s*2\b|abrar\s*farhan)/i',
            'label' => 'WAREK II',
            'default_nama' => "Bapak Abrar Farhan Sudibyo, S.Kel., S.M., M.M.\nWAREK II\nBid. Administrasi Umum  dan Keuangan"
        ],
        [
            'regex' => '/\b(warek\s*iii\b|warek\s*3\b|wakil\s*rektor\s*iii\b|wakil\s*rektor\s*3\b|ii\s*muhamad\s*misbah)/i',
            'label' => 'WAREK III',
            'default_nama' => "Bapak Ii Muhamad Misbah, S.Pd.I., S.E., M.M.\nWAREK III\nBid. Kemahasiswaan"
        ]
    ];

    foreach ($mandatory_notif_targets as $target) {
        $has_target = false;
        foreach ($clean_items as $g_item) {
            $g_nama_check = trim($g_item['nama'] ?? '');
            if (preg_match($target['regex'], $g_nama_check)) {
                $has_target = true;
                break;
            }
        }
        if (!$has_target) {
            $tpl_target = dbFetchOne(
                "SELECT isi_teks FROM surat_templates WHERE jenis = 'tujuan' AND LOWER(TRIM(label)) = LOWER(TRIM(?)) AND (periode_id = ? OR periode_id IS NULL) LIMIT 1",
                [$target['label'], $periode_id]
            );
            $nama_final = ($tpl_target && !empty($tpl_target['isi_teks'])) ? trim($tpl_target['isi_teks']) : $target['default_nama'];

            $clean_items[] = [
                'nama' => $nama_final,
                'perihal' => 'Pemberitahuan Kegiatan',
                'kategori' => 'D'
            ];
        }
    }
    
    $processed_staging_ids = [];
    $tgl_indo = function_exists('tanggalIndonesia') ? tanggalIndonesia() : date('d F Y');
    $kode_kegiatan = !empty($kegiatan['kode_kegiatan']) ? strtoupper(trim($kegiatan['kode_kegiatan'])) : 'UND';

    foreach ($clean_items as $g_item) {
        $g_nama = trim($g_item['nama']);
        $g_perihal_label = trim($g_item['perihal']);
        $g_kat = ($g_item['kategori'] ?? 'D') === 'L' ? 'L' : 'D';

        $tpl_perihal_row = dbFetchOne("SELECT isi_teks FROM surat_templates WHERE jenis = 'perihal' AND LOWER(TRIM(label)) = LOWER(TRIM(?)) AND (periode_id = ? OR periode_id IS NULL) LIMIT 1", [$g_perihal_label, $periode_id]);
        $g_perihal_surat = ($tpl_perihal_row && !empty($tpl_perihal_row['isi_teks'])) ? $tpl_perihal_row['isi_teks'] : $g_perihal_label;

        $existing_staging = dbFetchOne("SELECT id, nomor_surat, jenis_surat, konten_surat, tujuan, perihal FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging' AND tujuan = ? AND perihal = ? LIMIT 1", [$kegiatan_id, $g_nama, $g_perihal_surat]);
        if (!$existing_staging) {
            // Flexible matching for mandatory targets (BPM, Warek I, Warek II, Warek III)
            $all_staging = dbFetchAll("SELECT id, nomor_surat, jenis_surat, konten_surat, tujuan, perihal FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging'", [$kegiatan_id]);
            foreach ($all_staging as $stg) {
                foreach ($mandatory_notif_targets as $tgt) {
                    if (preg_match($tgt['regex'], $g_nama) && preg_match($tgt['regex'], $stg['tujuan']) && $stg['perihal'] === $g_perihal_surat) {
                        $existing_staging = $stg;
                        break 2;
                    }
                }
            }
        }

        $perihal_lower = strtolower($g_perihal_surat);
        $is_pemateri = (strpos($perihal_lower, 'pemateri') !== false || strpos($perihal_lower, 'narasumber') !== false);
        $is_undangan_kegiatan = (strpos($perihal_lower, 'undangan') !== false && !in_array($g_perihal_label, $rapat_perihals) && !in_array($g_perihal_surat, $rapat_perihals)) || (strpos($perihal_lower, 'kegiatan') !== false && strpos($perihal_lower, 'pemberitahuan') === false) || strpos($perihal_lower, 'sambutan') !== false || strpos($perihal_lower, 'tamu') !== false;

        // Jika perihal adalah pemateri atau undangan kegiatan & rundown belum dibuat oleh Sie Acara:
        // Tahan & tarik kembali (hapus draft staging) surat jika sebelumnya pernah dibuat
        if (($is_pemateri || $is_undangan_kegiatan) && !$rundown) {
            if ($existing_staging) {
                dbQuery("DELETE FROM arsip_surat WHERE id = ?", [$existing_staging['id']]);
            }
            continue;
        }

        $pelaksanaan_hari = 'Sesuai Jadwal Kegiatan';
        $pelaksanaan_waktu = '08.00 s.d Selesai';

        if (in_array($g_perihal_label, $rapat_perihals) || in_array($g_perihal_surat, $rapat_perihals)) {
            $konteks_narasi = 'untuk menghadiri ' . $g_perihal_surat . ' kegiatan tersebut';
            $pelaksanaan_waktu = 'Menyesuaikan';
            $pelaksanaan_hari = 'Menyesuaikan (Belum Ditetapkan)';
        } elseif ($is_pemateri) {
            $konteks_narasi = 'untuk berkenan penyampaikan materi pada acara tersebut';
        } elseif (strpos($perihal_lower, 'sambutan') !== false) {
            $konteks_narasi = 'untuk berkenan penyampaikan sambutan pada acara tersebut';
        } elseif (strpos($perihal_lower, 'undangan') !== false) {
            $konteks_narasi = 'agar dapat menghadiri kegiatan tersebut';
        } elseif (strpos($perihal_lower, 'peminjaman') !== false) {
            $konteks_narasi = 'untuk dapat menggunakan fasilitas tersebut';
        } elseif (strpos($perihal_lower, 'pemberitahuan') !== false) {
            $konteks_narasi = '';
        } else {
            $konteks_narasi = 'demi mendukung terselenggaranya acara tersebut';
        }
        
        $lampiran_ids = [];
        if ($rundown && ($is_pemateri || $is_undangan_kegiatan)) {
            $lampiran_ids = [$rundown['id']];
            
            if ($is_pemateri || strpos($perihal_lower, 'sambutan') !== false) {
                // Cari waktu spesifik dari rundown
                $rd_json = json_decode($rundown['rundown_json'], true) ?: [];
                $found_waktu = false;
                
                // Ekstrak nama pendek untuk pencarian di rundown
                $g_nama_parts = explode(',', $g_nama);
                $g_nama_pendek = trim($g_nama_parts[0]);
                
                foreach ($rd_json as $dayData) {
                    if ($found_waktu) break;
                    foreach ($dayData['items'] ?? [] as $item) {
                        if (stripos($item['keterangan'] ?? '', $g_nama_pendek) !== false || stripos($item['acara'] ?? '', $g_nama_pendek) !== false) {
                            $pelaksanaan_waktu = $item['waktu'];
                            $found_waktu = true;
                            break;
                        }
                    }
                }
            }
        }

        $konten_data = [
            'sapaan_tujuan'            => '',
            'nama_kegiatan'            => $kegiatan['nama_kegiatan'],
            'tema'                     => $kegiatan['deskripsi'] ?? '',
            'tema_kegiatan'            => $kegiatan['deskripsi'] ?? '',
            'pelaksanaan_hari_tanggal' => $pelaksanaan_hari,
            'pelaksanaan_waktu'        => $pelaksanaan_waktu,
            'pelaksanaan_tempat'       => 'Lingkungan Kampus INSTBUNAS Majalengka',
            'konteks'                  => $konteks_narasi,
            'label_panitia'            => strtoupper($kegiatan['nama_kegiatan']),
            'panitia_ketua'            => $ketuplat_nama,
            'panitia_sekretaris'       => $sekretaris_nama,
            'use_ttd_warek'            => '1',
            'use_ttd_presma'           => '1',
            'use_cap_panitia'          => '1',
            'use_cap_warek'            => '1',
            'use_cap_presma'           => '1',
            'rundown_internal_ids'     => $lampiran_ids,
            'is_edited'                => 0
        ];

        if ($existing_staging) {
            $old_jenis = $existing_staging['jenis_surat'];
            $nomor_surat_cur = $existing_staging['nomor_surat'];
            $old_konten = json_decode($existing_staging['konten_surat'], true) ?: [];
            if (!empty($old_konten['is_edited'])) {
                $konten_data = array_merge($konten_data, $old_konten);
                $konten_data['is_edited'] = 1;
            }

            if ($old_jenis !== $g_kat || strpos($nomor_surat_cur, "/{$g_kat}/{$kode_kegiatan}/") === false) {
                $last_seq = dbFetchOne("SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut FROM arsip_surat WHERE periode_id = ? AND jenis_surat = ?", [$periode_id, $g_kat]);
                $next_num = str_pad((($last_seq['max_urut'] ?? 0) + 1), 3, '0', STR_PAD_LEFT);
                $romawi = ['1'=>'I', '2'=>'II', '3'=>'III', '4'=>'IV', '5'=>'V', '6'=>'VI', '7'=>'VII', '8'=>'VIII', '9'=>'IX', '10'=>'X', '11'=>'XI', '12'=>'XII'];
                $b_rom = $romawi[(int)date('n')] ?? 'I';
                $thn_now = date('Y');
                $nomor_surat_cur = "{$next_num}/{$g_kat}/{$kode_kegiatan}/BEM/{$b_rom}/{$thn_now}";
            }

            dbQuery(
                "UPDATE arsip_surat SET jenis_surat = ?, nomor_surat = ?, perihal = ?, tujuan = ?, konten_surat = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$g_kat, $nomor_surat_cur, $g_perihal_surat, $g_nama, json_encode($konten_data, JSON_UNESCAPED_UNICODE), $existing_staging['id']]
            );
            $processed_staging_ids[] = (int)$existing_staging['id'];
        } else {
            $last_seq = dbFetchOne("SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut FROM arsip_surat WHERE periode_id = ? AND jenis_surat = ?", [$periode_id, $g_kat]);
            $next_num = str_pad((($last_seq['max_urut'] ?? 0) + 1), 3, '0', STR_PAD_LEFT);
            $romawi = ['1'=>'I', '2'=>'II', '3'=>'III', '4'=>'IV', '5'=>'V', '6'=>'VI', '7'=>'VII', '8'=>'VIII', '9'=>'IX', '10'=>'X', '11'=>'XI', '12'=>'XII'];
            $b_rom = $romawi[(int)date('n')] ?? 'I';
            $thn_now = date('Y');
            $nomor_surat_draft = "{$next_num}/{$g_kat}/{$kode_kegiatan}/BEM/{$b_rom}/{$thn_now}";
            $created_by = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : NULL;

            dbQuery(
                "INSERT INTO arsip_surat (periode_id, kegiatan_id, status_arsip, jenis_surat, nomor_surat, perihal, tujuan, tempat_tanggal, konten_surat, created_by) VALUES (?, ?, 'staging', ?, ?, ?, ?, ?, ?, ?)",
                [
                    $periode_id,
                    $kegiatan_id,
                    $g_kat,
                    $nomor_surat_draft,
                    $g_perihal_surat,
                    $g_nama,
                    'Majalengka, ' . $tgl_indo,
                    json_encode($konten_data, JSON_UNESCAPED_UNICODE),
                    $created_by
                ]
            );
            $new_id = dbLastId();
            if ($new_id > 0) {
                $processed_staging_ids[] = $new_id;
            }
        }
    }

    if (!empty($processed_staging_ids)) {
        $in_ids = implode(',', array_fill(0, count($processed_staging_ids), '?'));
        $delete_params = array_merge([$kegiatan_id], $processed_staging_ids);
        dbQuery("DELETE FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging' AND id NOT IN ({$in_ids})", $delete_params);
    } else {
        dbQuery("DELETE FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging'", [$kegiatan_id]);
    }

    resyncStagingNumbers($periode_id);
}

/**
 * Sinkronisasi ulang nomor urut untuk seluruh draft surat di Staging Index.
 * Nomor urut staging selalu meneruskan dari nomor urut tertinggi di Arsip Surat Utama.
 */
function resyncStagingNumbers($periode_id = null) {
    if (!$periode_id) {
        $periode_id = function_exists('getUserPeriode') ? getUserPeriode() : 1;
    }

    $categories = ['D', 'L'];
    foreach ($categories as $kat) {
        // Ambil nomor urut tertinggi dari Arsip Utama (status_arsip != 'staging')
        $max_archived = dbFetchOne(
            "SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut 
             FROM arsip_surat 
             WHERE periode_id = ? AND jenis_surat = ? AND (status_arsip IS NULL OR status_arsip != 'staging')",
            [$periode_id, $kat]
        );
        
        $current_num = ($max_archived && $max_archived['max_urut']) ? (int)$max_archived['max_urut'] : 0;
        
        // Ambil semua surat staging untuk jenis_surat ini, diurutkan agar Rapat BPM selalu mendapatkan nomor paling awal
        $staging_list = dbFetchAll(
            "SELECT id, nomor_surat FROM arsip_surat 
             WHERE periode_id = ? AND jenis_surat = ? AND status_arsip = 'staging' 
             ORDER BY kegiatan_id ASC, 
                      CASE 
                        WHEN perihal = 'Undangan Rapat Persiapan' THEN 1
                        WHEN perihal = 'Undangan Rapat Pemantapan' THEN 2
                        WHEN perihal = 'Undangan Rapat Final' THEN 3
                        ELSE 4
                      END ASC, 
                      id ASC",
            [$periode_id, $kat]
        );
        
        foreach ($staging_list as $stg) {
            $current_num++;
            $next_str = str_pad($current_num, 3, '0', STR_PAD_LEFT);
            
            $old_nomor = $stg['nomor_surat'];
            $parts = explode('/', $old_nomor);
            if (count($parts) >= 2) {
                $parts[0] = $next_str;
                $new_nomor = implode('/', $parts);
                if ($new_nomor !== $old_nomor) {
                    dbQuery("UPDATE arsip_surat SET nomor_surat = ? WHERE id = ?", [$new_nomor, $stg['id']]);
                }
            }
        }
    }
}

/**
 * Otomatis commit (pindahkan ke Arsip Utama) surat di Staging Index 
 * yang statusnya sudah 'terkirim' oleh Humas selama >= 30 menit.
 */
function autoCommitSentStagingLetters($periode_id = null) {
    if (!$periode_id) {
        $periode_id = function_exists('getUserPeriode') ? getUserPeriode() : 1;
    }

    $sent_letters = dbFetchAll(
        "SELECT id, nomor_surat FROM arsip_surat 
         WHERE periode_id = ? 
           AND status_arsip = 'staging' 
           AND (status_humas = 'terkirim' OR (tanggal_dikirim IS NOT NULL AND tanggal_dikirim != '0000-00-00'))
           AND updated_at <= (NOW() - INTERVAL 30 MINUTE)",
        [$periode_id]
    );

    if (!empty($sent_letters)) {
        foreach ($sent_letters as $s) {
            dbQuery(
                "UPDATE arsip_surat SET status_arsip = 'archived', status_humas = 'terkirim' WHERE id = ? AND periode_id = ?",
                [$s['id'], $periode_id]
            );
            if (function_exists('auditLog')) {
                auditLog('UPDATE', 'arsip_surat', $s['id'], 'Auto-commit otomatis (30 menit terkirim oleh Humas): ' . $s['nomor_surat']);
            }
        }
        resyncStagingNumbers($periode_id);
    }
}

/**
 * Menyimpan data peminjaman ke lampiran_pinjam dan secara otomatis
 * membuat / memperbarui draft surat peminjaman di Staging Index.
 */
function saveLogistikPeminjamanAndDraftLetter($kegiatan_id, $periode_id, $acara, $tanggal, $tahun, $barang_json, $target_edit_id = 0, $auto_create = 1, $admin_id = null) {
    if (!$kegiatan_id || !$periode_id) return false;

    $saved_lampiran_id = 0;
    if ($target_edit_id > 0) {
        dbQuery("UPDATE lampiran_pinjam SET nama_acara = ?, tanggal_kegiatan = ?, tahun = ?, barang_json = ? WHERE id = ? AND periode_id = ?", [
            $acara, $tanggal, $tahun, $barang_json, $target_edit_id, $periode_id
        ]);
        $saved_lampiran_id = $target_edit_id;
    } else {
        dbQuery("INSERT INTO lampiran_pinjam (nama_acara, tanggal_kegiatan, tahun, barang_json, periode_id) VALUES (?, ?, ?, ?, ?)", [
            $acara, $tanggal, $tahun, $barang_json, $periode_id
        ]);
        $saved_lampiran_id = dbLastId();
    }

    if ($saved_lampiran_id > 0 && $auto_create) {
        try {
            $kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id]);
            if (!$kegiatan) return $saved_lampiran_id;

            // Cari Ketuplak & Sekretaris
            $kp_row = dbFetchOne("SELECT u.nama FROM kegiatan_panitia kp JOIN users u ON kp.user_id = u.id WHERE kp.kegiatan_id = ? AND kp.event_role IN ('ketuplat', 'ketua_pelaksana') LIMIT 1", [$kegiatan_id]);
            $ketuplat_nama = $kp_row ? strtoupper($kp_row['nama']) : '';

            $sek_row = dbFetchOne("SELECT u.nama FROM kegiatan_panitia kp JOIN users u ON kp.user_id = u.id WHERE kp.kegiatan_id = ? AND kp.event_role IN ('sekretaris_panitia', 'sekretaris', 'sekretaris_kegiatan', 'sekretaris_1', 'sekretaris_2') LIMIT 1", [$kegiatan_id]);
            $sekretaris_nama = $sek_row ? strtoupper($sek_row['nama']) : '';

            // Default Tujuan Sarpras
            $tujuan_sarpras = "Tim Sarpras INSTBUNAS Majalengka";
            $tpl_tujuan = dbFetchOne("SELECT isi_teks FROM surat_templates WHERE jenis = 'tujuan' AND (LOWER(label) LIKE '%sarpras%' OR LOWER(isi_teks) LIKE '%sarpras%') LIMIT 1");
            if ($tpl_tujuan && !empty($tpl_tujuan['isi_teks'])) {
                $tujuan_sarpras = strip_tags($tpl_tujuan['isi_teks']);
            }

            $kode_keg_surat = !empty($kegiatan['kode_kegiatan']) ? strtoupper(trim($kegiatan['kode_kegiatan'])) : 'SARPRAS';

            // Validate created_by user ID against users table to prevent FK integrity errors
            $created_by = NULL;
            if (!empty($admin_id)) {
                $user_chk = dbFetchOne("SELECT id FROM users WHERE id = ?", [(int)$admin_id]);
                if ($user_chk) $created_by = (int)$admin_id;
            }

            $existing_surat = dbFetchOne("SELECT id, nomor_surat, konten_surat FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging' AND (perihal LIKE '%Peminjaman%' OR perihal LIKE '%Sarpras%') ORDER BY id DESC LIMIT 1", [$kegiatan_id]);

            $konten_data = [
                'sapaan_tujuan'            => '',
                'nama_kegiatan'            => $kegiatan['nama_kegiatan'],
                'tema'                     => $kegiatan['deskripsi'] ?? '',
                'tema_kegiatan'            => $kegiatan['deskripsi'] ?? '',
                'pelaksanaan_hari_tanggal' => $tanggal,
                'pelaksanaan_waktu'        => '08.00 s.d Selesai',
                'pelaksanaan_tempat'       => 'Lingkungan Kampus INSTBUNAS Majalengka',
                'konteks'                  => 'untuk dapat menggunakan fasilitas tersebut',
                'label_panitia'            => '',
                'panitia_ketua'            => $ketuplat_nama,
                'panitia_sekretaris'       => $sekretaris_nama,
                'use_ttd_warek'            => '1',
                'use_ttd_presma'           => '1',
                'use_cap_panitia'          => '1',
                'use_cap_warek'            => '1',
                'use_cap_presma'           => '1',
                'lampiran_internal_ids'    => [(string)$saved_lampiran_id]
            ];

            if ($existing_surat) {
                $ex_konten = json_decode($existing_surat['konten_surat'], true) ?: [];
                $existing_ids = $ex_konten['lampiran_internal_ids'] ?? [];
                if (!in_array((string)$saved_lampiran_id, $existing_ids)) {
                    $existing_ids[] = (string)$saved_lampiran_id;
                }
                $konten_data['lampiran_internal_ids'] = array_values(array_unique($existing_ids));

                $nomor_surat_cur = $existing_surat['nomor_surat'];
                if (strpos($nomor_surat_cur, "/{$kode_keg_surat}/") === false) {
                    $last_seq = dbFetchOne("SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'D' AND (status_arsip IS NULL OR status_arsip != 'staging')", [$periode_id]);
                    $next_num = str_pad((($last_seq['max_urut'] ?? 0) + 1), 3, '0', STR_PAD_LEFT);
                    $romawi = ['1'=>'I', '2'=>'II', '3'=>'III', '4'=>'IV', '5'=>'V', '6'=>'VI', '7'=>'VII', '8'=>'VIII', '9'=>'IX', '10'=>'X', '11'=>'XI', '12'=>'XII'];
                    $b_rom = $romawi[(int)date('n')] ?? 'I';
                    $thn_now = date('Y');
                    $nomor_surat_cur = "{$next_num}/D/{$kode_keg_surat}/BEM/{$b_rom}/{$thn_now}";
                }

                dbQuery("UPDATE arsip_surat SET nomor_surat = ?, konten_surat = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$nomor_surat_cur, json_encode($konten_data), $existing_surat['id']]);
            } else {
                $last_seq = dbFetchOne("SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'D' AND (status_arsip IS NULL OR status_arsip != 'staging')", [$periode_id]);
                $next_num = str_pad((($last_seq['max_urut'] ?? 0) + 1), 3, '0', STR_PAD_LEFT);
                $romawi = ['1'=>'I', '2'=>'II', '3'=>'III', '4'=>'IV', '5'=>'V', '6'=>'VI', '7'=>'VII', '8'=>'VIII', '9'=>'IX', '10'=>'X', '11'=>'XI', '12'=>'XII'];
                $b_rom = $romawi[(int)date('n')] ?? 'I';
                $thn_now = date('Y');
                $nomor_surat_draft = "{$next_num}/D/{$kode_keg_surat}/BEM/{$b_rom}/{$thn_now}";

                dbQuery(
                    "INSERT INTO arsip_surat (periode_id, kegiatan_id, status_arsip, jenis_surat, nomor_surat, perihal, tujuan, tempat_tanggal, konten_surat, created_by) VALUES (?, ?, 'staging', 'D', ?, 'Permohonan Peminjaman Barang & Tempat', ?, ?, ?, ?)",
                    [
                        $periode_id,
                        $kegiatan_id,
                        $nomor_surat_draft,
                        $tujuan_sarpras,
                        'Majalengka, ' . tanggalIndonesia(),
                        json_encode($konten_data),
                        $created_by
                    ]
                );
                resyncStagingNumbers($periode_id);
            }
        } catch (Exception $e) {
            error_log("Error generating Sarpras letter: " . $e->getMessage());
        }
    }

    return $saved_lampiran_id;
}

/**
 * Generates OAuth2 Access Token for Google FCM HTTP v1 using Service Account JSON
 */
function getFcmOAuthAccessToken(string $jsonKeyPath): ?string {
    static $cachedToken = null;
    static $expiresAt = 0;

    if ($cachedToken && time() < ($expiresAt - 60)) {
        return $cachedToken;
    }

    if (!file_exists($jsonKeyPath)) {
        return null;
    }

    $sa = json_decode(file_get_contents($jsonKeyPath), true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
        return null;
    }

    $now = time();
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now
    ]);

    $base64UrlHeader = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $base64UrlPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    $signatureData = $base64UrlHeader . "." . $base64UrlPayload;
    $binarySignature = '';

    $success = openssl_sign($signatureData, $binarySignature, $sa['private_key'], 'SHA256');
    if (!$success) {
        return null;
    }

    $base64UrlSignature = rtrim(strtr(base64_encode($binarySignature), '+/', '-_'), '=');
    $jwt = $signatureData . "." . $base64UrlSignature;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $tokenData = json_decode($response, true);
    if (isset($tokenData['access_token'])) {
        $cachedToken = $tokenData['access_token'];
        $expiresAt = time() + ($tokenData['expires_in'] ?? 3600);
        return $cachedToken;
    }

    return null;
}

/**
 * Mendapatkan daftar ID user yang berhak menerima notifikasi berdasarkan System Role dan Event Role.
 */
function getTargetUserIdsByRole(array $systemRoles = [], ?int $kegiatanId = null, array $eventRoles = []): array {
    $userIds = [];

    // 1. Filter berdasarkan System Role (users.role)
    if (!empty($systemRoles)) {
        $inRoles = implode(',', array_fill(0, count($systemRoles), '?'));
        $rows = dbFetchAll(
            "SELECT id FROM users WHERE role IN ($inRoles) AND is_active = 1",
            $systemRoles
        );
        foreach ($rows as $r) {
            $userIds[] = (int)$r['id'];
        }
    }

    // 2. Filter berdasarkan Event-Level Role (kegiatan_panitia.event_role)
    if ($kegiatanId > 0 && !empty($eventRoles)) {
        $inEvRoles = implode(',', array_fill(0, count($eventRoles), '?'));
        $params = array_merge([$kegiatanId], $eventRoles);
        $rows = dbFetchAll(
            "SELECT user_id FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role IN ($inEvRoles)",
            $params
        );
        foreach ($rows as $r) {
            $userIds[] = (int)$r['user_id'];
        }
    }

    return array_values(array_unique(array_filter($userIds)));
}

/**
 * Pembersihan Otomatis: Hapus arsip notifikasi yang umurnya lebih dari X hari (Default: 7 hari).
 */
function cleanupOldNotifications(int $days = 7): void {
    try {
        $isMysql = (defined('DB_DRIVER') && DB_DRIVER === 'mysql') || (isset($GLOBALS['db_driver']) && $GLOBALS['db_driver'] === 'mysql');
        if (function_exists('dbGetDriver')) { $isMysql = (dbGetDriver() === 'mysql'); }
        
        $cleanupSql = $isMysql ? "NOW() - INTERVAL $days DAY" : "now() - INTERVAL '$days days'";
        dbQuery("DELETE FROM notifikasi WHERE created_at < $cleanupSql");
    } catch (Throwable $e) {
        error_log("cleanupOldNotifications Error: " . $e->getMessage());
    }
}

/**
 * Unified Dispatch: Simpan ke DB Notifikasi Web/Desktop & Mobile + Kirim Push Notification FCM
 */
function createNotificationAndPush($targetUserIds, string $title, string $body, ?string $link = null, string $type = 'info'): bool {
    try {
        $userIds = is_array($targetUserIds) ? $targetUserIds : [$targetUserIds];
        $userIds = array_filter(array_map('intval', $userIds));
        if (empty($userIds)) return false;

        // 1. Simpan ke Database Notifikasi (In-App Web & Mobile Archive)
        foreach ($userIds as $uid) {
            try {
                dbQuery(
                    "INSERT INTO notifikasi (user_id, judul, pesan, link, tipe) VALUES (?, ?, ?, ?, ?)",
                    [$uid, $title, $body, $link, $type]
                );
            } catch (Throwable $dbErr) {
                error_log("Notification DB Save Error: " . $dbErr->getMessage());
            }
        }

        // 2. Pembersihan otomatis untuk notifikasi > 7 hari (Probabilistik 1 dari 10 request)
        if (rand(1, 10) === 1) {
            cleanupOldNotifications(7);
        }

        // 3. Kirim FCM Push ke Perangkat Mobile
        $dataPayload = [];
        if ($link) {
            $dataPayload['click_action'] = $link;
        }
        sendFcmNotification($userIds, $title, $body, $dataPayload);

        return true;
    } catch (Throwable $e) {
        error_log("createNotificationAndPush Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Sends Push Notification via Firebase Cloud Messaging HTTP v1 API
 */
function sendFcmNotification($targetUserIds, string $title, string $body, array $dataPayload = []): bool {
    try {
        $userIds = is_array($targetUserIds) ? $targetUserIds : [$targetUserIds];
        $userIds = array_filter(array_map('intval', $userIds));
        if (empty($userIds)) return false;

        // Pastikan tersimpan di DB notifikasi juga untuk dipantau di web
        $link = $dataPayload['click_action'] ?? null;
        foreach ($userIds as $uid) {
            try {
                // Cek apakah sudah pernah diinsert dalam 5 detik terakhir agar tidak duplikat dengan createNotificationAndPush
                $existing = dbFetchOne(
                    "SELECT id FROM notifikasi WHERE user_id = ? AND pesan = ? AND created_at >= (NOW() - INTERVAL 5 SECOND) LIMIT 1",
                    [$uid, $body]
                );
                if (!$existing) {
                    dbQuery(
                        "INSERT INTO notifikasi (user_id, judul, pesan, link, tipe) VALUES (?, ?, ?, ?, 'info')",
                        [$uid, $title, $body, $link]
                    );
                }
            } catch (Throwable $eDb) {}
        }

        // Probabilistik Auto Clean Up Notifikasi > 7 hari
        if (rand(1, 10) === 1) {
            cleanupOldNotifications(7);
        }

        $inClause = implode(',', array_fill(0, count($userIds), '?'));
        $tokens = dbFetchAll(
            "SELECT DISTINCT fcm_token FROM fcm_tokens WHERE user_id IN ($inClause)",
            $userIds,
            str_repeat('i', count($userIds))
        );

        if (empty($tokens)) return false;

        $jsonKeyPath = __DIR__ . '/../config/firebase-service-account.json';
        $accessToken = getFcmOAuthAccessToken($jsonKeyPath);
        if (!$accessToken) return false;

        $sa = json_decode(file_get_contents($jsonKeyPath), true);
        $projectId = $sa['project_id'] ?? '';
        if (empty($projectId)) return false;

        $fcmUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $successCount = 0;
        foreach ($tokens as $tRow) {
            $fcmToken = $tRow['fcm_token'];
            $data = [];
            foreach ($dataPayload as $k => $v) {
                $data[(string)$k] = (string)$v;
            }
            if (!isset($data['click_action'])) {
                $data['click_action'] = '/admin/core/dashboard.php';
            }

            $message = [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body
                    ],
                    'data' => $data
                ]
            ];

            $ch = curl_init($fcmUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $successCount++;
            }
        }

        return $successCount > 0;
    } catch (Throwable $e) {
        error_log("FCM Send Error: " . $e->getMessage());
        return false;
    }
}

