<?php
// admin/core/views/dashboard-admin.php
// Admin General Overview

$periode_id = getUserPeriode();

$totalBerita      = dbFetchOne("SELECT COUNT(*) as total FROM berita WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
$totalKementerian  = dbFetchOne("SELECT COUNT(*) as total FROM kementerian")['total'] ?? 0;
$totalAnggota     = dbFetchOne("SELECT COUNT(*) as total FROM anggota_kementerian WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
$totalKegiatan    = dbFetchOne("SELECT COUNT(*) as total FROM kegiatan WHERE periode_id = ? AND status != 'selesai'", [$periode_id], "i")['total'] ?? 0;

$beritaTerbaru    = dbFetchAll("SELECT judul, tanggal FROM berita WHERE periode_id = ? ORDER BY tanggal DESC LIMIT 5", [$periode_id], "i");
$kegiatanAktif    = dbFetchAll("SELECT nama_kegiatan, tanggal_mulai, status FROM kegiatan WHERE periode_id = ? AND status != 'selesai' ORDER BY tanggal_mulai ASC LIMIT 5", [$periode_id], "i");
?>

<div class="stats-group">
    <h2 style="margin-bottom: 15px; font-size: 1.1rem; color: #8BB9F0; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-university"></i> Statistik Kabinet & Program Kerja
    </h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-newspaper"></i></div>
            <div class="stat-value"><?php echo $totalBerita; ?></div>
            <div class="stat-label">Berita Kabinet</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-building"></i></div>
            <div class="stat-value"><?php echo $totalKementerian; ?></div>
            <div class="stat-label">Kementerian</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-value"><?php echo $totalAnggota; ?></div>
            <div class="stat-label">Anggota Terdaftar</div>
        </div>
        <div class="stat-card" style="border-left: 4px solid #2ecc71;">
            <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;"><i class="fas fa-tasks"></i></div>
            <div class="stat-value"><?php echo $totalKegiatan; ?></div>
            <div class="stat-label">Kegiatan / Proker Aktif</div>
        </div>
    </div>
</div>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px;">
    <!-- Berita Terbaru -->
    <div class="recent-news">
        <div class="section-header">
            <h2><i class="fas fa-newspaper"></i> Berita Terbaru</h2>
            <a href="<?php echo baseUrl('admin/konten/berita.php'); ?>" style="font-size: 0.8rem;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="news-list">
            <?php if (empty($beritaTerbaru)): ?>
                <div class="news-item"><div class="news-info"><p style="color: #666; text-align: center; width: 100%;">Belum ada berita dipublikasi.</p></div></div>
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

    <!-- Kegiatan Aktif Card -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 22px;">
        <div class="section-header">
            <h2><i class="fas fa-calendar-check" style="color: #2ecc71;"></i> Master Kegiatan Aktif</h2>
            <a href="<?php echo baseUrl('admin/kegiatan/kegiatan.php'); ?>" style="font-size: 0.8rem; color: #4A90E2; text-decoration: none;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php if (empty($kegiatanAktif)): ?>
                <p style="color: #666; font-size: 0.85rem; text-align: center; padding: 20px;">Belum ada kegiatan aktif terdaftar.</p>
            <?php else: ?>
                <?php foreach ($kegiatanAktif as $kg): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid #2ecc71;">
                    <div>
                        <h4 style="font-size: 0.9rem; color: #fff; margin: 0 0 4px 0;"><?php echo htmlspecialchars($kg['nama_kegiatan'], ENT_QUOTES, 'UTF-8'); ?></h4>
                        <small style="color: #888; font-size: 0.75rem;"><i class="far fa-clock"></i> <?php echo $kg['tanggal_mulai'] ? date('d/m/Y', strtotime($kg['tanggal_mulai'])) : 'TBA'; ?></small>
                    </div>
                    <span class="badge" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; font-size: 0.68rem; padding: 3px 8px; border-radius: 12px; text-transform: uppercase;">
                        <?php echo htmlspecialchars($kg['status'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Admin Quick Actions -->
<h2 style="margin-bottom: 16px; margin-top: 30px;"><i class="fas fa-bolt"></i> Aksi Cepat</h2>
<div class="quick-actions" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px;">
    <a href="<?php echo baseUrl('admin/konten/berita-edit.php'); ?>" class="action-card"><i class="fas fa-plus-circle"></i><span>Tambah Berita</span></a>
    <a href="<?php echo baseUrl('admin/konten/kepengurusan.php?action=new'); ?>" class="action-card"><i class="fas fa-user-plus"></i><span>Tambah Anggota</span></a>
    <a href="<?php echo baseUrl('admin/kegiatan/kegiatan.php'); ?>" class="action-card" style="background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.3);"><i class="fas fa-tasks"></i><span>Master Kegiatan</span></a>
    <a href="<?php echo baseUrl('admin/system/kelola-admin.php'); ?>" class="action-card" style="background: rgba(255,255,255,0.05);"><i class="fas fa-user-shield"></i><span>Kelola Admin</span></a>
</div>
