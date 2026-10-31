-- Jalankan satu kali pada MySQL/MariaDB sebelum deploy kode aplikasi.
-- Nilai lama tetap disimpan di kegiatan.program_kerja sebagai snapshot kompatibilitas.
CREATE TABLE `program_kerja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kementerian_id` int(11) DEFAULT NULL,
  `periode_id` int(11) DEFAULT NULL,
  `nama` varchar(255) NOT NULL,
  `status` enum('aktif','usulan','arsip') NOT NULL DEFAULT 'aktif',
  `proposed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_program_kerja_kementerian` (`kementerian_id`, `periode_id`, `status`),
  KEY `idx_program_kerja_proposed_by` (`proposed_by`),
  CONSTRAINT `fk_program_kerja_kementerian` FOREIGN KEY (`kementerian_id`) REFERENCES `kementerian` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_program_kerja_periode` FOREIGN KEY (`periode_id`) REFERENCES `periode_kepengurusan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_program_kerja_proposed_by` FOREIGN KEY (`proposed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE `kegiatan`
  ADD COLUMN `kementerian_id` int(11) DEFAULT NULL AFTER `pelaksana`,
  ADD COLUMN `program_kerja_id` int(11) DEFAULT NULL AFTER `program_kerja`,
  ADD KEY `idx_kegiatan_kementerian` (`kementerian_id`),
  ADD KEY `idx_kegiatan_program_kerja` (`program_kerja_id`),
  ADD CONSTRAINT `fk_kegiatan_kementerian` FOREIGN KEY (`kementerian_id`) REFERENCES `kementerian` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_kegiatan_program_kerja` FOREIGN KEY (`program_kerja_id`) REFERENCES `program_kerja` (`id`) ON DELETE SET NULL;

-- Import daftar kementerian dari JSON hanya jika valid dan bukan entri kosong.
INSERT INTO `program_kerja` (`kementerian_id`, `periode_id`, `nama`, `status`)
SELECT k.`id`, k.`periode_id`, MIN(TRIM(jt.`nama`)), 'aktif'
FROM `kementerian` k
CROSS JOIN JSON_TABLE(
  CASE WHEN JSON_VALID(k.`proker`) THEN k.`proker` ELSE '[]' END,
  '$[*]' COLUMNS (`nama` VARCHAR(255) PATH '$')
) AS jt
WHERE TRIM(COALESCE(jt.`nama`, '')) <> ''
GROUP BY k.`id`, k.`periode_id`, LOWER(TRIM(jt.`nama`));

-- Kaitkan kementerian ke kegiatan hanya jika nama pelaksana unik dalam periode.
UPDATE `kegiatan` k
JOIN (
  SELECT `periode_id`, LOWER(TRIM(`nama`)) AS `nama_normal`, MIN(`id`) AS `kementerian_id`
  FROM `kementerian`
  GROUP BY `periode_id`, LOWER(TRIM(`nama`))
  HAVING COUNT(*) = 1
) m
  ON m.`periode_id` = k.`periode_id`
 AND m.`nama_normal` = LOWER(TRIM(k.`pelaksana`))
SET k.`kementerian_id` = m.`kementerian_id`
WHERE k.`kementerian_id` IS NULL;

-- Kaitkan Program Kerja hanya jika nama cocok secara unik pada kementerian tersebut.
UPDATE `kegiatan` k
JOIN (
  SELECT `kementerian_id`, LOWER(TRIM(`nama`)) AS `nama_normal`, MIN(`id`) AS `program_kerja_id`
  FROM `program_kerja`
  WHERE `status` = 'aktif' AND `kementerian_id` IS NOT NULL
  GROUP BY `kementerian_id`, LOWER(TRIM(`nama`))
  HAVING COUNT(*) = 1
) p
  ON p.`kementerian_id` = k.`kementerian_id`
 AND p.`nama_normal` = LOWER(TRIM(k.`program_kerja`))
SET k.`program_kerja_id` = p.`program_kerja_id`
WHERE k.`program_kerja_id` IS NULL;
