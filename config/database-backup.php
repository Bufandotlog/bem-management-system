<?php
// config/database.php - Koneksi database (FIXED dbFetchAll)

// Konfigurasi database
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Ganti dengan user MariaDB Anda
define('DB_PASS', '057801'); // Ganti dengan password MariaDB Anda
define('DB_NAME', 'bem_astawidya');

// Membuat koneksi
function getConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        // Cek koneksi
        if ($conn->connect_error) {
            die("Koneksi database gagal: " . $conn->connect_error);
        }
        
        // Set charset
        $conn->set_charset("utf8mb4");
    }
    
    return $conn;
}

// Fungsi untuk query dengan prepared statement
function dbQuery($sql, $params = [], $types = "") {
    $conn = getConnection();
    
    if (empty($params)) {
        return $conn->query($sql);
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error prepare: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    return $stmt;
}

// Fungsi untuk mengambil satu baris
function dbFetchOne($sql, $params = [], $types = "") {
    $conn = getConnection();

    if (empty($params)) {
        $result = $conn->query($sql);
        return $result ? $result->fetch_assoc() : null;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error prepare: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $meta = $stmt->result_metadata();
    if (!$meta) return null;

    $row = [];
    $fields = [];

    while ($field = $meta->fetch_field()) {
        $fields[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $fields);
    $stmt->fetch();

    return $row;
}

// Fungsi untuk mengambil semua baris (FIXED VERSION)
function dbFetchAll($sql, $params = [], $types = "") {
    $conn = getConnection();

    // Tanpa parameter (query biasa)
    if (empty($params)) {
        $result = $conn->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    // Dengan prepared statement
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error prepare: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    // Ambil metadata
    $meta = $stmt->result_metadata();
    if (!$meta) return [];

    // Bind result
    $row = [];
    $fields = [];

    while ($field = $meta->fetch_field()) {
        $fields[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $fields);

    // Fetch semua baris
    $results = [];
    while ($stmt->fetch()) {
        // Copy row ke array baru agar tidak ketiban
        $newRow = [];
        foreach ($row as $key => $value) {
            $newRow[$key] = $value;
        }
        $results[] = $newRow;
    }

    return $results;
}

// Fungsi untuk insert dan return ID
function dbInsert($sql, $params = [], $types = "") {
    $stmt = dbQuery($sql, $params, $types);
    return $stmt->insert_id;
}
?>