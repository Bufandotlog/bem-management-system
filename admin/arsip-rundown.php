<?php
// admin/arsip-rundown.php
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
    
    // Cek apakah arsip rundown ini terikat dengan surat
    $is_terikat = false;
    $surat_terkait = [];
    $surat_list_check = dbFetchAll("SELECT nomor_surat, konten_surat FROM arsip_surat WHERE periode_id = ?", [$periode_id], "i");
    foreach ($surat_list_check as $s) {
        $konten = json_decode($s['konten_surat'], true);
        if (isset($konten['rundown_internal_ids']) && is_array($konten['rundown_internal_ids'])) {
            if (in_array($del_id, $konten['rundown_internal_ids'])) {
                $is_terikat = true;
                $surat_terkait[] = $s['nomor_surat'];
            }
        }
    }
    
    if ($is_terikat) {
        $error = "Tidak bisa menghapus arsip rundown karena masih terikat dengan surat (" . htmlspecialchars($surat_terkait[0]) . "). Rundown hanya bisa dihapus jika berdiri sendiri (tidak terikat pada surat apapun).";
    } else {
        try {
            $res = dbQuery("DELETE FROM arsip_rundown WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
            if ($res) {
                $success = "Data rundown berhasil dihapus karena berdiri sendiri (tidak terikat pada surat).";
            } else {
                $error = "Gagal menghapus data.";
            }
        } catch (Exception $e) {
            $error = "Gagal menghapus data: " . $e->getMessage();
        }
    }
}

// --- ACTION HANDLER: DUPLICATE ---
if (isset($_GET['duplicate']) && is_numeric($_GET['duplicate'])) {
    csrfVerify();
    $dup_id = (int)$_GET['duplicate'];
    
    $ori = dbFetchOne("SELECT * FROM arsip_rundown WHERE id = ? AND periode_id = ?", [$dup_id, $periode_id], "ii");
    if ($ori) {
        $new_nama = $ori['nama_acara'] . " (copy)";
        try {
            $res = dbQuery("INSERT INTO arsip_rundown (nama_acara, tahun, tanggal_mulai, durasi_hari, rundown_json, periode_id) VALUES (?, ?, ?, ?, ?, ?)", [
                $new_nama,
                $ori['tahun'],
                $ori['tanggal_mulai'],
                $ori['durasi_hari'],
                $ori['rundown_json'],
                $periode_id
            ]);
            if ($res) {
                $success = "Data rundown berhasil diduplikasi.";
            } else {
                $error = "Gagal menduplikasi data.";
            }
        } catch (Exception $e) {
            $error = "Gagal menduplikasi data: " . $e->getMessage();
        }
    } else {
        $error = "Data yang akan diduplikasi tidak ditemukan.";
    }
}

// Ambil data arsip rundown
$list_rundown = dbFetchAll("SELECT * FROM arsip_rundown WHERE periode_id = ? ORDER BY created_at DESC", [$periode_id], "i");

// Hitung keterkaitan surat untuk visualisasi (mirip arsip-lampiran)
$surat_list_all = dbFetchAll("SELECT nomor_surat, konten_surat FROM arsip_surat WHERE periode_id = ?", [$periode_id], "i");
$rundown_to_surat = [];
foreach ($surat_list_all as $s) {
    $konten = json_decode($s['konten_surat'], true);
    if (isset($konten['rundown_internal_ids']) && is_array($konten['rundown_internal_ids'])) {
        foreach ($konten['rundown_internal_ids'] as $r_id) {
            $rundown_to_surat[$r_id][] = $s['nomor_surat'];
        }
    }
}

// Bulan Indonesia untuk tampilan
$bulan_id = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

function formatTanggalId($tanggal, $bulan_id) {
    $parts = explode('-', $tanggal);
    if (count($parts) !== 3) return $tanggal;
    return (int)$parts[2] . ' ' . ($bulan_id[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
}
?>

<style>
/* RESPONSIVE DESIGN FOR ARSIP RUNDOWN */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    .page-header .btn-primary {
        width: 100%;
        justify-content: center;
    }
    
    .table-responsive {
        border: none;
    }
    
    /* Table to Cards */
    .arsip-table, 
    .arsip-table thead, 
    .arsip-table tbody, 
    .arsip-table th, 
    .arsip-table td, 
    .arsip-table tr { 
        display: block; 
    }
    
    .arsip-table thead { display: none; }
    
    .arsip-table tr {
        margin-bottom: 20px;
        background: rgba(255,255,255,0.02);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 15px;
        position: relative;
    }
    
    .arsip-table td {
        border: none !important;
        padding: 8px 0 !important;
        width: 100% !important;
        text-align: left !important;
    }
    
    .arsip-table td:first-child {
        font-size: 1.1rem;
        font-weight: bold;
        color: var(--accent-color);
        border-bottom: 1px solid var(--border-color) !important;
        padding-bottom: 12px !important;
        margin-bottom: 10px;
    }
    
    .arsip-table td:first-child::before {
        content: "NO. ";
        font-size: 0.8rem;
        color: #555;
    }

    .arsip-table td::before {
        content: attr(data-label);
        display: block;
        font-size: 0.7rem;
        color: #555;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .arsip-table td[data-label="AKSI"] {
        margin-top: 15px;
        padding-top: 15px !important;
        border-top: 1px dashed var(--border-color) !important;
    }
    
    .arsip-table td[data-label="AKSI"] div {
        justify-content: flex-start !important;
    }
}
</style>

<div class="arsip-surat-container">
    <div class="page-header" style="margin-bottom: 30px;">
        <div class="header-content">
            <h1><i class="fas fa-clipboard-list"></i> Arsip Rundown Acara</h1>
            <p>Kelola data susunan acara yang telah disimpan.</p>
        </div>
        <a href="cetak-rundown.php" class="btn-primary" style="background: var(--primary-gradient); color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-plus"></i> Buat Rundown Baru
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
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);">Tanggal Pelaksanaan</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="100">Durasi</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="120">Item Acara</th>
                        <th style="padding: 15px; text-align: center; color: #888; font-weight: 600; border-bottom: 1px solid var(--border-color);" width="200">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_rundown)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 50px; color: #555;">
                                <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; display:block; color: #333;"></i>
                                Belum ada data rundown yang disimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($list_rundown as $idx => $r): 
                            $rundown_data = json_decode($r['rundown_json'], true) ?: [];
                            $total_items = 0;
                            foreach ($rundown_data as $day) {
                                $total_items += count($day['items'] ?? []);
                            }
                            $tanggal_display = formatTanggalId($r['tanggal_mulai'], $bulan_id);
                            $durasi = (int)$r['durasi_hari'];
                            $terkait = $rundown_to_surat[$r['id']] ?? [];
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 15px;" data-label="No"><?php echo $idx + 1; ?></td>
                            <td style="padding: 15px;" data-label="Nama Acara / Kegiatan">
                                <div style="font-weight:600; color:var(--accent-color);"><?php echo htmlspecialchars($r['nama_acara']); ?></div>
                                <div style="font-size:0.75rem; color:#666;">ID: #<?php echo $r['id']; ?></div>
                                <div style="margin-top:5px;">
                                    <span style="background:rgba(79, 172, 254, 0.1); color:#4facfe; padding:3px 8px; border-radius:6px; font-size:0.7rem; font-weight:600; border:1px solid rgba(79, 172, 254, 0.3);">
                                        <?php echo htmlspecialchars($r['tahun']); ?>
                                    </span>
                                </div>
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
                            <td style="padding: 15px;" data-label="Tanggal Pelaksanaan">
                                <div style="color: #eee;"><?php echo htmlspecialchars($tanggal_display); ?></div>
                            </td>
                            <td style="padding: 15px;" data-label="Durasi">
                                <span style="background: rgba(155, 89, 182, 0.1); color: #9b59b6; border: 1px solid rgba(155, 89, 182, 0.2); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $durasi; ?> Hari
                                </span>
                            </td>
                            <td style="padding: 15px;" data-label="Item Acara">
                                <span style="background: rgba(79, 172, 254, 0.1); color: #4facfe; border: 1px solid rgba(79, 172, 254, 0.2); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $total_items; ?> Acara
                                </span>
                            </td>
                            <td style="padding: 15px; text-align:center;" data-label="AKSI">
                                <div style="display:flex; gap:8px; justify-content:center;">
                                    <!-- Cetak PDF langsung dari arsip -->
                                    <form action="cetak-rundown-pdf.php" method="POST" target="_blank" style="margin:0; padding:0;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="nama_acara" value="<?php echo htmlspecialchars($r['nama_acara']); ?>">
                                        <input type="hidden" name="tahun" value="<?php echo htmlspecialchars($r['tahun']); ?>">
                                        <input type="hidden" name="tanggal_mulai" value="<?php echo htmlspecialchars($r['tanggal_mulai']); ?>">
                                        <input type="hidden" name="durasi_hari" value="<?php echo (int)$r['durasi_hari']; ?>">
                                        <?php 
                                        // Rebuild POST data dari JSON arsip
                                        foreach ($rundown_data as $dayIndex => $dayData):
                                            $dayId = $dayIndex + 1;
                                            foreach ($dayData['items'] as $item):
                                        ?>
                                            <input type="hidden" name="waktu[<?php echo $dayId; ?>][]" value="<?php echo htmlspecialchars($item['waktu']); ?>">
                                            <input type="hidden" name="acara[<?php echo $dayId; ?>][]" value="<?php echo htmlspecialchars($item['acara']); ?>">
                                            <input type="hidden" name="keterangan[<?php echo $dayId; ?>][]" value="<?php echo htmlspecialchars($item['keterangan']); ?>">
                                            <input type="hidden" name="penanggung_jawab[<?php echo $dayId; ?>][]" value="<?php echo htmlspecialchars($item['pj']); ?>">
                                            <input type="hidden" name="is_parallel[<?php echo $dayId; ?>][]" value="<?php echo !empty($item['is_parallel']) ? '1' : '0'; ?>">
                                        <?php 
                                            endforeach;
                                        endforeach; 
                                        ?>
                                        <button type="submit" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(39, 174, 96, 0.1); color: #27ae60; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center;" title="Cetak Rundown PDF" onmouseover="this.style.background='rgba(39, 174, 96, 0.2)'" onmouseout="this.style.background='rgba(39, 174, 96, 0.1)'">
                                            <i class="fas fa-print"></i>
                                        </button>
                                    </form>
                                    <!-- Edit -->
                                    <a href="cetak-rundown.php?edit_id=<?php echo $r['id']; ?>" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(79, 172, 254, 0.1); color: #4facfe; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Edit Rundown">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Duplikat -->
                                    <a href="?duplicate=<?php echo $r['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(241, 196, 15, 0.1); color: #f1c40f; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Duplikasi data rundown ini?')" 
                                       title="Duplikat">
                                        <i class="fas fa-copy"></i>
                                    </a>
                                    <!-- Hapus -->
                                    <a href="?delete=<?php echo $r['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Hapus data rundown ini dari arsip?')" 
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
