<?php
// admin/core/views/dashboard-sekretaris.php
// Sekretariat, Berita Acara & Persuratan with Stacked Bar Chart

$periode_id = getUserPeriode();

$totalSuratL       = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'L'", [$periode_id], "i")['total'] ?? 0;
$totalSuratD       = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'D'", [$periode_id], "i")['total'] ?? 0;
$totalSuratM       = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'M'", [$periode_id], "i")['total'] ?? 0;
$totalBeritaAcara  = dbFetchOne("SELECT COUNT(*) as total FROM arsip_berita_acara WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
$totalLPJ          = dbFetchOne("SELECT COUNT(*) as total FROM lpj_dokumen WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;

$suratTerbaru      = dbFetchAll("SELECT nomor_surat, perihal, jenis_surat, created_at FROM arsip_surat WHERE periode_id = ? ORDER BY id DESC LIMIT 5", [$periode_id], "i");
$beritaAcaraTerbaru= dbFetchAll("SELECT nomor_berita, nama_kegiatan, tanggal_kegiatan FROM arsip_berita_acara WHERE periode_id = ? ORDER BY id DESC LIMIT 5", [$periode_id], "i");

// Data Simulasi Bar Chart Volume Surat per Bulan
$bulan_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun'];
$surat_monthly = [
    ['L' => 12, 'D' => 5, 'M' => 8],
    ['L' => 18, 'D' => 8, 'M' => 12],
    ['L' => 25, 'D' => 10, 'M' => 15],
    ['L' => 30, 'D' => 14, 'M' => 18],
    ['L' => 20, 'D' => 9, 'M' => 10],
    ['L' => 15, 'D' => 7, 'M' => 9]
];
?>

<div class="stats-group">
    <h2 style="margin-bottom: 15px; font-size: 1.1rem; color: #4A90E2; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-envelope-open-text"></i> Statistik Persuratan & Berita Acara (Sekretariat)
    </h2>
    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <div class="stat-card" style="border-left: 4px solid #4A90E2;">
            <div class="stat-icon" style="background: rgba(74, 144, 226, 0.1); color: #4A90E2;"><i class="fas fa-paper-plane"></i></div>
            <div class="stat-value"><?php echo $totalSuratL; ?></div>
            <div class="stat-label">Surat Keluar (L)</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #673AB7;">
            <div class="stat-icon" style="background: rgba(103, 58, 183, 0.1); color: #673AB7;"><i class="fas fa-file-export"></i></div>
            <div class="stat-value"><?php echo $totalSuratD; ?></div>
            <div class="stat-label">Surat Dalam (D)</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #f39c12;">
            <div class="stat-icon" style="background: rgba(243, 156, 18, 0.1); color: #f39c12;"><i class="fas fa-file-import"></i></div>
            <div class="stat-value"><?php echo $totalSuratM; ?></div>
            <div class="stat-label">Surat Masuk (M)</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #2ecc71;">
            <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;"><i class="fas fa-file-signature"></i></div>
            <div class="stat-value"><?php echo $totalBeritaAcara; ?></div>
            <div class="stat-label">Berita Acara Rapat</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #9b59b6;">
            <div class="stat-icon" style="background: rgba(155, 89, 182, 0.1); color: #9b59b6;"><i class="fas fa-book"></i></div>
            <div class="stat-value"><?php echo $totalLPJ; ?></div>
            <div class="stat-label">Dokumen LPJ</div>
        </div>
    </div>
</div>

<!-- DIAGRAM BAR CHART VOLUME SURAT PER BULAN -->
<div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px; margin-top: 25px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="font-size: 0.95rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-chart-bar" style="color: #4A90E2;"></i> Volume Penerbitan Surat per Bulan (L, D, M)
        </h3>
        <div style="display: flex; gap: 12px; font-size: 0.75rem;">
            <span style="color: #4A90E2;"><i class="fas fa-square"></i> Keluar (L)</span>
            <span style="color: #673AB7;"><i class="fas fa-square"></i> Dalam (D)</span>
            <span style="color: #f39c12;"><i class="fas fa-square"></i> Masuk (M)</span>
        </div>
    </div>
    <div style="display: flex; align-items: flex-end; justify-content: space-around; height: 160px; padding-top: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);">
        <?php foreach ($surat_monthly as $i => $data): ?>
        <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
            <div style="display: flex; align-items: flex-end; gap: 3px; height: 120px;">
                <div style="width: 12px; height: <?php echo min(100, $data['L'] * 3); ?>%; background: #4A90E2; border-radius: 3px 3px 0 0;"></div>
                <div style="width: 12px; height: <?php echo min(100, $data['D'] * 3); ?>%; background: #673AB7; border-radius: 3px 3px 0 0;"></div>
                <div style="width: 12px; height: <?php echo min(100, $data['M'] * 3); ?>%; background: #f39c12; border-radius: 3px 3px 0 0;"></div>
            </div>
            <small style="font-size: 0.72rem; color: #aaa; margin-top: 4px;"><?php echo $bulan_labels[$i]; ?></small>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 30px;">
    
    <!-- Arsip Surat Terbaru -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <span style="font-weight: 600; color: #fff; font-size: 0.95rem;"><i class="fas fa-folder-open" style="color: #4A90E2;"></i> Arsip Surat Terbaru</span>
            <a href="<?php echo baseUrl('admin/surat/arsip-surat.php'); ?>" style="font-size: 0.8rem; color: #4A90E2; text-decoration: none;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php if (empty($suratTerbaru)): ?>
                <p style="color: #666; font-size: 0.85rem; text-align: center; padding: 20px;">Belum ada arsip surat.</p>
            <?php else: ?>
                <?php foreach ($suratTerbaru as $surat): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid <?php echo $surat['jenis_surat']==='L' ? '#4A90E2' : ($surat['jenis_surat']==='D' ? '#673AB7' : '#f39c12'); ?>;">
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;">
                        <div style="font-weight: 700; font-size: 0.85rem; color: #fff;"><?php echo htmlspecialchars($surat['nomor_surat'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div style="font-size: 0.75rem; color: #aaa; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($surat['perihal'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge" style="background: <?php echo $surat['jenis_surat']==='L' ? '#4A90E2' : ($surat['jenis_surat']==='D' ? '#673AB7' : '#f39c12'); ?>; font-size: 0.65rem; padding: 3px 6px; border-radius: 4px; color: #fff;">
                        <?php echo $surat['jenis_surat']; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Widget Berita Acara Rapat Terbaru -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <span style="font-weight: 600; color: #fff; font-size: 0.95rem;"><i class="fas fa-file-signature" style="color: #2ecc71;"></i> Berita Acara Rapat Terbaru</span>
            <a href="<?php echo baseUrl('admin/surat/berita-acara.php'); ?>" style="font-size: 0.8rem; color: #2ecc71; text-decoration: none;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php if (empty($beritaAcaraTerbaru)): ?>
                <p style="color: #666; font-size: 0.85rem; text-align: center; padding: 20px;">Belum ada berita acara terdaftar.</p>
            <?php else: ?>
                <?php foreach ($beritaAcaraTerbaru as $ba): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid #2ecc71;">
                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;">
                        <div style="font-weight: 700; font-size: 0.85rem; color: #fff;"><?php echo htmlspecialchars($ba['nama_kegiatan'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div style="font-size: 0.75rem; color: #aaa;"><?php echo htmlspecialchars($ba['nomor_berita'], ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($ba['tanggal_kegiatan'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <span class="badge" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; font-size: 0.65rem; padding: 3px 6px; border-radius: 4px;">
                        Disetujui
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sekretariat Quick Actions -->
<h2 style="margin-bottom: 16px; margin-top: 30px;"><i class="fas fa-bolt"></i> Aksi Cepat Sekretariat</h2>
<div class="quick-actions" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px;">
    <a href="<?php echo baseUrl('admin/surat/buat-surat.php'); ?>" class="action-card" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3);"><i class="fas fa-file-signature"></i><span>Buat Surat Otomatis</span></a>
    <a href="<?php echo baseUrl('admin/surat/berita-acara.php'); ?>" class="action-card" style="background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.3);"><i class="fas fa-file-alt"></i><span>Buat Berita Acara</span></a>
    <a href="<?php echo baseUrl('admin/surat/arsip-surat.php'); ?>" class="action-card" style="background: rgba(103, 58, 183, 0.1); border-color: rgba(103, 58, 183, 0.3);"><i class="fas fa-search"></i><span>Cari Arsip Surat</span></a>
    <a href="<?php echo baseUrl('admin/lpj/lpj.php'); ?>" class="action-card" style="background: rgba(155, 89, 182, 0.1); border-color: rgba(155, 89, 182, 0.3);"><i class="fas fa-book"></i><span>Dokumen LPJ</span></a>
</div>
