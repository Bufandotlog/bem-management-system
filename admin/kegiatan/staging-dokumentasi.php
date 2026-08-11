<?php
// admin/staging-dokumentasi.php
require_once __DIR__ . '/../core/header.php';

if ($admin_role !== 'kominfo') {
    redirect('admin/core/dashboard.php', 'Anda tidak memiliki akses. Halaman ini khusus Kominfo.', 'error');
}

$periode_id = getUserPeriode();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_dokumentasi') {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $kegiatan_id = (int)$_POST['kegiatan_id'];
        
        if ($kegiatan_id <= 0) {
            $error_msg = "Pilih kegiatan terlebih dahulu.";
        } else {
            // Cek apakah sudah ada (hindari duplikat)
            $existing = dbFetchOne("SELECT id FROM arsip_dokumentasi WHERE kegiatan_id = ?", [$kegiatan_id], "i");
            if ($existing) {
                $error_msg = "Dokumentasi untuk kegiatan ini sudah di-commit sebelumnya.";
            } else {
                $docs = [];
                for ($i = 0; $i < 4; $i++) {
                    $caption = trim($_POST['doc_caption'][$i] ?? '');
                    $file = $_FILES['doc_img']['tmp_name'][$i] ?? '';
                    $foto_path = null;
                    if (!empty($file)) {
                        $file_array = [
                            'name' => $_FILES['doc_img']['name'][$i],
                            'type' => $_FILES['doc_img']['type'][$i],
                            'tmp_name' => $_FILES['doc_img']['tmp_name'][$i],
                            'error' => $_FILES['doc_img']['error'][$i],
                            'size' => $_FILES['doc_img']['size'][$i]
                        ];
                        $uploaded = uploadFile($file_array, 'umum');
                        if ($uploaded) {
                            $foto_path = $uploaded;
                        }
                    }
                    $docs[] = [
                        'foto' => $foto_path,
                        'caption' => $caption
                    ];
                }
                
                $json_data = json_encode($docs);
                dbInsert(
                    "INSERT INTO arsip_dokumentasi (kegiatan_id, periode_id, dokumentasi_json) 
                     VALUES (?, ?, ?)",
                    [
                        $kegiatan_id, $periode_id, $json_data
                    ]
                );
                $success_msg = "Dokumentasi berhasil di-commit! Data sekarang terhubung otomatis di Berita Acara.";
            }
        }
    }
}

$pending_events = dbFetchAll("
    SELECT k.* 
    FROM kegiatan k 
    LEFT JOIN arsip_dokumentasi d ON k.id = d.kegiatan_id 
    WHERE k.status = 'selesai' AND k.periode_id = ? AND d.id IS NULL
    ORDER BY k.id DESC
", [$periode_id], "i");

?>
<style>
.staging-doc-container {
    max-width: 1200px;
    margin: 0 auto;
    animation: fadeIn 0.5s ease-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.card {
    background: rgba(15, 18, 23, 0.95);
    border: 1px solid #2a3545;
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 24px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.4);
    backdrop-filter: blur(15px);
}
.card-header {
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 15px;
    color: #4A90E2;
}
.card-header h2 {
    margin: 0;
    font-size: 1.5rem;
    color: #fff;
}
.form-group label {
    display: block;
    font-size: 0.75rem;
    color: #777;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
    font-weight: 700;
}
.form-group input, .form-group select {
    width: 100%;
    background: #080808;
    border: 1px solid #2a3545;
    padding: 14px 18px;
    border-radius: 12px;
    color: #fff;
    font-size: 1rem;
    transition: all 0.3s;
}
.form-group input:focus, .form-group select:focus {
    border-color: #4A90E2;
    box-shadow: 0 0 15px rgba(74, 144, 226, 0.2);
    outline: none;
}
.photo-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
@media (min-width: 768px) {
    .photo-grid {
        grid-template-columns: 1fr 1fr;
    }
}
.photo-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid #2a3545;
    border-radius: 16px;
    padding: 20px;
    transition: transform 0.3s, box-shadow 0.3s;
}
.photo-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.3);
    border-color: rgba(74, 144, 226, 0.4);
}
.photo-preview-wrap {
    width: 100%;
    height: 180px;
    background: #0c1017;
    border-radius: 12px;
    margin-bottom: 15px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #2a3545;
}
.photo-preview-wrap img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.actions-bar {
    position: sticky;
    bottom: 20px;
    background: rgba(15, 18, 23, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px 30px;
    border-radius: 20px;
    border: 1px solid #2a3545;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.3);
    margin-top: 40px;
    z-index: 100;
}
.btn-print {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    border: none;
    color: #fff;
    padding: 14px 40px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(79, 172, 254, 0.3);
}
.btn-print:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 15px 30px rgba(79, 172, 254, 0.4);
}
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: rgba(15, 18, 23, 0.95);
    border-radius: 24px;
    border: 1px dashed #4A90E2;
}
.empty-state i {
    font-size: 4rem;
    color: #4A90E2;
    opacity: 0.5;
    margin-bottom: 20px;
}
.empty-state h3 {
    color: #fff;
    margin-bottom: 10px;
}
.empty-state p {
    color: #888;
}
</style>

<div class="staging-doc-container">
    <?php if ($success_msg): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($pending_events)): ?>
        <div class="empty-state">
            <i class="fas fa-camera-retro"></i>
            <h3>Tidak Ada Kegiatan Selesai Yang Menunggu</h3>
            <p>Semua dokumentasi acara yang berstatus selesai telah di-commit ke sistem, atau belum ada acara yang selesai.</p>
        </div>
    <?php else: ?>
        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_dokumentasi">
            
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-camera fa-2x"></i>
                    <h2>Staging Dokumentasi Acara</h2>
                </div>
                
                <div class="form-group" style="margin-bottom: 30px;">
                    <label>Pilih Kegiatan (Yang Sudah Selesai)</label>
                    <select name="kegiatan_id" required style="border-color: rgba(74, 144, 226, 0.4); font-weight: bold;">
                        <option value="">-- Pilih Kegiatan --</option>
                        <?php foreach ($pending_events as $kg): ?>
                            <option value="<?php echo $kg['id']; ?>"><?php echo htmlspecialchars($kg['nama_kegiatan']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="photo-grid">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="photo-card">
                            <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin-bottom: 10px; display: block;">Foto Slot <?php echo $i + 1; ?></label>
                            
                            <div class="photo-preview-wrap">
                                <img id="img_doc_preview_<?php echo $i; ?>" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>">
                            </div>

                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>Upload Foto</label>
                                <input type="file" name="doc_img[<?php echo $i; ?>]" accept="image/*" onchange="previewDocPhoto(<?php echo $i; ?>, this)">
                            </div>

                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Caption Foto</label>
                                <input type="text" name="doc_caption[<?php echo $i; ?>]" placeholder="Cth: Foto Bersama Pemateri">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="actions-bar">
                <button type="submit" class="btn-print" onclick="return confirm('Apakah Anda yakin dokumentasi ini sudah final dan siap di-commit ke Berita Acara?');">
                    <i class="fas fa-check-double"></i> Commit Dokumentasi
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
function previewDocPhoto(index, input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('img_doc_preview_' + index).src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
