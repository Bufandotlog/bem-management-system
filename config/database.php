<?php
declare(strict_types=1);

/**
 * config/database.php
 *
 * PDO database connection.
 *
 * Supported:
 * - MySQL / MariaDB
 * - PostgreSQL
 *
 * Konfigurasi database sepenuhnya berasal dari .env.
 * Tidak ada lagi deteksi localhost/production untuk menentukan database.
 */

// ============================================================
// 1. LOAD .ENV
// ============================================================

(function (): void {
    $candidates = [
        dirname(__DIR__) . '/.env',
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__, 3) . '/.env',
    ];

    foreach ($candidates as $envFile) {
        if (!is_file($envFile)) {
            continue;
        }

        $lines = file(
            $envFile,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Abaikan baris kosong dan komentar
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Hanya proses KEY=VALUE
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Hapus quote pembungkus
            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                    ($value[0] === "'" && $value[strlen($value) - 1] === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }

            /*
             * Environment eksternal memiliki prioritas.
             * Jangan menimpa $_ENV jika sudah disediakan oleh server.
             */
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }

        // Gunakan file .env pertama yang ditemukan.
        break;
    }
})();


// ============================================================
// 2. HELPER ENV
// ============================================================

/**
 * Ambil environment variable.
 *
 * Prioritas:
 * 1. $_ENV
 * 2. $_SERVER
 * 3. getenv()
 * 4. default
 */
$getEnv = static function (
    string $key,
    ?string $default = null
): ?string {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }

    $value = getenv($key);

    if ($value !== false) {
        return $value;
    }

    return $default;
};


// ============================================================
// 3. KONFIGURASI DATABASE
// ============================================================

/*
 * Gunakan nama variable standar dari .env:
 *
 * DB_CONNECTION
 * DB_HOST
 * DB_PORT
 * DB_DATABASE
 * DB_USERNAME
 * DB_PASSWORD
 *
 * DB_USER / DB_PASS / DB_NAME tetap didukung sebagai fallback
 * untuk kompatibilitas dengan konfigurasi lama.
 */

$dbConnection = strtolower(
    trim(
        (string) $getEnv('DB_CONNECTION', 'mysql')
    )
);

$dbHost = (string) $getEnv(
    'DB_HOST',
    '127.0.0.1'
);

$dbPort = (string) $getEnv(
    'DB_PORT',
    $dbConnection === 'pgsql' ? '5432' : '3306'
);

// Nama database modern
$dbName = $getEnv('DB_DATABASE');

// Fallback konfigurasi lama
if ($dbName === null || $dbName === '') {
    $dbName = $getEnv('DB_NAME', 'bem_astawidya');
}

// Username modern
$dbUser = $getEnv('DB_USERNAME');

// Fallback konfigurasi lama
if ($dbUser === null) {
    $dbUser = $getEnv('DB_USER', '');
}

// Password modern
$dbPass = $getEnv('DB_PASSWORD');

// Fallback konfigurasi lama
if ($dbPass === null) {
    $dbPass = $getEnv('DB_PASS', '');
}

$dbSslMode = $getEnv('DB_SSLMODE');

// getConnection() membaca dari $GLOBALS agar sslmode pgsql benar-benar diterapkan
if ($dbSslMode !== null && $dbSslMode !== '') {
    $GLOBALS['dbSslMode'] = $dbSslMode;
}


// ============================================================
// 4. VALIDASI DRIVER
// ============================================================

$allowedDrivers = [
    'mysql',
    'pgsql',
];

if (!in_array($dbConnection, $allowedDrivers, true)) {
    throw new RuntimeException(
        "DB_CONNECTION tidak valid: {$dbConnection}. " .
        "Driver yang didukung: mysql, pgsql."
    );
}


// ============================================================
// 5. DEFINISIKAN KONSTANTA UNTUK KOMPATIBILITAS
// ============================================================

defined('DB_CONNECTION') || define(
    'DB_CONNECTION',
    $dbConnection
);

defined('DB_HOST') || define(
    'DB_HOST',
    $dbHost
);

defined('DB_PORT') || define(
    'DB_PORT',
    $dbPort
);

defined('DB_USER') || define(
    'DB_USER',
    $dbUser
);

defined('DB_PASS') || define(
    'DB_PASS',
    $dbPass
);

defined('DB_NAME') || define(
    'DB_NAME',
    $dbName
);

defined('DB_DEBUG') || define(
    'DB_DEBUG',
    filter_var(
        $getEnv('DB_DEBUG', 'false'),
        FILTER_VALIDATE_BOOLEAN
    )
);


// ============================================================
// 6. BASE URL
// ============================================================
// [FIX v3.3] BASE_URL tidak didefinisikan di sini lagi agar tidak
// berkonflik dengan path-detection.php yang lebih cerdas (bisa
// mendeteksi subfolder project seperti /bpm/ atau /bem/).
// Definisi BASE_URL sekarang sepenuhnya ditangani oleh
// config/path-detection.php — panggil setelah .env ter-load.
// database.php tidak boleh mendefinisikan BASE_URL.


// ============================================================
// 7. DATABASE CONNECTION
// ============================================================

/**
 * Mendapatkan koneksi PDO.
 *
 * @return PDO
 * @throws RuntimeException
 */
function getConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $driver = DB_CONNECTION;

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];
        } elseif ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            if (
                isset($GLOBALS['dbSslMode']) &&
                $GLOBALS['dbSslMode'] !== null &&
                $GLOBALS['dbSslMode'] !== ''
            ) {
                $dsn .= ';sslmode=' . $GLOBALS['dbSslMode'];
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];
        } else {
            throw new RuntimeException(
                "Unsupported database driver: {$driver}"
            );
        }

        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            $options
        );

        return $pdo;

    } catch (PDOException $e) {
        error_log(
            '[DB CONNECT ERROR] ' . $e->getMessage()
        );

        throw new RuntimeException(
            'Koneksi DB gagal (' .
            DB_CONNECTION .
            '): ' .
            $e->getMessage(),
            0,
            $e
        );
    }
}


// ============================================================
// 8. QUERY HELPER
// ============================================================

/**
 * Jalankan prepared statement.
 *
 * @return PDOStatement
 */
function dbQuery(
    string $sql,
    array $params = [],
    string $types = ''
): PDOStatement {
    try {
        $pdo = getConnection();

        if (DB_DEBUG) {
            error_log('[DB QUERY] ' . $sql);

            if (!empty($params)) {
                error_log(
                    '[DB PARAMS] ' .
                    json_encode(
                        $params,
                        JSON_UNESCAPED_UNICODE
                    )
                );
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (DB_DEBUG) {
            error_log(
                '[DB SUCCESS] Query executed | row_count: ' .
                $stmt->rowCount()
            );
        }

        return $stmt;

    } catch (PDOException $e) {
        error_log(
            '[DB ERROR] ' .
            $e->getMessage() .
            ' | Query: ' .
            $sql
        );

        throw new RuntimeException(
            'DB Error: ' . $e->getMessage(),
            0,
            $e
        );
    }
}


// ============================================================
// 9. FETCH HELPERS
// ============================================================

/**
 * Ambil satu baris.
 */
function dbFetchOne(
    string $sql,
    array $params = [],
    string $types = ''
): ?array {
    $stmt = dbQuery($sql, $params, $types);

    $result = $stmt->fetch();

    return $result !== false ? $result : null;
}


/**
 * Ambil semua baris.
 */
function dbFetchAll(
    string $sql,
    array $params = [],
    string $types = ''
): array {
    $stmt = dbQuery($sql, $params, $types);

    return $stmt->fetchAll();
}


// ============================================================
// 10. INSERT / UPDATE / DELETE
// ============================================================

/**
 * Insert dan kembalikan ID.
 */
function dbInsert(
    string $sql,
    array $params = [],
    string $types = ''
): int {
    dbQuery($sql, $params, $types);

    return (int) getConnection()->lastInsertId();
}


/**
 * Update / Delete.
 */
function dbUpdate(
    string $sql,
    array $params = [],
    string $types = ''
): int {
    $stmt = dbQuery($sql, $params, $types);

    return $stmt->rowCount();
}


// ============================================================
// 11. BPH HELPER — resolve bph_id by posisi + periode
// ============================================================

/**
 * Dapatkan (atau create-on-the-fly) bph_id dari struktur_bph
 * berdasarkan posisi + periode_id.
 *
 * Bug lama di admin/konten/pendaftaran.php menghardcode bph_id=1/2/3
 * berdasarkan string jabatan, yang dapat melanggar FK anggota_bph.bph_id
 * → struktur_bph.id bila ID tersebut tidak ada.
 *
 * Solusi: query by posisi (canonical key di struktur_bph) dan create
 * bila belum ada, konsisten dengan pola di admin/konten/kepengurusan-edit.php
 * yang query: "SELECT * FROM struktur_bph WHERE posisi=? AND periode_id=?".
 *
 * @param string $posisi    'ketua'|'wakil_ketua'|'sekretaris_umum'|'bendahara_umum'
 * @param int    $periode_id
 * @return int bph_id yang valid dan ada di struktur_bph
 */
function getOrCreateBphByPosisi(
    string $posisi,
    int    $periode_id
): int {
    // Cari header BPH yang sudah ada
    $existing = dbFetchOne(
        "SELECT id FROM struktur_bph WHERE posisi = ? AND periode_id = ?",
        [$posisi, $periode_id],
        "si"
    );

    if ($existing) {
        return (int) $existing['id'];
    }

    // Header belum ada → create on-the-fly agar FK anggota_bph valid
    $judul_map = [
        'ketua'             => 'Ketua BEM',
        'wakil_ketua'       => 'Wakil Ketua BEM',
        'sekretaris_umum'   => 'Sekretaris Umum BEM',
        'bendahara_umum'    => 'Bendahara Umum BEM',
    ];

    $nama_default  = $judul_map[$posisi] ?? $posisi;
    $jabatan_default = $nama_default;

    $bph_id = dbInsert(
        "INSERT INTO struktur_bph
            (periode_id, created_by, posisi, nama, jabatan, urutan)
         VALUES
            (?, ?, ?, ?, ?, 0)",
        [$periode_id, $_SESSION['admin_id'] ?? null, $posisi, $nama_default, $jabatan_default],
        "iisss"
    );

    auditLog('INSERT', 'struktur_bph', $bph_id, "Auto-create BPH header: $nama_default (pending approval via kepengurusan-edit.php)");

    return $bph_id;
}


// ============================================================
// 12. UPSERT PENGATURAN
// ============================================================

/**
 * Upsert tabel pengaturan.
 *
 * MySQL:
 *   REPLACE INTO
 *
 * PostgreSQL:
 *   ON CONFLICT
 */
function dbUpsertPengaturan(
    string $kunci,
    string $nilai
) {
    if (DB_CONNECTION === 'pgsql') {
        return dbQuery(
            '
            INSERT INTO pengaturan (kunci, nilai)
            VALUES (?, ?)
            ON CONFLICT (kunci)
            DO UPDATE SET nilai = EXCLUDED.nilai
            ',
            [$kunci, $nilai]
        );
    }

    return dbQuery(
        '
        INSERT INTO pengaturan (kunci, nilai)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            nilai = VALUES(nilai)
        ',
        [$kunci, $nilai]
    );
}


// ============================================================
// 12. HELPER LAINNYA
// ============================================================

/**
 * Kompatibilitas dengan kode lama.
 */
function dbError(): string
{
    return '';
}


/**
 * ID terakhir.
 */
function dbLastId(): int
{
    return (int) getConnection()->lastInsertId();
}


/**
 * Escape string.
 */
function dbEscape(string $string): string
{
    return getConnection()->quote($string);
}


/**
 * Mulai transaksi.
 */
function dbBeginTransaction(): void
{
    getConnection()->beginTransaction();
}


/**
 * Commit transaksi.
 */
function dbCommit(): void
{
    getConnection()->commit();
}


/**
 * Rollback transaksi.
 */
function dbRollback(): void
{
    getConnection()->rollBack();
}
