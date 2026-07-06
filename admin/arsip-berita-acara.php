<?php
// admin/arsip-berita-acara.php
require_once __DIR__ . '/config.php';

// Check login and role sekretaris
if (!isLoggedIn()) {
    redirect('admin/login.php');
    exit();
}
requireSekretaris();

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Get Search query
$search = trim($_GET['search'] ?? '');
$params = [$periode_id];
$types = "i";
$sql_query = "SELECT * FROM arsip_berita_acara WHERE periode_id = ?";

if ($search !== '') {
    $sql_query .= " AND (nomor_berita LIKE ? OR nama_kegiatan LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$sql_query .= " ORDER BY id DESC";
$list_berita_acara = dbFetchAll($sql_query, $params, $types);

// Handle Export Excel (Must be done before header.php is loaded to prevent HTML pollution)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Arsip_Berita_Acara_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Pragma: public");
    
    echo "<table border='1'>";
    echo "<tr><th colspan='6' style='font-size:16px; font-weight:bold; text-align:center;'>Arsip Berita Acara Kegiatan BEM</th></tr>";
    echo "<tr><th>No</th><th>Nomor Berita Acara</th><th>Nama Kegiatan</th><th>Tanggal Kegiatan</th><th>Tempat</th><th>Waktu</th></tr>";
    if (empty($list_berita_acara)) {
        echo "<tr><td colspan='6' style='text-align:center;'>Belum ada data berita acara</td></tr>";
    } else {
        $no = 1;
        foreach ($list_berita_acara as $item) {
            echo "<tr>";
            echo "<td>" . $no++ . "</td>";
            echo "<td>" . htmlspecialchars($item['nomor_berita']) . "</td>";
            echo "<td>" . htmlspecialchars($item['nama_kegiatan']) . "</td>";
            echo "<td>" . htmlspecialchars($item['tanggal_kegiatan']) . "</td>";
            echo "<td>" . htmlspecialchars($item['tempat']) . "</td>";
            echo "<td>" . htmlspecialchars($item['waktu']) . "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    exit();
}

// Handle Delete Berita Acara
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_hapus = (int)$_GET['hapus'];
        $target = dbFetchOne("SELECT * FROM arsip_berita_acara WHERE id = ? AND periode_id = ?", [$id_hapus, $periode_id], "ii");
        
        if ($target) {
            $konten = json_decode((string)$target['konten_json'], true) ?: [];
            
            // Delete signature files if any
            if (!empty($konten['ketua_bem_ttd'])) deleteFile($konten['ketua_bem_ttd']);
            if (!empty($konten['sekretaris_bem_ttd'])) deleteFile($konten['sekretaris_bem_ttd']);
            if (!empty($konten['warek_ttd'])) deleteFile($konten['warek_ttd']);
            
            // Delete documentation images
            if (isset($konten['dokumentasi']) && is_array($konten['dokumentasi'])) {
                foreach ($konten['dokumentasi'] as $doc) {
                    if (!empty($doc['image'])) {
                        deleteFile($doc['image']);
                    }
                }
            }
            
            dbQuery("DELETE FROM arsip_berita_acara WHERE id = ?", [$id_hapus], "i");
            auditLog('DELETE', 'arsip_berita_acara', $id_hapus, 'Menghapus berita acara: ' . $target['nomor_berita']);
            $success = "Arsip berita acara beserta seluruh file fotonya berhasil dihapus.";
            
            // Refresh list
            $list_berita_acara = dbFetchAll($sql_query, $params, $types);
        } else {
            $error = "Data tidak ditemukan atau akses ditolak.";
        }
    } else {
        $error = "Token keamanan tidak valid.";
    }
}

// Load UI layout
$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';


$css = "
.ba-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 15px; flex-wrap: wrap; }
.ba-actions { display: flex; gap: 12px; }
.btn-primary { background: var(--primary-gradient); color: #111; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 242, 254, 0.2); transition: all 0.2s; border: none; cursor: pointer; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0, 242, 254, 0.4); }
.btn-export { background: #27ae60; color: white; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; border: none; cursor: pointer; }
.btn-export:hover { background: #219653; transform: translateY(-2px); }
.search-form { display: flex; gap: 10px; width: 100%; max-width: 400px; }
.search-input { background: #0a0c10; border: 1.5px solid var(--border-color); border-radius: 12px; padding: 10px 16px; color: white; flex-grow: 1; font-size: 0.9rem; }
.search-input:focus { border-color: var(--accent-color); outline: none; }
.btn-search { background: #1a2230; border: 1px solid var(--border-color); color: #8BB9F0; padding: 0 16px; border-radius: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.btn-search:hover { background: #243044; color: white; }
.ba-table-container { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow-premium); }
.ba-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.95rem; }
.ba-table th { background: rgba(74, 144, 226, 0.05); color: #8BB9F0; font-weight: 600; padding: 16px 20px; border-bottom: 1px solid var(--border-color); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; }
.ba-table td { padding: 16px 20px; border-bottom: 1px solid var(--border-color); color: #ddd; vertical-align: middle; }
.ba-table tr:last-child td { border-bottom: none; }
.ba-table tr:hover td { background: rgba(255, 255, 255, 0.01); }
.action-links { display: flex; gap: 8px; }
.btn-act { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 0.9rem; transition: all 0.2s; border: none; cursor: pointer; }
.btn-act-view { background: rgba(74, 144, 226, 0.1); color: #4A90E2; }
.btn-act-view:hover { background: #4A90E2; color: white; }
.btn-act-edit { background: rgba(241, 196, 15, 0.1); color: #f1c40f; }
.btn-act-edit:hover { background: #f1c40f; color: #111; }
.btn-act-del { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
.btn-act-del:hover { background: #e74c3c; color: white; }
";

echo "<style>{$css}</style>";
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <div class="ba-header">
        <div style="display: flex; flex-direction: column; gap: 5px;">
            <h1 style="font-weight: 700; letter-spacing: -0.5px; background: var(--primary-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: flex; align-items: center; gap: 15px; margin: 0;">
                <i class="fas fa-file-alt"></i>
                <span>Arsip Berita Acara</span>
            </h1>
            <span style="color: var(--text-muted); font-size: 0.85rem;">Daftar berita acara dan laporan kegiatan yang telah diarsipkan.</span>
        </div>
        <div class="ba-actions">
            <a href="buat-berita-acara.php" class="btn-primary">
                <i class="fas fa-plus"></i>
                <span>Buat Berita Acara</span>
            </a>
            <a href="arsip-berita-acara.php?export=excel&search=<?php echo urlencode($search); ?>" class="btn-export">
                <i class="fas fa-file-excel"></i>
                <span>Export Excel</span>
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; background: rgba(46,204,113,0.1); border: 1px solid #2ecc71; color: #2ecc71; padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px; background: rgba(231,76,60,0.1); border: 1px solid #e74c3c; color: #ff6b6b; padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Search Form -->
    <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
        <form method="GET" class="search-form">
            <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nomor atau nama kegiatan...">
            <button type="submit" class="btn-search"><i class="fas fa-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="arsip-berita-acara.php" class="btn-search" style="text-decoration:none;"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Container -->
    <div class="ba-table-container">
        <table class="ba-table">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">No</th>
                    <th>Nomor Berita Acara</th>
                    <th>Nama Kegiatan</th>
                    <th>Tanggal Kegiatan</th>
                    <th>Tempat</th>
                    <th>Waktu</th>
                    <th style="width: 150px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($list_berita_acara)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 40px 20px;">
                            <i class="fas fa-folder-open" style="font-size: 2.5rem; margin-bottom: 15px; display: block; color: #3a4b64;"></i>
                            <span>Belum ada data berita acara atau tidak ada yang sesuai dengan pencarian.</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($list_berita_acara as $item): 
                    ?>
                        <tr>
                            <td style="text-align: center; font-weight: bold; color: var(--text-muted);"><?php echo $no++; ?></td>
                            <td style="font-weight: bold; color: #8BB9F0;"><?php echo htmlspecialchars($item['nomor_berita']); ?></td>
                            <td><?php echo htmlspecialchars($item['nama_kegiatan']); ?></td>
                            <td><?php echo htmlspecialchars($item['tanggal_kegiatan']); ?></td>
                            <td><?php echo htmlspecialchars($item['tempat']); ?></td>
                            <td><?php echo htmlspecialchars($item['waktu']); ?></td>
                            <td style="text-align: center;">
                                <div class="action-links" style="justify-content: center;">
                                    <a href="cetak-berita-acara.php?id=<?php echo $item['id']; ?>" class="btn-act btn-act-view" title="Cetak / Pratinjau">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <a href="buat-berita-acara.php?edit=<?php echo $item['id']; ?>" class="btn-act btn-act-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn-act btn-act-del" title="Hapus" onclick="confirmDelete(<?php echo $item['id']; ?>, '<?php echo addslashes($item['nomor_berita']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmDelete(id, nomor) {
    if (confirm("Apakah Anda yakin ingin menghapus arsip Berita Acara \"" + nomor + "\" beserta file di dalamnya secara permanen?")) {
        window.location.href = "arsip-berita-acara.php?hapus=" + id + "&csrf_token=<?php echo $_SESSION['csrf_token']; ?>";
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
