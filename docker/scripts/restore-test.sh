#!/bin/bash
# ============================================================
# restore-test.sh - BEM ASTAWIDYA Automated Backup Restore Test
# Menguji integritas dekripsi dan validitas struktur SQL backup
# Usage: bash restore-test.sh [path_to_backup.sql.gz.enc]
# ============================================================

set -euo pipefail

COMPOSE_DIR="/var/www/html/bem"
BACKUP_DIR="${COMPOSE_DIR}/backups"
LOG_FILE="${COMPOSE_DIR}/logs/restore-test.log"

# Import env
if [ -f "${COMPOSE_DIR}/.env" ]; then
    source "${COMPOSE_DIR}/.env"
fi

PASSPHRASE="${BACKUP_PASSPHRASE:-${DB_PASS:-astawidya_secret}}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "=== Mulai Pengujian Pemulihan (Restore Test) ==="

# Tentukan berkas backup yang akan diuji
TARGET_FILE="${1:-}"
if [ -z "$TARGET_FILE" ]; then
    TARGET_FILE=$(ls -t "${BACKUP_DIR}"/db_*.sql.gz.enc 2>/dev/null | head -n 1 || true)
fi

if [ -z "$TARGET_FILE" ] || [ ! -f "$TARGET_FILE" ]; then
    # Fallback ke file unencrypted jika file terenkripsi belum ada
    TARGET_FILE=$(ls -t "${BACKUP_DIR}"/db_*.sql.gz 2>/dev/null | head -n 1 || true)
fi

if [ -z "$TARGET_FILE" ] || [ ! -f "$TARGET_FILE" ]; then
    log "❌ ERROR: Tidak ditemukan berkas backup di ${BACKUP_DIR}"
    exit 1
fi

log "Target file backup: $(basename "$TARGET_FILE")"

TEMP_GZ="/tmp/restore_test_$(date +%s).sql.gz"

# Cleanup otomatis saat exit
cleanup() {
    rm -f "$TEMP_GZ"
}
trap cleanup EXIT

# 1. DEKRIPSI (jika terenkripsi .enc)
if [[ "$TARGET_FILE" == *.enc ]]; then
    log "Mendekripsi berkas AES-256 OpenSSL..."
    if openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 \
        -in "$TARGET_FILE" \
        -out "$TEMP_GZ" \
        -pass "pass:${PASSPHRASE}" 2>/dev/null; then
        log "✅ Dekripsi OpenSSL berhasil."
    else
        log "❌ GAGAL: Dekripsi OpenSSL gagal (Passphrase salah atau file korup)."
        exit 1
    fi
else
    log "File tidak terenkripsi (.gz), menyalin untuk pengujian..."
    cp "$TARGET_FILE" "$TEMP_GZ"
fi

# 2. UJI INTEGRITAS GZIP
log "Memeriksa integritas arsip GZIP..."
if gzip -t "$TEMP_GZ" 2>/dev/null; then
    log "✅ Integritas GZIP valid."
else
    log "❌ GAGAL: Berkas GZIP korup!"
    exit 1
fi

# 3. UJI STRUKTUR SQL
log "Memeriksa tabel dan struktur SQL..."
TABLE_COUNT=$(zcat "$TEMP_GZ" | grep -i "^CREATE TABLE" | wc -l || echo 0)
SQL_HEADER=$(zcat "$TEMP_GZ" | head -n 10 || echo "")

if [ "$TABLE_COUNT" -gt 0 ]; then
    log "✅ Verifikasi SQL Sukses: Ditemukan ${TABLE_COUNT} struktur tabel CREATE TABLE."
    log "=== Restore Test SELESAI (STATUS: PASSED) ==="
    exit 0
else
    log "❌ GAGAL: Berkas SQL tidak memuat struktur tabel yang valid."
    exit 1
fi
