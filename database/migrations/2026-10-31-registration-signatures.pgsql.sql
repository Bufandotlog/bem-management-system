-- Align signature paths across registration, user, and member records.
ALTER TABLE "users"
  ADD COLUMN IF NOT EXISTS "file_ttd" VARCHAR(255);

ALTER TABLE "anggota_bph"
  ADD COLUMN IF NOT EXISTS "file_ttd" VARCHAR(255);

ALTER TABLE "anggota_kementerian"
  ADD COLUMN IF NOT EXISTS "file_ttd" VARCHAR(255);

UPDATE "anggota_bph"
SET "file_ttd" = "foto"
WHERE "file_ttd" IS NULL AND "foto" LIKE 'ttd/%';

UPDATE "anggota_kementerian"
SET "file_ttd" = "foto"
WHERE "file_ttd" IS NULL AND "foto" LIKE 'ttd/%';

CREATE TABLE IF NOT EXISTS "pendaftaran_anggota" (
  "id" SERIAL PRIMARY KEY,
  "nama_lengkap" VARCHAR(255) NOT NULL,
  "username" VARCHAR(255) NOT NULL,
  "penempatan" VARCHAR(50) NOT NULL,
  "kementerian_id" INTEGER,
  "jabatan" VARCHAR(100) NOT NULL,
  "status" VARCHAR(50) DEFAULT 'pending',
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "file_ttd" VARCHAR(255)
);

ALTER TABLE "pendaftaran_anggota"
  ADD COLUMN IF NOT EXISTS "file_ttd" VARCHAR(255);

CREATE TABLE IF NOT EXISTS "panitia_tetap" (
  "id" SERIAL PRIMARY KEY,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE CASCADE,
  "nama" VARCHAR(100) NOT NULL,
  "jabatan" VARCHAR(20) NOT NULL CHECK ("jabatan" IN ('ketua', 'sekretaris')),
  "file_ttd" VARCHAR(255) NOT NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE "panitia_tetap"
  ADD COLUMN IF NOT EXISTS "file_ttd" VARCHAR(255);
