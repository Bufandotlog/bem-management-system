-- ============================================
-- Backup Database: BEM Management System (POSTGRESQL)
-- Versi: 1.1 (Template Lengkap + Modul Baru)
-- Tanggal: 2026-05-04 07:02:03 WIB
-- ============================================

-- ----------------------------------------
-- 1. Tabel `periode_kepengurusan`
-- ----------------------------------------

DROP TABLE IF EXISTS "periode_kepengurusan" CASCADE;
CREATE TABLE "periode_kepengurusan" (
  "id" SERIAL PRIMARY KEY,
  "nama" VARCHAR(100) NOT NULL,
  "tahun_mulai" INTEGER NOT NULL,
  "tahun_selesai" INTEGER NOT NULL,
  "is_active" BOOLEAN DEFAULT FALSE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data awal untuk periode
INSERT INTO "periode_kepengurusan" ("nama", "tahun_mulai", "tahun_selesai", "is_active") VALUES
('BEM ASTAWIDYA', 2026, 2027, TRUE);

-- ----------------------------------------
-- 2. Tabel `users`
-- ----------------------------------------

DROP TABLE IF EXISTS "users" CASCADE;
CREATE TABLE "users" (
  "id" SERIAL PRIMARY KEY,
  "username" VARCHAR(50) NOT NULL UNIQUE,
  "password" VARCHAR(255) NOT NULL,
  "nama" VARCHAR(100) NOT NULL,
  "email" VARCHAR(100),
  "role" VARCHAR(20) NOT NULL DEFAULT 'kominfo' CHECK ("role" IN ('superadmin', 'admin', 'kominfo', 'sekretaris')),
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE SET NULL,
  "can_access_all" BOOLEAN DEFAULT FALSE,
  "totp_secret" VARCHAR(32),
  "totp_enabled" BOOLEAN DEFAULT FALSE,
  "is_active" BOOLEAN DEFAULT TRUE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Data awal untuk superadmin (password: admin1234)
INSERT INTO "users" ("username", "password", "nama", "email", "role", "can_access_all", "is_active", "periode_id") VALUES
('superadmin', '$2y$12$R.S9vG0h1D3rX9Y9E.W6Y.f7.G0h1D3rX9Y9E.W6Y.f7.G0h1D3r', 'Super Administrator', 'admin@bem.com', 'superadmin', TRUE, TRUE, 1);

-- ----------------------------------------
-- 3. Tabel `kabinet`
-- ----------------------------------------

DROP TABLE IF EXISTS "kabinet" CASCADE;
CREATE TABLE "kabinet" (
  "id" SERIAL PRIMARY KEY,
  "nama" VARCHAR(100) NOT NULL,
  "arti" VARCHAR(200),
  "tahun_mulai" INTEGER,
  "tahun_selesai" INTEGER,
  "logo" VARCHAR(255),
  "foto_bersama" VARCHAR(255),
  "deskripsi" TEXT
);

INSERT INTO "kabinet" ("nama", "arti", "tahun_mulai", "tahun_selesai") VALUES
('ASTAWIDYA', 'Delapan Arah Kejayaan', 2026, 2027);

-- ----------------------------------------
-- 4. Tabel `struktur_bph`
-- ----------------------------------------

DROP TABLE IF EXISTS "struktur_bph" CASCADE;
CREATE TABLE "struktur_bph" (
  "id" SERIAL PRIMARY KEY,
  "nama_divisi" VARCHAR(100) NOT NULL,
  "urutan" INTEGER DEFAULT 0
);

INSERT INTO "struktur_bph" ("nama_divisi", "urutan") VALUES
('Inti / Pimpinan', 1),
('Sekretariat', 2),
('Kebendaharaan', 3);

-- ----------------------------------------
-- 5. Tabel `anggota_bph`
-- ----------------------------------------

DROP TABLE IF EXISTS "anggota_bph" CASCADE;
CREATE TABLE "anggota_bph" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "bph_id" INTEGER NOT NULL REFERENCES "struktur_bph"("id") ON DELETE CASCADE,
  "nama" VARCHAR(100) NOT NULL,
  "jabatan" VARCHAR(100) NOT NULL,
  "foto" VARCHAR(255),
  "urutan" INTEGER DEFAULT 0,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 6. Tabel `kementerian`
-- ----------------------------------------

DROP TABLE IF EXISTS "kementerian" CASCADE;
CREATE TABLE "kementerian" (
  "id" SERIAL PRIMARY KEY,
  "nama_kementerian" VARCHAR(100) NOT NULL,
  "deskripsi" TEXT,
  "fungsi" TEXT,
  "urutan" INTEGER DEFAULT 0,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 7. Tabel `anggota_kementerian`
-- ----------------------------------------

DROP TABLE IF EXISTS "anggota_kementerian" CASCADE;
CREATE TABLE "anggota_kementerian" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "kementerian_id" INTEGER NOT NULL REFERENCES "kementerian"("id") ON DELETE CASCADE,
  "nama" VARCHAR(100) NOT NULL,
  "jabatan" VARCHAR(100) NOT NULL,
  "foto" VARCHAR(255),
  "urutan" INTEGER DEFAULT 0,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 8. Tabel `arsip_rundown`
-- ----------------------------------------

DROP TABLE IF EXISTS "arsip_rundown" CASCADE;
CREATE TABLE "arsip_rundown" (
  "id" SERIAL PRIMARY KEY,
  "nama_acara" VARCHAR(255) NOT NULL,
  "tahun" VARCHAR(50) NOT NULL,
  "tanggal_mulai" DATE NOT NULL,
  "durasi_hari" INTEGER NOT NULL DEFAULT 1,
  "rundown_json" TEXT NOT NULL,
  "periode_id" INTEGER NOT NULL REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 9. Tabel `arsip_surat`
-- ----------------------------------------

DROP TABLE IF EXISTS "arsip_surat" CASCADE;
CREATE TABLE "arsip_surat" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "jenis_surat" CHAR(1) NOT NULL,
  "nomor_surat" VARCHAR(255) NOT NULL,
  "tanggal_dikirim" DATE,
  "perihal" VARCHAR(255) NOT NULL,
  "tujuan" TEXT NOT NULL,
  "tempat_tanggal" VARCHAR(100),
  "lampiran" VARCHAR(100),
  "konten_surat" TEXT,
  "file_surat" VARCHAR(255),
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 10. Tabel `audit_log`
-- ----------------------------------------

DROP TABLE IF EXISTS "audit_log" CASCADE;
CREATE TABLE "audit_log" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER,
  "username" VARCHAR(100),
  "action" VARCHAR(50) NOT NULL,
  "target_table" VARCHAR(100),
  "target_id" INTEGER,
  "deskripsi" TEXT,
  "ip_address" VARCHAR(45),
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 11. Master Tables
-- ----------------------------------------

DROP TABLE IF EXISTS "barang_master" CASCADE;
CREATE TABLE "barang_master" (
  "id" SERIAL PRIMARY KEY,
  "nama_barang" VARCHAR(255) NOT NULL,
  "satuan" VARCHAR(50) DEFAULT 'pcs',
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "tempat_master" CASCADE;
CREATE TABLE "tempat_master" (
  "id" SERIAL PRIMARY KEY,
  "nama_tempat" VARCHAR(255) NOT NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "keterangan_master" CASCADE;
CREATE TABLE "keterangan_master" (
  "id" SERIAL PRIMARY KEY,
  "isi_keterangan" TEXT NOT NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "penanggung_jawab_master" CASCADE;
CREATE TABLE "penanggung_jawab_master" (
  "id" SERIAL PRIMARY KEY,
  "nama_pj" VARCHAR(255) NOT NULL,
  "jabatan_pj" VARCHAR(100),
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 12. CMS & Media Tables
-- ----------------------------------------

DROP TABLE IF EXISTS "berita" CASCADE;
CREATE TABLE "berita" (
  "id" SERIAL PRIMARY KEY,
  "judul" VARCHAR(255) NOT NULL,
  "slug" VARCHAR(255) NOT NULL UNIQUE,
  "tanggal" DATE NOT NULL,
  "penulis" VARCHAR(100) NOT NULL,
  "konten" TEXT NOT NULL,
  "gambar" VARCHAR(255),
  "status" VARCHAR(20) DEFAULT 'draft' CHECK ("status" IN ('draft', 'published')),
  "periode_id" INTEGER NOT NULL REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "struktur_organisasi" CASCADE;
CREATE TABLE "struktur_organisasi" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER NOT NULL REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "judul" VARCHAR(255) NOT NULL,
  "gambar" VARCHAR(255),
  "deskripsi" TEXT,
  "updated_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 13. System Tables
-- ----------------------------------------

DROP TABLE IF EXISTS "user_sessions" CASCADE;
CREATE TABLE "user_sessions" (
  "id" VARCHAR(128) PRIMARY KEY,
  "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
  "ip_address" VARCHAR(45),
  "user_agent" TEXT,
  "last_active" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "notifikasi" CASCADE;
CREATE TABLE "notifikasi" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
  "tipe" VARCHAR(50) DEFAULT 'info',
  "pesan" TEXT NOT NULL,
  "link" VARCHAR(255),
  "is_read" BOOLEAN DEFAULT FALSE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "signatures" CASCADE;
CREATE TABLE "signatures" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL REFERENCES "users"("id") ON DELETE CASCADE,
  "file_path" VARCHAR(255) NOT NULL,
  "is_digital" BOOLEAN DEFAULT FALSE,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS "short_links" CASCADE;
CREATE TABLE "short_links" (
  "id" SERIAL PRIMARY KEY,
  "target_url" TEXT NOT NULL,
  "short_code" VARCHAR(50) NOT NULL UNIQUE,
  "clicks" INTEGER DEFAULT 0,
  "created_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 14. Tabel `lpj_dokumen`
-- ----------------------------------------

DROP TABLE IF EXISTS "lpj_dokumen" CASCADE;
CREATE TABLE "lpj_dokumen" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER NOT NULL REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "kementerian_id" INTEGER NOT NULL REFERENCES "kementerian"("id") ON DELETE CASCADE,
  "triwulan" VARCHAR(50) NOT NULL,
  "status" VARCHAR(50) NOT NULL DEFAULT 'draft',
  "keanggotaan" TEXT,
  "keadaan_objektif" TEXT,
  "proker_terlaksana" TEXT,
  "proker_belum_terlaksana" TEXT,
  "anggaran" TEXT,
  "dokumentasi" TEXT,
  "file_path" VARCHAR(255),
  "evaluasi_kinerja_pribadi" TEXT,
  "evaluasi_anggota_internal" TEXT,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------
-- 16. Tabel `arsip_berita_acara`
-- ----------------------------------------

DROP TABLE IF EXISTS "arsip_berita_acara" CASCADE;
CREATE TABLE "arsip_berita_acara" (
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
);

-- ----------------------------------------
-- 17. Views untuk First Normal Form (1NF)
-- ----------------------------------------

-- View untuk Program Kerja LPJ (v_lpj_proker) dengan optimasi 3NF (COALESCE dari Berita Acara)
CREATE OR REPLACE VIEW v_lpj_proker AS
SELECT 
    d.id AS lpj_id,
    d.periode_id,
    d.kementerian_id,
    d.triwulan,
    'terlaksana'::varchar(16) AS tipe_proker,
    jt."Nama Program Kerja" AS nama_proker,
    COALESCE(ba.nama_kegiatan, jt."Nama Kegiatan") AS nama_kegiatan,
    COALESCE(ba.tempat, jt."Tempat Kegiatan") AS tempat_kegiatan,
    jt."Sifat" AS sifat,
    jt."Tema Kegiatan" AS tema_kegiatan,
    COALESCE(ba.tanggal_kegiatan, jt."Tanggal Kegiatan") AS tanggal_kegiatan,
    jt."Penanggung Jawab" AS penanggung_jawab,
    jt.berita_acara_id
FROM lpj_dokumen d
CROSS JOIN LATERAL json_to_recordset(NULLIF(d.proker_terlaksana, '')::json) AS jt(
    "Nama Program Kerja" VARCHAR(255),
    "Nama Kegiatan" VARCHAR(255),
    "Tempat Kegiatan" VARCHAR(255),
    "Sifat" VARCHAR(50),
    "Tema Kegiatan" VARCHAR(255),
    "Tanggal Kegiatan" VARCHAR(100),
    "Penanggung Jawab" VARCHAR(100),
    "berita_acara_id" INT
)
LEFT JOIN arsip_berita_acara ba ON jt.berita_acara_id = ba.id
UNION ALL
SELECT 
    d.id AS lpj_id,
    d.periode_id,
    d.kementerian_id,
    d.triwulan,
    'belum_terlaksana'::varchar(16) AS tipe_proker,
    jt."Nama Program Kerja" AS nama_proker,
    NULL::varchar(255) AS nama_kegiatan,
    NULL::varchar(255) AS tempat_kegiatan,
    jt."Sifat" AS sifat,
    jt."Tema Kegiatan" AS tema_kegiatan,
    NULL::varchar(100) AS tanggal_kegiatan,
    jt."Penanggung Jawab" AS penanggung_jawab,
    NULL::integer AS berita_acara_id
FROM lpj_dokumen d,
LATERAL json_to_recordset(NULLIF(d.proker_belum_terlaksana, '')::json) AS jt(
    "Nama Program Kerja" VARCHAR(255),
    "Sifat" VARCHAR(50),
    "Tema Kegiatan" VARCHAR(255),
    "Penanggung Jawab" VARCHAR(100)
);

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
FROM lpj_dokumen d,
LATERAL json_to_recordset(NULLIF(json_extract_path_text(NULLIF(d.anggaran, '')::json, 'transaksi'), '')::json) AS jt(
    jenis VARCHAR(50),
    kategori VARCHAR(100),
    keterangan VARCHAR(255),
    jumlah DECIMAL(15,2),
    total DECIMAL(15,2)
);

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
    jt.pj AS penanggung_jawab,
    jt.keterangan
FROM arsip_rundown r,
LATERAL json_to_recordset(NULLIF(r.rundown_json, '')::json) AS jt(
    hari INT,
    waktu VARCHAR(50),
    agenda VARCHAR(255),
    pj VARCHAR(100),
    keterangan TEXT
);


