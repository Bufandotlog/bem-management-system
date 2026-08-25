<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $__env->yieldContent('title', 'BEM Kabinet Astawidya'); ?></title>
    
    <!-- ===== FAVICON ===== -->
    <link rel="icon" type="image/svg+xml" href="<?php echo e(asset('assets/images/favicon/favicon.svg')); ?>">
    <link rel="icon" type="image/x-icon" href="<?php echo e(asset('assets/images/favicon/favicon.ico')); ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="<?php echo e(asset('assets/images/favicon/favicon-96x96.png')); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo e(asset('assets/images/favicon/apple-touch-icon.png')); ?>">
    <link rel="manifest" href="<?php echo e(asset('assets/images/favicon/site.webmanifest')); ?>">
    
    <meta name="theme-color" content="#4A90E2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    
    <!-- CSS -->
    <link rel="stylesheet" href="<?php echo e(asset('assets/css/style.css')); ?>">
    <?php echo $__env->yieldPushContent('css'); ?>
    
    <!-- External CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Meta tags untuk SEO -->
    <meta name="description" content="Website resmi BEM Kabinet Astawidya - Delapan Arah Kejayaan">
    <meta property="og:title" content="BEM Kabinet Astawidya">
    <meta property="og:image" content="<?php echo e(asset('assets/images/og-default.jpg')); ?>">
</head>
<body class="<?php echo $__env->yieldContent('page_class', ''); ?>">

    <!-- HERO SECTION -->
    <section class="hero">
        <div class="hero-background">
            <?php $kabinet = \App\Models\Kabinet::find(1); ?>
            <?php if($kabinet && $kabinet->foto_bersama): ?>
                <img src="<?php echo e(asset('uploads/' . $kabinet->foto_bersama)); ?>" alt="Foto Bersama BEM Kabinet <?php echo e($kabinet->nama ?? 'ASTAWIDYA'); ?>" loading="lazy">
            <?php else: ?>
                <img src="<?php echo e(asset('assets/images/default-hero.jpg')); ?>" alt="Default Hero" loading="lazy">
            <?php endif; ?>
        </div>
        <div class="hero-gradient-overlay"></div>
        
        <?php if(Route::currentRouteName() === 'home'): ?>
        <!-- Konten hero - HANYA TAMPIL DI INDEX -->
        <div class="hero-content">
            <h1 class="hero-title">
                KABINET <span class="biru"><?php echo e($kabinet->nama ?? 'ASTAWIDYA'); ?></span>
            </h1>
            <p class="hero-sub">
                BEM BUDI UTOMO NASIONAL 
                <?php
                    $periode = '';
                    if ($kabinet && $kabinet->tahun_mulai && $kabinet->tahun_selesai) {
                        $periode = $kabinet->tahun_mulai . '/' . $kabinet->tahun_selesai;
                    }
                ?>
                <?php echo e($periode ?: '2026/2027'); ?>

            </p>
        </div>
        
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
                <a href="<?php echo e(route('home')); ?>">
                    <?php if($kabinet && $kabinet->logo): ?>
                        <img src="<?php echo e(asset('uploads/' . $kabinet->logo)); ?>" alt="Logo BEM" class="logo-img" loading="lazy">
                    <?php else: ?>
                        <i class="fas fa-university"></i>
                    <?php endif; ?>
                    <span>BEM <span class="text-biru">INST</span>BUNAS</span>
                </a>
            </div>
            
            <!-- Menu Navigasi -->
            <ul class="nav-menu">
                <li class="<?php echo e(Route::currentRouteName() == 'home' ? 'active' : ''); ?>">
                    <a href="<?php echo e(route('home')); ?>">Beranda</a>
                </li>
                <li class="<?php echo e(Route::is('berita.*') ? 'active' : ''); ?>">
                    <a href="<?php echo e(route('berita.index')); ?>">Berita</a>
                </li>
                <li class="<?php echo e(Route::is('kepengurusan.*') ? 'active' : ''); ?>">
                    <a href="<?php echo e(route('kepengurusan.index')); ?>">Kepengurusan</a>
                </li>
                <li class="<?php echo e(Route::currentRouteName() == 'arsip' ? 'active' : ''); ?>">
                    <a href="<?php echo e(route('arsip')); ?>">Arsip</a>
                </li>
                <li class="<?php echo e(Route::currentRouteName() == 'kontak' ? 'active' : ''); ?>">
                    <a href="<?php echo e(route('kontak')); ?>">Kontak</a>
                </li>
            </ul>
            
            <div class="menu-toggle">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>
    
    <!-- Konten utama -->
    <main>
        <?php echo $__env->yieldContent('content'); ?>
    </main>

    <footer>
        <div class="footer-content">
            <div class="footer-section">
                <h3>BEM Kabinet Astawidya</h3>
                <p>Mewujudkan BEM yang responsif, aspiratif, dan inovatif dalam membangun mahasiswa yang berkarakter, berkualitas, dan bermanfaat bagi masyarakat.</p>
                <div class="social-links">
                    <a href="https://www.instagram.com/beminstbunas"><i class="fab fa-instagram"></i></a>
                    <a href="https://www.tiktok.com/@bem.instbunas"><i class="fab fa-tiktok"></i></a>
                </div>
            </div>
            <div class="footer-section">
                <h3>Tautan Cepat</h3>
                <a href="<?php echo e(route('home')); ?>">Beranda</a>
                <a href="<?php echo e(route('berita.index')); ?>">Berita</a>
                <a href="<?php echo e(route('kepengurusan.index')); ?>">Kepengurusan</a>
                <a href="<?php echo e(route('kontak')); ?>">Kontak</a>
            </div>
            <div class="footer-section">
                <h3>Kontak</h3>
                <p><i class="fas fa-map-marker-alt"></i> Jl. Siliwangi No. 121, Desa Heuleut, Kecamatan Kadipaten, Kabupaten Majalengka, Jawa Barat, 45452</p>
                <p><i class="fas fa-phone"></i> +62 838-0585-3345</p>
                <p><i class="fas fa-envelope"></i> beminstbunasastawidya@gmail.com</p>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; <?php echo e(date('Y')); ?> BEM Kabinet Astawidya.BFN.v1.2.27. Hak Cipta Dilindungi.</p>
        </div>
    </footer>

    <script type="module" src="<?php echo e(asset('assets/js/script.js')); ?>"></script>
    
    <?php echo $__env->yieldPushContent('js'); ?>
</body>
</html>
<?php /**PATH /var/www/html/bem/resources/views/public/layouts/app.blade.php ENDPATH**/ ?>