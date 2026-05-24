<?php
// berita.php - Halaman Daftar Berita
// VERSI: 2.1 - FIX: filter status published, mb_substr, urlencode slug

include 'header.php';
$page_title = 'Berita';

// ===========================================
// AMBIL BERITA PUBLISHED DARI DATABASE
// ✅ FIX: Tambah filter status = 'published' agar draft tidak tampil
// ===========================================
$berita_list = dbFetchAll("
    SELECT id, judul, slug, tanggal, penulis, gambar, konten, views
    FROM berita
    WHERE status = 'published'
    ORDER BY tanggal DESC, id DESC
");
?>

<!-- ===== HERO CAPTION ===== -->
<div class="hero-caption">
    <div class="caption-content">
        <h1 class="caption-title"><span>BERITA</span></h1>
        <p class="caption-narasi">
            adalah ruang narasi resmi BEM Budi Utomo Nasional Kabinet Astawidya,
            tempat kata-kata bertumbuh menjadi informasi, dan peristiwa kampus
            menjelma cerita yang bermakna. Di sini, kabar terkini, aspirasi mahasiswa,
            serta jejak setiap langkah perjuangan dihimpun dan disajikan secara
            jernih, akurat, dan mendidik.
        </p>
        <div class="caption-scroll">
            <span class="scroll-text">gulir ke bawah</span>
            <i class="fas fa-chevron-down"></i>
        </div>
    </div>
</div>

<!-- WRAPPER konten utama -->
<div class="berita-content-wrapper">
    <div class="container" style="padding-top: 2rem;">
        <h1 class="section-title"><span>BERITA TERKINI</span></h1>

        <?php if (empty($berita_list)): ?>
        <div class="no-news">
            <i class="far fa-newspaper"></i>
            <p>Belum ada berita saat ini.</p>
        </div>

        <?php else: ?>
        <div class="card-grid">
            <?php foreach ($berita_list as $b):
                // ✅ FIX: strip_tags + mb_substr agar aman untuk teks multibyte
                $preview = mb_substr(strip_tags($b['konten'] ?? ''), 0, 150, 'UTF-8');
                if (mb_strlen($b['konten'] ?? '', 'UTF-8') > 150) $preview .= '...';
            ?>
            <div class="card">
                <div class="card-image">
                    <?php if (!empty($b['gambar'])): ?>
                        <img src="<?php echo uploadUrl($b['gambar']); ?>"
                             alt="<?php echo htmlspecialchars($b['judul']); ?>"
                             loading="lazy"
                             onerror="this.src='<?php echo baseUrl('assets/images/no-image.jpg'); ?>'">
                    <?php else: ?>
                        <img src="<?php echo baseUrl('assets/images/no-image.jpg'); ?>"
                             alt="No Image">
                    <?php endif; ?>
                </div>
                <div class="card-content">
                    <h3><?php echo htmlspecialchars($b['judul']); ?></h3>
                    <div class="card-meta">
                        <span>
                            <i class="far fa-calendar-alt"></i>
                            <?php echo formatTanggal($b['tanggal']); ?>
                        </span>
                        <span>
                            <i class="far fa-user"></i>
                            <?php echo htmlspecialchars($b['penulis'] ?? 'Admin'); ?>
                        </span>
                        <span>
                            <i class="far fa-eye"></i>
                            <?php echo number_format($b['views'] ?? 0); ?>
                        </span>
                    </div>
                    <p><?php echo htmlspecialchars($preview); ?></p>
                    <!-- ✅ FIX: urlencode() pada slug untuk URL yang aman -->
                    <a href="berita-detail.php?slug=<?php echo urlencode($b['slug']); ?>"
                       class="btn btn-small">
                        Baca Selengkapnya
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards      = document.querySelectorAll('.card');
    const heroCaption = document.querySelector('.hero-caption');

    // Intersection Observer untuk animasi card
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        cards.forEach(card => observer.observe(card));
    } else {
        cards.forEach((card, i) => {
            setTimeout(() => card.classList.add('visible'), i * 100);
        });
    }

    // Efek fade hero caption (fallback jika module JS tidak terdeteksi)
    if (heroCaption) {
        function updateCaptionOpacity() {
            const scrollY = window.scrollY;
            if (scrollY <= 20) {
                heroCaption.style.opacity    = '1';
                heroCaption.style.visibility = 'visible';
                heroCaption.style.transform  = 'translateY(0)';
            } else if (scrollY >= 150) {
                heroCaption.style.opacity    = '0';
                heroCaption.style.visibility = 'hidden';
                heroCaption.style.transform  = 'translateY(-15px)';
            } else {
                const ratio = (scrollY - 20) / 130;
                heroCaption.style.opacity    = 1 - ratio;
                heroCaption.style.visibility = 'visible';
                heroCaption.style.transform  = `translateY(-${scrollY * 0.1}px)`;
            }
        }

        setTimeout(function() {
            const managed = window.getComputedStyle(heroCaption).opacity !== '1'
                         || heroCaption.style.transform !== '';
            if (!managed) {
                window.addEventListener('scroll', updateCaptionOpacity, { passive: true });
                updateCaptionOpacity();
            }
        }, 500);
    }
});
</script>

<style>
.berita-content-wrapper {
    position: relative;
    z-index: 10;
    background: transparent;
    margin-top: 100vh;
    min-height: 100vh;
    padding-top: 1rem;
}
.berita-content-wrapper .container {
    padding-top: 2rem !important;
    padding-bottom: 4rem;
}
.no-news {
    text-align: center;
    padding: 4rem;
    color: #888;
}
.no-news i {
    font-size: 4rem;
    margin-bottom: 1rem;
    color: #333;
    display: block;
}
@media (max-width: 768px) {
    .berita-content-wrapper { margin-top: 100vh; }
    .berita-content-wrapper .section-title { margin-top: 3rem; }
}
@media (max-width: 480px) {
    .berita-content-wrapper { margin-top: 100vh; }
    .berita-content-wrapper .section-title { margin-top: 4rem; }
}
</style>

<?php include 'footer.php'; ?>