-- View untuk Program Kerja LPJ (v_lpj_proker) dengan optimasi 3NF (COALESCE dari Berita Acara)
CREATE OR REPLACE VIEW v_lpj_proker AS
SELECT 
    d.id AS lpj_id,
    d.periode_id,
    d.kementerian_id,
    d.triwulan,
    _utf8mb4'terlaksana' COLLATE utf8mb4_uca1400_ai_ci AS tipe_proker,
    jt.nama_proker,
    COALESCE(ba.nama_kegiatan, jt.nama_kegiatan) COLLATE utf8mb4_uca1400_ai_ci AS nama_kegiatan,
    COALESCE(ba.tempat, jt.tempat_kegiatan) COLLATE utf8mb4_uca1400_ai_ci AS tempat_kegiatan,
    jt.sifat,
    jt.tema_kegiatan,
    COALESCE(ba.tanggal_kegiatan, jt.tanggal_kegiatan) COLLATE utf8mb4_uca1400_ai_ci AS tanggal_kegiatan,
    jt.penanggung_jawab,
    jt.berita_acara_id
FROM lpj_dokumen d
JOIN JSON_TABLE(
    d.proker_terlaksana,
    '$[*]' COLUMNS(
        nama_proker VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Program Kerja"',
        nama_kegiatan VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Kegiatan"',
        tempat_kegiatan VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tempat Kegiatan"',
        sifat VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Sifat"',
        tema_kegiatan VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tema Kegiatan"',
        tanggal_kegiatan VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tanggal Kegiatan"',
        penanggung_jawab VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Penanggung Jawab"',
        berita_acara_id INT PATH '$."berita_acara_id"'
    )
) jt
LEFT JOIN arsip_berita_acara ba ON jt.berita_acara_id = ba.id
UNION ALL
SELECT 
    d.id AS lpj_id,
    d.periode_id,
    d.kementerian_id,
    d.triwulan,
    _utf8mb4'belum_terlaksana' COLLATE utf8mb4_uca1400_ai_ci AS tipe_proker,
    jt.nama_proker,
    CAST(NULL AS CHAR(255)) COLLATE utf8mb4_uca1400_ai_ci AS nama_kegiatan,
    CAST(NULL AS CHAR(255)) COLLATE utf8mb4_uca1400_ai_ci AS tempat_kegiatan,
    jt.sifat,
    jt.tema_kegiatan,
    CAST(NULL AS CHAR(100)) COLLATE utf8mb4_uca1400_ai_ci AS tanggal_kegiatan,
    jt.penanggung_jawab,
    NULL AS berita_acara_id
FROM lpj_dokumen d
JOIN JSON_TABLE(
    d.proker_belum_terlaksana,
    '$[*]' COLUMNS(
        nama_proker VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Program Kerja"',
        sifat VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Sifat"',
        tema_kegiatan VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tema Kegiatan"',
        penanggung_jawab VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Penanggung Jawab"'
    )
) jt;

-- View untuk Anggaran LPJ (v_lpj_anggaran)
CREATE OR REPLACE VIEW v_lpj_anggaran AS
SELECT 
    d.id AS lpj_id,
    d.periode_id,
    d.kementerian_id,
    jt.jenis,
    jt.kategori,
    jt.keterangan,
    jt.jumlah,
    jt.total
FROM lpj_dokumen d
JOIN JSON_TABLE(
    d.anggaran,
    '$.transaksi[*]' COLUMNS(
        jenis VARCHAR(50) PATH '$.jenis',
        kategori VARCHAR(100) PATH '$.kategori',
        keterangan VARCHAR(255) PATH '$.keterangan',
        jumlah DECIMAL(15,2) PATH '$.jumlah',
        total DECIMAL(15,2) PATH '$.total'
    )
) jt;

-- View untuk Rundown Acara (v_rundown_acara)
CREATE OR REPLACE VIEW v_rundown_acara AS
SELECT 
    r.id AS rundown_id,
    r.periode_id,
    r.nama_acara,
    r.tahun,
    r.tanggal_mulai,
    jt.hari,
    jt.waktu,
    jt.agenda,
    jt.penanggung_jawab,
    jt.keterangan
FROM arsip_rundown r
JOIN JSON_TABLE(
    r.rundown_json,
    '$[*]' COLUMNS(
        hari INT PATH '$.hari',
        waktu VARCHAR(50) PATH '$.waktu',
        agenda VARCHAR(255) PATH '$.agenda',
        penanggung_jawab VARCHAR(100) PATH '$.pj',
        keterangan TEXT PATH '$.keterangan'
    )
) jt;
