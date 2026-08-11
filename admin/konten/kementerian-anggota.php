<?php
// admin/kementerian-anggota.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token + validasi di semua POST handler
//   CHANGED: Filter periode_id di query kementerian
//   CHANGED: Validasi kepemilikan delete_ids dengan AND kementerian_id
//   CHANGED: sanitizeText() untuk nama dan jabatan
//   CHANGED: Batasi max 100 anggota
//   CHANGED: Redirect ke admin/kementerian-anggota.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/../core/header.php';

$kementerian_id = (int) ($_GET['id'] ?? 0);
if (!$kementerian_id) {
    redirect('admin/konten/kepengurusan.php', 'ID kementerian tidak valid', 'error');
    exit();
}

// Ambil data kementerian — filter periode_id
$kementerian = dbFetchOne(
    "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
    [$kementerian_id, $active_periode], "ii"
);
if (!$kementerian) {
    redirect('admin/konten/kepengurusan.php', 'Kementerian tidak ditemukan atau akses ditolak', 'error');
    exit();
}

// Ambil anggota
$anggota = dbFetchAll(
    "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? ORDER BY urutan",
    [$kementerian_id], "i"
);

// Ambil list semua akun terdaftar untuk JS
// Jika kementerian ini BUKAN KOMINFO, exclude user dengan role 'kominfo'
$is_kominfo_kementerian = (stripos($kementerian['nama'], 'kominfo') !== false);
if ($is_kominfo_kementerian) {
    // Untuk KOMINFO: hanya tampilkan user dengan role 'kominfo'
    $list_akun = dbFetchAll("SELECT id, nama, username FROM users WHERE is_active = 1 AND role = 'kominfo' AND (periode_id = ? OR periode_id IS NULL) ORDER BY nama ASC", [$active_periode]);
} else {
    // Untuk kementerian lain: exclude user kominfo
    $list_akun = dbFetchAll("SELECT id, nama, username FROM users WHERE is_active = 1 AND role != 'kominfo' AND role != 'superadmin' AND (periode_id = ? OR periode_id IS NULL) ORDER BY nama ASC", [$active_periode]);
}
$digunakan = array_filter(array_column($anggota, 'user_id'));

// ============================================
// PROSES SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify()) {
        redirect('admin/konten/kementerian-anggota.php?id=' . $kementerian_id, 'Request tidak valid.', 'error');
        exit();
    }

    // Hapus anggota tunggal via action=delete
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $anggota_id = (int) $_POST['anggota_id'];
        // Validasi kepemilikan — harus milik kementerian ini
        $anggota_data = dbFetchOne(
            "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
            [$anggota_id, $kementerian_id], "ii"
        );
        if ($anggota_data) {
            if (!empty($anggota_data['foto'])) deleteFile($anggota_data['foto']);
            dbQuery("DELETE FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                    [$anggota_id, $kementerian_id], "ii");
        }
        redirect('admin/konten/kementerian-anggota.php?id=' . $kementerian_id, 'Anggota berhasil dihapus', 'success');
        exit();
    }

    // Hapus yang dicentang (delete_ids[])
    $deleted_ids = [];
    if (!empty($_POST['delete_ids'])) {
        foreach ($_POST['delete_ids'] as $delete_id) {
            $delete_id = (int) $delete_id;
            // Validasi kepemilikan sebelum hapus
            $old = dbFetchOne(
                "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                [$delete_id, $kementerian_id], "ii"
            );
            if (!$old) continue; // Bukan miliknya — skip
            if (!empty($old['foto'])) deleteFile($old['foto']);
            dbQuery("DELETE FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                    [$delete_id, $kementerian_id], "ii");
            $deleted_ids[] = $delete_id;
        }
    }

    // Simpan / update setiap baris — batasi max 100 anggota
    $user_ids = array_slice($_POST['user_id'] ?? [], 0, 100);

    foreach ($user_ids as $index => $user_id) {
        $user_id = !empty($user_id) ? (int)$user_id : null;
        
        if (!$user_id) continue;
        
        $u = dbFetchOne("SELECT nama FROM users WHERE id = ?", [$user_id], "i");
        $nama = $u['nama'] ?? '';

        if (empty($nama)) continue;

        $jabatan    = sanitizeText($_POST['jabatan'][$index] ?? '', 100);
        $anggota_id = (int) ($_POST['anggota_id'][$index] ?? 0);

        if (in_array($anggota_id, $deleted_ids)) continue;

        $foto = '';
        if ($anggota_id > 0) {
            $existing = dbFetchOne(
                "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                [$anggota_id, $kementerian_id], "ii"
            );
            $foto = $existing['foto'] ?? '';
        }

        $ada_upload = isset($_FILES['foto']['name'][$index])
                   && $_FILES['foto']['error'][$index] === UPLOAD_ERR_OK;

        if ($ada_upload) {
            $file = [
                'name'     => $_FILES['foto']['name'][$index],
                'type'     => $_FILES['foto']['type'][$index],
                'tmp_name' => $_FILES['foto']['tmp_name'][$index],
                'error'    => $_FILES['foto']['error'][$index],
                'size'     => $_FILES['foto']['size'][$index],
            ];
            $upload_result = uploadFile($file, 'struktur');
            if ($upload_result) {
                if (!empty($foto)) deleteFile($foto);
                $foto = $upload_result;
            }
        }

        if ($anggota_id > 0) {
            dbQuery(
                "UPDATE anggota_kementerian SET user_id=?, nama=?, jabatan=?, foto=?, urutan=? WHERE id=? AND kementerian_id=?",
                [$user_id, $nama, $jabatan, $foto, $index, $anggota_id, $kementerian_id],
                "isssiii"
            );
        } else {
            dbQuery(
                "INSERT INTO anggota_kementerian (periode_id, created_by, kementerian_id, user_id, nama, jabatan, foto, urutan)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$kementerian['periode_id'], $_SESSION['admin_id'], $kementerian_id, $user_id, $nama, $jabatan, $foto, $index],
                "iiiisssi"
            );
        }
    }

    auditLog('UPDATE', 'anggota_kementerian', $kementerian_id, 'Edit anggota kementerian: ' . $kementerian['nama']);
    redirect('admin/konten/kementerian-anggota.php?id=' . $kementerian_id, 'Data anggota berhasil disimpan!', 'success');
    exit();
}
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1>
        <i class="fas fa-users"></i>
        Anggota: <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>
    </h1>
    <p>Kelola daftar anggota kementerian ini</p>
</div>

<!-- ===== HEADER ACTIONS ===== -->
<div class="header-actions">
    <a href="kementerian-edit.php?id=<?php echo $kementerian_id; ?>" class="btn-secondary">
        <i class="fas fa-edit"></i> Edit Kementerian
    </a>
    <a href="kepengurusan.php" class="btn-secondary">
        <i class="fas fa-arrow-left"></i> Kembali
    </a>
</div>

<?php flashMessage(); ?>

<!-- ===== FORM ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form">

    <?php echo csrfField(); ?>

    <div id="anggotaContainer">
        <?php if (!empty($anggota)): ?>
            <?php foreach ($anggota as $index => $a): ?>
            <div class="anggota-item" data-id="<?php echo (int)$a['id']; ?>">
                <input type="hidden" name="anggota_id[]" value="<?php echo (int)$a['id']; ?>">

                <div class="anggota-photo-preview">
                    <?php if (!empty($a['foto'])): ?>
                        <img src="<?php echo uploadUrl($a['foto']); ?>"
                             class="preview-img"
                             alt="Foto <?php echo htmlspecialchars($a['nama'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php else: ?>
                        <div class="preview-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="anggota-fields">
                    <div class="tpl-picker" style="margin-bottom:10px;">
                        <?php 
                            $akun_nama = '';
                            foreach($list_akun as $akun) {
                                if ($a['user_id'] == $akun['id']) {
                                    $akun_nama = $akun['nama'];
                                    break;
                                }
                            }
                        ?>
                        <i class="fas fa-search tpl-search-icon"></i>
                        <input type="text" class="tpl-search-input form-control tpl-display-input" placeholder="Cari atau pilih anggota..." value="<?php echo htmlspecialchars($akun_nama); ?>" autocomplete="off" onfocus="showTplAnggota(this)" onkeyup="filterTplAnggota(this)">
                        <input type="hidden" name="user_id[]" class="tpl-hidden-input" value="<?php echo htmlspecialchars($a['user_id']); ?>">
                        <div class="tpl-results">
                            <div class="tpl-item" onclick='selectTplAnggota(this, "", "")'>
                                <div class="tpl-item-label" style="color:#aaa;">-- Kosongkan (Batal Pilih) --</div>
                            </div>
                            <?php foreach($list_akun as $akun): 
                                if (in_array($akun['id'], $digunakan) && $a['user_id'] != $akun['id']) continue;
                            ?>
                            <div class="tpl-item" onclick='selectTplAnggota(this, <?php echo json_encode($akun["id"]); ?>, <?php echo json_encode($akun["nama"]); ?>)'>
                                <div class="tpl-item-label"><?php echo htmlspecialchars($akun['nama']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <select name="jabatan[]" required style="background: var(--input-bg); border: 1.5px solid var(--border-color); border-radius: 10px; padding: 12px 15px; color: var(--text-main); font-size: 0.95rem;">
                        <option value="">-- Pilih Jabatan --</option>
                        <?php 
                        $jab_opts = [
                            "Ketua Umum " . $kementerian['nama'],
                            "Sekretaris " . $kementerian['nama'],
                            "Bendahara " . $kementerian['nama'],
                            "Anggota " . $kementerian['nama']
                        ];
                        foreach($jab_opts as $opt) {
                            $sel = ($a['jabatan'] === $opt) ? 'selected' : '';
                            echo '<option value="'.htmlspecialchars($opt, ENT_QUOTES, 'UTF-8').'" '.$sel.'>'.htmlspecialchars($opt, ENT_QUOTES, 'UTF-8').'</option>';
                        }
                        if (!in_array($a['jabatan'], $jab_opts) && !empty($a['jabatan'])) {
                            echo '<option value="'.htmlspecialchars($a['jabatan'], ENT_QUOTES, 'UTF-8').'" selected>'.htmlspecialchars($a['jabatan'], ENT_QUOTES, 'UTF-8').' (Custom)</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="anggota-foto-input">
                    <input type="file" name="foto[]" accept="image/*">
                    <?php if (!empty($a['foto'])): ?>
                        <span class="foto-note">
                            <i class="fas fa-info-circle"></i>
                            Kosongkan jika tidak ingin mengubah foto
                        </span>
                    <?php endif; ?>
                </div>

                <div class="anggota-actions">
                    <label class="checkbox-label" title="Centang untuk menghapus saat disimpan">
                        <input type="checkbox" name="delete_ids[]" value="<?php echo (int)$a['id']; ?>">
                        <span>Hapus</span>
                    </label>
                    <button type="button" class="btn-remove"
                            onclick="hapusAnggotaItem(this)"
                            title="Hilangkan dari form">×</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="anggota-item">
                <input type="hidden" name="anggota_id[]" value="0">
                <div class="anggota-photo-preview">
                    <div class="preview-placeholder"><i class="fas fa-user"></i></div>
                </div>
                <div class="anggota-fields">
                    <select name="user_id[]" class="form-control" style="margin-bottom:10px;">
                        <option value="">-- Pilih Akun Terdaftar --</option>
                        <?php foreach($list_akun as $akun): 
                            if (in_array($akun['id'], $digunakan)) continue;
                        ?>
                            <option value="<?php echo $akun['id']; ?>" data-nama="<?php echo htmlspecialchars($akun['nama'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($akun['nama']); ?> (@<?php echo htmlspecialchars($akun['username']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="jabatan[]" required style="background: var(--input-bg); border: 1.5px solid var(--border-color); border-radius: 10px; padding: 12px 15px; color: var(--text-main); font-size: 0.95rem;">
                        <option value="">-- Pilih Jabatan --</option>
                        <?php 
                        $jab_opts = [
                            "Ketua Umum " . $kementerian['nama'],
                            "Sekretaris " . $kementerian['nama'],
                            "Bendahara " . $kementerian['nama'],
                            "Anggota " . $kementerian['nama']
                        ];
                        foreach($jab_opts as $opt) {
                            echo '<option value="'.htmlspecialchars($opt, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($opt, ENT_QUOTES, 'UTF-8').'</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="anggota-foto-input">
                    <input type="file" name="foto[]" accept="image/*">
                </div>
                <div class="anggota-actions">
                    <button type="button" class="btn-remove"
                            onclick="hapusAnggotaItem(this)"
                            title="Hilangkan dari form">×</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" class="btn-add" onclick="tambahAnggota()">
        <i class="fas fa-user-plus"></i> Tambah Anggota
    </button>

    <div class="form-actions">
        <a href="kepengurusan.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan Semua
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function tambahAnggota() {
    const akunOptions = `
        <div class="tpl-item" onclick='selectTplAnggota(this, "", "")'>
            <div class="tpl-item-label" style="color:#aaa;">-- Kosongkan (Batal Pilih) --</div>
        </div>
        <?php foreach($list_akun as $akun): 
            if (in_array($akun['id'], $digunakan)) continue;
        ?>
            <div class="tpl-item" onclick='selectTplAnggota(this, <?php echo json_encode($akun["id"]); ?>, <?php echo json_encode($akun["nama"]); ?>)'>
                <div class="tpl-item-label"><?php echo htmlspecialchars($akun['nama']); ?></div>
            </div>
        <?php endforeach; ?>
    `;

    const container = document.getElementById('anggotaContainer');
    const div = document.createElement('div');
    div.className = 'anggota-item';
    div.innerHTML =
        `<input type="hidden" name="anggota_id[]" value="0">` +
        `<div class="anggota-photo-preview">` +
            `<div class="preview-placeholder"><i class="fas fa-user"></i></div>` +
        `</div>` +
        `<div class="anggota-fields">` +
            `<div class="tpl-picker" style="margin-bottom:10px;">` +
                `<i class="fas fa-search tpl-search-icon"></i>` +
                `<input type="text" class="tpl-search-input form-control tpl-display-input" placeholder="Cari atau pilih anggota..." value="" autocomplete="off" onfocus="showTplAnggota(this)" onkeyup="filterTplAnggota(this)">` +
                `<input type="hidden" name="user_id[]" class="tpl-hidden-input" value="">` +
                `<div class="tpl-results">${akunOptions}</div>` +
            `</div>` +
            `<select name="jabatan[]" required style="background: var(--input-bg); border: 1.5px solid var(--border-color); border-radius: 10px; padding: 12px 15px; color: var(--text-main); font-size: 0.95rem;">` +
                `<option value="">-- Pilih Jabatan --</option>` +
                `<option value="Ketua Umum <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>">Ketua Umum <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?></option>` +
                `<option value="Sekretaris <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>">Sekretaris <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?></option>` +
                `<option value="Bendahara <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>">Bendahara <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?></option>` +
                `<option value="Anggota <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>">Anggota <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?></option>` +
            `</select>` +
        `</div>` +
        `<div class="anggota-foto-input">` +
            `<input type="file" name="foto[]" accept="image/*">` +
        `</div>` +
        `<div class="anggota-actions">` +
            `<button type="button" class="btn-remove" onclick="hapusAnggotaItem(this)" title="Hilangkan dari form">×</button>` +
        `</div>`;
    container.appendChild(div);
    div.querySelector('input[name="nama[]"]').focus();
}

function hapusAnggotaItem(btn) {
    const item = btn.closest('.anggota-item');
    const container = document.getElementById('anggotaContainer');
    if (container.children.length > 1) {
        item.remove();
    } else {
        alert('Minimal harus ada satu baris anggota.');
    }
}


document.addEventListener('change', function (e) {
    if (e.target.name === 'delete_ids[]') {
        const item = e.target.closest('.anggota-item');
        if (item) {
            const inputs = item.querySelectorAll('select, input:not([name="delete_ids[]"]):not([name="anggota_id[]"])');
            if (e.target.checked) {
                inputs.forEach(input => input.removeAttribute('required'));
                item.style.opacity = '0.5';
            } else {
                inputs.forEach(input => input.setAttribute('required', 'required'));
                item.style.opacity = '1';
            }
        }
    }
    
    if (e.target.type === 'file' && e.target.name === 'foto[]') {
        const file = e.target.files[0];
        if (!file) return;
        const preview = e.target.closest('.anggota-item').querySelector('.anggota-photo-preview');
        const reader = new FileReader();
        reader.onload = ev => {
            preview.innerHTML = `<img src="${ev.target.result}" class="preview-img" alt="Preview">`;
        };
        reader.readAsDataURL(file);
    }
});

const adminForm = document.querySelector('.admin-form');
if (adminForm) {
    adminForm.addEventListener('submit', function () {
        document.querySelectorAll('.anggota-item').forEach(item => {
            const delCheckbox = item.querySelector('input[name="delete_ids[]"]');
            if (delCheckbox && delCheckbox.checked) {
                item.querySelectorAll('select, input').forEach(input => input.removeAttribute('required'));
            }
        });
        const btn = document.getElementById('submitBtn');
        if (btn && !btn.classList.contains('loading')) {
            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
        }
    });
}

function _elevatePickerCard(res) {
    const card = res.closest('.anggota-item');
    if (card) card.style.zIndex = '99';
}
function _resetPickerCards() {
    document.querySelectorAll('.anggota-item').forEach(c => c.style.zIndex = '');
}
function showTplAnggota(input) {
    document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    const picker = input.closest('.tpl-picker');
    const res = picker.querySelector('.tpl-results');
    if(res) {
        res.style.display = 'block';
        _elevatePickerCard(res);
    }
}
function filterTplAnggota(input) {
    const filter = input.value.toLowerCase();
    const picker = input.closest('.tpl-picker');
    const results = picker.querySelector('.tpl-results');
    const items = results.getElementsByClassName('tpl-item');
    let hasMatch = false;
    for(let i=0;i<items.length;i++) {
        const label = items[i].querySelector('.tpl-item-label').innerText.toLowerCase();
        if(label.includes(filter)) {
            items[i].style.display = "";
            hasMatch = true;
        } else {
            items[i].style.display = "none";
        }
    }
    let emptyMsg = results.querySelector('.tpl-empty');
    if(!hasMatch) {
        if(!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.className = 'tpl-empty';
            emptyMsg.innerText = 'Tidak ada hasil...';
            emptyMsg.style.padding = '15px';
            emptyMsg.style.textAlign = 'center';
            emptyMsg.style.color = '#888';
            emptyMsg.style.fontStyle = 'italic';
            results.appendChild(emptyMsg);
        }
    } else if(emptyMsg) {
        emptyMsg.remove();
    }
}
function selectTplAnggota(item, id, name) {
    const picker = item.closest('.tpl-picker');
    picker.querySelector('.tpl-hidden-input').value = id;
    picker.querySelector('.tpl-display-input').value = name;
    picker.querySelector('.tpl-results').style.display = 'none';
    _resetPickerCards();
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
        _resetPickerCards();
    }
});
</script>

<style>
/* CSS Tambahan untuk tpl-picker */
.tpl-picker { position: relative; }
.tpl-search-input { padding-left: 44px !important; }
.tpl-search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--accent-color); font-size: 1rem; pointer-events: none; z-index: 5; }
.tpl-results { position: absolute; top: calc(100% + 8px); left: 0; right: 0; background: #121822; border: 1px solid var(--border-color); border-radius: 16px; max-height: 250px; overflow-y: auto; z-index: 1000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: none; padding: 8px; }
.tpl-item { padding: 12px 16px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; border: 1px solid transparent; }
.tpl-item:hover { background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3); }
.tpl-item-label { font-weight: 600; color: #fff; margin-bottom: 4px; }
</style>

<link rel="stylesheet" href="<?php echo baseUrl('admin/css/kementerian-anggota.css'); ?>">

<?php require_once __DIR__ . '/../core/footer.php'; ?>