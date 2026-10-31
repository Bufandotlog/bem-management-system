-- Jalankan satu kali pada database PostgreSQL sebelum deploy kode aplikasi.
-- Arsip lama hanya ditautkan jika nama kegiatan unik dalam periode.
CREATE TABLE IF NOT EXISTS "lampiran_pinjam" (
  "id" SERIAL PRIMARY KEY,
  "kegiatan_id" INTEGER REFERENCES "kegiatan"("id") ON DELETE SET NULL,
  "nama_acara" VARCHAR(255) NOT NULL,
  "tanggal_kegiatan" VARCHAR(100),
  "tahun" VARCHAR(10),
  "barang_json" TEXT,
  "readiness_json" TEXT,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE SET NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE "lampiran_pinjam"
  ADD COLUMN IF NOT EXISTS "kegiatan_id" INTEGER REFERENCES "kegiatan"("id") ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS "idx_lampiran_pinjam_kegiatan_periode"
  ON "lampiran_pinjam" ("kegiatan_id", "periode_id");

UPDATE "lampiran_pinjam" lp
SET "kegiatan_id" = k."kegiatan_id"
FROM (
  SELECT "periode_id", LOWER(TRIM("nama_kegiatan")) AS "nama_normal",
         MIN("id") AS "kegiatan_id"
  FROM "kegiatan"
  GROUP BY "periode_id", LOWER(TRIM("nama_kegiatan"))
  HAVING COUNT(*) = 1
) k
WHERE lp."kegiatan_id" IS NULL
  AND k."periode_id" = lp."periode_id"
  AND k."nama_normal" = LOWER(TRIM(lp."nama_acara"));
