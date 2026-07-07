#!/bin/bash
# ==============================================================================
# CLOUDFLARE DOCKER-USER FIREWALL SCRIPT (VERSI PERBAIKAN OUTBOUND)
# ==============================================================================
# Script ini secara otomatis mengonfigurasi iptables dan ip6tables pada chain
# DOCKER-USER dengan membatasi blokir hanya pada interface publik (incoming).
# Ini mencegah pemblokiran koneksi keluar (outbound) dari dalam container.
# ==============================================================================

# Pastikan script dijalankan sebagai root
if [ "$EUID" -ne 0 ]; then
  echo "Error: Silakan jalankan script ini sebagai root (sudo)."
  exit 1
fi

echo "=== Memulai Konfigurasi Firewall DOCKER-USER ==="

# Auto-detect interface internet utama (default gateway interface)
DEFAULT_INTF=$(ip route show | grep '^default' | awk '{print $5}' | head -n 1)
if [ -z "$DEFAULT_INTF" ]; then
    DEFAULT_INTF="eth0"
fi

DEFAULT_INTF_V6=$(ip -6 route show | grep '^default' | awk '{print $5}' | head -n 1)
if [ -z "$DEFAULT_INTF_V6" ]; then
    DEFAULT_INTF_V6="$DEFAULT_INTF"
fi

echo " -> Interface publik terdeteksi: IPv4 ($DEFAULT_INTF), IPv6 ($DEFAULT_INTF_V6)"

# 1. Mengosongkan (flush) chain DOCKER-USER lama
echo "[1/5] Mematikan aturan DOCKER-USER lama..."
iptables -F DOCKER-USER
ip6tables -F DOCKER-USER

# 2. Mengizinkan koneksi yang sudah terjalin (ESTABLISHED, RELATED)
# Sangat krusial agar respon dari koneksi keluar tidak diblokir
echo "[2/5] Mengizinkan koneksi ESTABLISHED & RELATED..."
iptables -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
ip6tables -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN

# 3. Mengizinkan koneksi internal loopback (localhost)
echo "[3/5] Mengizinkan koneksi internal loopback (localhost)..."
iptables -A DOCKER-USER -i lo -j RETURN
ip6tables -A DOCKER-USER -i lo -j RETURN

# 4. Mengunduh IP Cloudflare dan mendaftarkannya ke whitelist
echo "[4/5] Mengunduh IP Cloudflare dan mendaftarkannya ke whitelist..."

# IPv4
CF_IPS_V4=$(curl -s https://www.cloudflare.com/ips-v4)
if [ -z "$CF_IPS_V4" ]; then
    echo "Error: Gagal mengunduh daftar IPv4 Cloudflare. Proses dibatalkan."
    exit 1
fi

for ip in $CF_IPS_V4; do
    iptables -A DOCKER-USER -i "$DEFAULT_INTF" -s "$ip" -p tcp -m multiport --dports 80,443 -j RETURN
done
echo " -> IPv4 Cloudflare berhasil didaftarkan."

# IPv6
CF_IPS_V6=$(curl -s https://www.cloudflare.com/ips-v6)
if [ -z "$CF_IPS_V6" ]; then
    echo "Warning: Gagal mengunduh daftar IPv6 Cloudflare. Melanjutkan tanpa IPv6."
else
    for ip in $CF_IPS_V6; do
        ip6tables -A DOCKER-USER -i "$DEFAULT_INTF_V6" -s "$ip" -p tcp -m multiport --dports 80,443 -j RETURN
    done
    echo " -> IPv6 Cloudflare berhasil didaftarkan."
fi

# 5. Blokir trafik port 80 & 443 yang masuk lewat interface luar (bukan dari Cloudflare)
echo "[5/5] Memblokir akses langsung masuk ke port 80 & 443 dari luar..."
iptables -A DOCKER-USER -i "$DEFAULT_INTF" -p tcp -m multiport --dports 80,443 -j DROP
ip6tables -A DOCKER-USER -i "$DEFAULT_INTF_V6" -p tcp -m multiport --dports 80,443 -j DROP

# 6. Izinkan trafik lainnya (outbound dari container, SSH, port lain)
iptables -A DOCKER-USER -j RETURN
ip6tables -A DOCKER-USER -j RETURN

echo "=== Firewall DOCKER-USER Sukses Dikonfigurasi ==="
echo "Gunakan perintah ini untuk memeriksa aturan:"
echo "sudo iptables -L DOCKER-USER -v -n"
