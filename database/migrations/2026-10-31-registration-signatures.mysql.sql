-- Align signature paths across registration, user, and member records.
-- Safe to apply repeatedly on MariaDB.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `file_ttd` varchar(255) DEFAULT NULL;

ALTER TABLE `pendaftaran_anggota`
  ADD COLUMN IF NOT EXISTS `file_ttd` varchar(255) DEFAULT NULL;

ALTER TABLE `anggota_bph`
  ADD COLUMN IF NOT EXISTS `file_ttd` varchar(255) DEFAULT NULL AFTER `foto`;

ALTER TABLE `anggota_kementerian`
  ADD COLUMN IF NOT EXISTS `file_ttd` varchar(255) DEFAULT NULL AFTER `foto`;

UPDATE `anggota_bph`
SET `file_ttd` = `foto`
WHERE `file_ttd` IS NULL AND `foto` LIKE 'ttd/%';

UPDATE `anggota_kementerian`
SET `file_ttd` = `foto`
WHERE `file_ttd` IS NULL AND `foto` LIKE 'ttd/%';
