<?php
// header.php - Header untuk halaman publik
// VERSI: 2.0 - Dengan helper functions

require_once __DIR__ . '/includes/functions.php';

// Ambil data yang diperlukan untuk semua halaman
$kabinet = getKabinet();

// Untuk navigasi, kita tetap seperti biasa
$page_title = $page_title ?? 'Beranda';

// Tentukan class halaman untuk CSS
$current_page = basename($_SERVER['PHP_SELF']);
$page_class = '';

// Array mapping untuk class halaman (lebih maintainable)
$pageClassMap = [
    'index.php' => 'page-index',
    'berita.php' => 'page-berita',
    'kepengurusan.php' => 'page-kepengurusan',
    'arsip-periode.php' => 'page-arsip',        // ✅ TAMBAHKAN
    'kontak.php' => 'page-kontak',
    'berita-detail.php' => 'page-berita-detail',
    'detail-menteri.php' => 'page-detail-menteri'
];

$page_class = $pageClassMap[$current_page] ?? '';

// Cek apakah ini halaman index
$isHomePage = ($current_page == 'index.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME . (!empty($page_title) ? " - $page_title" : ""); ?></title>
    
    <!-- ===== FAVICON ===== -->
    <!-- SVG untuk browser modern -->
    <link rel="icon" type="image/svg+xml" href="<?php echo baseUrl('assets/images/favicon/favicon.svg'); ?>">
    
    <!-- Fallback ICO untuk browser lama -->
    <link rel="icon" type="image/x-icon" href="<?php echo baseUrl('assets/images/favicon/favicon.ico'); ?>">
    
    <!-- PNG untuk berbagai ukuran -->
    <link rel="icon" type="image/png" sizes="96x96" href="<?php echo baseUrl('assets/images/favicon/favicon-96x96.png'); ?>">
    
    <!-- Apple Touch Icon untuk iOS -->
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo baseUrl('assets/images/favicon/apple-touch-icon.png'); ?>">
    
    <!-- Manifest untuk PWA -->
    <link rel="manifest" href="<?php echo baseUrl('assets/images/favicon/site.webmanifest'); ?>">
    
    <!-- Theme Color -->
    <meta name="theme-color" content="#4A90E2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    
    <!-- CSS - Gunakan assetUrl() -->
    <?php $css_style_ver = file_exists(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '1'; ?>
    <link rel="stylesheet" href="<?php echo assetUrl('css/style.css'); ?>?v=<?php echo $css_style_ver; ?>">
    
    <!-- TAMBAHKAN: CSS per halaman -->
    <?php $css_berita_ver = file_exists(__DIR__ . '/assets/css/berita.css') ? filemtime(__DIR__ . '/assets/css/berita.css') : '1'; ?>
    <link rel="stylesheet" href="<?php echo assetUrl('css/berita.css'); ?>?v=<?php echo $css_berita_ver; ?>">
    <?php $css_kepengurusan_ver = file_exists(__DIR__ . '/assets/css/kepengurusan.css') ? filemtime(__DIR__ . '/assets/css/kepengurusan.css') : '1'; ?>
    <link rel="stylesheet" href="<?php echo assetUrl('css/kepengurusan.css'); ?>?v=<?php echo $css_kepengurusan_ver; ?>">
    
    <!-- External CSS (tetap) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Meta tags untuk SEO -->
    <meta name="description" content="Website resmi BEM Kabinet <?php echo htmlspecialchars($kabinet['nama'] ?? 'ASTAWIDYA'); ?> - <?php echo htmlspecialchars($kabinet['arti'] ?? ''); ?>">
    <meta property="og:title" content="<?php echo SITE_NAME; ?>">
    <meta property="og:image" content="<?php echo !empty($kabinet['logo']) ? uploadUrl($kabinet['logo']) : assetUrl('images/og-default.jpg'); ?>">
</head>
<body class="<?php echo $page_class; ?>">

    <!-- HERO SECTION - Tampil di semua halaman -->
    <section class="hero">
        <div class="hero-background">
            <?php if (!empty($kabinet['foto_bersama'])): ?>
                <!-- PERBAIKAN: Gunakan uploadUrl() -->
                <img src="<?php echo uploadUrl($kabinet['foto_bersama']); ?>" 
                     alt="Foto Bersama BEM Kabinet <?php echo htmlspecialchars($kabinet['nama'] ?? 'ASTAWIDYA'); ?>"
                     loading="lazy">
            <?php else: ?>
                <!-- PERBAIKAN: Gunakan assetUrl() -->
                <img src="<?php echo assetUrl('images/default-hero.jpg'); ?>" 
                     alt="Default Hero"
                     loading="lazy">
            <?php endif; ?>
        </div>
        <div class="hero-gradient-overlay"></div>
        
        <!-- Konten hero - HANYA TAMPIL DI INDEX -->
        <?php if ($isHomePage): ?>
        <div class="hero-content">
            <h1 class="hero-title">
                KABINET <span class="biru"><?php echo htmlspecialchars($kabinet['nama'] ?? 'ASTAWIDYA'); ?></span>
            </h1>
            <p class="hero-sub">
                BEM BUDI UTOMO NASIONAL 
                <?php 
                $periode = '';
                if (!empty($kabinet['tahun_mulai']) && !empty($kabinet['tahun_selesai'])) {
                    $periode = $kabinet['tahun_mulai'] . '/' . $kabinet['tahun_selesai'];
                }
                echo htmlspecialchars($periode ?: '2025/2026');
                ?>
            </p>
            
        </div>
        
        <!-- Scroll hint - HANYA TAMPIL DI INDEX -->
        <div class="scroll-hint">
            <i class="fas fa-chevron-down"></i>
            <span>gulir ke bawah</span>
        </div>
        <?php endif; ?>
    </section>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">
                <!-- PERBAIKAN: Gunakan baseUrl() untuk link -->
                <a href="<?php echo baseUrl(); ?>">
                    <?php if (!empty($kabinet['logo'])): ?>
                        <!-- PERBAIKAN: Gunakan uploadUrl() -->
                        <img src="<?php echo uploadUrl($kabinet['logo']); ?>" 
                             alt="Logo BEM" 
                             class="logo-img"
                             loading="lazy">
                    <?php else: ?>
                        <i class="fas fa-university"></i>
                    <?php endif; ?>
                    <span>BEM <span class="text-biru">INST</span>BUNAS</span>
                </a>
            </div>
            
            <!-- Menu Navigasi - SUDAH DITAMBAH ARSIP -->
            <ul class="nav-menu">
                <li class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
                    <a href="<?php echo baseUrl(); ?>">Beranda</a>
                </li>
                <li class="<?php echo ($current_page == 'berita.php') ? 'active' : ''; ?>">
                    <a href="<?php echo baseUrl('berita.php'); ?>">Berita</a>
                </li>
                <li class="<?php echo ($current_page == 'kepengurusan.php') ? 'active' : ''; ?>">
                    <a href="<?php echo baseUrl('kepengurusan.php'); ?>">Kepengurusan</a>
                </li>
                <!-- ✅ TAMBAHKAN: Menu Arsip -->
                <li class="<?php echo ($current_page == 'arsip-periode.php') ? 'active' : ''; ?>">
                    <a href="<?php echo baseUrl('arsip-periode.php'); ?>">Arsip</a>
                </li>
                <li class="<?php echo ($current_page == 'kontak.php') ? 'active' : ''; ?>">
                    <a href="<?php echo baseUrl('kontak.php'); ?>">Kontak</a>
                </li>
            </ul>
            
            <!-- Mobile Menu Toggle -->
            <div class="menu-toggle">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>
    
    <!-- Konten utama - CATATAN: Tag pembuka <main> akan ditutup di footer -->
    <main></main>