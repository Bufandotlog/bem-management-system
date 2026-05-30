# 🏛️ Sistem Manajemen BEM (Astawidya)

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20PostgreSQL-orange.svg)](#)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Sistem informasi manajemen organisasi Badan Eksekutif Mahasiswa (BEM) yang dirancang untuk mengotomatisasi administrasi surat-menyurat, pengarsipan rundown acara, serta sinkronisasi logistik inventaris secara cerdas dan responsif.

---

## ✨ Fitur Unggulan

- **📂 Arsip Digital Cerdas**: Pengelolaan surat masuk, surat keluar, dan lampiran secara sistematis dengan sistem penomoran otomatis.
- **⏱️ Rundown Generator**: Pembuatan susunan acara dinamis dengan kalkulasi waktu otomatis (Durasi Jam & Menit) serta dukungan multi-hari.
- **📦 Inventory Sync**: Sinkronisasi data master barang dan tempat secara real-time ke dalam dokumen cetak (PDF).
- **📱 Premium Responsive UI**: Antarmuka dashboard modern yang dioptimalkan untuk perangkat mobile (Dark Mode Support & Glassmorphism Design).
- **🔗 Hybrid Database**: Mendukung arsitektur database ganda (MySQL & PostgreSQL) untuk fleksibilitas deployment.
- **🛡️ Security First**: Dilengkapi CSRF Protection, Password Hashing, Audit Logging, dan Session Management.

---

## 🛠️ Persyaratan Sistem

- **Server**: Apache / Nginx
- **Bahasa**: PHP 7.4 / 8.x
- **Database**: MySQL 5.7+ atau PostgreSQL 12+
- **Ekstensi PHP**: `pdo`, `gd`, `mbstring`, `openssl`

---

## 📚 Dokumentasi Proyek

Untuk mempermudah pemahaman teknis dan pemeliharaan jangka panjang, dokumentasi sistem telah dikelompokkan ke dalam folder domain terstruktur di bawah direktori `docs/`:

```text
docs/
├── 📁 setup/
│   └── 📄 INSTALL.md                     # Panduan setup & instalasi server
├── 📁 architecture/
│   └── 📄 PANDUAN_LOKAL_DAN_ARSITEKTUR.md # Struktur kode & panduan kelola admin
└── 📁 persuratan/
    └── 📄 SISTEM_PERSURATAN.md           # Analisis teknis & modul surat JSON
```

Berikut adalah tautan cepat ke masing-masing domain dokumentasi:

1. 🛠️ [**Domain Setup & Instalasi**](docs/setup/INSTALL.md) — Langkah setup lokal (Laragon/XAMPP/Built-in) & Database (PostgreSQL/MySQL).
2. 💻 [**Domain Arsitektur & Pengelolaan**](docs/architecture/PANDUAN_LOKAL_DAN_ARSITEKTUR.md) — Panduan data master BEM, 2FA security, serta pemetaan file-by-file.
3. ✉️ [**Domain Modul Persuratan**](docs/persuratan/SISTEM_PERSURATAN.md) — Cara kerja data persistence, skema JSON kolom `konten_surat`, PDF.js merger, dan pembersihan HTML WA.

---

## 🏗️ Struktur Proyek

- `/admin`: Panel kontrol administrasi dan manajemen data.
- `/config`: File konfigurasi database dan environment.
- `/databases`: Skema database SQL (MySQL & PostgreSQL).
- `/includes`: Fungsi inti, keamanan, dan logika aplikasi.
- `/uploads`: Lokasi penyimpanan file media dan dokumen (otomatis dibuat).

---

## 📄 Lisensi
Proyek ini dilisensikan di bawah **MIT License**.

**Dikembangkan dengan ❤️ untuk kemajuan organisasi mahasiswa.**
