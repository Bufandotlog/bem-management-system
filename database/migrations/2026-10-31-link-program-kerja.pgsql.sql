-- Jalankan satu kali pada PostgreSQL sebelum deploy kode aplikasi.
-- Backfill dilakukan hanya jika instalasi memiliki kolom legacy yang diperlukan.
CREATE TABLE IF NOT EXISTS "program_kerja" (
  "id" BIGSERIAL PRIMARY KEY,
  "kementerian_id" INTEGER REFERENCES "kementerian"("id") ON DELETE SET NULL,
  "periode_id" INTEGER REFERENCES "periode_kepengurusan"("id") ON DELETE SET NULL,
  "nama" VARCHAR(255) NOT NULL,
  "status" VARCHAR(20) NOT NULL DEFAULT 'aktif'
    CHECK ("status" IN ('aktif', 'usulan', 'arsip')),
  "proposed_by" INTEGER REFERENCES "users"("id") ON DELETE SET NULL,
  "created_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS "idx_program_kerja_kementerian"
  ON "program_kerja" ("kementerian_id", "periode_id", "status");

ALTER TABLE "kegiatan"
  ADD COLUMN IF NOT EXISTS "kementerian_id" INTEGER
    REFERENCES "kementerian"("id") ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS "program_kerja_id" BIGINT
    REFERENCES "program_kerja"("id") ON DELETE SET NULL;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kementerian' AND column_name = 'proker'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kementerian' AND column_name = 'periode_id'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kementerian' AND column_name = 'nama'
  ) THEN
    EXECUTE $backfill$
      INSERT INTO "program_kerja" ("kementerian_id", "periode_id", "nama", "status")
      SELECT k."id", k."periode_id", MIN(TRIM(item.value)), 'aktif'
      FROM "kementerian" k
      CROSS JOIN LATERAL jsonb_array_elements_text(
        CASE
          WHEN jsonb_typeof(k."proker"::jsonb) = 'array' THEN k."proker"::jsonb
          ELSE '[]'::jsonb
        END
      ) AS item(value)
      WHERE TRIM(item.value) <> ''
      GROUP BY k."id", k."periode_id", LOWER(TRIM(item.value))
      HAVING NOT EXISTS (
          SELECT 1 FROM "program_kerja" p
          WHERE p."kementerian_id" = k."id"
            AND p."periode_id" = k."periode_id"
            AND LOWER(TRIM(p."nama")) = LOWER(TRIM(item.value))
        )
    $backfill$;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kegiatan' AND column_name = 'pelaksana'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kegiatan' AND column_name = 'periode_id'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kementerian' AND column_name = 'periode_id'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kementerian' AND column_name = 'nama'
  ) THEN
    EXECUTE $backfill$
      UPDATE "kegiatan" k
      SET "kementerian_id" = m."id"
      FROM (
        SELECT "periode_id", LOWER(TRIM("nama")) AS "nama_normal", MIN("id") AS "id"
        FROM "kementerian"
        GROUP BY "periode_id", LOWER(TRIM("nama"))
        HAVING COUNT(*) = 1
      ) m
      WHERE k."kementerian_id" IS NULL
        AND k."periode_id" = m."periode_id"
        AND LOWER(TRIM(k."pelaksana")) = m."nama_normal"
    $backfill$;
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = current_schema() AND table_name = 'kegiatan' AND column_name = 'program_kerja'
  ) THEN
    EXECUTE $backfill$
      UPDATE "kegiatan" k
      SET "program_kerja_id" = p."id"
      FROM (
        SELECT "kementerian_id", LOWER(TRIM("nama")) AS "nama_normal", MIN("id") AS "id"
        FROM "program_kerja"
        WHERE "status" = 'aktif' AND "kementerian_id" IS NOT NULL
        GROUP BY "kementerian_id", LOWER(TRIM("nama"))
        HAVING COUNT(*) = 1
      ) p
      WHERE k."program_kerja_id" IS NULL
        AND k."kementerian_id" = p."kementerian_id"
        AND LOWER(TRIM(k."program_kerja")) = p."nama_normal"
    $backfill$;
  END IF;
END $$;
