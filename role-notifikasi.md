# Catatan Implementasi: Pembagian Notifikasi Berdasarkan Role (Role-Based Notification Dispatch)

Dokumen ini berisi hasil analisis mendalam dan panduan langkah demi langkah untuk merestrukturisasi sistem notifikasi di **BEM Management System** agar notifikasi dikirim secara terarah (*segmented & role-targeted*) dan tidak lagi ter-broadcast ke seluruh user.

---

## 1. Analisis Masalah Saat Ini (Root Cause Analysis)

### Masalah Utama
Saat ini, semua pengguna (`superadmin`, `admin`, `sekretaris`, `kominfo`, `anggota`) menerima seluruh notifikasi sistem tanpa adanya pemisahan hak akses (termasuk notifikasi sensitif seperti login Google OAuth, reset password, 2FA, dan manajemen user).

### Penyebab Utama di Kode:
1. **Pemicu Otomatis Audit Log (`includes/functions.php` - `auditLog()`)**:
   Dalam fungsi `auditLog()`, setiap ada rekaman aktivitas sistem, terdapat blok kode:
   ```php
   $activeUsers = dbFetchAll("SELECT id FROM users WHERE is_active = 1");
   foreach ($activeUsers as $u) {
       dbQuery(
           "INSERT INTO notifikasi (user_id, judul, pesan, link, tipe) VALUES (?, ?, ?, ?, ?)",
           [(int)$u['id'], $notifTitle, $deskripsi, $notifLink, $notifType]
       );
   }
   ```
   Blok kode ini secara tidak selektif mengambil **semua ID user aktif** (`SELECT id FROM users WHERE is_active = 1`) dan memasukkan notifikasi ke seluruh tabel `notifikasi`.

2. **Belum Ada Filtering Berbasis Matriks Role**:
   Fungsi penerbitan notifikasi belum memetakan target penerima berdasarkan **System Role** (`users.role`) maupun **Event-Level Role** (`kegiatan_panitia.event_role`).

---

## 2. Matriks Pembagian Notifikasi Berdasarkan Role (Diperbarui)

Sistem membagi notifikasi ke dalam **7 Kategori Utama** dengan penyesuaian hak akses role sebagai berikut:

| Kategori Notifikasi | Deskripsi & Contoh Kejadian | Tipe Icon / Visual | Target System Role (`users.role`) | Target Event Role (`kegiatan_panitia`) |
| :--- | :--- | :--- | :--- | :--- |
| **Keamanan & Keanggotaan** | Login OAuth/Normal, Reset Password, Aktivasi/Nonaktifkan 2FA, Tambah/Hapus Admin, Putus Sesi, Backup DB, Lockout IP | `danger` / `keamanan` | `superadmin` | - |
| **Persuratan & Dokumen** | Staging surat baru, Verifikasi/ACC Surat Sekretaris, Auto-commit Surat 30 Menit, Arsip Berita Acara | `info` / `surat` | `superadmin`, `admin`, `sekretaris` | `sekretaris_panitia`, `ketuplat` |
| **Kegiatan & Proker** | Tambah/Edit Kegiatan, Status Kegiatan, Pendaftaran Anggota BEM Baru (Pending/Approval) | `success` / `kegiatan` | `superadmin`, `admin`, `sekretaris` | `ketuplat`, `anggota_panitia` |
| **Rundown & Acara** | Update Rundown Event, Perubahan Jam Acara, Penunjukan PJ Agenda | `warning` / `rundown` | `superadmin`, `admin`, `sekretaris` | `ketuplat`, `sie_acara` |
| **Logistik & Sarpras** | Pengajuan Peminjaman Barang/Tempat, Update Inventaris Barang Master, Surat Peminjaman | `info` / `logistik` | `superadmin`, `admin`, `sekretaris` | `ketuplat`, `sie_logistik` |
| **Publikasi & CMS** | Drafter/Publish Berita Web, Update Banner Kabinet, Update Struktur Organisasi | `success` / `berita` | `superadmin`, `admin`, `kominfo` | - |
| **LPJ & Keuangan** | Pembuatan Dokumen LPJ Triwulan, Transaksi Anggaran LPJ, Evaluasi Kinerja | `info` / `lpj` | `superadmin`, `admin`, `sekretaris` | `ketuplat` |

---

## 3. Desain Arsitektur Kode Baru

### 3.1. Fungsi Helper Utama: `getTargetUserIdsByRole()`
Fungsi helper terpusat di `includes/functions.php` untuk memfilter ID pengguna yang berhak menerima notifikasi:

```php
/**
 * Mendapatkan daftar ID user yang berhak menerima notifikasi berdasarkan System Role dan Event Role.
 */
function getTargetUserIdsByRole(array $systemRoles = [], ?int $kegiatanId = null, array $eventRoles = []): array {
    $userIds = [];

    // 1. Filter berdasarkan System Role (users.role)
    if (!empty($systemRoles)) {
        $inRoles = implode(',', array_fill(0, count($systemRoles), '?'));
        $rows = dbFetchAll(
            "SELECT id FROM users WHERE role IN ($inRoles) AND is_active = 1",
            $systemRoles
        );
        foreach ($rows as $r) {
            $userIds[] = (int)$r['id'];
        }
    }

    // 2. Filter berdasarkan Event-Level Role (kegiatan_panitia.event_role)
    if ($kegiatanId > 0 && !empty($eventRoles)) {
        $inEvRoles = implode(',', array_fill(0, count($eventRoles), '?'));
        $params = array_merge([$kegiatanId], $eventRoles);
        $rows = dbFetchAll(
            "SELECT user_id FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role IN ($inEvRoles)",
            $params
        );
        foreach ($rows as $r) {
            $userIds[] = (int)$r['user_id'];
        }
    }

    // Selalu pastikan unik dan tidak ada nilai 0 / null
    return array_values(array_unique(array_filter($userIds)));
}
```

### 3.2. Refactoring Fungsi `auditLog()`
Pemetaan target pengguna di dalam fungsi `auditLog()` (`includes/functions.php`):

```php
// Pemetaan Target Role Berdasarkan Tabel yang Berubah
$targetRolesMap = [
    'users'                 => ['superadmin'],
    'user_sessions'         => ['superadmin'],
    'audit_log'             => ['superadmin'],
    'database'              => ['superadmin'],
    'periode_kepengurusan'  => ['superadmin', 'admin'],
    'arsip_surat'           => ['superadmin', 'admin', 'sekretaris'],
    'arsip_berita_acara'    => ['superadmin', 'admin', 'sekretaris'],
    'lpj_dokumen'           => ['superadmin', 'admin', 'sekretaris'],
    'berita'                => ['superadmin', 'admin', 'kominfo'],
    'struktur_organisasi'   => ['superadmin', 'admin', 'kominfo'],
    'kegiatan'              => ['superadmin', 'admin', 'sekretaris'],
    'barang_master'         => ['superadmin', 'admin', 'sekretaris'],
    'tempat_master'         => ['superadmin', 'admin', 'sekretaris']
];

$targetSystemRoles = $targetRolesMap[$targetTable ?? ''] ?? ['superadmin'];

// Ambil ID pengguna yang berhak saja (bukan seluruh user)
$targetUserIds = getTargetUserIdsByRole($targetSystemRoles);

foreach ($targetUserIds as $targetId) {
    dbQuery(
        "INSERT INTO notifikasi (user_id, judul, pesan, link, tipe) VALUES (?, ?, ?, ?, ?)",
        [$targetId, $notifTitle, $deskripsi, $notifLink, $notifType]
    );
}
```

---

## 4. Rencana Modifikasi Per Modul Aplikasi

### 4.1. Modul Keamanan & Auth
- **File**: `admin/auth/google-callback.php`, `admin/system/kelola-admin.php`, `admin/system/pengaturan.php`, `admin/auth/2fa-setup.php`
- **Tindakan**: Notifikasi keamanan (login attempt, ganti password, 2FA, kelola admin) hanya dikirim ke user dengan role `superadmin`.

### 4.2. Modul Persuratan (`admin/surat/`)
- **File**: `admin/surat/staging-surat.php`, `admin/surat/distribusi-surat.php`
- **Tindakan**:
  - Pendaftaran/draft surat baru -> target `['superadmin', 'admin', 'sekretaris']` dan panitia event `sekretaris_panitia`.
  - Surat siap disebar (Humas) -> target `['superadmin', 'admin', 'sekretaris']` dan panitia event `sie_humas`.

### 4.3. Modul Kegiatan, Rundown & Logistik (`admin/kegiatan/` & `admin/rundown/`)
- **File**: `admin/kegiatan/workspace-rundown.php`, `admin/kegiatan/workspace-logistik.php`
- **Tindakan**:
  - Update Rundown -> target `['superadmin', 'admin', 'sekretaris']` dan panitia event `['ketuplat', 'sie_acara']`.
  - Update Logistik Sarpras -> target `['superadmin', 'admin', 'sekretaris']` dan panitia event `['ketuplat', 'sie_logistik']`.

### 4.4. Modul Publikasi Berita & CMS (`admin/konten/`)
- **File**: `admin/konten/berita-edit.php`, `admin/konten/pendaftaran.php`
- **Tindakan**:
  - Publish Berita -> target `['superadmin', 'admin', 'kominfo']`.
  - Pendaftar Anggota BEM Baru (`daftar.php`) -> target `['superadmin', 'admin', 'sekretaris']`.

---

## 5. Skrip Pembersihan Notifikasi Lama (Cleanup SQL)

Untuk membersihkan notifikasi keamanan lama yang pernah terkirim ke pengguna non-superadmin:

```sql
-- Hapus notifikasi keamanan dari user yang bukan superadmin
DELETE n FROM notifikasi n
JOIN users u ON n.user_id = u.id
WHERE u.role != 'superadmin'
  AND (
      n.judul LIKE '%Keamanan%'
   OR n.judul LIKE '%Audit%'
   OR n.pesan LIKE '%Login%'
   OR n.pesan LIKE '%Google OAuth%'
   OR n.pesan LIKE '%2FA%'
   OR n.pesan LIKE '%password%'
  );

-- Auto cleanup notifikasi yang sudah usang (> 7 hari)
DELETE FROM notifikasi 
WHERE created_at < NOW() - INTERVAL 7 DAY;
```

---

## 6. Panduan Pengujian (Testing & Verification)

1. **Uji Login Google OAuth / Password Reset**:
   - Login menggunakan akun selain `superadmin` (misal `kominfo` atau `sekretaris`).
   - Verifikasi panel notifikasi pada akun `kominfo` / `sekretaris` -> Notifikasi keamanan **TIDAK Boleh Muncul**.
   - Buka panel notifikasi akun `superadmin` -> Notifikasi keamanan **MUNCUL**.

2. **Uji Update Rundown Event**:
   - Update rundown kegiatan sebagai `sie_acara`.
   - Verifikasi akun `sie_acara`, `ketuplat`, `sekretaris`, `admin`, dan `superadmin` menerima notifikasi.
   - Verifikasi akun `kominfo` / user luar panitia **TIDAK menerima** notifikasi rundown tersebut.

3. **Uji Logistik Sarpras & Publikasi Berita**:
   - Tambah/Update logistik sarpras -> `sekretaris` dan `admin` mendapatkan notifikasi.
   - Publish Berita -> `admin`, `kominfo`, dan `superadmin` mendapatkan notifikasi.

---
*Dokumen ini diperbarui untuk mengakomodasi penambahan role `sekretaris` pada Rundown & Logistik serta role `admin` pada Publikasi Berita.*
