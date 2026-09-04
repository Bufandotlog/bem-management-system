<?php
// admin/core/dashboard.php
// Main Controller & View Router for BEM Administrative Dashboard

require_once __DIR__ . '/header.php';

// 1. Context & User Credentials
$admin_role = $_SESSION['admin_role'] ?? 'anggota';
$periode_id = getUserPeriode();
$user_id    = $_SESSION['admin_id'] ?? 0;

// 2. Check Event Role (Panitia Context) di Kegiatan Aktif
$active_panitia = dbFetchOne(
    "SELECT kp.event_role, k.id as kegiatan_id, k.nama_kegiatan, k.tanggal_mulai, k.status 
     FROM kegiatan_panitia kp
     JOIN kegiatan k ON kp.kegiatan_id = k.id
     WHERE kp.user_id = ? AND k.periode_id = ? AND k.status != 'selesai'
     ORDER BY k.id DESC LIMIT 1",
    [$user_id, $periode_id],
    "ii"
);

$role_labels = [
    'superadmin' => 'Superadmin',
    'admin'      => 'Admin General',
    'sekretaris' => 'Sekretariat BEM',
    'kominfo'    => 'Kominfo & Media',
    'anggota'    => 'Pengurus BEM'
];
$display_role = $role_labels[$admin_role] ?? 'User';
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-tachometer-alt"></i> Dashboard <?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Selamat datang di panel kendali BEM Kabinet Astawidya</p>
    </div>
    <div class="date-display">
        <i class="far fa-calendar-alt"></i>
        <?php echo tanggalIndonesia(); ?>
    </div>
</div>

<div class="dashboard-wrapper" style="display: flex; flex-direction: column; gap: 20px;">

<?php
// 3. Render Event Panitia Command Center jika User Terdaftar Sebagai Panitia Event Aktif
if ($active_panitia) {
    include __DIR__ . '/views/dashboard-panitia.php';
}

// 4. Render Core Role-Based Dashboard View
switch ($admin_role) {
    case 'superadmin':
        include __DIR__ . '/views/dashboard-superadmin.php';
        break;
    case 'admin':
        include __DIR__ . '/views/dashboard-admin.php';
        break;
    case 'sekretaris':
        include __DIR__ . '/views/dashboard-sekretaris.php';
        break;
    case 'kominfo':
        include __DIR__ . '/views/dashboard-kominfo.php';
        break;
    case 'anggota':
    default:
        // Role Anggota biasa sementara di-hold
        if (!$active_panitia) {
            echo '<div class="empty-state" style="text-align: center; padding: 50px 20px; background: rgba(255,255,255,0.02); border-radius: 12px; margin-top: 10px; border: 1px dashed rgba(255,255,255,0.1);">
                <i class="fas fa-user-clock" style="font-size: 4rem; color: #666; margin-bottom: 20px;"></i>
                <h2 style="color: #ddd; margin-bottom: 10px;">Belum Ada Akses Kepanitiaan</h2>
                <p style="color: #888; max-width: 500px; margin: 0 auto; line-height: 1.6;">
                    Akun Anda saat ini berada pada role standar <strong>Anggota</strong>. Tampilan dashboard khusus anggota sedang dalam tahap pengonsepan ulang.<br><br>Jika Anda ditunjuk dalam kepanitiaan event aktif, widget <strong>Event Command Center</strong> akan otomatis tampil di sini.
                </p>
            </div>';
        }
        break;
}
?>

<!-- BEM MOBILE APP INSTALLER BANNER (BLACK, WHITE & SOFT MUTED BLUE PALETTE) -->
<style>
.apk-download-banner {
    margin-top: 20px;
    padding: 18px 20px;
    background: linear-gradient(135deg, rgba(30, 41, 59, 0.4) 0%, rgba(15, 18, 23, 0.95) 100%);
    border: 1px solid rgba(74, 144, 226, 0.18);
    border-radius: 16px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    box-sizing: border-box;
    width: 100%;
    overflow: hidden;
}

@media (max-width: 768px) {
    .apk-download-banner {
        padding: 14px 16px !important;
        gap: 12px !important;
    }
    .apk-banner-content {
        min-width: 0 !important;
        flex: 1 1 100% !important;
        width: 100% !important;
    }
    .apk-banner-title {
        font-size: 0.95rem !important;
        flex-wrap: wrap !important;
    }
    .apk-banner-desc {
        font-size: 0.76rem !important;
    }
    .apk-banner-btn-wrap {
        width: 100% !important;
    }
    .apk-banner-btn {
        width: 100% !important;
        justify-content: center !important;
        padding: 9px 16px !important;
        font-size: 0.8rem !important;
    }
}
</style>

<div class="apk-download-banner">
    <div class="apk-banner-content" style="display: flex; align-items: flex-start; gap: 14px; flex: 1; min-width: 240px; box-sizing: border-box; overflow: hidden;">
        <div style="width: 46px; height: 46px; border-radius: 12px; background: rgba(74, 144, 226, 0.12); border: 1px solid rgba(74, 144, 226, 0.25); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #70a1ff; flex-shrink: 0;">
            <i class="fab fa-android"></i>
        </div>
        <div style="flex: 1; min-width: 0; overflow: hidden;">
            <h3 class="apk-banner-title" style="margin: 0 0 4px 0; font-size: 1rem; color: #ffffff; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-weight: 700;">
                Aplikasi Mobile Pengurus BEM
                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-weight: 700; font-size: 0.65rem; padding: 3px 8px; border-radius: 20px;">Sedang Diperbaiki</span>
            </h3>
            <p class="apk-banner-desc" style="margin: 0 0 4px 0; font-size: 0.78rem; color: #888888; line-height: 1.4;">
                Rilis resmi Flutter v1.0 sedang dalam perbaikan. Untuk sementara gunakan APK Preview di samping (WebView Kodular, web penuh).
            </p>
            <small style="font-family: monospace; font-size: 0.65rem; color: #777777; display: block; word-break: break-all; overflow-wrap: anywhere; max-width: 100%;">
                SHA-256: d5aa38de289f7f9d3fe55fe91ef3a1af4046f3fc5079974d53817222d659454f
            </small>
        </div>
    </div>
    <div class="apk-banner-btn-wrap" style="align-self: center; display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end;">
        <a href="#" onclick="alert('Sedang diperbaiki.\n\nRilis resmi BEM Mobile v1.0 (Flutter) sedang dalam tahap testing dan perbaikan. Untuk sementara, silakan gunakan APK Preview (WebView Kodular) yang tersedia di sebelah tombol ini.\n\nTerima kasih atas kesabarannya.'); return false;" class="btn-primary apk-banner-btn" style="display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 24px; font-weight: 600; text-decoration: none; background: rgba(120, 120, 120, 0.12); color: #888888; border: 1px solid rgba(120, 120, 120, 0.35); font-size: 0.82rem; white-space: nowrap; transition: all 0.2s ease; cursor: not-allowed; opacity: 0.7;" title="Sedang diperbaiki. Gunakan APK Preview sebagai alternatif sementara.">
            <i class="fas fa-tools"></i> Unduh APK Resmi
        </a>
        <a href="<?php echo baseUrl('admin/download_app.php?release=preview'); ?>" class="btn-primary apk-banner-btn" title="APK darurat (Kodular WebView). Bukan pengganti rilis resmi. Hanya untuk rilis cepat internal." style="display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 24px; font-weight: 600; text-decoration: none; background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px dashed rgba(245, 158, 11, 0.45); font-size: 0.82rem; white-space: nowrap; transition: all 0.2s ease;">
            <i class="fas fa-flask"></i> Unduh APK Preview
        </a>
    </div>
</div>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>