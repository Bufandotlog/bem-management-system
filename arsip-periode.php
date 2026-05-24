<?php
// arsip-periode.php - Halaman daftar semua periode kepengurusan (Publik)
include 'header.php';
$page_title = 'Arsip Kepengurusan BEM';

// ===========================================
// AMBIL SEMUA PERIODE DARI DATABASE
// ===========================================
$semua_periode = dbFetchAll("SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
$periode_aktif = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");

// ===========================================
// HITUNG STATISTIK UNTUK SETIAP PERIODE
// ===========================================
$statistik = [];

foreach ($semua_periode as $p) {
    $periode_id = $p['id'];
    
    $bph_terisi = dbFetchOne(
        "SELECT COUNT(*) as total FROM struktur_bph WHERE periode_id = ?",
        [$periode_id], "i"
    )['total'] ?? 0;
    
    $jumlah_kementerian = dbFetchOne(
        "SELECT COUNT(*) as total FROM kementerian WHERE periode_id = ?",
        [$periode_id], "i"
    )['total'] ?? 0;
    
    $anggota_bph = dbFetchOne(
        "SELECT COUNT(*) as total FROM anggota_bph WHERE periode_id = ?",
        [$periode_id], "i"
    )['total'] ?? 0;
    
    $anggota_kementerian = dbFetchOne(
        "SELECT COUNT(*) as total FROM anggota_kementerian WHERE periode_id = ?",
        [$periode_id], "i"
    )['total'] ?? 0;
    
    $ketua = dbFetchOne(
        "SELECT foto FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?",
        [$periode_id], "i"
    );
    
    $statistik[$periode_id] = [
        'bph_terisi' => $bph_terisi,
        'jumlah_kementerian' => $jumlah_kementerian,
        'total_anggota' => $anggota_bph + $anggota_kementerian,
        'foto_ketua' => $ketua['foto'] ?? null
    ];
}
?>

<!-- ✅ Link CSS eksternal -->
<link rel="stylesheet" href="<?php echo assetUrl('css/arsip-periode.css'); ?>?v=<?php echo time(); ?>">

<!-- ===== HEADER HALAMAN ===== -->
<div class="arsip-header">
    <div class="arsip-header-content">
        <h1 class="arsip-title">
            <i class="fas fa-archive"></i> ARSIP KEPENGURUSAN
        </h1>
        <p class="arsip-subtitle">
            Jejak sejarah kepengurusan BEM Budi Utomo Nasional dari masa ke masa
        </p>
    </div>
</div>

<!-- ===== KONTEN UTAMA ===== -->
<div class="arsip-container">
    
    <!-- Periode Aktif (Featured) -->
    <?php if ($periode_aktif): ?>
    <div class="periode-featured">
        <h2 class="featured-title">
            <i class="fas fa-star"></i> Periode Aktif Saat Ini
        </h2>
        
        <a href="kepengurusan.php?periode=<?= $periode_aktif['id'] ?>" class="featured-card">
            <div class="featured-content">
                <div class="featured-badge">AKTIF</div>
                <h3><?= htmlspecialchars($periode_aktif['nama']) ?></h3>
                <div class="featured-tahun">
                    <?= $periode_aktif['tahun_mulai'] ?> - <?= $periode_aktif['tahun_selesai'] ?>
                </div>
                <?php if (!empty($periode_aktif['deskripsi'])): ?>
                    <p class="featured-deskripsi"><?= htmlspecialchars($periode_aktif['deskripsi']) ?></p>
                <?php endif; ?>
                
                <div class="featured-stats">
                    <div class="stat-item">
                        <i class="fas fa-users"></i>
                        <span><?= $statistik[$periode_aktif['id']]['bph_terisi'] ?? 0 ?>/4 BPH</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-building"></i>
                        <span><?= $statistik[$periode_aktif['id']]['jumlah_kementerian'] ?? 0 ?> Kementerian</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-user-friends"></i>
                        <span><?= $statistik[$periode_aktif['id']]['total_anggota'] ?? 0 ?> Anggota</span>
                    </div>
                </div>
                
                <div class="featured-link">
                    Lihat Kepengurusan <i class="fas fa-arrow-right"></i>
                </div>
            </div>
            
            <?php if (!empty($statistik[$periode_aktif['id']]['foto_ketua'])): ?>
            <div class="featured-image">
                <img src="<?= uploadUrl($statistik[$periode_aktif['id']]['foto_ketua']) ?>" alt="Ketua BEM">
            </div>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>
    
    <!-- Daftar Semua Periode -->
    <div class="arsip-grid-section">
        <h2 class="section-title">
            <i class="fas fa-timeline"></i> Semua Periode Kepengurusan
        </h2>
        
        <?php if (empty($semua_periode)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p>Belum ada data periode kepengurusan</p>
            </div>
        <?php else: ?>
            <div class="periode-grid">
                <?php foreach ($semua_periode as $p): 
                    $is_active = $p['is_active'];
                    $stats = $statistik[$p['id']] ?? ['bph_terisi' => 0, 'jumlah_kementerian' => 0, 'total_anggota' => 0];
                ?>
                <a href="kepengurusan.php?periode=<?= $p['id'] ?>" class="periode-card <?= $is_active ? 'active-card' : '' ?>">
                    <div class="periode-card-header">
                        <div class="periode-badge-container">
                            <?php if ($is_active): ?>
                                <span class="badge badge-active">Aktif</span>
                            <?php else: ?>
                                <span class="badge badge-arsip">Arsip</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($stats['foto_ketua'])): ?>
                            <div class="periode-thumbnail">
                                <img src="<?= uploadUrl($stats['foto_ketua']) ?>" alt="Ketua">
                            </div>
                        <?php else: ?>
                            <div class="periode-thumbnail default-thumbnail">
                                <i class="fas fa-users"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="periode-card-body">
                        <h3 class="periode-nama"><?= htmlspecialchars($p['nama']) ?></h3>
                        <div class="periode-tahun">
                            <?= $p['tahun_mulai'] ?> - <?= $p['tahun_selesai'] ?>
                        </div>
                        
                        <div class="periode-stats">
                            <div class="stat-row">
                                <i class="fas fa-user-tie"></i>
                                <span><?= $stats['bph_terisi'] ?>/4 BPH</span>
                            </div>
                            <div class="stat-row">
                                <i class="fas fa-building"></i>
                                <span><?= $stats['jumlah_kementerian'] ?> Kementerian</span>
                            </div>
                            <div class="stat-row">
                                <i class="fas fa-user-friends"></i>
                                <span><?= $stats['total_anggota'] ?> Anggota</span>
                            </div>
                        </div>
                        
                        <?php if (!empty($p['deskripsi'])): ?>
                            <p class="periode-deskripsi">
                                <?= htmlspecialchars(substr($p['deskripsi'], 0, 80)) ?>...
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="periode-card-footer">
                        <span class="view-link">
                            Lihat Detail <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Informasi Tambahan -->
    <div class="arsip-info">
        <div class="info-card">
            <i class="fas fa-info-circle"></i>
            <h4>Tentang Arsip Kepengurusan</h4>
            <p>
                Halaman ini menampilkan seluruh periode kepengurusan BEM 
                yang pernah ada. Klik pada salah satu periode untuk melihat 
                detail struktur organisasi, foto, dan informasi lengkap 
                kepengurusan pada masa tersebut.
            </p>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>