<?php
require_once __DIR__ . '/includes/functions.php';
try {
    dbQuery("
        CREATE TABLE IF NOT EXISTS arsip_dokumentasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kegiatan_id INT NOT NULL,
            periode_id INT NOT NULL,
            foto_1 VARCHAR(255) DEFAULT NULL,
            caption_1 VARCHAR(255) DEFAULT NULL,
            foto_2 VARCHAR(255) DEFAULT NULL,
            caption_2 VARCHAR(255) DEFAULT NULL,
            foto_3 VARCHAR(255) DEFAULT NULL,
            caption_3 VARCHAR(255) DEFAULT NULL,
            foto_4 VARCHAR(255) DEFAULT NULL,
            caption_4 VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "Tabel arsip_dokumentasi berhasil dibuat.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
