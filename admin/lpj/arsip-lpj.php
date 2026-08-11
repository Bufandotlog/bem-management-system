<?php
// admin/arsip-lpj.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Handle LPJ deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $delete_id = (int)$_POST['delete_id'];
        $lpj = dbFetchOne("SELECT file_path FROM lpj_dokumen WHERE id = ? AND periode_id = ?", [$delete_id, $periode_id], "ii");
        if ($lpj) {
            // Delete physical file
            if (!empty($lpj['file_path'])) {
                deleteFile($lpj['file_path']);
            }
            dbQuery("DELETE FROM lpj_dokumen WHERE id = ?", [$delete_id], "i");
            $success = "LPJ berhasil dihapus.";
        } else {
            $error = "LPJ tidak ditemukan atau bukan milik periode ini.";
        }
    }
}

// Handle LPJ Consolidation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_consolidate'])) {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $selected_ids = $_POST['selected_lpj'] ?? [];
        if (empty($selected_ids)) {
            $error = "Pilih minimal satu LPJ untuk dikonsolidasikan.";
        } else {
            // Fetch selected LPJs, ordered by ministry order
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids) + 1);
            $query_params = array_merge([$periode_id], array_map('intval', $selected_ids));
            
            $query = "SELECT l.file_path, k.nama as nama_kementerian 
                      FROM lpj_dokumen l
                      JOIN kementerian k ON l.kementerian_id = k.id
                      WHERE l.periode_id = ? AND l.id IN ($placeholders)
                      ORDER BY k.urutan ASC";
                      
            $lpjs = dbFetchAll($query, $query_params, $types);
            
            $files_to_consolidate = [];
            foreach ($lpjs as $lpj) {
                if (!empty($lpj['file_path'])) {
                    $abs_path = UPLOAD_PATH . '/' . $lpj['file_path'];
                    if (file_exists($abs_path)) {
                        $files_to_consolidate[] = $abs_path;
                    }
                }
            }
            
            if (empty($files_to_consolidate)) {
                $error = "File LPJ yang dipilih tidak ditemukan secara fisik di server.";
            } else {
                $out_filename = 'MASTER_LPJ_Triwulan_BEM_' . time() . '.docx';
                $out_filepath = UPLOAD_PATH . '/lpj/' . $out_filename;
                
                // Ensure directory exists
                if (!file_exists(UPLOAD_PATH . '/lpj')) {
                    mkdir(UPLOAD_PATH . '/lpj', 0777, true);
                }
                
                // Build consolidation command
                $files_escaped = array_map('escapeshellarg', $files_to_consolidate);
                $manager_script = escapeshellarg(__DIR__ . '/../../scratch/bem_lpj_manager.py');
                $command = "python3 {$manager_script} consolidate " . escapeshellarg($out_filepath) . " " . implode(' ', $files_escaped) . " 2>&1";
                $output = shell_exec($command);
                
                if (file_exists($out_filepath)) {
                    // Send file for download immediately
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
                    header('Content-Disposition: attachment; filename="' . $out_filename . '"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($out_filepath));
                    readfile($out_filepath);
                    
                    // Clean up consolidated file from server if needed (optional, let's keep it in uploads)
                    exit();
                } else {
                    $error = "Gagal melakukan konsolidasi LPJ. Output: " . $output;
                }
            }
        }
    }
}

// Fetch filter options
$filter_triwulan = sanitizeText($_GET['filter_triwulan'] ?? '');
$filter_kementerian = (int)($_GET['filter_kementerian'] ?? 0);

// Fetch all ministries for filter dropdown
$kementerian_list = dbFetchAll("SELECT id, nama FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");

// Build main LPJ query
$query_parts = [];
$query_params = [$periode_id];
$query_types = "i";

if (!empty($filter_triwulan)) {
    $query_parts[] = "l.triwulan = ?";
    $query_params[] = $filter_triwulan;
    $query_types .= "s";
}

if ($filter_kementerian > 0) {
    $query_parts[] = "l.kementerian_id = ?";
    $query_params[] = $filter_kementerian;
    $query_types .= "i";
}

$where_clause = "";
if (!empty($query_parts)) {
    $where_clause = " AND " . implode(" AND ", $query_parts);
}

$query_lpj = "SELECT l.*, k.nama as nama_kementerian, k.logo as logo_kementerian
              FROM lpj_dokumen l
              JOIN kementerian k ON l.kementerian_id = k.id
              WHERE l.periode_id = ? $where_clause
              ORDER BY l.updated_at DESC";

$lpj_list = dbFetchAll($query_lpj, $query_params, $query_types);

// Kelompokkan triwulan berdasarkan filter atau tampilkan semua default
$groups_to_display = [];
if (!empty($filter_triwulan)) {
    $groups_to_display = [$filter_triwulan];
} else {
    $groups_to_display = ['I', 'II', 'MUBESMA'];
    // Tambahkan triwulan lain jika ada di database (agar tidak hilang data)
    foreach ($lpj_list as $lpj) {
        if (!in_array($lpj['triwulan'], $groups_to_display)) {
            $groups_to_display[] = $lpj['triwulan'];
        }
    }
}
?>

<style>
    /* ===== GRID FILTER BAR ===== */
    .filter-bar {
        background: #0f1217;
        border: 1px solid #2a3545;
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 25px;
        display: flex;
        align-items: flex-end;
        gap: 15px;
        flex-wrap: wrap;
    }
    /* ===== FLOATING INPUT ===== */
    .filter-bar .floating-group { margin-bottom: 0; flex: 1; min-width: 200px; position: relative; }
    .floating-input {
        width: 100%; padding: 18px 14px 6px 14px; background: #080c14;
        border: 1px solid #2a3545; border-radius: 10px; color: #ffffff; font-size: 0.92rem;
        box-sizing: border-box; transition: all 0.2s ease;
    }
    .floating-input::placeholder { color: transparent; }
    .floating-label {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        font-size: 0.88rem; color: #9ea7b4; pointer-events: none; transition: all 0.2s ease;
        background: transparent; padding: 0 4px; font-weight: 500; z-index: 10;
    }
    input.floating-input:focus ~ .floating-label, input.floating-input:not(:placeholder-shown) ~ .floating-label {
        top: 0; transform: translateY(-50%) scale(0.82); transform-origin: left top;
        background: #080c14; color: #ffffff; font-weight: 700; border-radius: 4px;
    }
    .floating-input:focus { outline: none; border-color: #4A90E2; box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
    select.floating-input { 
        padding: 18px 35px 6px 14px !important; 
        appearance: none; 
        -moz-appearance: none; 
        -webkit-appearance: none; 
        background-color: #080c14;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ea7b4' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
    }
    select.floating-input[data-has-value="false"]:not(:focus) { color: transparent; }
    select.floating-input:focus ~ .floating-label,
    select.floating-input[data-has-value="true"] ~ .floating-label {
        top: 0; transform: translateY(-50%) scale(0.82); transform-origin: left top;
        background: #080c14; color: #ffffff; font-weight: 700; border-radius: 4px;
    }
    .filter-bar .btn-filter-group {
        display: flex;
        gap: 10px;
    }

    /* ===== ARSIP CARD STYLES ===== */
    .lpj-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
    }
    .lpj-card {
        background: #0f1217;
        border: 1px solid #2a3545;
        border-radius: 14px;
        overflow: hidden;
        transition: all 0.2s;
        display: flex;
        flex-direction: column;
    }
    .lpj-card:hover {
        border-color: #4A90E2;
        box-shadow: 0 5px 15px rgba(74, 144, 226, 0.15);
    }
    .lpj-card.lpj-draft {
        border-style: dashed;
        background: rgba(15, 18, 23, 0.6);
    }
    .lpj-card-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        position: relative;
    }
    .lpj-card-header .logo-container {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        background: #1e2633;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .lpj-card-header .logo-container img {
        max-width: 100%;
        max-height: 100%;
        object-fit: cover;
    }
    .lpj-card-header h3 {
        margin: 0;
        font-size: 0.95rem;
        color: #fff;
        line-height: 1.3;
    }
    .lpj-card-body {
        padding: 16px;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 10px;
        font-size: 0.85rem;
        color: #aaa;
    }
    .lpj-card-body .info-row {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 10px 12px;
        background: rgba(255,255,255,0.03);
        border-radius: 8px;
    }
    .lpj-card-body .info-value {
        color: #fff;
        font-weight: bold;
        font-size: 0.95rem;
    }
    .lpj-card-actions {
        padding: 16px;
        background: rgba(0,0,0,0.15);
        border-top: 1px solid rgba(255,255,255,0.05);
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .lpj-card-actions .actions-top {
        display: flex;
        justify-content: center;
        align-items: center;
        text-align: center;
        padding-bottom: 8px;
        border-bottom: 1px dashed rgba(255,255,255,0.1);
    }
    .lpj-card-actions .actions-buttons {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .lpj-card-actions .actions-buttons > a, .lpj-card-actions .actions-buttons > button {
        width: 100%;
        justify-content: center;
        display: flex;
        align-items: center;
        padding: 8px;
        font-size: 0.8rem;
    }
    .lpj-checkbox-label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: #8BB9F0;
        cursor: pointer;
        font-size: 0.85rem;
        user-select: none;
        font-weight: 500;
    }
    
    .status-badge {
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 20px;
        font-weight: bold;
    }
    .status-badge.status-submitted {
        background: rgba(40, 167, 69, 0.2);
        color: #28a745;
        border: 1px solid #28a745;
    }
    .status-badge.status-draft {
        background: rgba(255, 193, 7, 0.2);
        color: #ffc107;
        border: 1px solid #ffc107;
    }

    /* ===== GROUP STYLES ===== */
    .lpj-group-section {
        margin-bottom: 45px;
    }
    .lpj-group-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #2a3545;
        position: relative;
    }
    .lpj-group-header::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 100px;
        height: 2px;
        background: #4A90E2;
    }
    .lpj-group-title {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .lpj-group-title i {
        font-size: 1.4rem;
        color: #4A90E2;
    }
    .lpj-group-title h2 {
        font-size: 1.25rem;
        color: #fff;
        margin: 0;
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    .lpj-group-count {
        font-size: 0.78rem;
        background: rgba(74, 144, 226, 0.15);
        color: #8BB9F0;
        border: 1px solid rgba(74, 144, 226, 0.3);
        padding: 2px 10px;
        border-radius: 20px;
        font-weight: bold;
    }
    
    .empty-group-placeholder {
        background: rgba(15, 18, 23, 0.4);
        border: 1px dashed #2a3545;
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        transition: all 0.2s;
    }
    .empty-group-placeholder:hover {
        border-color: rgba(74, 144, 226, 0.4);
        background: rgba(15, 18, 23, 0.6);
    }
    .empty-group-placeholder i {
        font-size: 2.2rem;
        color: #55657e;
    }
    .empty-group-placeholder p {
        color: #7a8b9e;
        font-size: 0.85rem;
        margin: 0;
    }
    .btn-buat-quick {
        font-size: 0.78rem;
        color: #4A90E2;
        background: rgba(74, 144, 226, 0.1);
        border: 1px solid rgba(74, 144, 226, 0.2);
        padding: 6px 14px;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        font-weight: bold;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-buat-quick:hover {
        background: #4A90E2;
        color: #fff;
        border-color: #4A90E2;
        transform: translateY(-1px);
    }
    
    @media (max-width: 768px) {
        .lpj-group-title h2 {
            font-size: 1rem;
        }
        .lpj-group-title i {
            font-size: 1.2rem;
        }
    }
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
    <div>
        <h1><i class="fas fa-archive"></i> Arsip Laporan Pertanggungjawaban (LPJ)</h1>
    </div>
    <div style="display: flex; gap: 10px;">
        <button type="submit" form="consolidateForm" class="btn-primary" style="background: #28a745;"><i class="fas fa-compress-arrows-alt"></i> Konsolidasi LPJ Terpilih</button>
        <a href="buat-lpj.php" class="btn-primary"><i class="fas fa-plus"></i> Buat LPJ Baru</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Filter Bar -->
<form method="GET" class="filter-bar">
    <div class="floating-group">
        <select name="filter_triwulan" class="floating-input" id="filter_triwulan" data-has-value="<?php echo !empty($filter_triwulan) ? 'true' : 'false'; ?>" onchange="this.setAttribute('data-has-value', this.value !== '' ? 'true' : 'false')">
            <option value="" hidden>Filter Triwulan</option>
            <option value="I" <?php echo $filter_triwulan === 'I' ? 'selected' : ''; ?>>TRIWULAN I</option>
            <option value="II" <?php echo $filter_triwulan === 'II' ? 'selected' : ''; ?>>TRIWULAN II</option>
            <option value="MUBESMA" <?php echo $filter_triwulan === 'MUBESMA' ? 'selected' : ''; ?>>MUBESMA</option>
        </select>
        <label for="filter_triwulan" class="floating-label">Filter Triwulan</label>
    </div>
    <div class="floating-group">
        <select name="filter_kementerian" class="floating-input" id="filter_kementerian" data-has-value="<?php echo $filter_kementerian > 0 ? 'true' : 'false'; ?>" onchange="this.setAttribute('data-has-value', this.value !== '0' ? 'true' : 'false')">
            <option value="0" hidden>Filter Kementerian</option>
            <?php foreach ($kementerian_list as $k): ?>
                <option value="<?php echo $k['id']; ?>" <?php echo $filter_kementerian === $k['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nama']); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="filter_kementerian" class="floating-label">Filter Kementerian</label>
    </div>
    <div class="btn-filter-group">
        <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Filter</button>
        <a href="arsip-lpj.php" class="btn-secondary" style="height: 38px; display: inline-flex; align-items: center;"><i class="fas fa-sync-alt"></i> Reset</a>
    </div>
</form>

<!-- Consolidation Form wrapper -->
<form method="POST" id="consolidateForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_consolidate" value="1">

    <?php if (empty($lpj_list)): ?>
        <div style="text-align: center; padding: 40px; background: #0f1217; border: 1px solid #2a3545; border-radius: 12px; margin-bottom: 30px;">
            <i class="fas fa-file-excel" style="font-size: 3rem; color: #666; margin-bottom: 15px;"></i>
            <p style="color: #888;">Belum ada dokumen LPJ yang sesuai dengan filter.</p>
            <a href="buat-lpj.php" class="btn-primary" style="margin-top: 10px;"><i class="fas fa-plus"></i> Buat LPJ Sekarang</a>
        </div>
    <?php else: ?>
        <?php 
        // Group the LPJ items
        $grouped_lpj = [];
        foreach ($groups_to_display as $g) {
            $grouped_lpj[$g] = [];
        }
        foreach ($lpj_list as $lpj) {
            $tw = $lpj['triwulan'];
            $grouped_lpj[$tw][] = $lpj;
        }
        
        foreach ($groups_to_display as $group_key):
            $group_items = $grouped_lpj[$group_key] ?? [];
            
            // Define display name for group
            $group_name = '';
            $group_icon = 'fas fa-calendar-alt';
            if ($group_key === 'I') {
                $group_name = 'TRIWULAN I';
                $group_icon = 'fas fa-calendar-minus';
            } elseif ($group_key === 'II') {
                $group_name = 'TRIWULAN II';
                $group_icon = 'fas fa-calendar-plus';
            } elseif ($group_key === 'MUBESMA') {
                $group_name = 'MUBESMA';
                $group_icon = 'fas fa-scroll';
            } else {
                $group_name = 'TRIWULAN ' . htmlspecialchars($group_key);
            }
            
            // Skip empty groups if we have filter active
            if (empty($group_items) && (!empty($filter_triwulan) || $filter_kementerian > 0)) {
                continue;
            }
        ?>
            <div class="lpj-group-section">
                <div class="lpj-group-header">
                    <div class="lpj-group-title">
                        <i class="<?php echo $group_icon; ?>"></i>
                        <h2><?php echo $group_name; ?></h2>
                        <span class="lpj-group-count"><?php echo count($group_items); ?> Dokumen</span>
                    </div>
                </div>
                
                <?php if (empty($group_items)): ?>
                    <div class="empty-group-placeholder">
                        <i class="far fa-folder-open"></i>
                        <p>Belum ada LPJ kementerian yang dibuat untuk periode ini.</p>
                        <a href="buat-lpj.php?triwulan=<?php echo urlencode($group_key); ?>" class="btn-buat-quick"><i class="fas fa-plus"></i> Mulai Buat</a>
                    </div>
                <?php else: ?>
                    <div class="lpj-grid">
                        <?php foreach ($group_items as $lpj): 
                            $is_draft = $lpj['status'] === 'draft';
                            $logo = $lpj['logo_kementerian'] ? uploadUrl($lpj['logo_kementerian']) : BASE_URL . 'assets/images/default-logo.png';
                        ?>
                            <div class="lpj-card <?php echo $is_draft ? 'lpj-draft' : ''; ?>">
                                <div class="lpj-card-header">
                                    <div class="logo-container">
                                        <img src="<?php echo $logo; ?>" alt="Logo">
                                    </div>
                                    <div>
                                        <h3><?php echo htmlspecialchars($lpj['nama_kementerian']); ?></h3>
                                        <span class="status-badge <?php echo $is_draft ? 'status-draft' : 'status-submitted'; ?>">
                                            <?php echo $is_draft ? 'Draft' : 'Submitted'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="lpj-card-body">
                                    <div class="info-row">
                                        <span>Triwulan:</span>
                                        <span class="info-value">
                                            <?php 
                                            if ($lpj['triwulan'] === 'MUBESMA') {
                                                echo 'MUBESMA';
                                            } else {
                                                echo 'Triwulan ' . htmlspecialchars($lpj['triwulan']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span>Diperbarui:</span>
                                        <span class="info-value"><?php echo formatTanggal($lpj['updated_at'], true); ?></span>
                                    </div>
                                </div>
                                
                                <div class="lpj-card-actions">
                                    <div class="actions-top">
                                        <?php if (!$is_draft): ?>
                                            <label class="lpj-checkbox-label">
                                                <input type="checkbox" name="selected_lpj[]" value="<?php echo $lpj['id']; ?>">
                                                <span>Pilih untuk Konsolidasi</span>
                                            </label>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: #ffc107; font-style: italic;"><i class="fas fa-exclamation-triangle"></i> Selesaikan draft untuk konsolidasi</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="actions-buttons">
                                        <a href="buat-lpj.php?id=<?php echo $lpj['id']; ?>" class="btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                        <a href="cetak-lpj.php?id=<?php echo $lpj['id']; ?>" target="_blank" class="btn-buat" style="background: #9b59b6;"><i class="fas fa-eye"></i> Preview &amp; PDF</a>
                                        <?php if (!empty($lpj['file_path'])): ?>
                                            <a href="<?php echo uploadUrl($lpj['file_path']); ?>" class="btn-buat" style="background: #4A90E2;" download><i class="fas fa-download"></i> Docx</a>
                                        <?php else: ?>
                                            <button type="button" class="btn-buat" style="background: #4A90E2; opacity: 0.5; cursor: not-allowed;" title="File Docx belum di-generate (Silakan Edit & Simpan ulang)" disabled><i class="fas fa-download"></i> Docx</button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-delete" onclick="confirmDelete(<?php echo $lpj['id']; ?>)"><i class="fas fa-trash"></i> Hapus</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</form>

<!-- Delete Form -->
<form method="POST" id="deleteForm" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_delete" value="1">
    <input type="hidden" name="delete_id" id="deleteIdInput">
</form>

<script>
    function confirmDelete(id) {
        if (confirm('Yakin ingin menghapus dokumen LPJ ini? File fisik docx juga akan dihapus.')) {
            document.getElementById('deleteIdInput').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
