<?php
// config/database.php - Koneksi database dengan PDO (PostgreSQL/Supabase)
// VERSI: 4.0 - Migrasi ke Supabase (PostgreSQL) menggunakan PDO

// ============================================
// 1. LOAD KREDENSIAL DARI .env
// ============================================
(function () {
    $candidates = [
        dirname(__DIR__, 1) . '/.env',
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__, 3) . '/.env',
    ];

    foreach ($candidates as $envFile) {
        if (!file_exists($envFile)) continue;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            
            // Hapus tanda kutip jika ada (misal: "password" -> password)
            $val = trim($val, "\"'");
            
            // Jangan override jika sudah ada di $_ENV atau $_SERVER (prioritas eksternal)
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
            }
        }
        break;
    }
})();

// ============================================
// 2. DETEKSI LINGKUNGAN & KONSTANTA DATABASE
// ============================================

// Deteksi apakah berjalan di localhost atau server produksi
$is_local = (
    ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' || 
    ($_SERVER['REMOTE_ADDR'] ?? '') === '::1' || 
    ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' ||
    (isset($_SERVER['HTTP_HOST']) && substr($_SERVER['HTTP_HOST'], -6) === '.local') ||
    (isset($_SERVER['HTTP_HOST']) && substr($_SERVER['HTTP_HOST'], -5) === '.test') ||
    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '192.168') !== false)
);

// Gunakan APP_ENV dari .env jika ada
if (isset($_ENV['APP_ENV'])) {
    $is_local = ($_ENV['APP_ENV'] === 'development');
}

if ($is_local) {
    // --- KONFIGURASI LOKAL (POSTGRESQL) ---
    // Prioritaskan dari .env, jika tidak ada baru gunakan default (Supabase)
    defined('DB_CONNECTION') || define('DB_CONNECTION', $_ENV['DB_CONNECTION'] ?? 'pgsql');
    defined('DB_HOST')       || define('DB_HOST',       $_ENV['DB_HOST']       ?? 'aws-1-ap-northeast-2.pooler.supabase.com');
    defined('DB_PORT')       || define('DB_PORT',       $_ENV['DB_PORT']       ?? '6543');
    defined('DB_USER')       || define('DB_USER',       $_ENV['DB_USER']       ?? 'postgres.prskplzwcdnzdrdszkzy');
    defined('DB_PASS')       || define('DB_PASS',       $_ENV['DB_PASS']       ?? 'Bem Budi Utomo Nasional');
    defined('DB_NAME')       || define('DB_NAME',       $_ENV['DB_NAME']       ?? 'postgres');
    
    // BASE_URL akan di-handle oleh path-detection.php via resolveBaseUrl()
    // Namun kita beri fallback jika dipanggil sebelum path-detection
    defined('BASE_URL')      || define('BASE_URL',      $_ENV['BASE_URL']      ?? 'http://localhost/bem/');
} else {
    // --- KONFIGURASI PRODUKSI (MYSQL) ---
    defined('DB_CONNECTION') || define('DB_CONNECTION', $_ENV['DB_CONNECTION'] ?? 'mysql');
    defined('DB_HOST')       || define('DB_HOST',       $_ENV['DB_HOST']       ?? 'sql213.infinityfree.com');
    defined('DB_PORT')       || define('DB_PORT',       $_ENV['DB_PORT']       ?? '3306');
    defined('DB_USER')       || define('DB_USER',       $_ENV['DB_USER']       ?? 'if0_41167793');
    defined('DB_PASS')       || define('DB_PASS',       $_ENV['DB_PASS']       ?? 'rtmiqtTCfJo');
    defined('DB_NAME')       || define('DB_NAME',       $_ENV['DB_NAME']       ?? 'if0_41167793_bem_astawidya');
    
    // Otomatis deteksi domain di server jika tidak ada di .env
    if (!defined('BASE_URL')) {
        if (isset($_ENV['BASE_URL'])) {
            define('BASE_URL', $_ENV['BASE_URL']);
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            define('BASE_URL', $protocol . $domain . '/');
        }
    }
}

// ============================================
// 3. MODE DEBUG
// ============================================
defined('DB_DEBUG') || define('DB_DEBUG', false);

// ============================================
// 4. KONEKSI DATABASE (Hybrid MySQL/PostgreSQL)
// ============================================

/**
 * Mendapatkan koneksi database (singleton per request).
 * Mendukung driver mysql dan pgsql secara dinamis.
 *
 * @return PDO
 * @throws RuntimeException jika koneksi gagal
 */
function getConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $driver = strtolower(DB_CONNECTION);
            
            if ($driver === 'pgsql') {
                // Konfigurasi untuk PostgreSQL (Supabase)
                $sslMode = $_ENV['DB_SSLMODE'] ?? null;
                $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", DB_HOST, DB_PORT, DB_NAME);
                if ($sslMode) {
                    $dsn .= ";sslmode=" . $sslMode;
                }
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ];
            } else {
                // Konfigurasi untuk MySQL (InfinityFree)
                $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", DB_HOST, DB_PORT, DB_NAME);
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("[DB CONNECT ERROR] " . $e->getMessage());

            // Tampilkan pesan error asli untuk mempermudah debugging di InfinityFree
            throw new RuntimeException("Koneksi DB gagal (" . DB_CONNECTION . "): " . $e->getMessage());
        }
    }

    return $pdo;
}

// ============================================
// 5. QUERY HELPER
// ============================================

/**
 * Jalankan query dengan prepared statement.
 *
 * @param  string $sql
 * @param  array  $params
 * @param  string $types (Deprecated, kept for compatibility)
 * @return PDOStatement|bool
 * @throws RuntimeException
 */
function dbQuery(string $sql, array $params = [], string $types = "") {
    $pdo = getConnection();
    
    if (DB_DEBUG) {
        error_log("[DB QUERY] " . $sql);
        if (!empty($params)) {
            error_log("[DB PARAMS] " . json_encode($params));
        }
    }
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if (DB_DEBUG) {
            error_log("[DB SUCCESS] Query executed | row_count: " . $stmt->rowCount());
        }
        
        return $stmt;
    } catch (PDOException $e) {
        error_log("[DB ERROR] " . $e->getMessage() . " | Query: " . $sql);
        throw new RuntimeException("DB Error: " . $e->getMessage());
    }
}

/**
 * Ambil satu baris dari hasil query.
 *
 * @param  string     $sql
 * @param  array      $params
 * @param  string     $types
 * @return array|null
 */
function dbFetchOne(string $sql, array $params = [], string $types = ""): ?array {
    $stmt = dbQuery($sql, $params, $types);
    if (!$stmt) return null;
    
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Ambil semua baris dari hasil query.
 *
 * @param  string $sql
 * @param  array  $params
 * @param  string $types
 * @return array
 */
function dbFetchAll(string $sql, array $params = [], string $types = ""): array {
    $stmt = dbQuery($sql, $params, $types);
    if (!$stmt) return [];
    
    return $stmt->fetchAll();
}

/**
 * Insert dan kembalikan ID baru.
 *
 * @return int
 */
function dbInsert(string $sql, array $params = [], string $types = ""): int {
    dbQuery($sql, $params, $types);
    $id = getConnection()->lastInsertId();
    
    if (DB_DEBUG) error_log("[DB INSERT] ID: " . $id);
    return (int) $id;
}

/**
 * Update/Delete dan kembalikan jumlah baris terpengaruh.
 *
 * @return int
 */
function dbUpdate(string $sql, array $params = [], string $types = ""): int {
    $stmt = dbQuery($sql, $params, $types);
    if ($stmt) {
        $affected = $stmt->rowCount();
        if (DB_DEBUG) error_log("[DB UPDATE] Affected rows: " . $affected);
        return (int) $affected;
    }
    return 0;
}

/**
 * Upsert khusus untuk tabel pengaturan (Key-Value).
 * Mendukung MySQL (InfinityFree) dan PostgreSQL (Local/Supabase).
 */
function dbUpsertPengaturan(string $kunci, string $nilai) {
    if (DB_CONNECTION === 'pgsql') {
        return dbQuery("INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON CONFLICT (kunci) DO UPDATE SET nilai = EXCLUDED.nilai", [$kunci, $nilai], "ss");
    } else {
        // MySQL / MariaDB
        return dbQuery("REPLACE INTO pengaturan (kunci, nilai) VALUES (?, ?)", [$kunci, $nilai], "ss");
    }
}

// ============================================
// 6. FUNGSI HELPER
// ============================================

/** Kembalikan pesan error terakhir (dummy for PDO as it uses exceptions) */
function dbError(): string {
    return "";
}

/** Kembalikan ID terakhir yang di-insert */
function dbLastId(): int {
    return (int) getConnection()->lastInsertId();
}

/**
 * Escape string untuk query.
 * Untuk PDO, gunakan quote().
 */
function dbEscape(string $string): string {
    return getConnection()->quote($string);
}

/** Mulai transaksi */
function dbBeginTransaction(): void {
    getConnection()->beginTransaction();
}

/** Commit transaksi */
function dbCommit(): void {
    getConnection()->commit();
}

/** Rollback transaksi */
function dbRollback(): void {
    getConnection()->rollback();
}