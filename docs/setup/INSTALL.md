# 🛠️ Panduan Instalasi & Setup Teknis
## Sistem Manajemen BEM (Astawidya)

Panduan ini berisi langkah demi langkah instruksi teknis untuk menjalankan sistem di lingkungan **Lokal (PostgreSQL)** maupun **Hosting/Production (MySQL/MariaDB)**.

---

## 📋 Prasyarat Sistem
Sebelum memulai, pastikan perangkat Anda memenuhi spesifikasi berikut:
- **PHP**: Versi >= 8.1 (wajib, sesuai `composer.json`; wajib mengaktifkan ekstensi `pdo_mysql`, `pdo_pgsql`, `gd`, `mbstring`, `openssl`).
- **Database**:
  - MySQL 5.7+ / MariaDB 10.11+ (Produksi/Hosting — engine utama).
  - PostgreSQL 12+ (Lokal/Supabase — sekunder/opsional).
- **Web Server**: Apache (dengan `mod_rewrite` aktif) atau Nginx, atau PHP built-in server untuk uji lokal.

---

## 📥 Langkah 1: Persiapan Source Code
1. **Clone Repository** atau download file ZIP:
   ```bash
   git clone https://github.com/bufan354/bembudiutomo.git
   cd bembudiutomo
   ```
2. **Izin Folder (PENTING)**:
   Pastikan folder root dapat ditulis oleh web server agar sistem bisa membuat folder `uploads/` secara otomatis.
   ```bash
   # Contoh di Linux/Ubuntu
   sudo chown -R www-data:www-data /var/www/html/bembudiutomo
   chmod -R 755 /var/www/html/bembudiutomo
   ```

---

## 🗄️ Langkah 2: Setup Database
Pilih salah satu sesuai kebutuhan Anda:

### A. Menggunakan MySQL/MariaDB (Produksi/Hosting — utama)
1. Buat database baru melalui Control Panel hosting atau `CREATE DATABASE bem_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`.
2. Buka menu **phpMyAdmin** > Pilih database tersebut.
3. Klik tab **Import** > Pilih file: `databases/schema_mysql.sql` (struktur lengkap: 36 tabel aktif + 3 tabel deprecated + 3 views, tanpa data seed).
4. Klik **Go** dan tunggu hingga selesai.

### B. Menggunakan PostgreSQL (Lokal/Supabase — sekunder)
> [!NOTE]
> `schema_pgsql.sql` tidak sinkron dengan skema MySQL terbaru dan belum diperbarui. Gunakan hanya jika Anda siap menyesuaikannya manual.

1. Buat database di PostgreSQL lokal atau dashboard Supabase.
2. Jalankan perintah SQL dari file `databases/schema_pgsql.sql` melalui Query Tool (pgAdmin) atau SQL Editor Supabase.

---

## ⚙️ Langkah 3: Konfigurasi Environment

Salin file contoh konfigurasi menjadi file `.env`:

```bash
cp .env.example .env
```

Buka file `.env` dan sesuaikan nilainya:
```ini
DB_CONNECTION=mysql # 'pgsql' untuk PostgreSQL (sekunder/opsional)
DB_HOST=127.0.0.1
DB_PORT=3306 # 5432 untuk pgsql
DB_DATABASE=nama_db_anda
DB_USERNAME=username_anda
DB_PASSWORD=password_anda
DB_SSLMODE=disable # 'require' jika menggunakan SSL (mis. Supabase)
```

> [!NOTE]
> Nama variabel yang dikenal sistem: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` (atau `DB_NAME`), `DB_USERNAME` (atau `DB_USER`), `DB_PASSWORD` (atau `DB_PASS`), `DB_SSLMODE`, `BASE_URL`.

---

## 🔐 Langkah 4: Login Pertama Kali

> [!WARNING]
> **Skema SQL saat ini hanya memuat struktur tabel (tanpa data seed).** Akun admin pertama harus dibuat manual setelah impor skema, misalnya dengan INSERT berikut (hash adalah password `changeme123` — WAJIB diganti setelah login pertama):
>
> ```sql
> INSERT INTO users (username, password, nama, email, role, can_access_all, is_active)
> VALUES ('superadmin', '$2y$12$vr8fC229Upkp2ptkVKTPROTDCtCGH9n1uZB6pKOVGiw2DB1WXVdpy', 'Super Administrator', 'admin@example.com', 'superadmin', 1, 1);
> ```
>
> Hash di atas dapat dibuat ulang kapan saja: `php -r "echo password_hash('password_baru', PASSWORD_DEFAULT), PHP_EOL;"`

Setelah setup selesai, buka browser dan akses URL proyek Anda untuk masuk ke panel admin:

- **URL Admin**: `domain-anda.com/admin/`

---

## 📋 Langkah 5: Konfigurasi Awal Aplikasi
Setelah login, lakukan konfigurasi berikut agar fitur berjalan maksimal:
1. **Periode Kepengurusan**: Masuk ke menu periode dan pastikan satu periode sudah diatur sebagai **Aktif**.
2. **Data Master**: Isi data pada Master Barang, Tempat, Keterangan, dan Penanggung Jawab untuk memudahkan pengisian Rundown dan Surat.
3. **Logo Kabinet**: Masuk ke menu Kabinet untuk mengunggah logo organisasi yang akan muncul di Kop Surat PDF.

---

## 🆘 Troubleshooting
- **Error 500 / Blank Page**: Cek `error_log` di web server atau pastikan ekstensi PDO sudah aktif di `php.ini`.
- **Gambar Tidak Muncul**: Pastikan folder `uploads/` sudah memiliki izin tulis (755 atau 777).
- **Gagal Koneksi DB**: Periksa kembali `DB_HOST` dan `DB_PORT` di file `.env`. Gunakan `127.0.0.1` daripada `localhost` jika terjadi kendala pada beberapa OS.

---

**Sistem siap digunakan! 🚀**
