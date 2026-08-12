<?php
ob_start();
// admin/header.php - Header untuk halaman admin
// VERSI: 3.2 - Minor: komentar untuk peningkatan CSP di masa depan
//   CHANGED: Versi, tambah komentar saran CSP
//   UNCHANGED: Semua logika keamanan tetap sama

// ============================================
// ANTI-CACHE HEADERS (untuk halaman admin)
// ============================================
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

// ============================================
// LOAD DEPENDENSI & SECURITY HEADERS
// ============================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/config.php';

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
sendCspHeader();

$current_page = basename($_SERVER['PHP_SELF']);

if ($current_page !== 'login.php') {
    requireLogin();
    autoCommitSentStagingLetters();
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$admin_role = strtolower($_SESSION['admin_role'] ?? 'kominfo');

$isSuperadmin = $admin_role === 'superadmin'
                || !empty($_SESSION['admin_can_access_all']);

// Deteksi Role Sekretaris (Toleran terhadap ejaan)
$isSekretaris = (strpos($admin_role, 'sekretaris') !== false || strpos($admin_role, 'sekertaris') !== false);

$isHumas = false;
if (strpos($admin_role, 'humas') !== false) {
    $isHumas = true;
} else if (isset($_SESSION['admin_nama'])) {
    $cek_humas = dbFetchOne("
        SELECT k.id 
        FROM anggota_kementerian ak 
        JOIN kementerian k ON ak.kementerian_id = k.id 
        WHERE ak.nama = ? AND LOWER(k.nama) LIKE '%humas%' 
        LIMIT 1
    ", [$_SESSION['admin_nama']]);
    if ($cek_humas) $isHumas = true;
}

$isKetuplat = false;
if (isset($_SESSION['admin_id'])) {
    $unread_cnt = 0;

    $pending_dokumentasi = dbFetchOne("
        SELECT COUNT(*) as cnt 
        FROM kegiatan k 
        LEFT JOIN arsip_dokumentasi d ON k.id = d.kegiatan_id 
        WHERE k.status = 'selesai' AND k.periode_id = ? AND d.id IS NULL
    ", [getUserPeriode()], "i");
    $pending_dokumentasi_cnt = $pending_dokumentasi['cnt'] ?? 0;

    // Notifikasi Pendaftaran (hanya superadmin)
    $cek_ketuplat = dbFetchOne("SELECT id FROM kegiatan_panitia WHERE user_id = ? AND event_role = 'ketuplat' LIMIT 1", [$_SESSION['admin_id']], "i");
    if($cek_ketuplat) $isKetuplat = true;
}

// ============================================
// PROTEKSI AKSES ROLE SEKRETARIS
// ============================================

// ============================================
// LOAD WORKSPACE KEGIATAN (HYBRID RBAC)
// ============================================
$my_workspaces = [];
if (isset($_SESSION['admin_id'])) {
    $my_workspaces = dbFetchAll("
        SELECT k.id, k.nama_kegiatan, kp.event_role 
        FROM kegiatan_panitia kp
        JOIN kegiatan k ON kp.kegiatan_id = k.id
        WHERE kp.user_id = ? AND k.periode_id = ? AND k.status != 'selesai'
        ORDER BY k.created_at DESC
    ", [$_SESSION['admin_id'], getUserPeriode()]);
}
if ($isSekretaris && !isset($user_can_access_all)) {
    $restricted_pages = [
        'berita.php', 'berita-edit.php', 'berita-hapus.php',
        'kepengurusan.php', 'kepengurusan-edit.php', 'kepengurusan-hapus.php',
        'kabinet.php', 'visi-misi.php', 'kontak.php',
        'upload-struktur.php', 'upload-struktur-hapus.php'
    ];
    if (in_array($current_page, $restricted_pages)) {
        redirect('admin/core/dashboard.php', 'Akses ditolak! Sekretaris hanya diizinkan mengelola persuratan.', 'error');
        exit();
    }
}

// ============================================
// Cek Ketersediaan Kegiatan Persiapan
// ============================================
$has_persiapan_kegiatan = false;
$cek_persiapan = dbFetchOne("SELECT id FROM kegiatan WHERE status = 'persiapan' AND periode_id = ? LIMIT 1", [getUserPeriode()]);
if ($cek_persiapan) {
    $has_persiapan_kegiatan = true;
}

// ============================================
// KONFIGURASI BOTTOM NAVBAR MOBILE (HYBRID)
// ============================================
$show_bottom_nav = false;
$bottom_nav_tabs = [];
$bottom_sheet_items = [];

$is_full_sidebar_role = in_array($admin_role, ['superadmin', 'admin', 'sekretaris']);

if (isset($_SESSION['admin_id']) && !$is_full_sidebar_role && $current_page !== 'login.php') {
    $active_ws_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
    $primary_event_role = '';
    
    if (!empty($my_workspaces)) {
        if (!$active_ws_id) {
            $active_ws_id = (int)$my_workspaces[0]['id'];
            $primary_event_role = $my_workspaces[0]['event_role'];
        } else {
            foreach ($my_workspaces as $ws) {
                if ($ws['id'] == $active_ws_id) {
                    $primary_event_role = $ws['event_role'];
                    break;
                }
            }
        }
    }

    // A. Kominfo
    if ($admin_role === 'kominfo') {
        $show_bottom_nav = true;
        $bottom_nav_tabs[] = [
            'label' => 'Dashboard',
            'icon'  => 'fas fa-tachometer-alt',
            'url'   => baseUrl('admin/core/dashboard.php'),
            'active'=> ($current_page === 'dashboard.php')
        ];
        $bottom_nav_tabs[] = [
            'label' => 'Berita',
            'icon'  => 'fas fa-newspaper',
            'url'   => baseUrl('admin/konten/berita.php'),
            'active'=> in_array($current_page, ['berita.php', 'berita-edit.php', 'berita-hapus.php'])
        ];
        if ($pending_dokumentasi_cnt > 0) {
            $bottom_nav_tabs[] = [
                'label' => 'Dokumentasi',
                'icon'  => 'fas fa-camera',
                'url'   => baseUrl('admin/kegiatan/staging-dokumentasi.php'),
                'active'=> ($current_page === 'staging-dokumentasi.php'),
                'badge' => $pending_dokumentasi_cnt
            ];
        }
        $bottom_nav_tabs[] = [
            'label' => 'Kontak',
            'icon'  => 'fas fa-address-book',
            'url'   => baseUrl('admin/konten/kontak.php'),
            'active'=> ($current_page === 'kontak.php')
        ];
        
        $is_sheet_active = in_array($current_page, ['kepengurusan.php', 'kepengurusan-edit.php', 'kabinet.php', 'visi-misi.php']);
        $bottom_nav_tabs[] = [
            'label'  => 'Konten BEM',
            'icon'   => 'fas fa-university',
            'url'    => 'javascript:void(0)',
            'onclick'=> 'toggleMobileBottomSheet()',
            'active' => $is_sheet_active
        ];
        
        $bottom_sheet_items = [
            [
                'label' => 'Kepengurusan',
                'icon'  => 'fas fa-users',
                'url'   => baseUrl('admin/konten/kepengurusan.php'),
                'active'=> in_array($current_page, ['kepengurusan.php', 'kepengurusan-edit.php', 'kementerian-anggota.php'])
            ],
            [
                'label' => 'Kabinet',
                'icon'  => 'fas fa-crown',
                'url'   => baseUrl('admin/konten/kabinet.php'),
                'active'=> ($current_page === 'kabinet.php')
            ],
            [
                'label' => 'Visi Misi',
                'icon'  => 'fas fa-bullseye',
                'url'   => baseUrl('admin/konten/visi-misi.php'),
                'active'=> ($current_page === 'visi-misi.php')
            ]
        ];
    }
    // B. Ketuplak
    elseif ($primary_event_role === 'ketuplat' || $isKetuplat) {
        $show_bottom_nav = true;
        $bottom_nav_tabs[] = [
            'label' => 'Dashboard',
            'icon'  => 'fas fa-tachometer-alt',
            'url'   => baseUrl('admin/core/dashboard.php'),
            'active'=> ($current_page === 'dashboard.php')
        ];
        if ($active_ws_id > 0) {
            $bottom_nav_tabs[] = [
                'label' => 'Panitia',
                'icon'  => 'fas fa-users-cog',
                'url'   => baseUrl('admin/kegiatan/buat-panitia.php?kegiatan_id=' . $active_ws_id),
                'active'=> ($current_page === 'buat-panitia.php')
            ];
            $bottom_nav_tabs[] = [
                'label' => 'Undangan',
                'icon'  => 'fas fa-user-tie',
                'url'   => baseUrl('admin/kegiatan/tamu-undangan.php?kegiatan_id=' . $active_ws_id),
                'active'=> ($current_page === 'tamu-undangan.php')
            ];
        }
    }
    // C. Sie Acara
    elseif ($primary_event_role === 'sie_acara') {
        $show_bottom_nav = true;
        $bottom_nav_tabs[] = [
            'label' => 'Dashboard',
            'icon'  => 'fas fa-tachometer-alt',
            'url'   => baseUrl('admin/core/dashboard.php'),
            'active'=> ($current_page === 'dashboard.php')
        ];
        $ws_param = $active_ws_id ? '?kegiatan_id=' . $active_ws_id : '';
        $bottom_nav_tabs[] = [
            'label' => 'Rundown',
            'icon'  => 'fas fa-calendar-alt',
            'url'   => baseUrl('admin/kegiatan/workspace-rundown.php' . $ws_param),
            'active'=> ($current_page === 'workspace-rundown.php')
        ];
        $bottom_nav_tabs[] = [
            'label' => 'Teks MC',
            'icon'  => 'fas fa-microphone-alt',
            'url'   => baseUrl('admin/kegiatan/workspace-teks-mc.php' . $ws_param),
            'active'=> ($current_page === 'workspace-teks-mc.php')
        ];
    }
    // D. Sie Logistik
    elseif ($primary_event_role === 'sie_logistik') {
        $show_bottom_nav = true;
        $bottom_nav_tabs[] = [
            'label' => 'Dashboard',
            'icon'  => 'fas fa-tachometer-alt',
            'url'   => baseUrl('admin/core/dashboard.php'),
            'active'=> ($current_page === 'dashboard.php')
        ];
        $ws_param = $active_ws_id ? '?kegiatan_id=' . $active_ws_id : '';
        $bottom_nav_tabs[] = [
            'label' => 'Logistik',
            'icon'  => 'fas fa-boxes',
            'url'   => baseUrl('admin/kegiatan/workspace-logistik.php' . $ws_param),
            'active'=> ($current_page === 'workspace-logistik.php')
        ];
    }
    // E. Humas / Sie Humas
    elseif ($isHumas || $primary_event_role === 'sie_humas') {
        $show_bottom_nav = true;
        $bottom_nav_tabs[] = [
            'label' => 'Dashboard',
            'icon'  => 'fas fa-tachometer-alt',
            'url'   => baseUrl('admin/core/dashboard.php'),
            'active'=> ($current_page === 'dashboard.php')
        ];
        $bottom_nav_tabs[] = [
            'label' => 'Distribusi Surat',
            'icon'  => 'fas fa-paper-plane',
            'url'   => baseUrl('admin/surat/distribusi-surat.php'),
            'active'=> ($current_page === 'distribusi-surat.php')
        ];
    }
}

// ============================================
// CACHE BUSTER UNTUK CSS
// ============================================
$adminCssPath = __DIR__ . '/../css/admin.css';
$adminCssVer  = file_exists($adminCssPath) ? filemtime($adminCssPath) : '1';

$pageCssTag = '';
if (isset($page_css)) {
    $safeCss = preg_replace('/[^a-zA-Z0-9\-_]/', '', $page_css);
    if (!empty($safeCss)) {
        $pageCssPath = __DIR__ . '/../css/' . $safeCss . '.css';
        $pageCssVer  = file_exists($pageCssPath) ? filemtime($pageCssPath) : '1';
        $pageCssUrl  = htmlspecialchars(
            baseUrl('admin/css/' . $safeCss . '.css') . '?v=' . $pageCssVer,
            ENT_QUOTES, 'UTF-8'
        );
        $pageCssTag = "<link rel=\"stylesheet\" href=\"{$pageCssUrl}\">";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - BEM Kabinet Astawidya</title>

    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <link rel="icon" type="image/svg+xml"  href="<?php echo baseUrl('assets/images/favicon/favicon.svg'); ?>">
    <link rel="icon" type="image/x-icon"   href="<?php echo baseUrl('assets/images/favicon/favicon.ico'); ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="<?php echo baseUrl('assets/images/favicon/favicon-96x96.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180"    href="<?php echo baseUrl('assets/images/favicon/apple-touch-icon.png'); ?>">
    <link rel="manifest" href="<?php echo baseUrl('assets/images/favicon/site.webmanifest'); ?>">
    <meta name="theme-color" content="#4A90E2">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(baseUrl('admin/css/admin.css') . '?v=' . $adminCssVer, ENT_QUOTES, 'UTF-8'); ?>">

    <?php echo $pageCssTag; ?>
    <style>
            .btn-group-mobile {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }
            .btn-group-mobile > * {
                width: 100%;
                justify-content: center;
                display: flex;
                align-items: center;
                padding: 10px;
                border-radius: 8px;
                font-size: 0.85rem;
                min-height: 38px;
                box-sizing: border-box;
                text-decoration: none !important;
            }

            @media (max-width: 768px) {
                .responsive-card-table { 
                    border: none !important; 
                    width: 100% !important; 
                    min-width: 0 !important; 
                    margin: 0 !important; 
                    table-layout: fixed !important; 
                }
                .responsive-card-table thead { display: none; }
                .responsive-card-table tr { 
                    display: block; 
                    margin-bottom: 1.2rem; 
                    background: #1e1e1e; 
                    border: 1px solid rgba(255,255,255,0.08); 
                    border-radius: 16px; 
                    padding: 8px 0; /* Kurangi padding samping agar tombol bisa ke pinggir */
                    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                    width: 100% !important;
                    box-sizing: border-box;
                }
                .responsive-card-table td { 
                    display: flex; 
                    justify-content: space-between; 
                    align-items: flex-start; 
                    padding: 10px 12px; 
                    border: none !important; 
                    border-bottom: 1px solid rgba(255,255,255,0.05) !important;
                    min-height: 40px;
                    box-sizing: border-box;
                    width: 100% !important;
                }
                
                /* Paksa kolom Aksi lebar penuh dan tanpa label */
                .responsive-card-table td.td-aksi { 
                    display: block !important; 
                    width: 100% !important;
                    padding: 5px 8px 15px 8px !important; /* Kurangi padding atas untuk menghilangkan gap gaib */
                    border-bottom: none !important;
                    text-align: center !important;
                }
                .responsive-card-table td.td-aksi::before { 
                    display: none !important; 
                }
                
                /* Tampilan Nomor Surat Berderet Vertikal */
                .surat-indicators {
                    display: flex;
                    flex-direction: column;
                    gap: 5px;
                    align-items: flex-start; /* Sejajar kiri di mobile */
                    margin-top: 5px;
                }
                .surat-indicators > span, .surat-indicators > a {
                    display: block !important;
                    width: fit-content;
                }

                .group-ribbon-mobile {
                    display: block;
                    width: 100%;
                    background: linear-gradient(to right, rgba(74, 144, 226, 0.2), rgba(74, 144, 226, 0.1));
                    color: #4A90E2;
                    text-align: center;
                    font-size: 0.65rem;
                    padding: 6px 0;
                    margin-top: 10px;
                    border-top: 1px solid rgba(74, 144, 226, 0.1);
                    cursor: pointer;
                    transition: all 0.3s ease;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    font-weight: 600;
                    border-radius: 0 0 14px 14px;
                }
                .group-ribbon-mobile:hover {
                    background: rgba(74, 144, 226, 0.3);
                }

                .responsive-card-table td::before { 
                    content: attr(data-label); 
                    font-weight: 600; 
                    color: #4A90E2;
                    font-size: 0.7rem;
                    text-transform: uppercase;
                    flex: 0 0 90px;
                    text-align: left;
                }
                .responsive-card-table td > span, 
                .responsive-card-table td > strong, 
                .responsive-card-table td > div,
                .responsive-card-table td > a {
                    word-break: break-word;
                    flex: 1;
                    margin-left: 10px;
                    text-align: left;
                    font-size: 0.85rem;
                    max-width: calc(100% - 100px);
                }

                /* Hapus batasan max-width dan margin untuk isi kolom Aksi */
                .responsive-card-table td.td-aksi > div,
                .responsive-card-table td.td-aksi > a,
                .responsive-card-table td.td-aksi > span,
                .responsive-card-table td.td-aksi > button {
                    margin-left: 0 !important;
                    max-width: 100% !important;
                    text-align: center !important;
                    flex: none !important;
                    width: 100% !important;
                }

                /* Mobile: Tombol 1 Kolom Vertikal */
                .btn-group-mobile {
                    display: flex !important;
                    flex-direction: column !important;
                    gap: 8px;
                    width: 100%;
                    align-items: center;
                    margin: 0 !important;
                    padding: 0 !important;
                    box-sizing: border-box;
                }
                .btn-group-mobile > * {
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 10px !important;
                    font-size: 0.8rem !important;
                    min-height: 40px !important;
                    justify-content: center;
                    display: flex;
                    align-items: center;
                    box-sizing: border-box;
                }
            }

            /* Desktop Adjustments */
            @media (min-width: 769px) {
                .responsive-card-table td {
                    vertical-align: top;
                    padding-top: 15px;
                }
                .btn-group-mobile {
                    display: flex !important;
                    flex-direction: column !important;
                    gap: 5px !important;
                    align-items: center !important;
                    width: 100% !important;
                }
                .btn-group-mobile > * {
                    width: 130px !important; /* Uniform width on desktop */
                    min-width: 130px !important;
                    justify-content: center;
                }
                .group-ribbon-mobile {
                    display: inline-block !important;
                    width: 130px !important;
                    margin-top: 8px !important;
                    padding: 6px 0 !important;
                    border-radius: 6px !important;
                    background: rgba(74, 144, 226, 0.1) !important;
                    border: 1px solid rgba(74, 144, 226, 0.2) !important;
                }
                .group-ribbon-mobile:hover {
                    background: rgba(74, 144, 226, 0.2) !important;
                    border-color: rgba(74, 144, 226, 0.4) !important;
                }
                .child-nomor-surat { padding-left: 45px !important; }
                .child-nomor-surat::before {
                    content: "└";
                    position: absolute;
                    left: 20px;
                    color: #555;
                }
                .surat-indicators {
                    display: flex;
                    flex-direction: row;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-top: 5px;
                }
                .surat-indicators > span, .surat-indicators > a {
                    font-size: 0.7rem;
                }
            }

            /* Group Rows Logic */
            .child-row {
                display: none;
                opacity: 0;
                transform: translateY(-10px);
                transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .child-row.show {
                display: table-row !important;
                opacity: 1;
                transform: translateY(0);
            }
            @media (max-width: 768px) {
                .child-row.show {
                    display: block !important;
                }
            }

        /* Modern Toggle Switch */
        .switch-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            margin-bottom: 14px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .switch-container:hover { background: rgba(255,255,255,0.06); border-color: rgba(74,144,226,0.4); transform: translateY(-1px); }
        .switch-label { font-size: 0.92rem; color: #ddd; display: flex; align-items: center; gap: 12px; }
        .switch-label i { color: #4A90E2; font-size: 1rem; width: 20px; text-align: center; }
        .switch { position: relative; display: inline-block; width: 48px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #222; transition: .3s; border-radius: 34px; border: 1.5px solid #444; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 2.5px; background-color: #666; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: #4A90E2; border-color: #4A90E2; }
        input:checked + .slider:before { transform: translateX(23px); background-color: white; box-shadow: 0 0 8px rgba(255,255,255,0.4); }

        /* === Sidebar Dropdown (inline critical) === */
        .sidebar-dropdown-menu {
            max-height: 0 !important;
            overflow: hidden !important;
            transition: max-height 0.35s ease !important;
        }
        .sidebar-dropdown.open > .sidebar-dropdown-menu {
            max-height: 500px !important;
        }
        .sidebar-dropdown-toggle .chevron-icon {
            transition: transform 0.3s ease;
        }
        .sidebar-dropdown.open > .sidebar-dropdown-toggle .chevron-icon {
            transform: rotate(90deg);
        }
    </style>
    <script>
    function toggleSidebarDropdown(btn) {
        var dropdown = btn.closest('.sidebar-dropdown');
        if (!dropdown) return;
        
        var isOpen = dropdown.classList.contains('open');
        
        // Close all other dropdowns (accordion behavior)
        var allDropdowns = document.querySelectorAll('.sidebar-dropdown');
        for (var i = 0; i < allDropdowns.length; i++) {
            if (allDropdowns[i] !== dropdown) {
                allDropdowns[i].classList.remove('open');
            }
        }
        
        // Toggle current dropdown
        if (isOpen) {
            dropdown.classList.remove('open');
        } else {
            dropdown.classList.add('open');
        }
    }
    </script>
</head>
<body class="<?php echo htmlspecialchars(basename($current_page, '.php'), ENT_QUOTES, 'UTF-8'); ?> <?php echo !empty($show_bottom_nav) ? 'has-bottom-nav' : ''; ?>">
<div class="admin-wrapper" style="overflow-x: hidden;">

<?php if ($current_page !== 'login.php'): ?>

    <!-- Mobile Topbar -->
    <div class="mobile-topbar">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
            <i class="fas fa-bars"></i>
        </button>
        <div class="mobile-brand">
            <span>BEM Admin</span>
            <?php if (isset($periode_data)): ?>
            <small><?php echo htmlspecialchars($periode_data['nama'] ?? 'Astawidya', ENT_QUOTES, 'UTF-8'); ?></small>
            <?php endif; ?>
        </div>
        <div class="mobile-user" style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="notif-bell-btn" onclick="toggleNotifDropdown(event)" title="Arsip Notifikasi">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="notifBadgeMobile" style="display:none;">0</span>
            </button>
            <i class="fas fa-user-circle"></i>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <button class="sidebar-close" id="sidebarClose" aria-label="Tutup menu">
            <i class="fas fa-times"></i>
        </button>

        <div class="sidebar-header">
            <h2>BEM Admin</h2>
            <p>Kabinet Astawidya</p>
        </div>

        <?php if (isset($periode_data)): ?>
        <div class="periode-info">
            <div class="periode-info-label">Periode Aktif</div>
            <div class="periode-info-nama">
                <?php echo htmlspecialchars($periode_data['nama'] ?? 'Astawidya', ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="periode-info-tahun">
                <?php
                $tahunMulai   = htmlspecialchars($periode_data['tahun_mulai']   ?? '2025', ENT_QUOTES, 'UTF-8');
                $tahunSelesai = htmlspecialchars($periode_data['tahun_selesai'] ?? '2026', ENT_QUOTES, 'UTF-8');
                echo $tahunMulai . '/' . $tahunSelesai;
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Map halaman ke status aktif untuk menu sidebar
        $info_bem_pages = [
            'berita.php', 'berita-edit.php', 'berita-hapus.php',
            'kepengurusan.php', 'kepengurusan-edit.php', 'kepengurusan-hapus.php',
            'kabinet.php', 'visi-misi.php', 'kontak.php',
            'upload-struktur.php', 'upload-struktur-hapus.php',
            'kementerian-anggota.php', 'kementerian-edit.php', 'kementerian-hapus.php', 'pendaftaran.php'
        ];
        $is_info_bem_active = in_array($current_page, $info_bem_pages);
        
        $surat_pages = [
            'arsip-surat.php', 'staging-surat.php', 'buat-surat.php', 'pengaturan-surat.php', 'cetak-surat.php', 'arsip-manual.php', 'catat-surat-masuk.php',
            'buat-berita-acara.php', 'arsip-berita-acara.php', 'cetak-berita-acara.php'
        ];
        $is_surat_active = in_array($current_page, $surat_pages);
        
        $lpj_pages = [
            'arsip-lpj.php', 'buat-lpj.php', 'pengaturan-lpj.php'
        ];
        $is_lpj_active = in_array($current_page, $lpj_pages);
        
        $barang_pages = [
            'master-barang.php', 'master-tempat.php', 'cetak-lampiran.php', 'arsip-lampiran.php', 'cetak-lampiran-pdf.php'
        ];
        $is_barang_active = in_array($current_page, $barang_pages);
        
        $rundown_pages = [
            'master-penanggung-jawab.php', 'master-keterangan.php', 'master-tempat-kegiatan.php', 'cetak-rundown.php', 'arsip-rundown.php', 'cetak-rundown-pdf.php', 'workspace-teks-mc.php', 'arsip-teks-mc.php', 'reader-teks-mc.php', 'cetak-teks-mc-pdf.php'
        ];
        $is_rundown_active = in_array($current_page, $rundown_pages);
        
        $panitia_pages = [
            'buat-panitia.php', 'arsip-panitia.php', 'cetak-panitia.php'
        ];
        $is_panitia_active = in_array($current_page, $panitia_pages);
        
        $superadmin_pages = [
            'periode-kepengurusan.php', 'kelola-admin.php', 'ganti-periode.php', 'audit-log.php', 'backup-database.php'
        ];
        $is_superadmin_active = in_array($current_page, $superadmin_pages);
        
        $akun_pages = [
            'pengaturan.php', '2fa-setup.php', '2fa-verify.php'
        ];
        $is_akun_active = in_array($current_page, $akun_pages);
        ?>

        <nav class="sidebar-menu">
            <!-- Dashboard (Direct Link) -->
            <a href="<?php echo baseUrl('admin/core/dashboard.php'); ?>"
               class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?> mobile-nav-redundant">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </a>

            <!-- Workspaces & Monitoring (Dinamis per Kegiatan) -->
            <?php foreach ($my_workspaces as $ws): ?>
            <?php 
                $active_kegiatan_id = $_GET['kegiatan_id'] ?? 0;
                $is_ws_active = ($active_kegiatan_id == $ws['id'] && in_array($current_page, ['buat-panitia.php', 'tamu-undangan.php', 'workspace-panitia.php', 'distribusi-surat.php', 'workspace-rundown.php', 'workspace-teks-mc.php']));
                $is_mon_active = ($active_kegiatan_id == $ws['id'] && in_array($current_page, ['workspace-rundown.php', 'workspace-teks-mc.php', 'workspace-logistik.php', 'distribusi-surat.php']));
                $ws_nama_short = htmlspecialchars((strlen($ws['nama_kegiatan']) > 15 ? substr($ws['nama_kegiatan'],0,12).'...' : $ws['nama_kegiatan']));
                
                $has_ws_items = in_array($ws['event_role'], ['ketuplat', 'sekretaris_panitia', 'sie_acara', 'sie_logistik'])
                                || ($ws['event_role'] === 'sie_humas' && $admin_role !== 'kominfo');
            ?>
            
            <?php if ($has_ws_items): ?>
            <!-- WS Utama (Tugas Langsung) -->
            <div class="sidebar-dropdown <?php echo $is_ws_active ? 'active open' : ''; ?> mobile-nav-redundant" style="background: rgba(255,255,255,0.03); border-left: 3px solid #f39c12;">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-briefcase" style="color: #f39c12;"></i>
                    <span>WS: <?php echo $ws_nama_short; ?></span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <?php if (in_array($ws['event_role'], ['ketuplat', 'sekretaris_panitia'])): ?>
                    <a href="<?php echo baseUrl('admin/kegiatan/buat-panitia.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'buat-panitia.php') ? 'active' : ''; ?>">
                        <i class="fas fa-users-cog"></i><span>Susunan Panitia</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/kegiatan/tamu-undangan.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'tamu-undangan.php') ? 'active' : ''; ?>">
                        <i class="fas fa-user-tie" style="color: #f1c40f;"></i><span>Tamu Undangan VVIP</span>
                    </a>
                    <?php endif; ?>

                    <?php if ($ws['event_role'] === 'sie_acara'): ?>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-rundown.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'workspace-rundown.php') ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i><span>Rundown Acara</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-teks-mc.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'workspace-teks-mc.php') ? 'active' : ''; ?>">
                        <i class="fas fa-microphone-alt"></i><span>Teks MC</span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($ws['event_role'] === 'sie_logistik'): ?>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-logistik.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'workspace-logistik.php') ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i><span>Logistik</span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($ws['event_role'] === 'sie_humas' && $admin_role !== 'kominfo'): ?>
                    <a href="<?php echo baseUrl('admin/surat/distribusi-surat.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_ws_active && $current_page === 'distribusi-surat.php') ? 'active' : ''; ?>">
                        <i class="fas fa-paper-plane"></i><span>Distribusi Surat</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Monitoring Divisi (Khusus Ketuplak) -->
            <?php if ($ws['event_role'] === 'ketuplat'): ?>
            <div class="sidebar-dropdown <?php echo $is_mon_active ? 'active open' : ''; ?> mobile-nav-redundant" style="background: rgba(255,255,255,0.03); border-left: 3px solid #3498db;">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-desktop" style="color: #3498db;"></i>
                    <span>Monitoring: <?php echo $ws_nama_short; ?></span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-rundown.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_mon_active && $current_page === 'workspace-rundown.php') ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i><span>Rundown Acara</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-teks-mc.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_mon_active && $current_page === 'workspace-teks-mc.php') ? 'active' : ''; ?>">
                        <i class="fas fa-microphone-alt"></i><span>Teks MC</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-logistik.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_mon_active && $current_page === 'workspace-logistik.php') ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i><span>Logistik</span>
                    </a>
                    <?php if ($admin_role !== 'kominfo'): ?>
                    <a href="<?php echo baseUrl('admin/surat/distribusi-surat.php?kegiatan_id=' . $ws['id']); ?>" class="<?php echo ($is_mon_active && $current_page === 'distribusi-surat.php') ? 'active' : ''; ?>">
                        <i class="fas fa-paper-plane"></i><span>Staging Distribusi Surat</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>

            <!-- Manajemen Kegiatan (Direct Link) -->
            <?php if ($isHumas && $admin_role !== 'kominfo'): ?>
                <a href="<?php echo baseUrl('admin/surat/distribusi-surat.php'); ?>" class="nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'distribusi-surat.php') !== false && empty($_GET['kegiatan_id']) ? 'active' : ''; ?> mobile-nav-redundant">
                    <i class="fas fa-paper-plane"></i> Staging Distribusi Surat
                </a>
            <?php endif; ?>

            <?php if (in_array($admin_role, ['superadmin', 'admin', 'kominfo']) && $pending_dokumentasi_cnt > 0): ?>
                <a href="<?php echo baseUrl('admin/kegiatan/staging-dokumentasi.php'); ?>" class="nav-item <?php echo $current_page === 'staging-dokumentasi.php' ? 'active' : ''; ?> mobile-nav-redundant">
                    <i class="fas fa-camera"></i> Staging Dokumentasi 
                    <span class="badge" style="background: #e74c3c; color: white; border-radius: 50%; padding: 2px 6px; font-size: 0.75rem; margin-left: auto;"><?php echo $pending_dokumentasi_cnt; ?></span>
                </a>
            <?php endif; ?>

            <?php if (in_array($admin_role, ['superadmin', 'admin'])): ?>
            <a href="<?php echo baseUrl('admin/kegiatan/master-kegiatan.php'); ?>" class="<?php echo $current_page === 'master-kegiatan.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i><span>Manajemen Kegiatan</span>
            </a>
            <?php endif; ?>

            <!-- Informasi BEM (Dropdown) -->
            <?php if (in_array($admin_role, ['superadmin', 'admin', 'kominfo'])): ?>
            <div class="sidebar-dropdown <?php echo $is_info_bem_active ? 'active open' : ''; ?> <?php echo $admin_role === 'kominfo' ? 'mobile-nav-redundant' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-university"></i>
                    <span>Informasi BEM</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/konten/berita.php'); ?>" class="<?php echo in_array($current_page, ['berita.php', 'berita-edit.php', 'berita-hapus.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-newspaper"></i><span>Berita</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/konten/kepengurusan.php'); ?>" class="<?php echo in_array($current_page, ['kepengurusan.php', 'kepengurusan-edit.php', 'kepengurusan-hapus.php', 'kementerian-anggota.php', 'kementerian-edit.php', 'kementerian-hapus.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i><span>Kepengurusan</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/konten/kabinet.php'); ?>" class="<?php echo $current_page === 'kabinet.php' ? 'active' : ''; ?>">
                        <i class="fas fa-crown"></i><span>Kabinet</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/konten/visi-misi.php'); ?>" class="<?php echo $current_page === 'visi-misi.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bullseye"></i><span>Visi Misi</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/konten/kontak.php'); ?>" class="<?php echo $current_page === 'kontak.php' ? 'active' : ''; ?>">
                        <i class="fas fa-address-book"></i><span>Kontak</span>
                    </a>
                    <?php if (in_array($admin_role, ['superadmin', 'admin'])): ?>
                    <a href="<?php echo baseUrl('admin/konten/pendaftaran.php'); ?>" class="<?php echo $current_page === 'pendaftaran.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-plus"></i><span>Pendaftaran Anggota</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Surat & Arsip (Dropdown) -->
            <?php if ($isSekretaris || $isSuperadmin || $admin_role === 'admin'): ?>
            <div class="sidebar-dropdown <?php echo $is_surat_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-envelope"></i>
                    <span>Surat & Arsip</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <?php if ($has_persiapan_kegiatan): ?>
                    <a href="<?php echo baseUrl('admin/surat/staging-surat.php'); ?>" class="<?php echo $current_page === 'staging-surat.php' ? 'active' : ''; ?>" style="color: #f1c40f; font-weight: bold; background: rgba(241, 196, 15, 0.1); border-left: 3px solid #f1c40f;">
                        <i class="fas fa-layer-group" style="color: #f1c40f;"></i><span style="color: #f1c40f;">Staging Index Surat</span>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo baseUrl('admin/surat/arsip-surat.php'); ?>" class="<?php echo $current_page === 'arsip-surat.php' ? 'active' : ''; ?>">
                        <i class="fas fa-folder-open"></i><span>Arsip Surat Utama</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/surat/buat-surat.php'); ?>" class="<?php echo in_array($current_page, ['buat-surat.php', 'cetak-surat.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-file-signature"></i><span>Buat Surat Otomatis</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/surat/arsip-berita-acara.php'); ?>" class="<?php echo $current_page === 'arsip-berita-acara.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt"></i><span>Arsip Berita Acara</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/surat/buat-berita-acara.php'); ?>" class="<?php echo in_array($current_page, ['buat-berita-acara.php', 'cetak-berita-acara.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-pen-nib"></i><span>Buat Berita Acara</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/surat/pengaturan-surat.php'); ?>" class="<?php echo $current_page === 'pengaturan-surat.php' ? 'active' : ''; ?>">
                        <i class="fas fa-cogs"></i><span>Pengaturan Surat</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- LPJ (Dropdown) -->
            <?php if ($isSekretaris || $isSuperadmin || $admin_role === 'admin'): ?>
            <div class="sidebar-dropdown <?php echo $is_lpj_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>LPJ</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/lpj/arsip-lpj.php'); ?>" class="<?php echo $current_page === 'arsip-lpj.php' ? 'active' : ''; ?>">
                        <i class="fas fa-archive"></i><span>Arsip LPJ</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/lpj/buat-lpj.php'); ?>" class="<?php echo $current_page === 'buat-lpj.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-medical"></i><span>Buat LPJ</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/lpj/pengaturan-lpj.php'); ?>" class="<?php echo $current_page === 'pengaturan-lpj.php' ? 'active' : ''; ?>">
                        <i class="fas fa-sliders-h"></i><span>Pengaturan LPJ</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Peminjaman Barang (Dropdown) -->
            <?php if ($isSekretaris || $isSuperadmin || $admin_role === 'admin'): ?>
            <div class="sidebar-dropdown <?php echo $is_barang_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-boxes"></i>
                    <span>Peminjaman Barang</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/logistik/master-barang.php'); ?>" class="<?php echo $current_page === 'master-barang.php' ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i><span>Master Barang</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/logistik/master-tempat.php'); ?>" class="<?php echo $current_page === 'master-tempat.php' ? 'active' : ''; ?>">
                        <i class="fas fa-map-marker-alt"></i><span>Master Tempat</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/logistik/cetak-lampiran.php'); ?>" class="<?php echo in_array($current_page, ['cetak-lampiran.php', 'cetak-lampiran-pdf.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-print"></i><span>Cetak Lampiran</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/logistik/arsip-lampiran.php'); ?>" class="<?php echo $current_page === 'arsip-lampiran.php' ? 'active' : ''; ?>">
                        <i class="fas fa-archive"></i><span>Arsip Lampiran</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Rundown Acara (Dropdown) -->
            <?php if ($isSekretaris || $isSuperadmin || $admin_role === 'admin'): ?>
            <div class="sidebar-dropdown <?php echo $is_rundown_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Rundown Acara</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/rundown/master-penanggung-jawab.php'); ?>" class="<?php echo $current_page === 'master-penanggung-jawab.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-tie"></i><span>Master PJ</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/master-keterangan.php'); ?>" class="<?php echo $current_page === 'master-keterangan.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i><span>Master Keterangan</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/master-tempat-kegiatan.php'); ?>" class="<?php echo $current_page === 'master-tempat-kegiatan.php' ? 'active' : ''; ?>">
                        <i class="fas fa-map-marked-alt"></i><span>Master Tempat Kegiatan</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/cetak-rundown.php'); ?>" class="<?php echo in_array($current_page, ['cetak-rundown.php', 'cetak-rundown-pdf.php']) ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check"></i><span>Cetak Rundown</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/arsip-rundown.php'); ?>" class="<?php echo $current_page === 'arsip-rundown.php' ? 'active' : ''; ?>">
                        <i class="fas fa-clipboard-list"></i><span>Arsip Rundown</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/arsip-teks-mc.php'); ?>" class="<?php echo $current_page === 'arsip-teks-mc.php' ? 'active' : ''; ?>">
                        <i class="fas fa-microphone-alt"></i><span>Arsip Teks MC</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Susunan Panitia (Dropdown) -->
            <?php if ($isSekretaris || $isSuperadmin || $admin_role === 'admin'): ?>
            <div class="sidebar-dropdown <?php echo $is_panitia_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-users-cog"></i>
                    <span>Susunan Panitia</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/kegiatan/buat-panitia.php'); ?>" class="<?php echo $current_page === 'buat-panitia.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-plus"></i><span>Buat Panitia</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/kegiatan/arsip-panitia.php'); ?>" class="<?php echo $current_page === 'arsip-panitia.php' ? 'active' : ''; ?>">
                        <i class="fas fa-archive"></i><span>Arsip Panitia</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Akun (Direct Link) -->
            <div class="menu-divider"></div>
            <a href="<?php echo baseUrl('admin/system/pengaturan.php'); ?>"
               class="<?php echo $is_akun_active ? 'active' : ''; ?>">
                <i class="fas fa-user-cog"></i><span>Profil & Keamanan</span>
            </a>
            <?php if ($admin_role === 'admin'): ?>
            <a href="<?php echo baseUrl('admin/system/kelola-admin.php'); ?>"
               class="<?php echo $current_page === 'kelola-admin.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i><span>Kelola Admin</span>
            </a>
            <a href="<?php echo baseUrl('admin/system/audit-log.php'); ?>"
               class="<?php echo $current_page === 'audit-log.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i><span>Audit Log</span>
            </a>
            <?php endif; ?>

            <!-- Superadmin (Dropdown) -->
            <?php if ($isSuperadmin): ?>
            <div class="sidebar-dropdown <?php echo $is_superadmin_active ? 'active open' : ''; ?>">
                <button type="button" class="sidebar-dropdown-toggle" onclick="toggleSidebarDropdown(this)">
                    <i class="fas fa-user-shield"></i>
                    <span>Superadmin</span>
                    <i class="fas fa-chevron-right chevron-icon"></i>
                </button>
                <div class="sidebar-dropdown-menu">
                    <a href="<?php echo baseUrl('admin/system/periode-kepengurusan.php'); ?>" class="<?php echo $current_page === 'periode-kepengurusan.php' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i><span>Periode</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/system/kelola-admin.php'); ?>" class="<?php echo $current_page === 'kelola-admin.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-shield"></i><span>Kelola Admin</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/system/ganti-periode.php'); ?>" class="<?php echo $current_page === 'ganti-periode.php' ? 'active' : ''; ?>">
                        <i class="fas fa-sync-alt"></i><span>Ganti Periode</span>
                    </a>
                    <a href="<?php echo baseUrl('admin/system/audit-log.php'); ?>" class="<?php echo $current_page === 'audit-log.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i><span>Audit Log</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <div>
                    <div class="user-name">
                        <?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="user-role">
                        <?php echo htmlspecialchars($admin_role, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </div>
            <a href="javascript:void(0)"
               class="logout-btn"
               onclick="confirmLogout('<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>')">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </aside>

<?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php flashMessage(); ?>

        <?php if ($current_page !== 'login.php'): ?>
        <div class="breadcrumb">
            <i class="fas fa-home"></i>
            <a href="<?php echo baseUrl('admin/core/dashboard.php'); ?>">Dashboard</a>
            <?php if ($current_page !== 'dashboard.php'):
                $page_label = ucwords(str_replace(['.php', '-'], ['', ' '], $current_page));
            ?>
                <span class="separator">/</span>
                <span><?php echo htmlspecialchars($page_label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>

            <?php if (isset($periode_data)): ?>
            <span class="separator">|</span>
            <span class="breadcrumb-periode">
                <i class="fas fa-calendar-alt"></i>
                <?php echo htmlspecialchars($periode_data['nama'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo $tahunMulai . '/' . $tahunSelesai; ?>)
            </span>
            <?php endif; ?>

            <div style="margin-left: auto; display: flex; align-items: center;">
                <button type="button" class="notif-bell-btn" onclick="toggleNotifDropdown(event)" title="Arsip Notifikasi">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge" id="notifBadgeDesktop" style="display:none;">0</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

<!-- Logout Modal -->
<div id="logoutModal" class="header-custom-modal">
    <div class="header-modal-content">
        <div class="header-modal-header">
            <i class="fas fa-sign-out-alt"></i>
            <h4>Konfirmasi Logout</h4>
        </div>
        <div class="header-modal-body">
            <p>Apakah Anda yakin ingin keluar dari sistem admin?</p>
        </div>
        <div class="header-modal-footer">
            <button type="button" class="header-btn-cancel" onclick="closeLogoutModal()">Batal</button>
            <a href="#" id="confirmLogoutBtn" class="header-btn-confirm" style="text-decoration:none;">Ya, Logout</a>
        </div>
    </div>
</div>

<style>
.header-custom-modal {
    display: none;
    position: fixed;
    z-index: 10001; /* Di atas sidebar & konten */
    left: 0; top: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.8);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
}
.header-modal-content {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 12px;
    width: 90%;
    max-width: 380px;
    padding: 25px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    animation: headerModalSlide 0.3s ease-out;
}
@keyframes headerModalSlide {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.header-modal-header { display: flex; align-items: center; gap: 12px; margin-bottom: 15px; color: #4A90E2; }
.header-modal-header h4 { margin: 0; font-size: 1.2rem; }
.header-modal-body p { color: #ccc; margin: 0; }
.header-modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; }
.header-btn-cancel { background: #333; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; }
.header-btn-confirm { background: #f44336; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 500; }
</style>

<script>
function confirmLogout(token) {
    const modal = document.getElementById('logoutModal');
    const btn = document.getElementById('confirmLogoutBtn');
    btn.href = '<?php echo baseUrl("admin/auth/logout.php"); ?>?csrf_token=' + token;
    modal.style.display = 'flex';
}
function closeLogoutModal() {
    document.getElementById('logoutModal').style.display = 'none';
}
// Tutup modal jika klik di luar box
window.onclick = function(event) {
    const modal = document.getElementById('logoutModal');
    if (event.target == modal) closeLogoutModal();
}
</script>

<!-- Notification Dropdown Panel -->
<div id="notifDropdown" class="notif-dropdown">
    <div class="notif-header">
        <div class="notif-header-title">
            <i class="fas fa-bell"></i>
            <span>Arsip Notifikasi</span>
            <span class="notif-count-pill" id="notifUnreadPill">0 Baru</span>
        </div>
        <div class="notif-header-actions">
            <button type="button" onclick="markAllNotifRead()" title="Tandai semua dibaca"><i class="fas fa-check-double"></i></button>
            <button type="button" onclick="clearAllNotif()" title="Hapus semua arsip"><i class="fas fa-trash-alt"></i></button>
            <button type="button" onclick="closeNotifDropdown()" title="Tutup"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="notif-body" id="notifList">
        <div class="notif-empty"><i class="fas fa-spinner fa-spin"></i> Memuat notifikasi...</div>
    </div>
    <div class="notif-footer">
        <i class="fas fa-shield-alt"></i> Auto clean up aktif (> 7 hari)
    </div>
</div>

<script>
(function() {
    const notifApiUrl = '<?php echo baseUrl("api/notifications.php"); ?>';
    
    function fetchNotifications() {
        fetch(notifApiUrl + '?action=fetch')
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                
                // Update Badge Counts
                const unread = data.unread_count || 0;
                const bMobile = document.getElementById('notifBadgeMobile');
                const bDesktop = document.getElementById('notifBadgeDesktop');
                const pUnread = document.getElementById('notifUnreadPill');
                
                if (bMobile) {
                    bMobile.textContent = unread > 99 ? '99+' : unread;
                    bMobile.style.display = unread > 0 ? 'inline-block' : 'none';
                }
                if (bDesktop) {
                    bDesktop.textContent = unread > 99 ? '99+' : unread;
                    bDesktop.style.display = unread > 0 ? 'inline-block' : 'none';
                }
                if (pUnread) {
                    pUnread.textContent = unread + ' Baru';
                }

                // Render Notif List
                const notifList = document.getElementById('notifList');
                if (!notifList) return;

                if (!data.notifications || data.notifications.length === 0) {
                    notifList.innerHTML = '<div class="notif-empty"><i class="far fa-bell-slash" style="font-size:1.8rem; margin-bottom:8px; display:block;"></i>Belum ada notifikasi</div>';
                    return;
                }

                let html = '';
                data.notifications.forEach(item => {
                    const isUnread = (!item.is_read || item.is_read == 0) ? 'unread' : '';
                    let iconClass = 'fas fa-info-circle';
                    if (item.tipe === 'success') iconClass = 'fas fa-check-circle';
                    if (item.tipe === 'warning') iconClass = 'fas fa-exclamation-triangle';
                    if (item.tipe === 'danger') iconClass = 'fas fa-exclamation-circle';

                    html += `
                        <div class="notif-item ${isUnread}" onclick="clickNotifItem(event, ${item.id}, '${item.link || ''}')">
                            <div class="notif-item-icon"><i class="${iconClass}"></i></div>
                            <div class="notif-item-content">
                                <div class="notif-item-title">${escapeHtml(item.judul)}</div>
                                <div class="notif-item-msg">${escapeHtml(item.pesan)}</div>
                                <div class="notif-item-time"><i class="far fa-clock"></i> ${item.time_ago}</div>
                            </div>
                            <button class="notif-item-del" onclick="deleteSingleNotif(event, ${item.id})" title="Hapus"><i class="fas fa-times"></i></button>
                        </div>
                    `;
                });
                notifList.innerHTML = html;
            })
            .catch(err => console.error("Notif fetch error:", err));
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    window.toggleNotifDropdown = function(e) {
        if (e) e.stopPropagation();
        const dd = document.getElementById('notifDropdown');
        if (!dd) return;
        
        const isShow = dd.classList.contains('show');
        if (isShow) {
            dd.classList.remove('show');
        } else {
            dd.classList.add('show');
            fetchNotifications();
        }
    };

    window.closeNotifDropdown = function() {
        const dd = document.getElementById('notifDropdown');
        if (dd) dd.classList.remove('show');
    };

    window.clickNotifItem = function(e, id, link) {
        if (e.target.closest('.notif-item-del')) return;
        
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('id', id);

        fetch(notifApiUrl, { method: 'POST', body: formData })
            .then(() => {
                fetchNotifications();
                if (link && link.trim() !== '') {
                    window.location.href = link;
                }
            });
    };

    window.markAllNotifRead = function() {
        const formData = new FormData();
        formData.append('action', 'mark_read');
        formData.append('all', '1');

        fetch(notifApiUrl, { method: 'POST', body: formData })
            .then(() => fetchNotifications());
    };

    window.deleteSingleNotif = function(e, id) {
        if (e) e.stopPropagation();
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch(notifApiUrl, { method: 'POST', body: formData })
            .then(() => fetchNotifications());
    };

    window.clearAllNotif = function() {
        if (!confirm('Apakah Anda yakin ingin menghapus seluruh arsip notifikasi?')) return;
        const formData = new FormData();
        formData.append('action', 'clear_all');

        fetch(notifApiUrl, { method: 'POST', body: formData })
            .then(() => fetchNotifications());
    };

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('notifDropdown');
        if (dd && dd.classList.contains('show')) {
            if (!dd.contains(e.target) && !e.target.closest('.notif-bell-btn')) {
                dd.classList.remove('show');
            }
        }
    });

    // Auto fetch on load & polling 30 sec
    document.addEventListener('DOMContentLoaded', function() {
        fetchNotifications();
        setInterval(fetchNotifications, 30000);
    });
})();
</script>