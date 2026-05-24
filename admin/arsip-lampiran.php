<?php
// admin/arsip-lampiran.php
// Tambahkan error reporting untuk debugging di InfinityFree
error_reporting(E_ALL);
ini_set('display_errors', 1);

$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();

$success = '';
$error = '';

// --- ACTION HANDLER: DELETE ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    csrfVerify();
    $del_id = (int)$_GET['delete'];
    
    // Cek apakah arsip lampiran ini terikat dengan surat
    $is_terikat = false;
    $surat_terkait = [];
    $surat_list = dbFetchAll("SELECT nomor_surat, konten_surat FROM arsip_surat WHERE periode_id = ?", [$periode_id], "i");
    foreach ($surat_list as $s) {
        $konten = json_decode($s['konten_surat'], true);
        if (isset($konten['lampiran_internal_ids']) && is_array($konten['lampiran_internal_ids'])) {
            if (in_array($del_id, $konten['lampiran_internal_ids'])) {
                $is_terikat = true;
                $surat_terkait[] = $s['nomor_surat'];
            }
        }
    }
    
    if ($is_terikat) {
        $error = "Tidak bisa menghapus arsip lampiran karena masih terikat dengan surat (" . htmlspecialchars($surat_terkait[0]) . "). Lampiran hanya bisa dihapus jika berdiri sendiri (tidak terikat pada surat apapun).";
    } else {
        $res = dbQuery("DELETE FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
        if ($res) {
            $success = "Data lampiran berhasil dihapus karena berdiri sendiri (tidak terikat pada surat).";
        } else {
            $error = "Gagal menghapus data.";
        }
    }
}


// --- ACTION HANDLER: DUPLICATE ---
if (isset($_GET['duplicate']) && is_numeric($_GET['duplicate'])) {
    csrfVerify();
    $dup_id = (int)$_GET['duplicate'];
    
    $ori = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$dup_id, $periode_id], "ii");
    if ($ori) {
        $new_nama = $ori['nama_acara'] . " (copy)";
        $res = dbQuery("INSERT INTO lampiran_pinjam (nama_acara, tanggal_kegiatan, tahun, barang_json, periode_id) VALUES (?, ?, ?, ?, ?)", [
            $new_nama,
            $ori['tanggal_kegiatan'],
            $ori['tahun'],
            $ori['barang_json'],
            $periode_id
        ]);
        if ($res) {
            $success = "Data lampiran berhasil diduplikasi.";
        } else {
            $error = "Gagal menduplikasi data.";
        }
    } else {
        $error = "Data yang akan diduplikasi tidak ditemukan.";
    }
}

// Ambil data arsip lampiran
$list_lampiran = dbFetchAll("SELECT * FROM lampiran_pinjam WHERE periode_id = ? ORDER BY created_at DESC", [$periode_id], "i");

// Hitung keterkaitan surat untuk visualisasi
$surat_list_all = dbFetchAll("SELECT nomor_surat, konten_surat FROM arsip_surat WHERE periode_id = ?", [$periode_id], "i");
$lampiran_to_surat = [];
foreach ($surat_list_all as $s) {
    $konten = json_decode($s['konten_surat'], true);
    if (isset($konten['lampiran_internal_ids']) && is_array($konten['lampiran_internal_ids'])) {
        foreach ($konten['lampiran_internal_ids'] as $l_id) {
            $lampiran_to_surat[$l_id][] = $s['nomor_surat'];
        }
    }
}

?>

<div class="arsip-surat-container">
    <div class="page-header" style="margin-bottom: 30px;">
        <div class="header-content">
            <h1><i class="fas fa-box-archive"></i> Arsip Pustaka Lampiran</h1>
            <p>Kelola data peminjaman barang yang telah disimpan untuk lampiran surat.</p>
        </div>
        <a href="cetak-lampiran.php" class="btn-primary" style="background: var(--primary-gradient); color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-plus"></i> Tambah Lampiran Baru
        </a>
    </div>

    <?php if ($success): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px; overflow: hidden; backdrop-filter: blur(10px);">
        <div class="table-responsive">
            <table class="arsip-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: rgba(255,255,255,0.03);">
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="50">No</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);">Nama Acara / Kegiatan</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);">Waktu Pelaksanaan</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="120">Barang</th>
                        <th style="padding: 15px; text-align: center; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="150">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_lampiran)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 50px; color: #555;">
                                <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; display:block; color: #333;"></i>
                                Belum ada data lampiran yang disimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($list_lampiran as $idx => $l): 
                            $barang_data = json_decode($l['barang_json'], true) ?: [];
                            $jml_barang = count($barang_data);
                            $terkait = $lampiran_to_surat[$l['id']] ?? [];
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 15px;"><?php echo $idx + 1; ?></td>
                            <td style="padding: 15px;">
                                <div style="font-weight:600; color:var(--accent-color);"><?php echo htmlspecialchars($l['nama_acara']); ?></div>
                                <div style="font-size:0.75rem; color:#666;">ID: #<?php echo $l['id']; ?></div>
                                <?php if(!empty($terkait)): ?>
                                    <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:4px;">
                                        <?php foreach($terkait as $ns): ?>
                                            <span style="background:rgba(243, 156, 18, 0.1); color:#f39c12; padding:3px 8px; border-radius:6px; font-size:0.7rem; font-weight:600; border:1px solid rgba(243, 156, 18, 0.3);" title="Terikat dengan surat ini">
                                                <i class="fas fa-link"></i> <?php echo htmlspecialchars($ns); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top:8px;">
                                        <span style="background:rgba(46, 204, 113, 0.1); color:#2ecc71; padding:3px 8px; border-radius:6px; font-size:0.7rem; font-weight:600; border:1px solid rgba(46, 204, 113, 0.3);" title="Tidak terikat pada surat manapun">
                                            <i class="fas fa-check"></i> Berdiri Sendiri
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 15px;">
                                <div style="color: #eee;"><?php echo htmlspecialchars($l['tanggal_kegiatan']); ?></div>
                                <div style="font-size:0.75rem; font-weight:bold; color:#555;"><?php echo htmlspecialchars($l['tahun']); ?></div>
                            </td>
                            <td style="padding: 15px;">
                                <span style="background: rgba(79, 172, 254, 0.1); color: #4facfe; border: 1px solid rgba(79, 172, 254, 0.2); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $jml_barang; ?> Item
                                </span>
                            </td>
                            <td style="padding: 15px; text-align:center;">
                                <div style="display:flex; gap:8px; justify-content:center;">
                                    <form action="cetak-lampiran-pdf.php" method="POST" target="_blank" style="margin:0; padding:0;">
                                        <input type="hidden" name="acara" value="<?php echo htmlspecialchars($l['nama_acara']); ?>">
                                        <input type="hidden" name="tanggal" value="<?php echo htmlspecialchars($l['tanggal_kegiatan']); ?>">
                                        <input type="hidden" name="tahun" value="<?php echo htmlspecialchars($l['tahun']); ?>">
                                        <?php foreach($barang_data as $b): ?>
                                            <input type="hidden" name="qty[<?php echo htmlspecialchars($b['id']); ?>]" value="<?php echo (int)$b['qty']; ?>">
                                            <input type="hidden" name="item_name[<?php echo htmlspecialchars($b['id']); ?>]" value="<?php echo htmlspecialchars($b['nama']); ?>">
                                        <?php endforeach; ?>
                                        <button type="submit" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(39, 174, 96, 0.1); color: #27ae60; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center;" title="Cetak Lampiran PDF" onmouseover="this.style.background='rgba(39, 174, 96, 0.2)'" onmouseout="this.style.background='rgba(39, 174, 96, 0.1)'">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </form>
                                    <a href="cetak-lampiran.php?edit_id=<?php echo $l['id']; ?>" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(79, 172, 254, 0.1); color: #4facfe; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Edit Info, Barang & Tempat">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?duplicate=<?php echo $l['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(241, 196, 15, 0.1); color: #f1c40f; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Duplikasi data lampiran ini?')" 
                                       title="Duplikat">
                                        <i class="fas fa-copy"></i>
                                    </a>
                                    <a href="?delete=<?php echo $l['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Hapus data lampiran ini dari arsip?')" 
                                       title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<?php require_once __DIR__ . '/footer.php'; ?>
