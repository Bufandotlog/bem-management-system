-- Migration: Tambah kolom file_ttd ke anggota_bph & anggota_kementerian
-- Tanggal: 2026-09-04
-- Tujuan: Memisahkan TTD dari kolom `foto` (yg seharusnya berisi foto profil).
--         Sebelumnya `pendaftaran.php` salah copy TTD ke kolom `foto` di
--         anggota_bph/anggota_kementerian, membuat path TTD/foto_profil
--         tercampur dan membingungkan picker Panitia Tetap.
--
-- Sudah dijalankan di VPS production. Jalankan ulang hanya di environment baru.

ALTER TABLE anggota_bph
  ADD COLUMN file_ttd VARCHAR(255) NULL AFTER foto;

ALTER TABLE anggota_kementerian
  ADD COLUMN file_ttd VARCHAR(255) NULL AFTER foto;

-- Backfill: copy path TTD (yg tersimpan di kolom foto) ke kolom file_ttd.
-- HANYA copy path yang dimulai dengan 'ttd/' — path 'struktur/' adalah
-- foto profil yang asli, JANGAN dipindahkan.
UPDATE anggota_bph
  SET file_ttd = foto
  WHERE foto LIKE 'ttd/%';

UPDATE anggota_kementerian
  SET file_ttd = foto
  WHERE foto LIKE 'ttd/%';
