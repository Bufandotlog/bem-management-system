-- ============================================
-- BEM Management System - Schema MySQL/MariaDB
-- Diregenerasi dari live DB (MariaDB 11.8) pada 2026-08-25
-- Basis: bem_astawidya @ localhost (struktur saja, TANPA data)
-- Regenerasi: mariadb-dump --no-data lalu dipost-proses
-- Aturan: file ini HARUS mencerminkan live DB. Jangan edit manual;
--         regenerasi ulang setiap ada perubahan struktur.
-- Catatan deprecated (dipertahankan, tidak dipakai kode aktif):
--   - keterangan_master      : digantikan tabel rundown_keterangan
--   - penanggung_jawab_master: digantikan tabel rundown_pj
--   - signatures             : hanya direferensikan di admin/system/kelola-admin.php
--                              dengan cek SHOW TABLES (aman jika absen)
-- Catatan engine: beberapa tabel rundown_*/tempat_master masih MyISAM/latin1
--                 sesuai live DB (migrasi InnoDB = hardening terpisah).
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `anggota_bph` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `anggota_kementerian` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `arsip_berita_acara` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `arsip_dokumentasi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kegiatan_id` int(11) NOT NULL,
  `periode_id` int(11) NOT NULL,
  `dokumentasi_json` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kegiatan_id` (`kegiatan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `arsip_panitia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kegiatan` varchar(255) NOT NULL,
  `periode_id` int(11) NOT NULL,
  `panitia_json` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_arsip_panitia_periode_id` (`periode_id`),
  CONSTRAINT `fk_arsip_panitia_periode_id` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `arsip_rundown` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kegiatan_id` int(11) DEFAULT NULL,
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
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `arsip_surat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `kegiatan_id` int(11) DEFAULT NULL,
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
  `status_arsip` enum('staging','archived') NOT NULL DEFAULT 'archived',
  `status_humas` varchar(20) DEFAULT 'draft',
  PRIMARY KEY (`id`),
  KEY `fk_arsip_periode` (`periode_id`),
  KEY `fk_arsip_user` (`created_by`),
  CONSTRAINT `fk_arsip_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arsip_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `arsip_teks_mc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kegiatan_id` int(11) NOT NULL,
  `rundown_id` int(11) DEFAULT NULL,
  `judul_naskah` varchar(255) NOT NULL,
  `tipe_acara` enum('formal','semi_formal','non_formal') DEFAULT 'formal',
  `susunan_mc` longtext NOT NULL,
  `catatan_mc` text DEFAULT NULL,
  `periode_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `kegiatan_id` (`kegiatan_id`),
  CONSTRAINT `arsip_teks_mc_ibfk_1` FOREIGN KEY (`kegiatan_id`) REFERENCES `kegiatan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
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
  KEY `idx_created_at` (`created_at`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `barang_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_barang` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `satuan` varchar(50) DEFAULT 'pcs',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `berita` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `judul` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `tanggal` date NOT NULL,
  `penulis` varchar(100) NOT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  `konten` mediumtext DEFAULT NULL,
  `views` int(11) DEFAULT 0,
  `status` enum('draft','published') DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `footnote` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_periode_id` (`periode_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `fcm_tokens` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `kabinet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `arti` text DEFAULT NULL,
  `tahun_mulai` year(4) NOT NULL,
  `tahun_selesai` year(4) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `foto_bersama` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `kegiatan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) NOT NULL,
  `nama_kegiatan` varchar(255) NOT NULL,
  `pelaksana` varchar(255) DEFAULT NULL,
  `program_kerja` varchar(255) DEFAULT NULL,
  `tujuan` text DEFAULT NULL,
  `manfaat` text DEFAULT NULL,
  `kode_kegiatan` varchar(50) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `tanggal_mulai` date DEFAULT NULL,
  `tanggal_selesai` date DEFAULT NULL,
  `status` enum('persiapan','berjalan','selesai') DEFAULT 'persiapan',
  `panitia_json` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `waktu_pelaksanaan` varchar(100) DEFAULT NULL,
  `tempat_pelaksanaan` text DEFAULT NULL,
  `tamu_undangan` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_kegiatan_periode` (`periode_id`),
  KEY `fk_kegiatan_user` (`created_by`),
  CONSTRAINT `fk_kegiatan_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kegiatan_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `kegiatan_panitia` (
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
  CONSTRAINT `fk_panitia_ditunjuk_oleh` FOREIGN KEY (`ditunjuk_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_panitia_kegiatan` FOREIGN KEY (`kegiatan_id`) REFERENCES `kegiatan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_panitia_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `kementerian` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `tugas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `proker` longtext DEFAULT NULL,
  `urutan` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  `fungsi` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `kontak` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alamat` text NOT NULL,
  `telepon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `jam_kerja` longtext DEFAULT NULL,
  `sosial_media` longtext DEFAULT NULL,
  `map_embed` longtext DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `lampiran_pinjam` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_acara` varchar(255) NOT NULL,
  `tanggal_kegiatan` varchar(100) DEFAULT NULL,
  `tahun` varchar(10) DEFAULT NULL,
  `barang_json` text DEFAULT NULL,
  `readiness_json` text DEFAULT NULL,
  `periode_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `login_attempts_ip` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `attempt_type` enum('login_failed','turnstile_failed','lockout') NOT NULL DEFAULT 'login_failed',
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_created` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
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
  `evaluasi_kinerja_pribadi` text DEFAULT NULL,
  `evaluasi_anggota_internal` longtext DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `penutup` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `periode_id` (`periode_id`),
  KEY `kementerian_id` (`kementerian_id`),
  CONSTRAINT `fk_lpj_kementerian` FOREIGN KEY (`kementerian_id`) REFERENCES `kementerian` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lpj_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `notifikasi` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `panitia_tetap` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `nama` varchar(100) NOT NULL,
  `jabatan` enum('ketua','sekretaris') NOT NULL,
  `file_ttd` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_panitia_periode` (`periode_id`),
  CONSTRAINT `fk_panitia_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pendaftaran_anggota` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_lengkap` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `penempatan` varchar(50) NOT NULL,
  `kementerian_id` int(11) DEFAULT NULL,
  `jabatan` varchar(100) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `file_ttd` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `pengaturan` (
  `kunci` varchar(100) NOT NULL,
  `nilai` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `periode_kepengurusan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `tahun_mulai` year(4) NOT NULL,
  `tahun_selesai` year(4) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `rundown_keterangan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_keterangan` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `rundown_pj` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pj` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `rundown_tempat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_tempat` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `short_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(32) NOT NULL,
  `target_url` varchar(500) NOT NULL,
  `target_page` varchar(100) NOT NULL,
  `params` text DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `views` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  KEY `idx_hash` (`hash`),
  KEY `idx_target` (`target_page`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `short_links_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `struktur_bph` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posisi` varchar(50) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `jabatan` varchar(100) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `tugas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `proker` longtext DEFAULT NULL,
  `urutan` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `struktur_organisasi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `judul` varchar(255) NOT NULL DEFAULT 'Struktur Organisasi BEM',
  `gambar` varchar(255) DEFAULT NULL,
  `deskripsi` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `updated_by` (`updated_by`),
  KEY `periode_id` (`periode_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_struktur_organisasi_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_struktur_organisasi_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_struktur_organisasi_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `surat_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `periode_id` int(11) DEFAULT NULL,
  `nama_template` varchar(100) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `jenis` varchar(20) NOT NULL DEFAULT 'L',
  `urutan` int(11) DEFAULT 0,
  `perihal_default` varchar(255) DEFAULT NULL,
  `isi_teks` text DEFAULT NULL,
  `konten_default` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_template_periode` (`periode_id`),
  CONSTRAINT `fk_template_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tempat_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_tempat` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_active` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('superadmin','admin','kominfo','sekretaris','anggota') NOT NULL DEFAULT 'anggota',
  `periode_id` int(11) DEFAULT NULL,
  `can_access_all` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `totp_secret` varchar(32) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_last_counter` int(11) NOT NULL DEFAULT 0,
  `totp_verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `file_ttd` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `google_email` varchar(255) DEFAULT NULL,
  `google_linked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `periode_id` (`periode_id`),
  CONSTRAINT `fk_users_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE TABLE `visi_misi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visi` text NOT NULL,
  `misi` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_lpj_anggaran` AS select `d`.`id` AS `lpj_id`,`d`.`periode_id` AS `periode_id`,`d`.`kementerian_id` AS `kementerian_id`,`jt`.`jenis` AS `jenis`,`jt`.`kategori` AS `kategori`,`jt`.`keterangan` AS `keterangan`,`jt`.`jumlah` AS `jumlah`,`jt`.`total` AS `total` from (`lpj_dokumen` `d` join JSON_TABLE(`d`.`anggaran`, '$.transaksi[*]' COLUMNS (`jenis` varchar(50) PATH '$.jenis', `kategori` varchar(100) PATH '$.kategori', `keterangan` varchar(255) PATH '$.keterangan', `jumlah` decimal(15,2) PATH '$.jumlah', `total` decimal(15,2) PATH '$.total')) `jt`) ;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_lpj_proker` AS select `d`.`id` AS `lpj_id`,`d`.`periode_id` AS `periode_id`,`d`.`kementerian_id` AS `kementerian_id`,`d`.`triwulan` AS `triwulan`,_utf8mb4'terlaksana' collate utf8mb4_uca1400_ai_ci AS `tipe_proker`,`jt`.`nama_proker` AS `nama_proker`,coalesce(`ba`.`nama_kegiatan`,`jt`.`nama_kegiatan`) collate utf8mb4_uca1400_ai_ci AS `nama_kegiatan`,coalesce(`ba`.`tempat`,`jt`.`tempat_kegiatan`) collate utf8mb4_uca1400_ai_ci AS `tempat_kegiatan`,`jt`.`sifat` AS `sifat`,`jt`.`tema_kegiatan` AS `tema_kegiatan`,coalesce(`ba`.`tanggal_kegiatan`,`jt`.`tanggal_kegiatan`) collate utf8mb4_uca1400_ai_ci AS `tanggal_kegiatan`,`jt`.`penanggung_jawab` AS `penanggung_jawab`,`jt`.`berita_acara_id` AS `berita_acara_id` from ((`lpj_dokumen` `d` join JSON_TABLE(`d`.`proker_terlaksana`, '$[*]' COLUMNS (`nama_proker` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Program Kerja"', `nama_kegiatan` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Kegiatan"', `tempat_kegiatan` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tempat Kegiatan"', `sifat` varchar(50) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Sifat"', `tema_kegiatan` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tema Kegiatan"', `tanggal_kegiatan` varchar(100) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tanggal Kegiatan"', `penanggung_jawab` varchar(100) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Penanggung Jawab"', `berita_acara_id` int(11) PATH '$."berita_acara_id"')) `jt`) left join `arsip_berita_acara` `ba` on(`jt`.`berita_acara_id` = `ba`.`id`)) union all select `d`.`id` AS `lpj_id`,`d`.`periode_id` AS `periode_id`,`d`.`kementerian_id` AS `kementerian_id`,`d`.`triwulan` AS `triwulan`,_utf8mb4'belum_terlaksana' collate utf8mb4_uca1400_ai_ci AS `tipe_proker`,`jt`.`nama_proker` AS `nama_proker`,cast(NULL as char(255) charset utf8mb4) collate utf8mb4_uca1400_ai_ci AS `nama_kegiatan`,cast(NULL as char(255) charset utf8mb4) collate utf8mb4_uca1400_ai_ci AS `tempat_kegiatan`,`jt`.`sifat` AS `sifat`,`jt`.`tema_kegiatan` AS `tema_kegiatan`,cast(NULL as char(100) charset utf8mb4) collate utf8mb4_uca1400_ai_ci AS `tanggal_kegiatan`,`jt`.`penanggung_jawab` AS `penanggung_jawab`,NULL AS `berita_acara_id` from (`lpj_dokumen` `d` join JSON_TABLE(`d`.`proker_belum_terlaksana`, '$[*]' COLUMNS (`nama_proker` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Nama Program Kerja"', `sifat` varchar(50) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Sifat"', `tema_kegiatan` varchar(255) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Tema Kegiatan"', `penanggung_jawab` varchar(100) CHARSET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PATH '$."Penanggung Jawab"')) `jt`) ;
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_rundown_acara` AS select `r`.`id` AS `rundown_id`,`r`.`periode_id` AS `periode_id`,`r`.`nama_acara` AS `nama_acara`,`r`.`tahun` AS `tahun`,`r`.`tanggal_mulai` AS `tanggal_mulai`,`jt`.`hari` AS `hari`,`jt`.`waktu` AS `waktu`,`jt`.`agenda` AS `agenda`,`jt`.`penanggung_jawab` AS `penanggung_jawab`,`jt`.`keterangan` AS `keterangan` from (`arsip_rundown` `r` join JSON_TABLE(`r`.`rundown_json`, '$[*]' COLUMNS (`hari` int(11) PATH '$.hari', `waktu` varchar(50) PATH '$.waktu', `agenda` varchar(255) PATH '$.agenda', `penanggung_jawab` varchar(100) PATH '$.pj', `keterangan` text PATH '$.keterangan')) `jt`) ;

-- ============================================
-- DEPRECATED TABLES — dipertahankan sesuai keputusan audit 2026-08-25
-- Tabel ini TIDAK ada di live DB dan TIDAK dipakai kode aktif.
-- Dipertahankan untuk kompatibilitas/riwayat. Jangan gunakan di kode baru.
--   keterangan_master       -> digantikan rundown_keterangan
--   penanggung_jawab_master -> digantikan rundown_pj
--   signatures              -> hanya cek eksistensi di admin/system/kelola-admin.php
--                              (dibungkus SHOW TABLES check, aman jika absen)
-- Kandidat penghapusan pada audit berikutnya setelah verifikasi penuh.
-- ============================================

CREATE TABLE IF NOT EXISTS `keterangan_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `isi_keterangan` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `penanggung_jawab_master` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama_pj` varchar(255) NOT NULL,
  `jabatan_pj` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `signatures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `is_digital` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- VIEWS (LPJ & Rundown) — diambil dari databases/create_1nf_views.sql
-- ============================================================

-- View untuk Program Kerja LPJ (v_lpj_proker)
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
        berita_acara_id INT PATH '$.berita_acara_id'
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

SET FOREIGN_KEY_CHECKS = 1;
