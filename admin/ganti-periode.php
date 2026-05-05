<?php
// admin/ganti-periode.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form POST + validasi
//   CHANGED: Redirect ke admin/ganti-periode.php dan admin/dashboard.php
//   CHANGED: htmlspecialchars() pada semua output dinamis
//   CHANGED: Cast (int) pada semua output ID
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

$page_css = 'ganti-periode';
require_once __DIR__ . '/header.php';

if ($_SESSION['admin_role'] !== 'superadmin' && !$user_can_access_all) {
    redirect('admin/dashboard.php', 'Akses ditolak!', 'error');
    exit();
}

$periode_list      = dbFetchAll("SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
$periode_aktif     = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");
$current_selected  = $_SESSION['selected_periode'] ?? $active_periode;

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['periode_id'])) {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $new_periode_id = (int) $_POST['periode_id'];
        $periode = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE id = ?", [$new_periode_id], "i");

        if ($periode) {
            $_SESSION['selected_periode'] = $new_periode_id;
            auditLog('UPDATE', 'periode_kepengurusan', $new_periode_id, 'Ganti tampilan periode ke: ' . $periode['nama']);
            $success = "Periode berhasil diganti ke: "
                . htmlspecialchars($periode['nama'])
                . " (" . (int)$periode['tahun_mulai'] . "/" . (int)$periode['tahun_selesai'] . ")";
            $current_selected = $new_periode_id;
        } else {
            $error = 'Periode tidak valid!';
        }
    }
}

$selected_periode_data = dbFetchOne(
    "SELECT * FROM periode_kepengurusan WHERE id = ?",
    [$current_selected], "i"
);
?>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-sync-alt"></i> Ganti Periode</h1>
    <p style="margin-bottom:20px;">Superadmin: Beralih antar periode kepengurusan</p>
</div>

<?php flashMessage(); ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-top:10px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- Info Periode Saat Ini -->
<div class="info-box">
    <div style="margin-top:10px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div class="icon-circle"><i class="fas fa-eye"></i></div>
        <div style="flex:1;">
            <h3>Sedang Melihat Periode:</h3>
            <p class="periode-title">
                <?php echo htmlspecialchars($selected_periode_data['nama'] ?? 'Tidak diketahui', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <p class="periode-detail">
                <?php echo (int)($selected_periode_data['tahun_mulai'] ?? 0); ?>/<?php echo (int)($selected_periode_data['tahun_selesai'] ?? 0); ?>
                <?php if ($selected_periode_data && $selected_periode_data['is_active']): ?>
                    <span class="badge-active"><i class="fas fa-check-circle"></i> Periode Aktif</span>
                <?php else: ?>
                    <span class="badge-arsip"><i class="fas fa-archive"></i> Periode Arsip</span>
                <?php endif; ?>
            </p>
        </div>
        <div>
            <a href="dashboard.php" class="btn-primary" style="padding:12px 25px;">
                <i class="fas fa-tachometer-alt"></i> Ke Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Form Ganti Periode -->
<div class="card">
    <div class="card-header">
        <i class="fas fa-calendar-alt"></i> Pilih Periode Lain
    </div>
    <div class="card-body">
        <form method="POST" id="gantiPeriodeForm">
            <?php echo csrfField(); ?>
            <div style="display:grid;grid-template-columns:1fr auto;gap:15px;align-items:end;">
                <div class="form-group" style="margin:0;">
                    <label for="periode_id"><i class="fas fa-calendar-check"></i> Periode:</label>
                    <select name="periode_id" id="periode_id" class="form-control"
                            style="font-size:1.1rem;padding:12px;">
                        <?php foreach ($periode_list as $p):
                            $pid      = (int)$p['id'];
                            $selected = ($pid === (int)$current_selected) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $pid; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($p['nama'], ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo (int)$p['tahun_mulai']; ?>/<?php echo (int)$p['tahun_selesai']; ?>)
                                <?php echo $p['is_active'] ? ' [AKTIF]' : ' [ARSIP]'; ?>
                                <?php echo $pid === (int)$current_selected ? ' (Sedang Dilihat)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="padding:12px 30px;">
                    <i class="fas fa-sync-alt"></i> Ganti Periode
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Access Cards -->
<div style="margin-top:30px;">
    <h3 style="color:white;margin-bottom:15px;">Akses Cepat ke Periode:</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;">
        <?php foreach ($periode_list as $p):
            $pid = (int)$p['id'];
        ?>
            <div class="periode-card <?php echo $pid === (int)$current_selected ? 'active' : ''; ?>"
                 onclick="selectPeriode(<?php echo $pid; ?>)">
                <div class="card-header">
                    <h4><?php echo htmlspecialchars($p['nama'], ENT_QUOTES, 'UTF-8'); ?></h4>
                    <?php if ($p['is_active']): ?>
                        <span class="badge-aktif">Aktif</span>
                    <?php endif; ?>
                </div>
                <p class="periode-tahun"><?php echo (int)$p['tahun_mulai']; ?>/<?php echo (int)$p['tahun_selesai']; ?></p>
                <p class="periode-deskripsi">
                    <?php echo htmlspecialchars(mb_substr($p['deskripsi'] ?? 'Tidak ada deskripsi', 0, 50), ENT_QUOTES, 'UTF-8'); ?>
                    <?php echo mb_strlen($p['deskripsi'] ?? '') > 50 ? '...' : ''; ?>
                </p>
                <?php if ($pid === (int)$current_selected): ?>
                    <div class="viewing-indicator">
                        <i class="fas fa-eye"></i> Sedang dilihat
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Informasi -->
<div class="card" style="margin-top:30px;">
    <div class="card-header"><i class="fas fa-info-circle"></i> Tentang Fitur Ganti Periode</div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item">
                <h4 style="color:#4A90E2;"><i class="fas fa-check-circle"></i> Manfaat</h4>
                <ul>
                    <li>Superadmin bisa preview periode lain</li>
                    <li>Cek data periode lama tanpa mengaktifkannya</li>
                    <li>Memudahkan migrasi data antar periode</li>
                    <li>Tidak perlu logout/login ulang</li>
                </ul>
            </div>
            <div class="info-item">
                <h4 style="color:#ffaa00;"><i class="fas fa-exclamation-triangle"></i> Catatan</h4>
                <ul>
                    <li>Fitur ini hanya untuk superadmin</li>
                    <li>Periode aktif website tidak berubah</li>
                    <li>Hanya tampilan admin yang berganti</li>
                    <li>Admin biasa tetap di periode aslinya</li>
                </ul>
            </div>
            <div class="info-item">
                <h4 style="color:#4CAF50;"><i class="fas fa-arrow-right"></i> Yang Berubah</h4>
                <ul>
                    <li>Data kepengurusan yang ditampilkan</li>
                    <li>Kementerian dan anggota</li>
                    <li>Gambar struktur organisasi</li>
                    <li>Statistik periode</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript — tidak diubah -->
<script>
function selectPeriode(id) {
    document.getElementById('periode_id').value = id;
    document.getElementById('gantiPeriodeForm').submit();
}

document.getElementById('gantiPeriodeForm').addEventListener('submit', function(e) {
    const selected = document.getElementById('periode_id');
    const selectedText = selected.options[selected.selectedIndex].text;
    if (!confirm('Ganti tampilan admin ke periode: ' + selectedText + '?')) {
        e.preventDefault();
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>