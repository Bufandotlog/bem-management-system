<?php
// admin/kepengurusan.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: Hapus kementerian pindah dari GET ke POST + CSRF
//   CHANGED: Cast $k['id'] ke (int) di semua URL output
//   CHANGED: escape slug output dengan htmlspecialchars()
//   CHANGED: htmlspecialchars() pada $periode_info
//   UNCHANGED: Seluruh HTML, CSS, JavaScript, struktur layout

require_once __DIR__ . '/header.php';

// ============================================
// PROSES HAPUS KEMENTERIAN — POST + CSRF
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_kementerian_id'])) {
    if (!csrfVerify()) {
        redirect('admin/kepengurusan.php', 'Request tidak valid.', 'error');
        exit();
    }
    $hapusId = (int) $_POST['hapus_kementerian_id'];
    if ($hapusId > 0) {
        // Ambil logo untuk dihapus, filter periode agar tidak bisa hapus kementerian periode lain
        $hapusKem = dbFetchOne(
            "SELECT logo FROM kementerian WHERE id = ? AND periode_id = ?",
            [$hapusId, $active_periode], "ii"
        );
        if ($hapusKem) {
            // Hapus logo jika ada
            if (!empty($hapusKem['logo'])) deleteFile($hapusKem['logo']);
            // Hapus anggota kementerian
            $anggotaHapus = dbFetchAll(
                "SELECT foto FROM anggota_kementerian WHERE kementerian_id = ?",
                [$hapusId], "i"
            );
            foreach ($anggotaHapus as $a) {
                if (!empty($a['foto'])) deleteFile($a['foto']);
            }
            dbQuery("DELETE FROM anggota_kementerian WHERE kementerian_id = ?", [$hapusId], "i");
            dbQuery("DELETE FROM kementerian WHERE id = ? AND periode_id = ?",
                    [$hapusId, $active_periode], "ii");
            redirect('admin/kepengurusan.php', 'Kementerian berhasil dihapus!', 'success');
        } else {
            redirect('admin/kepengurusan.php', 'Kementerian tidak ditemukan atau akses ditolak.', 'error');
        }
    }
    exit();
}

// ============================================
// AMBIL DATA KEPENGURUSAN UNTUK PERIODE AKTIF
// ============================================
$bph = [
    'ketua'           => dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?",           [$active_periode], "i"),
    'wakil_ketua'     => dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'wakil_ketua' AND periode_id = ?",     [$active_periode], "i"),
    'sekretaris_umum' => dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'sekretaris_umum' AND periode_id = ?", [$active_periode], "i"),
    'bendahara_umum'  => dbFetchOne("SELECT * FROM struktur_bph WHERE posisi = 'bendahara_umum' AND periode_id = ?",  [$active_periode], "i"),
];

$total_bph_terisi = 0;
foreach ($bph as $posisi) {
    if ($posisi) $total_bph_terisi++;
}

$total_anggota_bph = 0;
if ($bph['sekretaris_umum']) {
    $bph['sekretaris_umum']['anggota'] = dbFetchAll(
        "SELECT * FROM anggota_bph WHERE bph_id = ? AND periode_id = ? ORDER BY urutan",
        [$bph['sekretaris_umum']['id'], $active_periode], "ii"
    );
    $total_anggota_bph += count($bph['sekretaris_umum']['anggota']);
}
if ($bph['bendahara_umum']) {
    $bph['bendahara_umum']['anggota'] = dbFetchAll(
        "SELECT * FROM anggota_bph WHERE bph_id = ? AND periode_id = ? ORDER BY urutan",
        [$bph['bendahara_umum']['id'], $active_periode], "ii"
    );
    $total_anggota_bph += count($bph['bendahara_umum']['anggota']);
}

$kementerian_raw = dbFetchAll(
    "SELECT * FROM kementerian WHERE periode_id = ? ORDER BY urutan",
    [$active_periode], "i"
);
$total_kementerian = count($kementerian_raw);
$total_anggota_kementerian = 0;
$kementerian = [];
foreach ($kementerian_raw as $item) {
    $item['anggota'] = dbFetchAll(
        "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan",
        [$item['id'], $active_periode], "ii"
    );
    $total_anggota_kementerian += count($item['anggota']);
    $kementerian[] = $item;
}

$default_avatar = BASE_URL . 'assets/images/default-avatar.jpg';
$default_logo   = BASE_URL . 'assets/images/default-logo.png';

$periode_info = htmlspecialchars(
    "Periode: " . ($periode_data['nama'] ?? 'Astawidya')
    . " (" . ($periode_data['tahun_mulai'] ?? '2025')
    . "/" . ($periode_data['tahun_selesai'] ?? '2026') . ")",
    ENT_QUOTES, 'UTF-8'
);
?>

<!-- Page Header -->
<div class="page-header">
    <h1>Manajemen Kepengurusan</h1>
    <p>Kelola struktur organisasi BEM Kabinet Astawidya</p>
    <div style="margin-top: 10px; padding: 8px 15px; background: rgba(74,144,226,0.1); border-radius: 5px; display: inline-block;">
        <i class="fas fa-calendar-alt" style="color: var(--primary);"></i>
        <span style="color: var(--text-light);"><?php echo $periode_info; ?></span>
        <?php if ($user_can_access_all): ?>
            <a href="ganti-periode.php" style="margin-left: 15px; color: var(--primary); text-decoration: none;">
                <i class="fas fa-sync-alt"></i> Ganti Periode
            </a>
        <?php endif; ?>
    </div>
</div>

<?php flashMessage(); ?>

<!-- Statistik Cards -->
<div class="stats-grid" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(74,144,226,0.1);">
            <i class="fas fa-users" style="color: var(--primary);"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_bph_terisi; ?>/4</div>
            <div class="stat-label">BPH Terisi</div>
            <div class="stat-detail">Ketua, Wakil, Sekre, Bendum</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(74,144,226,0.1);">
            <i class="fas fa-user-friends" style="color: var(--primary);"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_anggota_bph; ?></div>
            <div class="stat-label">Anggota BPH</div>
            <div class="stat-detail">Sekretaris & Bendahara</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(74,144,226,0.1);">
            <i class="fas fa-building" style="color: var(--primary);"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_kementerian; ?></div>
            <div class="stat-label">Kementerian</div>
            <div class="stat-detail">Total departemen</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(74,144,226,0.1);">
            <i class="fas fa-users-cog" style="color: var(--primary);"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_anggota_kementerian; ?></div>
            <div class="stat-label">Anggota Kementerian</div>
            <div class="stat-detail">Total seluruh anggota</div>
        </div>
    </div>
</div>

<!-- Admin Tabs -->
<div class="admin-tabs">
    <button class="tab-btn active" onclick="openTab('bph')">
        <i class="fas fa-users"></i> BPH
        <span class="badge"><?php echo $total_bph_terisi; ?>/4</span>
    </button>
    <button class="tab-btn" onclick="openTab('kementerian')">
        <i class="fas fa-building"></i> Kementerian
        <span class="badge"><?php echo $total_kementerian; ?></span>
    </button>
    <a href="upload-struktur.php" class="btn btn-primary">
        <i class="fas fa-image"></i>
        <span class="d-none d-sm-inline">Upload Struktur Organisasi</span>
        <span class="d-inline d-sm-none">Upload</span>
    </a>
</div>

<!-- ===== TAB BPH ===== -->
<div id="bph" class="tab-content active">
    <div class="bph-grid">

        <!-- Ketua -->
        <div class="bph-card">
            <h3><i class="fas fa-crown"></i> Ketua BEM</h3>
            <?php if ($bph['ketua']): ?>
                <div class="bph-info">
                    <img src="<?php echo !empty($bph['ketua']['foto']) ? uploadUrl($bph['ketua']['foto']) : $default_avatar; ?>" class="bph-photo">
                    <p><strong><?php echo htmlspecialchars($bph['ketua']['nama']); ?></strong></p>
                    <a href="kepengurusan-edit.php?posisi=ketua" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user"></i>
                    <p>Belum ada data Ketua</p>
                    <a href="kepengurusan-edit.php?posisi=ketua" class="btn-add">
                        <i class="fas fa-plus"></i> Tambah Ketua
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Wakil Ketua -->
        <div class="bph-card">
            <h3><i class="fas fa-user-tie"></i> Wakil Ketua</h3>
            <?php if ($bph['wakil_ketua']): ?>
                <div class="bph-info">
                    <img src="<?php echo !empty($bph['wakil_ketua']['foto']) ? uploadUrl($bph['wakil_ketua']['foto']) : $default_avatar; ?>" class="bph-photo">
                    <p><strong><?php echo htmlspecialchars($bph['wakil_ketua']['nama']); ?></strong></p>
                    <a href="kepengurusan-edit.php?posisi=wakil_ketua" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user"></i>
                    <p>Belum ada data Wakil Ketua</p>
                    <a href="kepengurusan-edit.php?posisi=wakil_ketua" class="btn-add">
                        <i class="fas fa-plus"></i> Tambah Wakil
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sekretaris Umum -->
        <div class="bph-card">
            <h3><i class="fas fa-file-alt"></i> Sekretaris Umum</h3>
            <?php if ($bph['sekretaris_umum']): ?>
                <div class="bph-info">
                    <img src="<?php echo !empty($bph['sekretaris_umum']['logo']) ? uploadUrl($bph['sekretaris_umum']['logo']) : $default_logo; ?>" class="bph-logo">
                    <p><strong>Sekretaris Umum</strong></p>
                    <?php if (!empty($bph['sekretaris_umum']['anggota'])): ?>
                        <div class="anggota-list">
                            <?php foreach ($bph['sekretaris_umum']['anggota'] as $anggota): ?>
                                <div class="anggota-item">
                                    <img src="<?php echo !empty($anggota['foto']) ? uploadUrl($anggota['foto']) : $default_avatar; ?>" class="anggota-photo">
                                    <span><?php echo htmlspecialchars($anggota['nama']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="text-align: center; padding: 0.5rem; color: var(--text-muted);">Belum ada anggota</p>
                    <?php endif; ?>
                    <a href="kepengurusan-edit.php?posisi=sekretaris_umum" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <p>Belum ada data Sekretaris</p>
                    <a href="kepengurusan-edit.php?posisi=sekretaris_umum" class="btn-add">
                        <i class="fas fa-plus"></i> Tambah Sekretaris
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bendahara Umum -->
        <div class="bph-card">
            <h3><i class="fas fa-coins"></i> Bendahara Umum</h3>
            <?php if ($bph['bendahara_umum']): ?>
                <div class="bph-info">
                    <img src="<?php echo !empty($bph['bendahara_umum']['logo']) ? uploadUrl($bph['bendahara_umum']['logo']) : $default_logo; ?>" class="bph-logo">
                    <p><strong>Bendahara Umum</strong></p>
                    <?php if (!empty($bph['bendahara_umum']['anggota'])): ?>
                        <div class="anggota-list">
                            <?php foreach ($bph['bendahara_umum']['anggota'] as $anggota): ?>
                                <div class="anggota-item">
                                    <img src="<?php echo !empty($anggota['foto']) ? uploadUrl($anggota['foto']) : $default_avatar; ?>" class="anggota-photo">
                                    <span><?php echo htmlspecialchars($anggota['nama']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted" style="text-align: center; padding: 0.5rem; color: var(--text-muted);">Belum ada anggota</p>
                    <?php endif; ?>
                    <a href="kepengurusan-edit.php?posisi=bendahara_umum" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-coins"></i>
                    <p>Belum ada data Bendahara</p>
                    <a href="kepengurusan-edit.php?posisi=bendahara_umum" class="btn-add">
                        <i class="fas fa-plus"></i> Tambah Bendahara
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== TAB KEMENTERIAN ===== -->
<div id="kementerian" class="tab-content">
    <div class="header-actions">
        <a href="kementerian-edit.php" class="btn-primary">
            <i class="fas fa-plus"></i> Tambah Kementerian Baru
        </a>
    </div>

    <?php if (empty($kementerian)): ?>
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <p>Belum ada kementerian</p>
            <a href="kementerian-edit.php" class="btn-primary">
                <i class="fas fa-plus"></i> Tambah Kementerian Pertama
            </a>
        </div>
    <?php else: ?>
        <div class="kementerian-grid">
            <?php foreach ($kementerian as $k):
                $kid  = (int) $k['id'];
                $kslug = htmlspecialchars($k['slug'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="kementerian-card">
                <div class="kementerian-header">
                    <?php if ($k['logo']): ?>
                        <img src="<?php echo uploadUrl($k['logo']); ?>" class="kementerian-logo">
                    <?php else: ?>
                        <div class="kementerian-logo" style="background: var(--primary-dark); display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-building" style="color: var(--primary); font-size: 1.5rem;"></i>
                        </div>
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($k['nama']); ?></h3>
                </div>

                <div class="kementerian-info">
                    <p>
                        <strong>Slug:</strong>
                        <span><?php echo $kslug; ?></span>
                    </p>
                    <p>
                        <strong>Jumlah Anggota:</strong>
                        <span><?php echo count($k['anggota']); ?> orang</span>
                    </p>
                </div>

                <div class="kementerian-actions">
                    <a href="kementerian-edit.php?id=<?php echo $kid; ?>" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="kementerian-anggota.php?id=<?php echo $kid; ?>" class="btn-view">
                        <i class="fas fa-users"></i> Anggota
                    </a>
                    <?php /* Hapus via POST + CSRF — bukan GET link */ ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Yakin ingin menghapus kementerian ini? Semua anggota juga akan ikut terhapus.')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="hapus_kementerian_id" value="<?php echo $kid; ?>">
                        <button type="submit" class="btn-delete">
                            <i class="fas fa-trash"></i> Hapus
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript untuk Tab — tidak diubah -->
<script>
function openTab(tabName) {
    var tabs = document.getElementsByClassName('tab-content');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove('active');
    }
    var btns = document.getElementsByClassName('tab-btn');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('active');
    }
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}
</script>

<link rel="stylesheet" href="css/kepengurusan.css">

<?php require_once __DIR__ . '/footer.php'; ?>