#!/bin/bash
# ============================================================
# backup.sh - BEM ASTAWIDYA Database Backup
# Cron: 0 2 * * * /opt/bem/scripts/backup.sh
# ============================================================

set -euo pipefail

# ── Config ────────────────────────────────────────────────────
BACKUP_DIR="/opt/bem/backups"
COMPOSE_DIR="/opt/bem"
KEEP_DAYS=14       # Simpan backup 14 hari terakhir
DATE=$(date +%Y%m%d_%H%M%S)
LOG_FILE="/opt/bem/logs/backup.log"

# Ambil credentials dari .env
source "${COMPOSE_DIR}/.env"

# ── Fungsi Logging ────────────────────────────────────────────
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# ── Mulai Backup ──────────────────────────────────────────────
log "=== Mulai backup BEM database ==="

# Buat folder backup jika belum ada
mkdir -p "$BACKUP_DIR"

# ── 1. Backup Database ────────────────────────────────────────
BACKUP_FILE="${BACKUP_DIR}/db_${DATE}.sql.gz"

log "Dumping database '${DB_NAME}'..."
docker exec bem_db mysqldump \
    -u root \
    -p"${DB_ROOT_PASS}" \
    --single-transaction \
    --quick \
    --lock-tables=false \
    "${DB_NAME}" \
    | gzip > "$BACKUP_FILE"

if [ -f "$BACKUP_FILE" ]; then
    SIZE=$(du -sh "$BACKUP_FILE" | cut -f1)
    log "✅ Database backup berhasil: ${BACKUP_FILE} (${SIZE})"
else
    log "❌ GAGAL: File backup database tidak terbentuk!"
    exit 1
fi

# ── 2. Backup Uploads ─────────────────────────────────────────
UPLOADS_BACKUP="${BACKUP_DIR}/uploads_${DATE}.tar.gz"

log "Compressing uploads folder..."
tar -czf "$UPLOADS_BACKUP" \
    -C "${COMPOSE_DIR}" \
    uploads/ \
    2>/dev/null || true

SIZE=$(du -sh "$UPLOADS_BACKUP" | cut -f1)
log "✅ Uploads backup berhasil: ${UPLOADS_BACKUP} (${SIZE})"

# ── 3. Hapus Backup Lama ──────────────────────────────────────
log "Menghapus backup lebih dari ${KEEP_DAYS} hari..."
find "$BACKUP_DIR" -name "*.sql.gz" -mtime "+${KEEP_DAYS}" -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime "+${KEEP_DAYS}" -delete
log "Cleanup selesai."

# ── 4. Ringkasan ──────────────────────────────────────────────
TOTAL=$(du -sh "$BACKUP_DIR" | cut -f1)
log "=== Backup selesai. Total storage backup: ${TOTAL} ==="
