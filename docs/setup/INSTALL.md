# 🛠️ Panduan Instalasi & Setup Teknis
## Sistem Manajemen BPM (Astawidya)

Panduan ini berisi langkah-masing instruksi teknis untuk menjalankan sistem di lingkungan **Lokal (PostgreSQL)** maupun **Hosting/Production (MySQL)**.

---

## 📋 Prasyarat Sistem
Sebelum memulai, pastikan perangkat Anda memenuhi spesifikasi berikut:
- **PHP**: Versi 7.4 atau 8.x (Wajib mengaktifkan ekstensi `pdo_mysql`, `pdo_pgsql`, `gd`, `mbstring`).
- **Database**: 
  - MySQL 5.7+ / MariaDB 10.x (Untuk Hosting).
  - PostgreSQL 12+ (Untuk Lokal/Supabase).
- **Web Server**: Apache (dengan `mod_rewrite` aktif) atau Nginx.

---

## 📥 Langkah 1: Persiapan Source Code
1. **Clone Repository** atau download file ZIP:
   ```bash
   git clone https://github.com/bufan354/bpmbudiutomo.git
   cd bpmbudiutomo
   ```
2. **Izin Folder (PENTING)**:
   Pastikan folder root dapat ditulis oleh web server agar sistem bisa membuat folder `uploads/` secara otomatis.
   ```bash
   # Contoh di Linux/Ubuntu
   sudo chown -R www-data:www-data /var/www/html/bpmbudiutomo
   chmod -R 755 /var/www/html/bpmbudiutomo
   ```

---

## 🗄️ Langkah 2: Setup Database
Pilih salah satu sesuai kebutuhan Anda:

### A. Menggunakan MySQL (Hosting/InfinityFree)
1. Buat database baru melalui Control Panel hosting Anda (misal: `bpm_db`).
2. Buka menu **phpMyAdmin** > Pilih database tersebut.
3. Klik tab **Import** > Pilih file: `databases/schema_mysql.sql`.
4. Klik **Go** dan tunggu hingga selesai.

### B. Menggunakan PostgreSQL (Lokal/Supabase)
1. Buat database di PostgreSQL lokal atau dashboard Supabase.
2. Jalankan perintah SQL dari file `databases/schema_pgsql.sql` melalui Query Tool (pgAdmin) atau SQL Editor Supabase.

---

## ⚙️ Langkah 3: Konfigurasi Environment
Salin file contoh konfigurasi menjadi file `.env`:

- **Untuk MySQL**:
  ```bash
  cp .env.example.mysql .env
  ```
- **Untuk PostgreSQL**:
  ```bash
  cp .env.example.pgsql .env
  ```

Buka file `.env` dan sesuaikan nilainya:
```ini
DB_CONNECTION=mysql # atau pgsql
DB_HOST=127.0.0.1
DB_PORT=3306 # 5432 untuk pgsql
DB_DATABASE=nama_db_anda
DB_USERNAME=username_anda
DB_PASSWORD=password_anda
DB_SSL_MODE=disable # 'require' jika menggunakan Supabase
```

---

## 🔐 Langkah 4: Login Pertama Kali
Setelah setup selesai, buka browser dan akses URL proyek Anda. Gunakan kredensial default berikut untuk masuk ke panel admin:

- **URL Admin**: `domain-anda.com/admin/login.php`
- **Username**: `superadmin`
- **Password**: `admin1234`

> [!IMPORTANT]
> Segera ganti password Anda di menu **Manajemen Admin** setelah berhasil login untuk menjaga keamanan sistem.

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
