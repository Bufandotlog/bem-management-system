-- ============================================
-- Backup Database: BEM Management System (MYSQL)
-- Versi: 1.1 (Template Lengkap + Modul Baru)
-- Tanggal: 2026-05-04 07:02:03 WIB
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+07:00";

-- ----------------------------------------
-- 1. Tabel `periode_kepengurusan`
-- ----------------------------------------

DROP TABLE IF EXISTS `periode_kepengurusan`;
CREATE TABLE `periode_kepengurusan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `tahun_mulai` int(4) NOT NULL,
  `tahun_selesai` int(4) NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data awal untuk periode
INSERT INTO `periode_kepengurusan` (`id`, `nama`, `tahun_mulai`, `tahun_selesai`, `is_active`) VALUES
(1, 'BEM ASTAWIDYA', 2026, 2027, 1);

-- ----------------------------------------
-- 2. Tabel `users`
-- ----------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('superadmin','admin','kominfo','sekretaris') NOT NULL DEFAULT 'kominfo',
  `periode_id` int(11) DEFAULT NULL,
  `can_access_all` tinyint(1) DEFAULT 0,
  `totp_secret` varchar(32) DEFAULT NULL,
  `totp_enabled` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `periode_id` (`periode_id`),
  CONSTRAINT `fk_users_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data awal untuk superadmin (password: admin1234)
INSERT INTO `users` (`id`, `username`, `password`, `nama`, `email`, `role`, `can_access_all`, `is_active`) VALUES
(1, 'superadmin', '$2y$12$R.S9vG0h1D3rX9Y9E.W6Y.f7.G0h1D3rX9Y9E.W6Y.f7.G0h1D3r', 'Super Administrator', 'admin@bem.com', 'superadmin', 1, 1);

-- ----------------------------------------
-- 3. Tabel `kabinet`
-- ----------------------------------------

DROP TABLE IF EXISTS `kabinet`;
CREATE TABLE `kabinet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `arti` varchar(200) DEFAULT NULL,
  `tahun_mulai` int(4) DEFAULT NULL,
  `tahun_selesai` int(4) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `foto_bersama` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Data awal kabinet
INSERT INTO `kabinet` (`id`, `nama`, `arti`, `tahun_mulai`, `tahun_selesai`) VALUES
(1, 'ASTAWIDYA', 'Delapan Arah Kejayaan', 2026, 2027);

-- ----------------------------------------
-- 4. Tabel `struktur_bph`
-- ----------------------------------------

DROP TABLE IF EXISTS `struktur_bph`;
CREATE TABLE `struktur_bph` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_divisi` varchar(100) NOT NULL,
  `urutan` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `struktur_bph` (`id`, `nama_divisi`, `urutan`) VALUES
(1, 'Inti / Pimpinan', 1),
(2, 'Sekretariat', 2),
(3, 'Kebendaharaan', 3);

-- ----------------------------------------
-- 5. Tabel `anggota_bph`
-- ----------------------------------------

DROP TABLE IF EXISTS `anggota_bph`;
CREATE TABLE `anggota_bph` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `bph_id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `jabatan` varchar(100) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `urutan` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `bph_id` (`bph_id`),
  KEY `periode_id` (`periode_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_anggota_bph_bph` FOREIGN KEY (`bph_id`) REFERENCES `struktur_bph` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anggota_bph_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_anggota_bph_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 6. Tabel `kementerian`
-- ----------------------------------------

DROP TABLE IF EXISTS `kementerian`;
CREATE TABLE `kementerian` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kementerian` varchar(100) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `fungsi` text DEFAULT NULL,
  `urutan` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 7. Tabel `anggota_kementerian`
-- ----------------------------------------

DROP TABLE IF EXISTS `anggota_kementerian`;
CREATE TABLE `anggota_kementerian` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `kementerian_id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `jabatan` varchar(100) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `urutan` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `kementerian_id` (`kementerian_id`),
  KEY `periode_id` (`periode_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_anggota_kementerian_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_anggota_kementerian_kementerian` FOREIGN KEY (`kementerian_id`) REFERENCES `kementerian` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anggota_kementerian_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 8. Tabel `arsip_rundown`
-- ----------------------------------------

DROP TABLE IF EXISTS `arsip_rundown`;
CREATE TABLE `arsip_rundown` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_acara` varchar(255) NOT NULL,
  `tahun` varchar(50) NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `durasi_hari` int(11) NOT NULL DEFAULT 1,
  `rundown_json` longtext NOT NULL,
  `periode_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_periode` (`periode_id`),
  CONSTRAINT `fk_rundown_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 9. Tabel `arsip_surat`
-- ----------------------------------------

DROP TABLE IF EXISTS `arsip_surat`;
CREATE TABLE `arsip_surat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `jenis_surat` char(1) NOT NULL,
  `nomor_surat` varchar(255) NOT NULL,
  `tanggal_dikirim` date DEFAULT NULL,
  `perihal` varchar(255) NOT NULL,
  `tujuan` text NOT NULL,
  `tempat_tanggal` varchar(100) DEFAULT NULL,
  `lampiran` varchar(100) DEFAULT NULL,
  `konten_surat` mediumtext DEFAULT NULL,
  `file_surat` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_arsip_periode` (`periode_id`),
  KEY `fk_arsip_user` (`created_by`),
  CONSTRAINT `fk_arsip_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arsip_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 10. Tabel `audit_log`
-- ----------------------------------------

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `target_table` varchar(100) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 11. Master Tables
-- ----------------------------------------

DROP TABLE IF EXISTS `barang_master`;
CREATE TABLE `barang_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_barang` varchar(255) NOT NULL,
  `satuan` varchar(50) DEFAULT 'pcs',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `tempat_master`;
CREATE TABLE `tempat_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_tempat` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `keterangan_master`;
CREATE TABLE `keterangan_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `isi_keterangan` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `penanggung_jawab_master`;
CREATE TABLE `penanggung_jawab_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pj` varchar(255) NOT NULL,
  `jabatan_pj` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 12. CMS & Media Tables
-- ----------------------------------------

DROP TABLE IF EXISTS `berita`;
CREATE TABLE `berita` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `judul` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `tanggal` date NOT NULL,
  `penulis` varchar(100) NOT NULL,
  `konten` longtext NOT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  `status` enum('draft','published') DEFAULT 'draft',
  `periode_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `periode_id` (`periode_id`),
  CONSTRAINT `fk_berita_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `struktur_organisasi`;
CREATE TABLE `struktur_organisasi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) NOT NULL,
  `judul` varchar(255) NOT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `periode_id` (`periode_id`),
  CONSTRAINT `fk_struktur_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 13. System Tables
-- ----------------------------------------

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `last_active` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `notifikasi`;
CREATE TABLE `notifikasi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tipe` varchar(50) DEFAULT 'info',
  `pesan` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `signatures`;
CREATE TABLE `signatures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `is_digital` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `short_links`;
CREATE TABLE `short_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_url` text NOT NULL,
  `short_code` varchar(50) NOT NULL,
  `clicks` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `short_code` (`short_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------
-- 14. Tabel `lpj_dokumen`
-- ----------------------------------------

DROP TABLE IF EXISTS `lpj_dokumen`;
CREATE TABLE `lpj_dokumen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) NOT NULL,
  `kementerian_id` int(11) NOT NULL,
  `triwulan` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'draft',
  `keanggotaan` longtext DEFAULT NULL,
  `keadaan_objektif` text DEFAULT NULL,
  `proker_terlaksana` longtext DEFAULT NULL,
  `proker_belum_terlaksana` longtext DEFAULT NULL,
  `anggaran` longtext DEFAULT NULL,
  `dokumentasi` longtext DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `evaluasi_kinerja_pribadi` text DEFAULT NULL,
  `evaluasi_anggota_internal` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `periode_id` (`periode_id`),
  KEY `kementerian_id` (`kementerian_id`),
  CONSTRAINT `fk_lpj_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lpj_kementerian` FOREIGN KEY (`kementerian_id`) REFERENCES `kementerian` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
