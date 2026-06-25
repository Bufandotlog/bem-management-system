<?php
// admin/dashboard.php
require_once __DIR__ . '/header.php';

// Ambil data statistik kabinet
$totalBerita = dbFetchOne("SELECT COUNT(*) as total FROM berita")['total'];
$totalKementerian = dbFetchOne("SELECT COUNT(*) as total FROM kementerian")['total'];
$totalAnggota = dbFetchOne("SELECT COUNT(*) as total FROM anggota_kementerian")['total'];
$totalBPH = dbFetchOne("SELECT COUNT(*) as total FROM struktur_bph")['total'];

// Ambil data statistik persuratan (sesuai periode aktif)
$periode_id = getUserPeriode();
$totalSuratL = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'L'", [$periode_id], "i")['total'];
$totalSuratD = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'D'", [$periode_id], "i")['total'];
$totalSuratM = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'M'", [$periode_id], "i")['total'];

// Ambil data terbaru
$beritaTerbaru = dbFetchAll("SELECT judul, tanggal FROM berita ORDER BY tanggal DESC LIMIT 5");
$suratTerbaru = dbFetchAll("SELECT nomor_surat, perihal, jenis_surat FROM arsip_surat WHERE periode_id = ? ORDER BY id DESC LIMIT 5", [$periode_id], "i");

// Logika tampilan (Hybrid)
$showGeneralStats = ($isSuperadmin || $admin_role === 'kominfo' || $admin_role === 'admin');
$showLetterStats  = ($isSuperadmin || $admin_role === 'sekretaris' || $admin_role === 'admin');
?>

<div class="page-header">
    <div>
        <h1>Dashboard <?php echo $isSuperadmin ? 'Superadmin' : ($admin_role === 'sekretaris' ? 'Sekretariat' : ($admin_role === 'kominfo' ? 'Kominfo' : ($admin_role === 'admin' ? 'Admin' : ''))); ?></h1>
        <p>Selamat datang di panel kendali BEM Kabinet Astawidya</p>
    </div>
    <div class="date-display">
        <i class="far fa-calendar-alt"></i>
        <?php echo tanggalIndonesia(); ?>
    </div>
</div>

<!-- STATS SECTION -->
<div class="dashboard-sections" style="display: flex; flex-direction: column; gap: 30px;">
    
    <!-- STATISTIK KABINET (Admin/Superadmin) -->
    <?php if ($showGeneralStats): ?>
    <div class="stats-group">
        <h2 style="margin-bottom: 15px; font-size: 1.1rem; color: #8BB9F0; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-university"></i> Statistik Kabinet
        </h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-newspaper"></i></div>
                <div class="stat-value"><?php echo $totalBerita; ?></div>
                <div class="stat-label">Berita</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value"><?php echo $totalKementerian; ?></div>
                <div class="stat-label">Kementerian</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-friends"></i></div>
                <div class="stat-value"><?php echo $totalAnggota; ?></div>
                <div class="stat-label">Anggota</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                <div class="stat-value"><?php echo $totalBPH; ?></div>
                <div class="stat-label">BPH</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- STATISTIK PERSURATAN (Sekretaris/Superadmin) -->
    <?php if ($showLetterStats): ?>
    <div class="stats-group">
        <h2 style="margin-bottom: 15px; font-size: 1.1rem; color: #4A90E2; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-envelope-open-text"></i> Statistik Persuratan (Sekretariat)
        </h2>
        <div class="stats-grid">
            <div class="stat-card" style="border-left: 4px solid #4A90E2;">
                <div class="stat-icon" style="background: rgba(74, 144, 226, 0.1); color: #4A90E2;"><i class="fas fa-paper-plane"></i></div>
                <div class="stat-value"><?php echo $totalSuratL; ?></div>
                <div class="stat-label">Surat Keluar</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #673AB7;">
                <div class="stat-icon" style="background: rgba(103, 58, 183, 0.1); color: #673AB7;"><i class="fas fa-file-export"></i></div>
                <div class="stat-value"><?php echo $totalSuratD; ?></div>
                <div class="stat-label">Surat Dalam</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #f39c12;">
                <div class="stat-icon" style="background: rgba(243, 156, 18, 0.1); color: #f39c12;"><i class="fas fa-file-import"></i></div>
                <div class="stat-value"><?php echo $totalSuratM; ?></div>
                <div class="stat-label">Surat Masuk</div>
            </div>
            <div class="stat-card" style="border-left: 4px solid #2ecc71;">
                <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;"><i class="fas fa-check-double"></i></div>
                <div class="stat-value"><?php echo $totalSuratL + $totalSuratD + $totalSuratM; ?></div>
                <div class="stat-label">Total Arsip</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: <?php echo ($showGeneralStats && $showLetterStats) ? '1fr 1fr' : '2fr 1fr'; ?>; gap: 25px; margin-top: 30px;">
    
    <!-- Berita Terbaru -->
    <?php if ($showGeneralStats): ?>
    <div class="recent-news">
        <div class="section-header">
            <h2><i class="fas fa-newspaper"></i> Berita Terbaru</h2>
            <a href="berita.php" style="font-size: 0.8rem;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="news-list">
            <?php if (empty($beritaTerbaru)): ?>
                <div class="news-item"><div class="news-info"><p style="color: #666; text-align: center; width: 100%;">Belum ada berita.</p></div></div>
            <?php else: ?>
                <?php foreach ($beritaTerbaru as $berita): ?>
                <div class="news-item">
                    <div class="news-info">
                        <h3><?php echo htmlspecialchars($berita['judul'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($berita['tanggal'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Arsip Surat Terbaru -->
    <?php if ($showLetterStats): ?>
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <span><i class="fas fa-folder-open"></i> Arsip Surat Terbaru</span>
            <a href="arsip-surat.php" style="font-size: 0.8rem; color: #4A90E2; text-decoration: none;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="admin-table" style="margin: 0; border: none;">
                <tbody>
                    <?php if (empty($suratTerbaru)): ?>
                        <tr><td style="text-align:center; padding:30px; color:#666;">Belum ada arsip surat.</td></tr>
                    <?php else: ?>
                        <?php foreach ($suratTerbaru as $surat): ?>
                        <tr>
                            <td style="padding: 10px 15px;">
                                <div style="font-weight:bold; font-size:0.85rem;"><?php echo htmlspecialchars($surat['nomor_surat']); ?></div>
                                <div style="font-size:0.75rem; color:#888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;">
                                    <?php echo htmlspecialchars($surat['perihal']); ?>
                                </div>
                            </td>
                            <td style="text-align:right; padding: 10px 15px;">
                                <span class="badge" style="background: <?php echo $surat['jenis_surat']==='L' ? '#4A90E2' : ($surat['jenis_surat']==='D' ? '#673AB7' : '#f39c12'); ?>; font-size:0.6rem;">
                                    <?php echo $surat['jenis_surat']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Quick Actions -->
<h2 style="margin-bottom: 20px; margin-top: 30px;"><i class="fas fa-bolt"></i> Aksi Cepat</h2>
<div class="quick-actions" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px;">
    <?php if ($isSuperadmin || $admin_role !== 'sekretaris'): ?>
        <a href="berita-edit.php" class="action-card"><i class="fas fa-plus-circle"></i><span>Tambah Berita</span></a>
        <a href="kepengurusan.php?action=new" class="action-card"><i class="fas fa-user-plus"></i><span>Tambah Anggota</span></a>
    <?php endif; ?>
    
    <?php if ($isSuperadmin || $admin_role === 'sekretaris' || $admin_role === 'admin'): ?>
        <a href="buat-surat.php" class="action-card" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3);"><i class="fas fa-file-signature"></i><span>Buat Surat</span></a>
        <a href="arsip-surat.php" class="action-card" style="background: rgba(103, 58, 183, 0.1); border-color: rgba(103, 58, 183, 0.3);"><i class="fas fa-search"></i><span>Cari Arsip</span></a>
    <?php endif; ?>

    <?php if ($isSuperadmin || $admin_role === 'admin'): ?>
        <a href="kelola-admin.php" class="action-card" style="background: rgba(255,255,255,0.05);"><i class="fas fa-user-shield"></i><span>Kelola Admin</span></a>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>