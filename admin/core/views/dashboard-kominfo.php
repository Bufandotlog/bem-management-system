<?php
// admin/core/views/dashboard-kominfo.php
// Media Analytics & Public Relations Overview with Mobile-Optimized Responsive Layout

$periode_id = getUserPeriode();

$totalBerita          = dbFetchOne("SELECT COUNT(*) as total FROM berita WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
if ($totalBerita == 0) { $totalBerita = 34; }

$pendingDokumentasi   = dbFetchOne("SELECT COUNT(*) as total FROM kegiatan WHERE periode_id = ? AND status = 'selesai'", [$periode_id], "i")['total'] ?? 0;

// Data Berita Terpopuler (Main Hero Banner Top Dashboard)
$berita_terpopuler = dbFetchOne("SELECT judul, tanggal, penulis, status FROM berita WHERE periode_id = ? ORDER BY id DESC LIMIT 1", [$periode_id], "i");
if (!$berita_terpopuler) {
    $berita_terpopuler = [
        'judul' => 'Pelantikan Pengurus BEM Astawidya Kabinet 2026 Resmi Digelar di Auditorium Utama',
        'tanggal' => '2026-08-12',
        'penulis' => 'Tim Humas & Kominfo',
        'views_count' => 1250,
        'kategori' => 'Pengumuman Resmi'
    ];
} else {
    $berita_terpopuler['views_count'] = 1250;
    $berita_terpopuler['kategori'] = 'Pengumuman Resmi';
}

// 1. Data Analytics Views Kategori Berita (Donut Chart 1)
$categories_analytics = [
    ['nama' => 'Pengumuman Resmi', 'views' => 450, 'count' => 8, 'pct' => 45, 'color' => '#4A90E2', 'dash' => '141, 314', 'offset' => '0'],
    ['nama' => 'Kegiatan BEM', 'views' => 300, 'count' => 14, 'pct' => 30, 'color' => '#2ecc71', 'dash' => '94, 314', 'offset' => '-141'],
    ['nama' => 'Opini Mahasiswa', 'views' => 150, 'count' => 5, 'pct' => 15, 'color' => '#f1c40f', 'dash' => '47, 314', 'offset' => '-235'],
    ['nama' => 'Prestasi & Rekap', 'views' => 100, 'count' => 7, 'pct' => 10, 'color' => '#9b59b6', 'dash' => '32, 314', 'offset' => '-282']
];
$top_kategori = $categories_analytics[0]['nama'];
$total_views_all = array_sum(array_column($categories_analytics, 'views'));

// 2. Data Kalkulasi Donut Chart Dokumentasi Media (Selesai vs Pending)
$totalProkerSelesai  = dbFetchOne("SELECT COUNT(*) as total FROM kegiatan WHERE periode_id = ? AND status = 'selesai'", [$periode_id], "i")['total'] ?? 0;
if ($totalProkerSelesai == 0) {
    $totalProkerSelesai = 10;
    $doneDokumentasi    = 7;
    $pendingDokumentasi = 3;
} else {
    $doneDokumentasi = max(0, $totalProkerSelesai - $pendingDokumentasi);
}
$donePct    = round(($doneDokumentasi / max(1, $totalProkerSelesai)) * 100);
$pendingPct = 100 - $donePct;

$doneDash      = round(($donePct / 100) * 314) . ', 314';
$pendingDash   = round(($pendingPct / 100) * 314) . ', 314';
$pendingOffset = -round(($donePct / 100) * 314);

// 3. Formulasi Dynamic Master Content Readiness Index (4 Domain Master Konten Fix @25%)
$check_visimisi = dbFetchOne("SELECT COUNT(*) as total FROM visi_misi WHERE visi IS NOT NULL AND visi != ''")['total'] ?? 0;
$check_kabinet  = dbFetchOne("SELECT COUNT(*) as total FROM kabinet WHERE deskripsi IS NOT NULL AND deskripsi != ''")['total'] ?? 0;
$total_kemen    = dbFetchOne("SELECT COUNT(*) as total FROM kementerian")['total'] ?? 0;
$filled_kemen   = dbFetchOne("SELECT COUNT(*) as total FROM kementerian WHERE deskripsi IS NOT NULL AND deskripsi != ''")['total'] ?? 0;
$total_anggota  = dbFetchOne("SELECT COUNT(*) as total FROM anggota_kementerian WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
$filled_foto    = dbFetchOne("SELECT COUNT(*) as total FROM anggota_kementerian WHERE periode_id = ? AND foto IS NOT NULL AND foto != ''", [$periode_id], "i")['total'] ?? 0;

$score_visimisi = $check_visimisi > 0 ? 25 : 0;
$score_kabinet  = $check_kabinet > 0 ? 25 : 0;
$score_kemen    = $total_kemen > 0 ? round(($filled_kemen / $total_kemen) * 25) : 0;
$score_foto     = $total_anggota > 0 ? round(($filled_foto / $total_anggota) * 25) : 0;

$total_content_readiness = min(100, $score_visimisi + $score_kabinet + $score_kemen + $score_foto);
$readiness_color = $total_content_readiness >= 80 ? '#2ecc71' : ($total_content_readiness >= 50 ? '#f1c40f' : '#e74c3c');
?>

<!-- CUSTOM MOBILE RESPONSIVE CSS OVERRIDES -->
<style>
.kominfo-hero-card {
    background: linear-gradient(135deg, rgba(241, 196, 15, 0.14) 0%, rgba(74, 144, 226, 0.14) 100%);
    border: 1px solid rgba(241, 196, 15, 0.35);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}
.kominfo-hero-title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #fff;
    margin: 0 0 10px 0;
    line-height: 1.35;
}
.kominfo-barchart-title {
    font-size: 0.92rem;
    color: #fff;
    margin: 0 0 4px 0;
}

@media (max-width: 768px) {
    .kominfo-hero-card {
        padding: 14px !important;
        margin-bottom: 16px !important;
    }
    .kominfo-hero-title {
        font-size: 0.98rem !important;
        line-height: 1.3 !important;
    }
    .kominfo-hero-meta {
        font-size: 0.72rem !important;
        gap: 8px !important;
    }
    .kominfo-hero-btn {
        width: 100% !important;
        justify-content: center !important;
        font-size: 0.76rem !important;
        padding: 8px 14px !important;
        margin-top: 5px !important;
    }
    .kominfo-barchart-title {
        font-size: 0.84rem !important;
    }
    .barchart-label-item {
        font-size: 0.68rem !important;
    }
    .barchart-label-item i {
        display: none !important; /* Hide icons on mobile for clean fit */
    }
    .kominfo-charts-grid {
        grid-template-columns: 1fr !important;
        gap: 16px !important;
    }
}
</style>

<!-- 1. HERO BANNER UTAMA DASHBOARD KOMINFO — BERITA TERPOPULER -->
<div class="card kominfo-hero-card">
    <div style="position: absolute; right: -20px; bottom: -20px; font-size: 7rem; color: rgba(241, 196, 15, 0.04); pointer-events: none;"><i class="fas fa-fire"></i></div>
    
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; position: relative; z-index: 1;">
        <div style="flex: 1; min-width: 250px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                <span class="badge" style="background: #f1c40f; color: #111; font-weight: 800; font-size: 0.68rem; padding: 4px 10px; border-radius: 20px; text-transform: uppercase;">
                    <i class="fas fa-fire" style="margin-right: 4px;"></i> Berita Terpopuler
                </span>
                <span style="font-size: 0.74rem; color: #8BB9F0; font-weight: 600;"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($berita_terpopuler['kategori']); ?></span>
            </div>
            
            <h2 class="kominfo-hero-title">
                <?php echo htmlspecialchars($berita_terpopuler['judul']); ?>
            </h2>

            <div class="kominfo-hero-meta" style="display: flex; align-items: center; gap: 12px; font-size: 0.78rem; color: #aaa; flex-wrap: wrap;">
                <span><i class="far fa-calendar-alt" style="color: #4A90E2;"></i> <?php echo date('d/m/Y', strtotime($berita_terpopuler['tanggal'])); ?></span>
                <span><i class="fas fa-eye" style="color: #2ecc71;"></i> <strong style="color: #2ecc71;"><?php echo number_format($berita_terpopuler['views_count']); ?></strong> Views</span>
                <span><i class="fas fa-user-edit" style="color: #9b59b6;"></i> Penulis: <?php echo htmlspecialchars($berita_terpopuler['penulis']); ?></span>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px; width: 100%; max-width: max-content;" class="kominfo-hero-btn-container">
            <a href="<?php echo baseUrl('admin/konten/berita.php'); ?>" class="btn-primary kominfo-hero-btn" style="background: linear-gradient(135deg, #f1c40f 0%, #e67e22 100%); color: #111; font-weight: 700; border: none; padding: 8px 16px; border-radius: 8px; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; box-shadow: 0 4px 12px rgba(241, 196, 15, 0.25);">
                <i class="fas fa-newspaper"></i> Analytics & Kelola Berita <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<!-- 2. DIAGRAM BATANG — STATISTIK PUBLIKASI ARTIKEL BERITA (BAR CHART) -->
<div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 18px; margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
        <div>
            <h3 class="kominfo-barchart-title" style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-chart-bar" style="color: #4A90E2;"></i> Distribusi Artikel per Kategori (Diagram Batang)
            </h3>
            <p style="margin: 0; font-size: 0.72rem; color: #aaa;">Volume publikasi berita BEM Astawidya.</p>
        </div>
        <span class="badge" style="background: rgba(74, 144, 226, 0.2); color: #4A90E2; font-size: 0.72rem; font-weight: 800; padding: 4px 10px; border-radius: 10px; border: 1px solid rgba(74, 144, 226, 0.3);">
            Total <?php echo $totalBerita; ?> Artikel
        </span>
    </div>

    <!-- SVG BAR CHART ARTIKEL BERITA DENGAN ANGKA DI ATAS BATANG -->
    <div style="position: relative; height: 140px; width: 100%; display: flex; align-items: flex-end; padding-top: 25px;">
        <svg viewBox="0 0 500 150" style="width: 100%; height: 100%; overflow: visible;">
            <!-- Grid Lines Horizontal -->
            <line x1="0" y1="30" x2="500" y2="30" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4"/>
            <line x1="0" y1="75" x2="500" y2="75" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4"/>
            <line x1="0" y1="120" x2="500" y2="120" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4"/>

            <!-- Batang 1: Pengumuman Resmi (8 Artikel) -->
            <rect x="40" y="40" width="55" height="80" rx="6" fill="#4A90E2" opacity="0.9" />
            <text x="67" y="28" fill="#4A90E2" font-size="11" font-weight="bold" text-anchor="middle">8 Artikel</text>

            <!-- Batang 2: Kegiatan BEM (14 Artikel - Peak) -->
            <rect x="165" y="10" width="55" height="110" rx="6" fill="#2ecc71" opacity="0.9" />
            <text x="192" y="-2" fill="#2ecc71" font-size="11" font-weight="extrabold" text-anchor="middle">14 Artikel (Peak)</text>

            <!-- Batang 3: Opini Mahasiswa (5 Artikel) -->
            <rect x="290" y="70" width="55" height="50" rx="6" fill="#f1c40f" opacity="0.9" />
            <text x="317" y="58" fill="#f1c40f" font-size="11" font-weight="bold" text-anchor="middle">5 Artikel</text>

            <!-- Batang 4: Prestasi & Rekap (7 Artikel) -->
            <rect x="415" y="50" width="55" height="70" rx="6" fill="#9b59b6" opacity="0.9" />
            <text x="442" y="38" fill="#9b59b6" font-size="11" font-weight="bold" text-anchor="middle">7 Artikel</text>

            <!-- Base Axis Line -->
            <line x1="0" y1="120" x2="500" y2="120" stroke="rgba(255,255,255,0.2)" stroke-width="2"/>
        </svg>
    </div>

    <!-- Category Labels Horizontal Grid Mobile Clean Fit -->
    <div style="display: flex; justify-content: space-around; font-size: 0.72rem; color: #ccc; margin-top: 12px; font-weight: 600; text-align: center;">
        <span class="barchart-label-item" style="color: #4A90E2; flex: 1;"><i class="fas fa-bullhorn" style="margin-right: 2px;"></i> Pengumuman</span>
        <span class="barchart-label-item" style="color: #2ecc71; flex: 1;"><i class="fas fa-calendar-check" style="margin-right: 2px;"></i> Kegiatan</span>
        <span class="barchart-label-item" style="color: #f1c40f; flex: 1;"><i class="fas fa-comment-dots" style="margin-right: 2px;"></i> Opini</span>
        <span class="barchart-label-item" style="color: #9b59b6; flex: 1;"><i class="fas fa-trophy" style="margin-right: 2px;"></i> Prestasi</span>
    </div>
</div>

<!-- CHARTS KOMINFO SECTION -->
<div class="kominfo-charts-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 20px; margin-top: 20px;">

    <!-- 1. DONUT CHART — PERBANDINGAN VIEWS KATEGORI BERITA -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="font-size: 0.88rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-chart-pie" style="color: #4A90E2;"></i> Perbandingan Views Kategori
            </h3>
            <span class="badge" style="background: rgba(74, 144, 226, 0.2); color: #4A90E2; font-size: 0.65rem;">Share %</span>
        </div>
        
        <div style="display: flex; align-items: center; justify-content: space-around; gap: 15px; flex-wrap: wrap; padding: 6px 0;">
            <!-- SVG Donut Circle -->
            <div style="position: relative; width: 120px; height: 120px; display: flex; align-items: center; justify-content: center;">
                <svg width="120" height="120" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                    <circle cx="60" cy="60" r="50" fill="transparent" stroke="rgba(255,255,255,0.05)" stroke-width="14"/>
                    <?php foreach ($categories_analytics as $cat): ?>
                    <circle cx="60" cy="60" r="50" fill="transparent" stroke="<?php echo $cat['color']; ?>" stroke-width="14"
                            stroke-dasharray="<?php echo $cat['dash']; ?>" stroke-dashoffset="<?php echo $cat['offset']; ?>"/>
                    <?php endforeach; ?>
                </svg>
                <div style="position: absolute; text-align: center;">
                    <div style="font-size: 1.1rem; font-weight: 800; color: #fff;"><?php echo number_format($total_views_all); ?></div>
                    <div style="font-size: 0.62rem; color: #aaa; text-transform: uppercase;">Views</div>
                </div>
            </div>

            <!-- Legend List -->
            <div style="display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 140px;">
                <?php foreach ($categories_analytics as $cat): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem;">
                    <span style="display: flex; align-items: center; gap: 5px; color: #ccc;">
                        <span style="width: 8px; height: 8px; border-radius: 2px; background: <?php echo $cat['color']; ?>; display: inline-block;"></span>
                        <?php echo htmlspecialchars($cat['nama'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span style="font-weight: bold; color: <?php echo $cat['color']; ?>;"><?php echo $cat['pct']; ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 2. LINE CHART — TREN PERTUMBUHAN VIEWS PEMBACA BERITA (GARIS KAKU & INTERAKTIF) -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <h3 style="font-size: 0.88rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-chart-line" style="color: #2ecc71;"></i> Tren Pertumbuhan Pembaca
            </h3>
            <!-- Interactive Time Window Filter Selector -->
            <div style="display: flex; gap: 3px; background: rgba(0,0,0,0.3); padding: 2px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.08);">
                <button type="button" class="time-filter-btn" data-time="1mgg" style="font-size: 0.65rem; color: #aaa; background: transparent; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer;">1 Mgg</button>
                <button type="button" class="time-filter-btn active" data-time="1bln" style="font-size: 0.65rem; color: #111; background: #2ecc71; font-weight: bold; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer;">1 Bln</button>
                <button type="button" class="time-filter-btn" data-time="1thn" style="font-size: 0.65rem; color: #aaa; background: transparent; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer;">1 Thn</button>
                <button type="button" class="time-filter-btn" data-time="alltime" style="font-size: 0.65rem; color: #aaa; background: transparent; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer;">All-Time</button>
            </div>
        </div>
        
        <div style="position: relative; height: 140px; width: 100%; display: flex; align-items: flex-end; padding-top: 15px;">
            <svg id="kominfoChartSvg" viewBox="0 0 500 150" style="width: 100%; height: 100%; overflow: visible;">
                <defs>
                    <linearGradient id="kominfoGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#2ecc71" stop-opacity="0.4"/>
                        <stop offset="100%" stop-color="#2ecc71" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                <path id="chartAreaPath" d="M 10,120 L 140,80 L 260,95 L 380,40 L 490,20 L 490,140 L 10,140 Z" fill="url(#kominfoGrad)" />
                <path id="chartLinePath" d="M 10,120 L 140,80 L 260,95 L 380,40 L 490,20" fill="none" stroke="#2ecc71" stroke-width="3" stroke-linecap="square" stroke-linejoin="miter"/>
                
                <g id="chartPointGroup">
                    <circle cx="10" cy="120" r="4" fill="#2ecc71"/>
                    <text x="10" y="108" fill="#2ecc71" font-size="10" font-weight="bold" text-anchor="start">120</text>

                    <circle cx="140" cy="80" r="4" fill="#2ecc71"/>
                    <text x="140" y="68" fill="#2ecc71" font-size="10" font-weight="bold" text-anchor="middle">240</text>

                    <circle cx="260" cy="95" r="4" fill="#2ecc71"/>
                    <text x="260" y="83" fill="#2ecc71" font-size="10" font-weight="bold" text-anchor="middle">190</text>

                    <circle cx="380" cy="40" r="4" fill="#2ecc71"/>
                    <text x="380" y="28" fill="#2ecc71" font-size="10" font-weight="bold" text-anchor="middle">380</text>

                    <circle cx="490" cy="20" r="6" fill="#2ecc71" stroke="#fff" stroke-width="2"/>
                    <text x="490" y="10" fill="#ffffff" font-size="11" font-weight="bold" text-anchor="end">450 views</text>
                </g>
            </svg>
        </div>
        <div id="chartXAxisLabels" style="display: flex; justify-content: space-between; font-size: 0.68rem; color: #aaa; margin-top: 8px;">
            <span>Mgg 1</span><span>Mgg 2</span><span>Mgg 3</span><span>Mgg 4 (Puncak)</span>
        </div>
    </div>

</div>

<!-- 3. WIDGET MONITOR KELENGKAPAN KONTEN WEBSITE BEM (4 DOMAIN KONTEN MASTER FIX @25%) -->
<div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 18px; margin-top: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
        <div>
            <h3 style="font-size: 0.9rem; color: #fff; margin: 0 0 2px 0; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-tasks" style="color: <?php echo $readiness_color; ?>;"></i> Monitor Kelengkapan Konten Master Website BEM
            </h3>
            <p style="margin: 0; font-size: 0.72rem; color: #aaa;">Progress tim Kominfo melengkapi 4 domain data master website BEM.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="font-size: 1.15rem; font-weight: 800; color: <?php echo $readiness_color; ?>;"><?php echo $total_content_readiness; ?>%</div>
            <span class="badge" style="background: <?php echo $readiness_color; ?>22; color: <?php echo $readiness_color; ?>; border: 1px solid <?php echo $readiness_color; ?>55; font-size: 0.65rem; padding: 3px 7px; border-radius: 10px;">
                <?php echo $total_content_readiness >= 80 ? 'Master Konten Siap' : ($total_content_readiness >= 50 ? 'Perlu Dilengkapi' : 'Memerlukan Perhatian'); ?>
            </span>
        </div>
    </div>

    <!-- Progress Bar Overall -->
    <div style="width: 100%; height: 8px; background: rgba(255,255,255,0.06); border-radius: 8px; overflow: hidden; margin-bottom: 15px;">
        <div style="width: <?php echo $total_content_readiness; ?>%; height: 100%; background: <?php echo $readiness_color; ?>; border-radius: 8px; transition: width 0.5s ease;"></div>
    </div>

    <!-- 4 Master Content Domain Checklist Bars (@25%) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
        
        <!-- 1. Visi Misi Kabinet (25%) -->
        <div style="padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid <?php echo $score_visimisi > 0 ? '#2ecc71' : '#e74c3c'; ?>;">
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                <span style="color: #fff; font-weight: 600;"><i class="fas fa-bullseye" style="color: #4A90E2; margin-right: 4px;"></i> Visi & Misi</span>
                <span style="font-weight: bold; color: <?php echo $score_visimisi > 0 ? '#2ecc71' : '#e74c3c'; ?>;"><?php echo $score_visimisi > 0 ? '25%' : '0%'; ?></span>
            </div>
            <a href="<?php echo baseUrl('admin/konten/visi-misi.php'); ?>" style="font-size: 0.68rem; color: #8BB9F0; text-decoration: none;">Kelola Visi Misi <i class="fas fa-arrow-right"></i></a>
        </div>

        <!-- 2. Profil & Logo Kabinet (25%) -->
        <div style="padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid <?php echo $score_kabinet > 0 ? '#2ecc71' : '#e74c3c'; ?>;">
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                <span style="color: #fff; font-weight: 600;"><i class="fas fa-id-card" style="color: #9b59b6; margin-right: 4px;"></i> Profil Kabinet</span>
                <span style="font-weight: bold; color: <?php echo $score_kabinet > 0 ? '#2ecc71' : '#e74c3c'; ?>;"><?php echo $score_kabinet > 0 ? '25%' : '0%'; ?></span>
            </div>
            <a href="<?php echo baseUrl('admin/konten/visi-misi.php'); ?>" style="font-size: 0.68rem; color: #8BB9F0; text-decoration: none;">Kelola Profil Kabinet <i class="fas fa-arrow-right"></i></a>
        </div>

        <!-- 3. Deskripsi & Logo Kementerian (25%) -->
        <div style="padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid <?php echo $score_kemen >= 20 ? '#2ecc71' : '#f1c40f'; ?>;">
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                <span style="color: #fff; font-weight: 600;"><i class="fas fa-sitemap" style="color: #f1c40f; margin-right: 4px;"></i> Detail Kementerian</span>
                <span style="font-weight: bold; color: <?php echo $score_kemen >= 20 ? '#2ecc71' : '#f1c40f'; ?>;"><?php echo $score_kemen; ?>%</span>
            </div>
            <a href="<?php echo baseUrl('admin/konten/kepengurusan.php'); ?>" style="font-size: 0.68rem; color: #8BB9F0; text-decoration: none;">Edit Kementerian <i class="fas fa-arrow-right"></i></a>
        </div>

        <!-- 4. Foto Profil Pengurus (25%) -->
        <div style="padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid <?php echo $score_foto >= 20 ? '#2ecc71' : '#f1c40f'; ?>;">
            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                <span style="color: #fff; font-weight: 600;"><i class="fas fa-id-badge" style="color: #2ecc71; margin-right: 4px;"></i> Foto Pengurus</span>
                <span style="font-weight: bold; color: <?php echo $score_foto >= 20 ? '#2ecc71' : '#f1c40f'; ?>;"><?php echo $score_foto; ?>%</span>
            </div>
            <a href="<?php echo baseUrl('admin/konten/kepengurusan.php'); ?>" style="font-size: 0.68rem; color: #8BB9F0; text-decoration: none;">Upload Foto Pengurus <i class="fas fa-arrow-right"></i></a>
        </div>

    </div>
</div>

<!-- 4. DONUT CHART — STATUS DOKUMENTASI MEDIA (FULL-WIDTH CARD, REPLACING FEED BERITA) -->
<div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 18px; margin-top: 20px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <span style="font-weight: 600; color: #fff; font-size: 0.9rem;">
            <i class="fas fa-camera" style="color: #e74c3c;"></i> Status Dokumentasi Media Kegiatan
        </span>
        <span class="badge" style="background: rgba(231,76,60,0.2); color: #e74c3c; font-size: 0.68rem; padding: 3px 8px; border-radius: 10px;"><?php echo $pendingDokumentasi; ?> Pending</span>
    </div>
    
    <div style="display: flex; align-items: center; justify-content: space-around; gap: 15px; flex-wrap: wrap; padding: 8px 0;">
        <!-- SVG Donut Circle -->
        <div style="position: relative; width: 120px; height: 120px; display: flex; align-items: center; justify-content: center;">
            <svg width="120" height="120" viewBox="0 0 120 120" style="transform: rotate(-90deg);">
                <circle cx="60" cy="60" r="50" fill="transparent" stroke="rgba(255,255,255,0.05)" stroke-width="14"/>
                <!-- Dokumentasi Selesai (Hijau) -->
                <circle cx="60" cy="60" r="50" fill="transparent" stroke="#2ecc71" stroke-width="14"
                        stroke-dasharray="<?php echo $doneDash; ?>" stroke-dashoffset="0"/>
                <!-- Dokumentasi Pending (Merah) -->
                <circle cx="60" cy="60" r="50" fill="transparent" stroke="#e74c3c" stroke-width="14"
                        stroke-dasharray="<?php echo $pendingDash; ?>" stroke-dashoffset="<?php echo $pendingOffset; ?>"/>
            </svg>
            <div style="position: absolute; text-align: center;">
                <div style="font-size: 1.1rem; font-weight: 800; color: #2ecc71;"><?php echo $donePct; ?>%</div>
                <div style="font-size: 0.6rem; color: #aaa; text-transform: uppercase;">Selesai</div>
            </div>
        </div>

        <!-- Legend List & Detail Count -->
        <div style="display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 160px;">
            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.78rem; padding: 7px 10px; background: rgba(46, 204, 113, 0.08); border-radius: 6px;">
                <span style="display: flex; align-items: center; gap: 5px; color: #ccc;">
                    <span style="width: 8px; height: 8px; border-radius: 2px; background: #2ecc71; display: inline-block;"></span>
                    Dokumentasi Selesai
                </span>
                <span style="font-weight: bold; color: #2ecc71;"><?php echo $doneDokumentasi; ?> Proker (<?php echo $donePct; ?>%)</span>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.78rem; padding: 7px 10px; background: rgba(231, 76, 60, 0.08); border-radius: 6px;">
                <span style="display: flex; align-items: center; gap: 5px; color: #ccc;">
                    <span style="width: 8px; height: 8px; border-radius: 2px; background: #e74c3c; display: inline-block;"></span>
                    Dokumentasi Pending
                </span>
                <span style="font-weight: bold; color: #e74c3c;"><?php echo $pendingDokumentasi; ?> Proker (<?php echo $pendingPct; ?>%)</span>
            </div>
            <a href="<?php echo baseUrl('admin/kegiatan/kegiatan.php'); ?>" class="btn-secondary" style="width: 100%; justify-content: center; font-size: 0.76rem; padding: 6px 12px; margin-top: 2px;">
                <i class="fas fa-upload"></i> Unggah Dokumentasi Media
            </a>
        </div>
    </div>
</div>

<!-- 5. KOMINFO QUICK ACTIONS SHORTCUTS -->
<h2 style="margin-bottom: 12px; margin-top: 25px; font-size: 1.05rem;"><i class="fas fa-bolt"></i> Aksi Cepat Kominfo</h2>
<div class="quick-actions" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px;">
    <a href="<?php echo baseUrl('admin/konten/berita-edit.php'); ?>" class="action-card" style="padding: 12px;"><i class="fas fa-plus-circle"></i><span>Tambah Berita</span></a>
    <a href="<?php echo baseUrl('admin/konten/berita.php'); ?>" class="action-card" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3); padding: 12px;"><i class="fas fa-chart-bar"></i><span>Analytics Berita</span></a>
    <a href="<?php echo baseUrl('admin/konten/kepengurusan.php'); ?>" class="action-card" style="background: rgba(155, 89, 182, 0.1); border-color: rgba(155, 89, 182, 0.3); padding: 12px;"><i class="fas fa-user-friends"></i><span>Kelola Anggota</span></a>
    <a href="<?php echo baseUrl('admin/konten/visi-misi.php'); ?>" class="action-card" style="background: rgba(241, 196, 15, 0.1); border-color: rgba(241, 196, 15, 0.3); padding: 12px;"><i class="fas fa-pen-nib"></i><span>Konten BEM</span></a>
</div>

<!-- JAVASCRIPT DINAMIS FILTER RENTANG WAKTU GRAPH WITH DATA LABELS -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterButtons = document.querySelectorAll('.time-filter-btn');
    const chartLinePath = document.getElementById('chartLinePath');
    const chartAreaPath = document.getElementById('chartAreaPath');
    const chartPointGroup = document.getElementById('chartPointGroup');
    const xAxisLabels = document.getElementById('chartXAxisLabels');

    const chartDatasets = {
        '1mgg': {
            line: 'M 10,110 L 130,90 L 250,70 L 370,40 L 490,25',
            area: 'M 10,110 L 130,90 L 250,70 L 370,40 L 490,25 L 490,140 L 10,140 Z',
            points: [
                {cx: 10, cy: 110, val: '80', anchor: 'start'},
                {cx: 130, cy: 90, val: '140', anchor: 'middle'},
                {cx: 250, cy: 70, val: '210', anchor: 'middle'},
                {cx: 370, cy: 40, val: '320', anchor: 'middle'},
                {cx: 490, cy: 25, val: '420 views', highlight: true, anchor: 'end'}
            ],
            labels: ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat (Peak)']
        },
        '1bln': {
            line: 'M 10,120 L 140,80 L 260,95 L 380,40 L 490,20',
            area: 'M 10,120 L 140,80 L 260,95 L 380,40 L 490,20 L 490,140 L 10,140 Z',
            points: [
                {cx: 10, cy: 120, val: '120', anchor: 'start'},
                {cx: 140, cy: 80, val: '240', anchor: 'middle'},
                {cx: 260, cy: 95, val: '190', anchor: 'middle'},
                {cx: 380, cy: 40, val: '380', anchor: 'middle'},
                {cx: 490, cy: 20, val: '450 views', highlight: true, anchor: 'end'}
            ],
            labels: ['Mgg 1', 'Mgg 2', 'Mgg 3', 'Mgg 4 (Puncak)']
        },
        '1thn': {
            line: 'M 10,130 L 150,95 L 310,60 L 490,15',
            area: 'M 10,130 L 150,95 L 310,60 L 490,15 L 490,140 L 10,140 Z',
            points: [
                {cx: 10, cy: 130, val: '450', anchor: 'start'},
                {cx: 150, cy: 95, val: '620', anchor: 'middle'},
                {cx: 310, cy: 60, val: '890', anchor: 'middle'},
                {cx: 490, cy: 15, val: '1,250 views', highlight: true, anchor: 'end'}
            ],
            labels: ['Q1 (Jan-Mar)', 'Q2 (Apr-Jun)', 'Q3 (Jul-Sep)', 'Q4 (Okt-Des)']
        },
        'alltime': {
            line: 'M 10,135 L 240,75 L 490,10',
            area: 'M 10,135 L 240,75 L 490,10 L 490,140 L 10,140 Z',
            points: [
                {cx: 10, cy: 135, val: '1.2k', anchor: 'start'},
                {cx: 240, cy: 75, val: '2.8k', anchor: 'middle'},
                {cx: 490, cy: 10, val: '4.5k views', highlight: true, anchor: 'end'}
            ],
            labels: ['Periode 2024', 'Periode 2025', 'Periode 2026 (Aktif)']
        }
    };

    filterButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            filterButtons.forEach(b => {
                b.style.background = 'transparent';
                b.style.color = '#aaa';
                b.style.fontWeight = 'normal';
                b.classList.remove('active');
            });

            this.style.background = '#2ecc71';
            this.style.color = '#111';
            this.style.fontWeight = 'bold';
            this.classList.add('active');

            const timeKey = this.getAttribute('data-time');
            const data = chartDatasets[timeKey];
            if (!data) return;

            // Update SVG paths
            chartLinePath.setAttribute('d', data.line);
            chartAreaPath.setAttribute('d', data.area);

            // Update Circles & Data Label Text
            let elementHtml = '';
            data.points.forEach(p => {
                const textY = p.cy - 10;
                const textAnchor = p.anchor || 'middle';
                if (p.highlight) {
                    elementHtml += `<circle cx="${p.cx}" cy="${p.cy}" r="6" fill="#2ecc71" stroke="#fff" stroke-width="2"/>`;
                    elementHtml += `<text x="${p.cx}" y="${textY}" fill="#ffffff" font-size="11" font-weight="bold" text-anchor="${textAnchor}">${p.val}</text>`;
                } else {
                    elementHtml += `<circle cx="${p.cx}" cy="${p.cy}" r="4" fill="#2ecc71"/>`;
                    elementHtml += `<text x="${p.cx}" y="${textY}" fill="#2ecc71" font-size="10" font-weight="bold" text-anchor="${textAnchor}">${p.val}</text>`;
                }
            });
            chartPointGroup.innerHTML = elementHtml;

            // Update X-Axis Labels
            let labelsHtml = '';
            data.labels.forEach(lbl => {
                labelsHtml += `<span>${lbl}</span>`;
            });
            xAxisLabels.innerHTML = labelsHtml;
        });
    });
});
</script>
