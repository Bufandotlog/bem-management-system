<?php
// detail-menteri.php - Halaman Detail Universal (BPH & Kementerian)
// VERSI: 2.2 - FIX: $active_periode undefined → ambil dari $_GET dengan fallback DB

include 'header.php';

// ===========================================
// AMBIL PARAMETER & VALIDASI
// ===========================================
$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!$id || !in_array($type, ['bph', 'kementerian'])) {
    header('Location: kepengurusan.php');
    exit;
}

// ===========================================
// TENTUKAN PERIODE
// ✅ FIX: Ambil dari $_GET['periode'] yang dikirim kepengurusan.php
//         Fallback ke periode aktif di DB jika tidak ada
// ===========================================
$periode_id = (int)($_GET['periode'] ?? 0);

if (!$periode_id) {
    $periode_aktif = dbFetchOne("SELECT id FROM periode_kepengurusan WHERE is_active = 1");
    $periode_id    = (int)($periode_aktif['id'] ?? 0);
}

// ===========================================
// AMBIL DATA PARENT (BPH / KEMENTERIAN)
// ===========================================
$parent        = null;
$anggota_table = '';
$foreign_key   = '';

if ($type === 'bph') {
    // Jika ada periode_id, filter dengan periode — jika tidak, cari hanya by id
    $parent = $periode_id
        ? dbFetchOne(
            "SELECT * FROM struktur_bph WHERE id = ? AND periode_id = ?",
            [$id, $periode_id], "ii"
          )
        : dbFetchOne(
            "SELECT * FROM struktur_bph WHERE id = ?",
            [$id], "i"
          );

    $anggota_table = 'anggota_bph';
    $foreign_key   = 'bph_id';

} else {
    $parent = $periode_id
        ? dbFetchOne(
            "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
            [$id, $periode_id], "ii"
          )
        : dbFetchOne(
            "SELECT * FROM kementerian WHERE id = ?",
            [$id], "i"
          );

    $anggota_table = 'anggota_kementerian';
    $foreign_key   = 'kementerian_id';
}

if (!$parent) {
    header('Location: kepengurusan.php');
    exit;
}

// Gunakan periode_id dari data parent jika belum ada (extra safety)
if (!$periode_id && !empty($parent['periode_id'])) {
    $periode_id = (int)$parent['periode_id'];
}

// ===========================================
// AMBIL ANGGOTA
// ===========================================
$anggota_list = $periode_id
    ? dbFetchAll(
        "SELECT * FROM {$anggota_table}
         WHERE {$foreign_key} = ? AND periode_id = ? ORDER BY urutan",
        [$parent['id'], $periode_id], "ii"
      )
    : dbFetchAll(
        "SELECT * FROM {$anggota_table}
         WHERE {$foreign_key} = ? ORDER BY urutan",
        [$parent['id']], "i"
      );

// ===========================================
// TENTUKAN TIPE HEADER
// ===========================================
$tipe_header  = 'text';
$header_image = '';

if (!empty($parent['foto'])) {
    $tipe_header  = 'photo';
    $header_image = $parent['foto'];
} elseif (!empty($parent['logo'])) {
    $tipe_header  = 'logo';
    $header_image = $parent['logo'];
}

// ===========================================
// DECODE JSON TUGAS & PROKER
// ===========================================
$deskripsi = $parent['deskripsi'] ?? '';

$tugas = [];
if (!empty($parent['tugas'])) {
    $decoded = json_decode($parent['tugas'], true);
    $tugas   = is_array($decoded) ? $decoded : [];
}

$proker = [];
if (!empty($parent['proker'])) {
    $decoded = json_decode($parent['proker'], true);
    $proker  = is_array($decoded) ? $decoded : [];
}

// ===========================================
// JUDUL HALAMAN
// ===========================================
$judul_halaman = htmlspecialchars($parent['nama'] ?? 'Detail');
$tahun_label   = date('Y') . '/' . (date('Y') + 1);
?>

<!-- =========================================== -->
<!-- HEADER HALAMAN (DINAMIS)                    -->
<!-- =========================================== -->

<?php if ($tipe_header === 'photo'): ?>
<div class="detail-header photo-header">
    <div class="header-photo-container">
        <img src="<?php echo uploadUrl($header_image); ?>"
             alt="<?php echo $judul_halaman; ?>"
             onerror="this.src='<?php echo assetUrl('images/default-avatar.jpg'); ?>'">
    </div>
    <div class="header-text">
        <h1><?php echo $judul_halaman; ?></h1>
        <p>Kabinet Astawidya <?php echo $tahun_label; ?></p>
    </div>
</div>

<?php elseif ($tipe_header === 'logo'): ?>
<div class="detail-header logo-header">
    <div class="header-logo-container">
        <img src="<?php echo uploadUrl($header_image); ?>"
             alt="Logo <?php echo $judul_halaman; ?>"
             class="header-logo"
             onerror="this.src='<?php echo assetUrl('images/default-logo.png'); ?>'">
    </div>
    <div class="header-text">
        <h1><?php echo $judul_halaman; ?></h1>
        <p>Kabinet Astawidya <?php echo $tahun_label; ?></p>
    </div>
</div>

<?php else: ?>
<div class="detail-header text-header">
    <div class="header-text">
        <h1><?php echo $judul_halaman; ?></h1>
        <p>Kabinet Astawidya <?php echo $tahun_label; ?></p>
    </div>
</div>
<?php endif; ?>


<!-- =========================================== -->
<!-- DESKRIPSI, TUGAS & PROGRAM KERJA            -->
<!-- =========================================== -->

<?php if (!empty($deskripsi) || !empty($tugas) || !empty($proker)): ?>
<div class="detail-description">
    <div class="description-container">

        <?php if (!empty($deskripsi)): ?>
        <div class="desc-section">
            <h2><i class="fas fa-info-circle"></i> Tentang</h2>
            <div class="desc-card">
                <p><?php echo nl2br(htmlspecialchars($deskripsi)); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($tugas)): ?>
        <div class="desc-section">
            <h2><i class="fas fa-tasks"></i> Tugas Pokok</h2>
            <div class="desc-card">
                <ul class="tugas-list">
                    <?php foreach ($tugas as $item): ?>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($item); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($proker)): ?>
        <div class="desc-section">
            <h2><i class="fas fa-calendar-alt"></i> Program Kerja</h2>
            <div class="desc-card">
                <ul class="proker-list">
                    <?php foreach ($proker as $item): ?>
                    <li>
                        <i class="fas fa-star"></i>
                        <?php echo htmlspecialchars($item); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>


<!-- =========================================== -->
<!-- GRID ANGGOTA                                -->
<!-- =========================================== -->

<?php if (!empty($anggota_list)): ?>
<div class="anggota-section">
    <h2 class="anggota-title">
        <i class="fas fa-users"></i>
        <?php echo count($anggota_list) > 1 ? 'Daftar Anggota' : 'Anggota'; ?>
    </h2>

    <div class="anggota-grid">
        <?php foreach ($anggota_list as $anggota): ?>
        <div class="anggota-item">
            <div class="member-photo-container">
                <img src="<?php echo !empty($anggota['foto'])
                                ? uploadUrl($anggota['foto'])
                                : assetUrl('images/default-avatar.jpg'); ?>"
                     alt="<?php echo htmlspecialchars($anggota['nama']); ?>"
                     loading="lazy"
                     onerror="this.src='<?php echo assetUrl('images/default-avatar.jpg'); ?>'">
            </div>
            <div class="item-info">
                <h3><?php echo htmlspecialchars($anggota['nama']); ?></h3>
                <div class="jabatan">
                    <?php echo htmlspecialchars($anggota['jabatan'] ?? 'Anggota'); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<!-- =========================================== -->
<!-- TOMBOL KEMBALI                              -->
<!-- =========================================== -->

<div class="back-button-container">
    <!-- ✅ FIX: Sertakan periode agar user kembali ke periode yang sama -->
    <a href="kepengurusan.php<?php echo $periode_id ? '?periode=' . $periode_id : ''; ?>"
       class="btn btn-kembali">
        <i class="fas fa-arrow-left"></i> Kembali ke Kepengurusan
    </a>
</div>

<?php include 'footer.php'; ?>