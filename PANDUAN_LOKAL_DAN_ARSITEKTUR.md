# PANDUAN TEKNIS LOKAL & DOKUMENTASI ARSITEKTUR KODE
## Sistem Manajemen Administrasi BEM (Astawidya)

Dokumen ini menyajikan panduan lengkap mengenai cara kerja sistem, cara menjalankan dan mengelolanya secara lokal di PC/laptop Anda, serta penjelasan detail untuk setiap berkas (*file-by-file*) dalam arsitektur aplikasi ini.

---

### Bagian 1: Panduan Setup Proyek di Lokal

#### Kebutuhan Perangkat & Perangkat Lunak
Untuk menjalankan aplikasi ini secara lokal, pastikan perangkat Anda telah memenuhi prasyarat berikut:
1. **PHP (versi 8.0 ke atas sangat direkomendasikan)**
   * Ekstensi wajib yang harus diaktifkan di `php.ini` Anda:
     * `pdo_mysql` (untuk database MySQL/MariaDB)
     * `pdo_pgsql` (untuk database PostgreSQL)
     * `gd` (untuk mengolah gambar seperti Kop Surat dan TTD digital)
     * `mbstring` (untuk pemrosesan multibyte string)
     * `openssl` (untuk keamanan autentikasi 2FA)
2. **Database Server**
   * **MySQL/MariaDB** (melalui paket server XAMPP, Laragon, atau MAMP)
   * **ATAU PostgreSQL** (melalui instalasi pgAdmin lokal atau container Docker)
3. **Web Server**
   * Apache / Nginx
   * **ATAU** cukup menggunakan **PHP Built-in Server** bawaan PHP (sangat praktis dan direkomendasikan untuk uji coba lokal).

---

#### Langkah-Langkah Menjalankan di Lokal

##### Langkah 1: Mempersiapkan Folder Proyek
1. Salin seluruh direktori proyek ini ke dalam folder root web server Anda (misal `C:/xampp/htdocs/bem/` di XAMPP, atau `/var/www/html/bem/` di Linux).
2. Pastikan folder `/uploads/` memiliki izin baca dan tulis yang cukup (di Linux/macOS: `chmod -R 777 uploads/`). Folder ini digunakan untuk menyimpan berkas Kop Surat, tanda tangan elektronik pengurus, lampiran PDF eksternal, dan gambar berita.

##### Langkah 2: Pembuatan & Impor Database
Sistem ini dirancang secara *hybrid* dan dapat berjalan di atas MySQL maupun PostgreSQL.
* **Jika menggunakan MySQL (Laragon/XAMPP):**
  1. Buka phpMyAdmin, buat database baru bernama `bem_astawidya`.
  2. Impor berkas skema MySQL dari: `databases/schema_mysql.sql`.
* **Jika menggunakan PostgreSQL (Lokal/Supabase):**
  1. Buka pgAdmin, buat database baru bernama `bem_astawidya`.
  2. Jalankan kueri SQL dari berkas skema PostgreSQL: `databases/schema_pgsql.sql`.

##### Langkah 3: Konfigurasi Environment File (`.env`)
Salin berkas template `.env` sesuai dengan database pilihan Anda. Di root direktori proyek Anda:
* **Untuk PostgreSQL (Rekomendasi Lokal):**
  Salin berkas `.env.example.pgsql` menjadi `.env`
* **Untuk MySQL:**
  Salin berkas `.env.example.mysql` menjadi `.env`

Buka berkas `.env` tersebut dan sesuaikan konfigurasinya:
```ini
DB_CONNECTION=pgsql # Ubah menjadi 'mysql' jika menggunakan XAMPP/Laragon
DB_HOST=127.0.0.1
DB_PORT=5432 # Gunakan 3306 untuk MySQL
DB_DATABASE=bem_astawidya
DB_USERNAME=postgres # Sesuaikan dengan username database Anda
DB_PASSWORD=password_anda # Sesuaikan dengan password database Anda
DB_SSL_MODE=disable

# BASE_URL adalah alamat URL akses proyek Anda di browser
# SANGAT PENTING: Wajib diakhiri dengan tanda garis miring (/)
BASE_URL=http://localhost:8000/
APP_ENV=development
```

##### Langkah 4: Menjalankan Aplikasi
Cara tercepat tanpa konfigurasi *Virtual Host* yang rumit adalah menggunakan built-in web server bawaan PHP:
1. Buka terminal atau Command Prompt (CMD) di folder root proyek Anda.
2. Jalankan perintah berikut:
   ```bash
   php -S localhost:8000
   ```
3. Buka browser Anda dan akses: `http://localhost:8000/`

##### Langkah 5: Login Pertama Kali ke Admin Panel
* **URL Panel Admin:** `http://localhost:8000/admin/login.php`
* **Username Default:** `superadmin`
* **Password Default:** `admin1234`
* *Segera masuk ke menu **Kelola Admin** setelah login pertama kali untuk mengubah password default demi keamanan akun Anda.*

---

### Bagian 2: Panduan Mengelola Sistem (Administration & Management)

Sistem BEM (Astawidya) didesain agar mudah dioperasikan secara mandiri dari halaman admin:

1. **Mengaktifkan Periode Kepengurusan (Langkah Wajib Pertama)**
   * Masuk ke **Periode Kepengurusan** (`/admin/periode-kepengurusan.php`).
   * Buat periode baru (misalnya: Periode 2026-2027) dan tandai sebagai **"Aktif"**. Sistem hanya akan menampilkan dan memproses surat-menyurat yang masuk dalam periode kepengurusan yang sedang aktif. Anda juga bisa berganti periode dengan cepat melalui menu `/admin/ganti-periode.php`.

2. **Mengisi Data Master (Inventaris & Lokasi)**
   * Sebelum Anda membuat surat peminjaman barang atau menyusun susunan acara (rundown), isi terlebih dahulu master data berikut:
     * **Master Barang** (`/admin/master-barang.php`): Menyimpan daftar inventaris BEM (cth: Sound System, Proyektor).
     * **Master Tempat** (`/admin/master-tempat.php`): Daftar lokasi kegiatan (cth: Aula, Gedung Serbaguna).
     * **Master Penanggung Jawab** (`/admin/master-penanggung-jawab.php`): Nama & jabatan penanggung jawab sesi rundown.

3. **Mengunggah Kop Surat & Profil Kabinet**
   * Masuk ke menu **Visi, Misi & Kabinet** (`/admin/kabinet.php`).
   * Unggah Logo Kabinet Anda. Logo ini otomatis akan di-render di pojok kiri atas pada KOP Surat resmi saat mencetak surat dalam format PDF/Cetak Dokumen.

4. **Konfigurasi Template Surat**
   * Masuk ke menu **Pengaturan Surat** (`/admin/pengaturan-surat.php`).
   * Anda bisa menambahkan template perihal surat (cth: "Undangan Rapat"), sasaran tujuan (cth: "Badan Perwakilan Mahasiswa"), tempat pelaksanaan, dan kode surat. Ini akan mempercepat pembuatan surat baru karena sekretaris tinggal mengklik saran pencarian saat mengetik form surat.

5. **Manajemen Admin & Keamanan 2FA**
   * Untuk keamanan ekstra, sekretaris dan admin dapat mengaktifkan **Autentikasi Dua Faktor (2FA)** via Google Authenticator di menu `/admin/2fa-setup.php`. Setiap login akan membutuhkan kode OTP dinamis dari ponsel admin.
   * Catatan audit semua tindakan admin disimpan dengan aman di `/admin/audit-log.php`.

---

### Bagian 3: Penjelasan Berkas & Struktur Folder Lengkap

Berikut adalah pemetaan arsitektur direktori dan berkas beserta fungsinya masing-masing dalam sistem:

#### 1. Direktori `/config/` (Konfigurasi Inti)
Direktori ini berisi seluruh pengaturan koneksi database, penentuan path url, serta sistem deteksi lingkungan (env).

| Nama Berkas | Fungsi dan Kegunaan |
| :--- | :--- |
| `app.php` | Menginisialisasi sistem, membaca berkas `.env`, mengatur zona waktu, serta mendefinisikan konstanta global aplikasi. |
| `database.php` | Mengelola koneksi database secara terpusat (*DB Connection Pool*) berbasis PDO. File ini mendeteksi apakah sistem menggunakan MySQL (`pdo_mysql`) atau PostgreSQL (`pdo_pgsql`) dan secara otomatis menyesuaikan kueri SQL. |
| `path-detection.php` | Secara dinamis mendeteksi alamat `BASE_URL` and `UPLOAD_PATH` baik ketika diakses via browser maupun ketika dieksekusi via CLI (terminal). |
| `database-backup.php` | Menyimpan fungsi-fungsi utilitas untuk melakukan ekspor struktur dan data tabel guna keperluan cadangan (backup). |

---

#### 2. Direktori `/includes/` (Helper & Library Internal)
Berisi pustaka kode yang digunakan berulang kali di seluruh halaman aplikasi.

| Nama Berkas | Fungsi dan Kegunaan |
| :--- | :--- |
| `functions.php` | **Jantung dari fungsionalitas sistem.** Berisi helper autentikasi, sanitasi input (XSS protection), pembuatan CSRF token, formatter tanggal Indonesia, log audit, serta *wrapper* query database (`dbQuery`, `dbFetchAll`, `dbFetchOne`). |
| `totp.php` | Pustaka kriptografi ringan untuk memverifikasi kunci rahasia TOTP (Google Authenticator) untuk fitur 2FA. |
| `/dompdf/` (Folder) | Pustaka pihak ketiga (**Dompdf v2.0.3**) untuk merender template HTML menjadi dokumen PDF statis (digunakan pada modul cetak lampiran). |

---

#### 3. Halaman Publik (Root Folder `/`)
Halaman-halaman publik yang dapat diakses oleh mahasiswa umum tanpa perlu login.

| Nama Berkas | Fungsi dan Kegunaan |
| :--- | :--- |
| `index.php` | Beranda utama yang menampilkan sekilas visi misi kabinet, daftar berita terbaru, struktur organisasi BEM, dan formulir hubungi kami. |
| `berita.php` | Menampilkan seluruh daftar berita/artikel/pengumuman resmi BEM dengan fitur pencarian dan paginasi. |
| `berita-detail.php` | Menampilkan konten penuh suatu berita dengan tampilan premium, responsif, dan ramah SEO. |
| `kepengurusan.php` | Halaman publik yang menampilkan bagan visual interaktif struktur organisasi BEM (Kabinet, BPH, Kementerian, dan Anggota). |
| `kontak.php` | Halaman yang berisi info kontak resmi kesekretariatan dan formulir bagi mahasiswa umum untuk mengirim pesan atau saran. |
| `config.php` | Berkas bootstrap root yang memuat `/config/app.php` agar berkas di root direktori dapat terhubung ke basis data. |
| `header.php` & `footer.php` | Tata letak (layout) navigasi atas dan bawah untuk halaman publik dengan desain premium, responsif, dan mendukung *dark mode*. |

---

#### 4. Direktori `/admin/` (Panel Administrasi Inti)
Berisi seluruh sistem manajemen administrasi surat menyurat, rundown, inventaris, dan hak akses.

##### A. Sistem Autentikasi & Pengguna
* `login.php` & `logout.php`: Halaman masuk dan keluar dari panel kontrol admin. Dilengkapi proteksi brute force dan CSRF.
* `auth-check.php`: Skrip proteksi session keamanan yang disisipkan di atas setiap file admin untuk mencegah bypass URL.
* `2fa-setup.php` & `2fa-verify.php`: Pengaturan awal dan verifikasi gerbang keamanan kedua menggunakan Google Authenticator.
* `kelola-admin.php`: Manajemen akun admin, menambah sekretaris/bendahara baru, serta mengubah hak akses.

##### B. Dashboard & Log Keamanan
* `dashboard.php`: Pusat visual administrasi yang menampilkan grafik statistik surat masuk/keluar, lampiran, rundown, serta aktivitas log terbaru.
* `audit-log.php`: Halaman pelacak log keamanan untuk melacak semua operasi database (Siapa melakukan apa, kapan, dan dari IP mana).
* `backup-database.php`: Fitur sekali-klik untuk mengunduh cadangan database `.sql` langsung dari browser.
* `ganti-periode.php`: Fitur sakelar cepat untuk beralih data ke tahun kepengurusan BEM lainnya.

##### C. Pembuatan & Cetak Surat Resmi (Core Module)
* `buat-surat.php`: Form pembuatan surat keluar dan surat dalam tercanggih. Dilengkapi:
  * Fitur pencarian saran template perihal, tujuan, dan lokasi.
  * *Mini Rich Text Editor* (RTE) untuk menulis isi surat secara tebal/miring.
  * *Signature Pad* (papan tanda tangan elektronik) berbasis HTML5 Canvas untuk melukis tanda tangan langsung di layar.
  * Sistem multi-upload dokumen PDF eksternal sebagai lampiran fisik.
* `arsip-surat.php`: Daftar arsip seluruh surat yang pernah dibuat. Menyediakan fitur:
  * Fitur **"Salin Redaksi"** (menyalin teks surat rapi ber-emoji bebas HTML tag untuk langsung ditempel ke WhatsApp/sosial media).
  * Pencarian instan dan filter surat berdasarkan periode/jenis.
  * Fitur hapus, edit, duplikat (clone), dan ekspor massal.
* `cetak-surat.php`: Halaman pratampilan cetak surat resmi BEM. Menggunakan CSS `@media print` presisi tinggi untuk tata letak kertas A4 standar surat resmi. Menggunakan library **PDF.js v2.16.105** di sisi browser untuk merender secara otomatis lampiran-lampiran PDF luar yang diunggah sekretaris agar tercetak menyatu di halaman akhir surat.

##### D. Manajemen Lampiran Peminjaman & Rundown (Visual Module)
* `arsip-lampiran.php`: Halaman manajemen arsip dokumen lampiran peminjaman barang/tempat.
* `cetak-lampiran.php` & `cetak-lampiran-pdf.php`: Form penyuntingan visual dan render cetak lampiran daftar barang yang dipinjam lengkap dengan tabel garis batas profesional dan pencegahan pemotongan baris tabel antar halaman.
* `arsip-rundown.php`: Pengarsipan dan daftar susunan acara (rundown) seluruh program kerja BEM.
* `cetak-rundown.php` & `cetak-rundown-pdf.php`: Editor susunan acara interaktif berbasis baris acara (hari/tanggal, durasi waktu, nama agenda, lokasi, dan penanggung jawab) serta pencetakan dokumen PDF.

##### E. Pengaturan, Master Data & Konten Publik
* `pengaturan.php`: Mengonfigurasi data nama organisasi, nama perguruan tinggi, alamat kesekretariatan, serta tanda tangan & stempel Warek III / Presiden Mahasiswa.
* `pengaturan-surat.php`: Menyimpan data saran template cepat (perihal, tujuan, tempat) agar pengetikan surat sangat efisien.
* `periode-kepengurusan.php`: Membuat, mengaktifkan, dan menonaktifkan tahun periode kepengurusan BEM.
* `visi-misi.php`: Mengelola teks visi & misi resmi organisasi untuk ditampilkan di halaman beranda publik.
* `upload-struktur.php`: Mengunggah diagram gambar struktur organisasi kabinet.
* `berita.php` & `berita-edit.php` & `berita-hapus.php`: Manajemen CRUD artikel portal berita mahasiswa BEM.
* `kabinet.php`: Mengelola nama kabinet dan mengunggah logo resmi kabinet BEM.
* `/master-...php`: Modul master data (barang, tempat, penanggung jawab, dll.) untuk menyuplai data pilihan pada rundown dan lampiran.

---

### Bagian 4: Pemeliharaan & Troubleshooting Umum di Lokal

1. **Gagal Mengunggah Berkas / Gambar TTD Terpotong**
   * Pastikan folder `uploads/` serta sub-foldernya (`uploads/ttd/`, `uploads/umum/lampiran/`) memiliki izin akses tulis. Jika menggunakan OS Linux/macOS, jalankan:
     ```bash
     chmod -R 777 uploads
     ```
   * **Spesifikasi Batasan Ukuran Upload PHP:** Jika Anda menemui error saat mengunggah berkas PDF lampiran berukuran sangat besar, hal ini biasanya disebabkan oleh batasan default PHP. Anda dapat meningkatkannya dengan mengedit berkas `php.ini` Anda dan menyesuaikan konfigurasi berikut:
     ```ini
     upload_max_filesize = 20M
     post_max_size = 24M
     ```
     *Setelah memperbarui `php.ini`, pastikan untuk merestart web server Anda (Apache/Nginx/PHP Server) agar perubahan tersebut diterapkan.*
2. **Masalah CORS saat Memuat Lampiran PDF Luar**
   * Di dalam file `cetak-surat.php`, pemanggilan lampiran PDF eksternal sudah disempurnakan menggunakan alamat relatif aman (`../uploads/umum/lampiran/...`).
   * Pastikan browser Anda tidak memblokir local resource fetch. Menggunakan web server bawaan PHP (`php -S localhost:8000`) menjamin bebas masalah CORS karena berjalan di bawah asal port yang sama (*same-origin*).
3. **Database Error: Connection Refused**
   * Periksa apakah status MySQL/PostgreSQL Anda sudah menyala.
   * Di Windows, kadangkala terjadi hambatan resolusi nama host. Jika koneksi gagal menggunakan `DB_HOST=localhost`, gantilah menjadi IP loopback eksplisit `DB_HOST=127.0.0.1` pada berkas `.env` Anda.
