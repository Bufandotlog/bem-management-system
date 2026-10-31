-- Jalankan satu kali pada database MySQL/MariaDB sebelum deploy kode aplikasi.
-- Arsip lama hanya ditautkan jika nama kegiatan unik dalam periode.
ALTER TABLE `lampiran_pinjam`
  ADD COLUMN `kegiatan_id` int(11) DEFAULT NULL AFTER `id`,
  ADD KEY `idx_lampiran_pinjam_kegiatan_periode` (`kegiatan_id`, `periode_id`);

UPDATE `lampiran_pinjam` lp
JOIN (
  SELECT `periode_id`, LOWER(TRIM(`nama_kegiatan`)) AS `nama_normal`,
         MIN(`id`) AS `kegiatan_id`
  FROM `kegiatan`
  GROUP BY `periode_id`, LOWER(TRIM(`nama_kegiatan`))
  HAVING COUNT(*) = 1
) k
  ON k.`periode_id` = lp.`periode_id`
 AND k.`nama_normal` = LOWER(TRIM(lp.`nama_acara`))
SET lp.`kegiatan_id` = k.`kegiatan_id`
WHERE lp.`kegiatan_id` IS NULL;
