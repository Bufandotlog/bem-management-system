<?php
// index.php - Halaman Beranda (Database Version - Full Integration)
include 'header.php';
$page_title = 'Beranda';

// ===========================================
// AMBIL PERIODE AKTIF
// ===========================================
$periode_aktif = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");
$periode_id = $periode_aktif['id'] ?? 1; // Default ke 1 jika tidak ada

// ===== AMBIL DATA DARI DATABASE =====
$kabinet = dbFetchOne("SELECT * FROM kabinet WHERE id = 1");

// Ambil data ketua UNTUK PERIODE AKTIF
$ketua = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?", 
    [$periode_id], "i"
);

// Parse sambutan dari deskripsi (format: pembuka|paragraf1|paragraf2)
$sambutan = [
    'pembuka' => 'Assalamu\'alaikum warahmatullahi wabarakatuh,',
    'paragraf1' => 'Selamat datang di website resmi BEM Kabinet Astawidya. Kabinet ini hadir dengan semangat Astawidya — delapan arah kejayaan — untuk mewujudkan kampus yang progresif, kritis, dan humanis. Mari bergandengan tangan, berkarya nyata untuk mahasiswa dan bangsa.',
    'paragraf2' => 'Website ini adalah wadah informasi dan komunikasi kita semua. Silakan eksplorasi program kerja, struktur kepengurusan, dan berbagai kegiatan kami.'
];

if ($ketua && !empty($ketua['deskripsi'])) {
    // Coba parse dengan format pipe
    $parts = explode('|', $ketua['deskripsi']);
    if (count($parts) >= 3) {
        $sambutan['pembuka'] = trim($parts[0]);
        $sambutan['paragraf1'] = trim($parts[1]);
        $sambutan['paragraf2'] = trim($parts[2]);
    } else {
        // Fallback: gunakan deskripsi sebagai paragraf1
        $sambutan['paragraf1'] = $ketua['deskripsi'];
    }
}

// Ambil visi misi (global, tidak terikat periode)
$visi_misi_data = dbFetchOne("SELECT * FROM visi_misi WHERE id = 1");
$visi_misi = [
    'visi' => $visi_misi_data['visi'] ?? 'Mewujudkan BEM yang responsif, aspiratif, dan inovatif dalam membangun mahasiswa yang berkarakter, berkualitas, dan bermanfaat bagi masyarakat.',
    'misi' => json_decode($visi_misi_data['misi'] ?? '[]', true)
];

// Fallback misi jika kosong
if (empty($visi_misi['misi'])) {
    $visi_misi['misi'] = [
        'Meningkatkan kualitas akademik dan non-akademik mahasiswa',
        'Memperjuangkan hak dan kesejahteraan mahasiswa',
        'Membangun sinergi dengan seluruh elemen kampus',
        'Mengembangkan potensi dan kreativitas mahasiswa',
        'Menjalin kerjasama dengan berbagai pihak eksternal',
        'Mengoptimalkan peran BEM sebagai jembatan aspirasi'
    ];
}

// Ambil 3 berita terbaru (berita GLOBAL, tidak terikat periode)
$berita_terbaru = dbFetchAll("SELECT * FROM berita ORDER BY tanggal DESC LIMIT 3");
?>

<!-- HERO SECTION TELAH DIHAPUS - SUDAH ADA DI HEADER -->

<!-- Sambutan Presiden Mahasiswa - dengan sambutan dari database -->
<section id="sambutan" class="sambutan">
    <div class="sambutan-container">
        <div class="sambutan-foto">
            <?php if (!empty($ketua['foto'])): ?>
                <!-- ✨ DIPERBAIKI: menggunakan uploadUrl() -->
                <img src="<?php echo uploadUrl($ketua['foto']); ?>" 
                     alt="Presiden Mahasiswa">
            <?php else: ?>
                <!-- ⚠️ Default avatar tetap menggunakan baseUrl() -->
                <img src="<?php echo baseUrl('assets/images/default-avatar.jpg'); ?>" alt="Default Avatar">
            <?php endif; ?>
        </div>
        <div class="sambutan-text">
            <h2>Sambutan<br><span class="text-merah">Presiden Mahasiswa</span></h2>
            <div class="jabatan">
                <?php echo htmlspecialchars($ketua['nama'] ?? 'Dede Anggi Muhyidin'); ?> • 
                Ketua BEM 
                <?php 
                if ($periode_aktif) {
                    echo htmlspecialchars($periode_aktif['nama']) . ' ' . $periode_aktif['tahun_mulai'];
                } else {
                    echo htmlspecialchars($kabinet['tahun_mulai'] ?? '2025');
                }
                ?>
            </div>
            
            <p><?php echo nl2br(htmlspecialchars($sambutan['pembuka'])); ?></p>
            <p><?php echo nl2br(htmlspecialchars($sambutan['paragraf1'])); ?></p>
            <p><?php echo nl2br(htmlspecialchars($sambutan['paragraf2'])); ?></p>
            
            <div class="ttd">
                <strong><?php echo htmlspecialchars($ketua['nama'] ?? 'Dede Anggi Muhyidin'); ?></strong><br>
                Presiden Mahasiswa 
                <?php 
                if ($periode_aktif) {
                    echo htmlspecialchars($periode_aktif['nama']) . ' ' . $periode_aktif['tahun_mulai'];
                } else {
                    echo htmlspecialchars($kabinet['tahun_mulai'] ?? '2025');
                }
                ?>
            </div>
        </div>
    </div>
</section>

<!-- Visi Misi - dari tabel visi_misi -->
<div class="container">
    <h2 class="section-title"><span>VISI & MISI</span></h2>
    <div class="visi-misi">
        <div class="visi">
            <h3>Visi</h3>
            <p><?php echo nl2br(htmlspecialchars($visi_misi['visi'])); ?></p>
        </div>
        <div class="misi">
            <h3>Misi</h3>
            <ul>
                <?php foreach($visi_misi['misi'] as $misi): ?>
                <li><?php echo htmlspecialchars($misi); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<!-- Berita Terkini - dengan class spesifik untuk home -->
<div class="container news-section"> <!-- TAMBAHKAN class news-section -->
    <h2 class="section-title"><span>BERITA TERKINI</span></h2>
    
    <?php if (empty($berita_terbaru)): ?>
        <div style="text-align: center; padding: 3rem; color: #888;">
            <i class="far fa-newspaper" style="font-size: 3rem; margin-bottom: 1rem;"></i>
            <p>Belum ada berita saat ini.</p>
        </div>
    <?php else: ?>
        <div class="card-grid home-news-grid">
            <?php foreach($berita_terbaru as $b): ?>
            <div class="card home-news-card">
                <div class="card-image home-news-image">
                    <?php if (!empty($b['gambar'])): ?>
                        <!-- ✨ DIPERBAIKI: menggunakan uploadUrl() -->
                        <img src="<?php echo uploadUrl($b['gambar']); ?>" 
                             alt="<?php echo htmlspecialchars($b['judul']); ?>">
                    <?php else: ?>
                        <!-- ⚠️ Default news tetap menggunakan baseUrl() -->
                        <img src="<?php echo baseUrl('assets/images/default-news.jpg'); ?>" alt="Default News">
                    <?php endif; ?>
                </div>
                <div class="card-content home-news-content">
                    <h3><?php echo htmlspecialchars($b['judul']); ?></h3>
                    <div class="card-meta home-news-meta">
                        <span><i class="far fa-calendar-alt"></i> <?php echo formatTanggal($b['tanggal']); ?></span>
                        <span><i class="far fa-user"></i> <?php echo htmlspecialchars($b['penulis']); ?></span>
                    </div>
                    <p><?php echo htmlspecialchars(substr($b['konten'], 0, 150)); ?>...</p>
                    <a href="berita-detail.php?slug=<?php echo $b['slug']; ?>" class="btn btn-small">Baca Selengkapnya</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align: center; margin-top: 3rem;">
            <a href="berita.php" class="btn">Lihat Semua Berita</a>
        </div>
    <?php endif; ?>
</div>

<!-- CSS untuk mengatur jarak dengan footer -->
<style>
/* Beri jarak antara section berita dengan footer */
.news-section {
    margin-bottom: 6rem !important;  /* Jarak 6rem sebelum footer */
    padding-bottom: 2rem;
}

/* Responsive untuk mobile */
@media (max-width: 768px) {
    .news-section {
        margin-bottom: 4rem !important;
    }
}

/* Pastikan footer tidak memiliki margin-top yang mengganggu */
footer {
    margin-top: 0 !important;
    clear: both;
}
</style>

<?php include 'footer.php'; ?>