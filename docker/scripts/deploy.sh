#!/bin/bash
# ============================================================
# deploy.sh - BEM ASTAWIDYA VPS Setup & Deploy Script
# Jalankan sekali di VPS sebagai user: bufan
# Usage: bash deploy.sh
# ============================================================

set -euo pipefail

# Warna output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

APP_DIR="/var/www/html/bem"
REPO_URL="https://github.com/Bufandotlog/bem-management-system"
DOMAIN="bembudiutomo.my.id"

log()  { echo -e "${GREEN}[✅]${NC} $1"; }
warn() { echo -e "${YELLOW}[⚠️ ]${NC} $1"; }
err()  { echo -e "${RED}[❌]${NC} $1"; exit 1; }
info() { echo -e "${BLUE}[ℹ️ ]${NC} $1"; }

# ─────────────────────────────────────────────────────────────
# LANGKAH 1: Update sistem & install prerequisite
# ─────────────────────────────────────────────────────────────
echo ""
echo "=================================================="
echo " 🚀 BEM ASTAWIDYA - VPS Deployment Setup"
echo "=================================================="
echo ""

info "Update package list..."
sudo apt-get update -qq

info "Install dependensi dasar..."
sudo apt-get install -y -qq \
    curl \
    git \
    gnupg \
    ca-certificates \
    lsb-release \
    ufw \
    fail2ban \
    htop \
    ncdu \
    unzip \
    logrotate

# ─────────────────────────────────────────────────────────────
# LANGKAH 2: Setup Swap (VPS 1GB tidak punya swap)
# ─────────────────────────────────────────────────────────────
if [ ! -f /swapfile ]; then
    info "Membuat swap 1GB..."
    sudo fallocate -l 1G /swapfile
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile
    sudo swapon /swapfile
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
    # Tuning swappiness untuk VPS
    echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
    echo 'vm.vfs_cache_pressure=50' | sudo tee -a /etc/sysctl.conf
    sudo sysctl -p
    log "Swap 1GB berhasil dibuat"
else
    warn "Swap sudah ada, skip..."
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 3: Install Docker Engine
# ─────────────────────────────────────────────────────────────
if ! command -v docker &> /dev/null; then
    info "Install Docker Engine..."
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER"
    log "Docker berhasil diinstall"
else
    log "Docker sudah ada: $(docker --version)"
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 4: Install Docker Compose Plugin
# ─────────────────────────────────────────────────────────────
if ! docker compose version &> /dev/null; then
    info "Install Docker Compose plugin..."
    sudo apt-get install -y docker-compose-plugin
    log "Docker Compose berhasil diinstall"
else
    log "Docker Compose sudah ada: $(docker compose version)"
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 5: Setup Firewall (UFW)
# ─────────────────────────────────────────────────────────────
info "Konfigurasi UFW Firewall..."
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 40222/tcp comment 'SSH custom port'
sudo ufw allow 80/tcp   comment 'HTTP'
sudo ufw allow 443/tcp  comment 'HTTPS'
# UFW enable (non-interactive)
echo "y" | sudo ufw enable
log "UFW Firewall aktif"
sudo ufw status

# ─────────────────────────────────────────────────────────────
# LANGKAH 6: Clone / Update repository
# ─────────────────────────────────────────────────────────────
info "Setup direktori aplikasi di ${APP_DIR}..."
sudo mkdir -p "${APP_DIR}"
sudo chown "$USER":www-data "${APP_DIR}"

if [ -d "${APP_DIR}/.git" ]; then
    info "Repository sudah ada, pull versi terbaru..."
    cd "${APP_DIR}"
    git pull origin main
else
    info "Clone repository dari GitHub..."
    git clone "${REPO_URL}" "${APP_DIR}"
    log "Repository berhasil di-clone ke ${APP_DIR}"
fi

cd "${APP_DIR}"

# ─────────────────────────────────────────────────────────────
# LANGKAH 7: Setup .env
# ─────────────────────────────────────────────────────────────
if [ ! -f "${APP_DIR}/.env" ]; then
    warn ".env belum ada. Buat dari template..."
    cp .env.production .env
    echo ""
    echo -e "${RED}⚠️  PENTING: Edit .env dan isi semua nilai yang kosong!${NC}"
    echo "   nano ${APP_DIR}/.env"
    echo ""
    read -p "Tekan Enter setelah .env selesai diisi..." -r
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 8: Setup folder & permission
# ─────────────────────────────────────────────────────────────
info "Setup folder aplikasi..."
mkdir -p uploads/lpj uploads/surat uploads/foto uploads/dokumen
mkdir -p logs/nginx backups
chmod 755 uploads logs backups
log "Folder berhasil dibuat"

# ─────────────────────────────────────────────────────────────
# LANGKAH 9: SSL Certificate (Let's Encrypt) - Step 1
# ─────────────────────────────────────────────────────────────
info "Minta SSL certificate untuk ${DOMAIN}..."
echo ""
echo "Pastikan domain sudah diarahkan ke IP VPS ini (202.134.242.217)"
echo "Cek: dig ${DOMAIN}"
echo ""
read -p "Apakah DNS sudah dikonfigurasi? (y/n): " -r dns_ready

if [[ "$dns_ready" =~ ^[Yy]$ ]]; then
    # Jalankan nginx sementara (HTTP only) untuk certbot challenge
    info "Jalankan nginx sementara untuk certbot challenge..."

    # Buat config nginx sementara (HTTP only, tanpa SSL)
    cat > /tmp/nginx_temp.conf << 'NGINX_TEMP'
server {
    listen 80;
    server_name _;
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
        allow all;
    }
    location / {
        return 200 'BEM Server OK';
        add_header Content-Type text/plain;
    }
}
NGINX_TEMP

    # Cleanup container lama jika ada (dari run sebelumnya yang gagal)
    docker rm -f nginx_temp 2>/dev/null || true

    # Jalankan nginx temp container
    docker run -d --rm \
        --name nginx_temp \
        -p 80:80 \
        -v /tmp/nginx_temp.conf:/etc/nginx/conf.d/default.conf:ro \
        -v "${APP_DIR}/certbot_www:/var/www/certbot" \
        nginx:1.25-alpine

    mkdir -p "${APP_DIR}/certbot_www"
    mkdir -p "${APP_DIR}/certbot_conf"

    # Download SSL helper files yang dibutuhkan Nginx
    if [ ! -f "${APP_DIR}/certbot_conf/options-ssl-nginx.conf" ]; then
        info "Download options-ssl-nginx.conf..."
        curl -fsSL https://raw.githubusercontent.com/certbot/certbot/master/certbot-nginx/certbot_nginx/_internal/tls_configs/options-ssl-nginx.conf \
            -o "${APP_DIR}/certbot_conf/options-ssl-nginx.conf"
    fi

    if [ ! -f "${APP_DIR}/certbot_conf/ssl-dhparams.pem" ]; then
        info "Download ssl-dhparams.pem..."
        curl -fsSL https://raw.githubusercontent.com/certbot/certbot/master/certbot/certbot/ssl-dhparams.pem \
            -o "${APP_DIR}/certbot_conf/ssl-dhparams.pem"
    fi

    # Request certificate (non-interactive, otomatis pakai cert yg sudah ada)
    docker run --rm \
        -v "${APP_DIR}/certbot_conf:/etc/letsencrypt" \
        -v "${APP_DIR}/certbot_www:/var/www/certbot" \
        certbot/certbot certonly \
        --webroot \
        --webroot-path=/var/www/certbot \
        --email "bembudiutomo@gmail.com" \
        --agree-tos \
        --no-eff-email \
        --non-interactive \
        --keep-until-expiring \
        -d "${DOMAIN}" \
        -d "www.${DOMAIN}"

    # Stop nginx temp
    docker stop nginx_temp || true

    log "SSL Certificate berhasil didapat!"
else
    warn "SSL dilewati. Jalankan manual nanti."
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 10: Build & Deploy Stack
# ─────────────────────────────────────────────────────────────
info "Build Docker image aplikasi..."
docker compose build --no-cache app

info "Jalankan semua container..."
docker compose up -d

# Tunggu database siap
info "Menunggu database ready..."
sleep 30

# ─────────────────────────────────────────────────────────────
# LANGKAH 11: Import data dari shared hosting (jika ada)
# ─────────────────────────────────────────────────────────────
DUMP_FILE=$(ls "${APP_DIR}/databases/dump_migration_"*.sql.gz 2>/dev/null | head -1)

if [ -n "$DUMP_FILE" ]; then
    warn "Ditemukan dump file: $(basename "$DUMP_FILE")"
    read -p "Import data dari shared hosting? (y/n): " -r do_import
    if [[ "$do_import" =~ ^[Yy]$ ]]; then
        info "Import database..."
        source .env
        zcat "$DUMP_FILE" | docker exec -i bem_db mysql \
            -u root -p"${DB_ROOT_PASS}" \
            "${DB_NAME}"
        log "Import database berhasil!"
    fi
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 12: Setup Cron untuk Backup Otomatis
# ─────────────────────────────────────────────────────────────
info "Setup cron backup otomatis..."
chmod +x "${APP_DIR}/docker/scripts/backup.sh"

# Tambah cron job (backup jam 02:00 setiap hari)
CRON_JOB="0 2 * * * ${APP_DIR}/docker/scripts/backup.sh >> ${APP_DIR}/logs/backup.log 2>&1"
# Cek apakah sudah ada
if ! crontab -l 2>/dev/null | grep -q "backup.sh"; then
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    log "Cron backup otomatis ditambahkan (setiap hari jam 02:00)"
else
    warn "Cron backup sudah ada, skip..."
fi

# ─────────────────────────────────────────────────────────────
# LANGKAH 13: Setup SSL Auto-Renewal via Cron
# ─────────────────────────────────────────────────────────────
info "Setup cron SSL auto-renewal..."
SSL_CRON="0 0 */12 * * docker compose -f ${APP_DIR}/docker-compose.yml exec certbot certbot renew --quiet"
if ! crontab -l 2>/dev/null | grep -q "certbot renew"; then
    (crontab -l 2>/dev/null; echo "$SSL_CRON") | crontab -
    log "Cron SSL renewal ditambahkan"
fi

# ─────────────────────────────────────────────────────────────
# SELESAI
# ─────────────────────────────────────────────────────────────
echo ""
echo "=================================================="
echo -e "${GREEN}✅ DEPLOYMENT SELESAI!${NC}"
echo "=================================================="
echo ""
echo "Status container:"
docker compose ps
echo ""
echo "Cek logs: docker compose logs -f"
echo "Website: https://${DOMAIN}"
echo ""
