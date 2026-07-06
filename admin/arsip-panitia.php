<?php
// admin/arsip-panitia.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();

$success = '';
$error = '';

// --- ACTION HANDLER: DELETE ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    csrfVerify();
    $del_id = (int)$_GET['delete'];
    try {
        $res = dbQuery("DELETE FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
        if ($res) {
            $success = "Data susunan panitia berhasil dihapus.";
        } else {
            $error = "Gagal menghapus data.";
        }
    } catch (Exception $e) {
        $error = "Gagal menghapus data: " . $e->getMessage();
    }
}

// --- ACTION HANDLER: DUPLICATE ---
if (isset($_GET['duplicate']) && is_numeric($_GET['duplicate'])) {
    csrfVerify();
    $dup_id = (int)$_GET['duplicate'];
    
    $ori = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$dup_id, $periode_id], "ii");
    if ($ori) {
        $new_nama = $ori['nama_kegiatan'] . " (copy)";
        try {
            $res = dbQuery("INSERT INTO arsip_panitia (nama_kegiatan, periode_id, panitia_json) VALUES (?, ?, ?)", [
                $new_nama,
                $periode_id,
                $ori['panitia_json']
            ]);
            if ($res) {
                $success = "Data susunan panitia berhasil diduplikasi.";
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

// Ambil list arsip panitia
$list_panitia = dbFetchAll("SELECT * FROM arsip_panitia WHERE periode_id = ? ORDER BY created_at DESC", [$periode_id], "i");

// Bulan Indonesia untuk tampilan tanggal
$bulan_id = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

function formatTanggalId($tanggal, $bulan_id) {
    if (!$tanggal) return '-';
    $parts = explode(' ', $tanggal);
    $date_parts = explode('-', $parts[0]);
    if (count($date_parts) !== 3) return $tanggal;
    return (int)$date_parts[2] . ' ' . ($bulan_id[$date_parts[1]] ?? $date_parts[1]) . ' ' . $date_parts[0];
}
?>

<style>
.arsip-panitia-container {
    max-width: 1400px;
    margin: 0 auto;
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.table-responsive {
    border: none;
    overflow-x: auto;
}

.arsip-table {
    width: 100%;
    border-collapse: collapse;
}

.arsip-table th {
    padding: 15px;
    text-align: left;
    color: #888;
    font-weight: 600;
    border-bottom: 1px solid var(--border-color);
}

.arsip-table td {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    color: #eee;
    font-size: 0.95rem;
}

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

<div class="arsip-panitia-container">
    <div class="page-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center;">
        <div class="header-content">
            <h1><i class="fas fa-archive"></i> Arsip Susunan Panitia</h1>
            <p>Kelola data susunan panitia yang telah dibuat dan disimpan.</p>
        </div>
        <a href="buat-panitia.php" class="btn-primary" style="background: var(--primary-gradient); color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
            <i class="fas fa-plus"></i> Buat Susunan Panitia
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
            <table class="arsip-table">
                <thead>
                    <tr style="background: rgba(255,255,255,0.03);">
                        <th style="width: 50px;">No</th>
                        <th>Nama Kegiatan</th>
                        <th>Penanggung Jawab</th>
                        <th>Ketua Pelaksana</th>
                        <th>Jumlah Seksi</th>
                        <th>Tanggal Dibuat</th>
                        <th style="text-align: center; width: 220px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_panitia)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 50px; color: #555;">
                                <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; display:block; color: #333;"></i>
                                Belum ada data susunan panitia yang disimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($list_panitia as $idx => $p): 
                            $json = json_decode($p['panitia_json'], true) ?: [];
                            $seksi_count = count($json['seksi_seksi'] ?? []);
                            $ketua = $json['ketua_pelaksana'] ?? '-';
                            $pj = $json['penanggung_jawab'] ?? '-';
                            $tanggal_display = formatTanggalId($p['created_at'], $bulan_id);
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color); transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td data-label="No"><?php echo $idx + 1; ?></td>
                            <td data-label="Nama Kegiatan">
                                <div style="font-weight: 600; color: var(--accent-color);"><?php echo htmlspecialchars($p['nama_kegiatan']); ?></div>
                                <div style="font-size: 0.75rem; color: #666;">ID: #<?php echo $p['id']; ?></div>
                            </td>
                            <td data-label="Penanggung Jawab"><?php echo htmlspecialchars($pj); ?></td>
                            <td data-label="Ketua Pelaksana"><?php echo htmlspecialchars($ketua); ?></td>
                            <td data-label="Jumlah Seksi">
                                <span style="background: rgba(155, 89, 182, 0.1); color: #9b59b6; border: 1px solid rgba(155, 89, 182, 0.2); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $seksi_count; ?> Seksi
                                </span>
                            </td>
                            <td data-label="Tanggal Dibuat">
                                <div style="color: #eee;"><?php echo htmlspecialchars($tanggal_display); ?></div>
                            </td>
                            <td data-label="AKSI" style="text-align: center;">
                                <div style="display: flex; gap: 8px; justify-content: center;">
                                    <!-- Print -->
                                    <a href="cetak-panitia.php?id=<?php echo $p['id']; ?>" target="_blank" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(39, 174, 96, 0.1); color: #27ae60; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center;" title="Cetak Susunan Panitia">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <!-- Edit -->
                                    <a href="buat-panitia.php?edit_id=<?php echo $p['id']; ?>" style="width: 38px; height: 38px; border-radius: 10px; background: rgba(79, 172, 254, 0.1); color: #4facfe; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Edit Susunan">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Duplicate -->
                                    <a href="?duplicate=<?php echo $p['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(241, 196, 15, 0.1); color: #f1c40f; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Duplikasi data susunan panitia ini?')" 
                                       title="Duplikat">
                                        <i class="fas fa-copy"></i>
                                    </a>
                                    <!-- Delete -->
                                    <a href="?delete=<?php echo $p['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 38px; height: 38px; border-radius: 10px; background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Hapus data susunan panitia ini dari arsip?')" 
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
