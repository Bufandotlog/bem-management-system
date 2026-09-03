<?php
// admin/pendaftaran.php - Kelola pendaftaran publik
require_once __DIR__ . '/../core/header.php';

// Hanya superadmin, admin, atau kominfo yang bisa kelola
if (!in_array($admin_role, ['superadmin', 'admin', 'kominfo'])) {
    redirect('admin/core/dashboard.php', 'Akses Ditolak', 'error');
}

$status_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'status_pendaftaran'");
$status_pendaftaran = $status_row ? $status_row['nilai'] : 'tutup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect('admin/konten/pendaftaran.php', 'Token CSRF tidak valid atau telah kedaluwarsa.', 'error');
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_status') {
        $new_status = ($status_pendaftaran === 'buka') ? 'tutup' : 'buka';
        dbUpsertPengaturan('status_pendaftaran', $new_status);
        redirect('admin/konten/pendaftaran.php', "Status pendaftaran publik diubah menjadi: " . strtoupper($new_status), 'success');
    }

    if ($action === 'update_default_password') {
        if (!in_array($admin_role, ['superadmin', 'admin'])) {
            redirect('admin/konten/pendaftaran.php', 'Akses Ditolak: Hanya Admin/Superadmin yang dapat mengubah password default.', 'error');
        }
        
        $target_periode_id = (int)($_POST['target_periode_id'] ?? getUserPeriode());
        $new_default_pw    = trim($_POST['new_default_password'] ?? '');
        
        if (mb_strlen($new_default_pw) < 8) {
            redirect('admin/konten/pendaftaran.php', 'Gagal: Password default minimal 8 karakter!', 'error');
        }
        if (!preg_match('/[A-Za-z]/', $new_default_pw) || !preg_match('/[0-9]/', $new_default_pw)) {
            redirect('admin/konten/pendaftaran.php', 'Gagal: Password default harus mengombinasikan huruf dan angka!', 'error');
        }
        
        dbUpsertPengaturan('default_password_periode_' . $target_periode_id, $new_default_pw);
        auditLog('UPDATE', 'pengaturan', $target_periode_id, 'Ubah password default pendaftaran periode ID: ' . $target_periode_id);
        redirect('admin/konten/pendaftaran.php', 'Password default pendaftaran berhasil diperbarui!', 'success');
    }
    
    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $row = dbFetchOne("SELECT * FROM pendaftaran_anggota WHERE id = ?", [$id]);
        
        if ($row && $row['status'] === 'pending') {
            // Validasi username exist
            $cek = dbFetchOne("SELECT id FROM users WHERE username = ?", [$row['username']]);
            if ($cek) {
                redirect('admin/konten/pendaftaran.php', "Gagal: Username {$row['username']} sudah ada di sistem.", 'error');
            } else {
                $periode_id = getUserPeriode();
                $raw_default_pw = getDefaultPassword($periode_id);
                $default_password = password_hash($raw_default_pw, PASSWORD_DEFAULT);
                $role = 'anggota'; // default role users
                
                // Cek apakah mendaftar ke Kominfo
                if (!empty($row['kementerian_id'])) {
                    $kem_row = dbFetchOne("SELECT nama FROM kementerian WHERE id = ?", [$row['kementerian_id']]);
                    if ($kem_row && stripos($kem_row['nama'], 'kominfo') !== false) {
                        $role = 'kominfo';
                    }
                }
                
                dbBeginTransaction();
                try {
                    // Insert Users
                    $user_id = dbInsert("INSERT INTO users (username, password, nama, role, periode_id, file_ttd) VALUES (?, ?, ?, ?, ?, ?)", [
                        $row['username'], $default_password, $row['nama_lengkap'], $role, $periode_id, $row['file_ttd']
                    ]);
                    
                    // Insert Kepengurusan
                    if ($row['penempatan'] === 'BPH') {
                        // Tentukan posisi BPH berdasarkan jabatan, bukan ID hardcode.
                        // Model: 1 header struktur_bph per posisi (ketua/wakil/ketua/sekum/bendum),
                        //      dengan 0..N anggota di anggota_bph yang merujuk ke header via FK.
                        $jabatan_lower = strtolower($row['jabatan'] ?? '');
                        $is_sekum = strpos($jabatan_lower, 'sekretaris') !== false;
                        $is_bendum = strpos($jabatan_lower, 'bendahara') !== false;

                        // Resolve bph_id secara dinamis: cari header di struktur_bph
                        // by posisi + periode_id. Jika belum ada, create on-the-fly.
                        if ($is_sekum) {
                            $bph_id = getOrCreateBphByPosisi('sekretaris_umum', $periode_id);
                            if (strtolower(trim($row['jabatan'])) === 'sekretaris umum i' && !empty($row['file_ttd'])) {
                                dbUpsertPengaturan('ttd_sekretaris_name', strtoupper($row['nama_lengkap']));
                                dbUpsertPengaturan('ttd_sekretaris_jabatan', 'Sekretaris BEM INSTBUNAS Majalengka');
                                dbUpsertPengaturan('ttd_sekretaris_image', $row['file_ttd']);
                            }
                        } elseif ($is_bendum) {
                            $bph_id = getOrCreateBphByPosisi('bendahara_umum', $periode_id);
                        } else {
                            $bph_id = getOrCreateBphByPosisi('ketua', $periode_id);
                        }

                        // [FIX 2026-09-04] file_ttd ke kolom file_ttd, BUKAN foto.
                        // Sebelumnya TTD di-copy ke kolom foto (yg seharusnya foto profil)
                        // — ini membingungkan picker Panitia Tetap krn path-nya campur.
                        dbInsert("INSERT INTO anggota_bph (periode_id, created_by, bph_id, user_id, nama, jabatan, file_ttd) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                            $periode_id, $_SESSION['admin_id'], $bph_id, $user_id, $row['nama_lengkap'], $row['jabatan'], $row['file_ttd']
                        ]);
                    } else {
                        // [FIX 2026-09-04] Resolve kementerian_id ke periode aktif
                        // pendaftaran_anggota.kementerian_id bisa reference kementerian
                        // dari periode lama (cross-period issue). Saat INSERT ke
                        // anggota_kementerian dengan periode_id=2, kementerian_id harus
                        // di-resolve ke ID di periode 2 yg nama-nya sama.
                        $resolved_kem_id = $row['kementerian_id'];
                        if (!empty($row['kementerian_id'])) {
                            $kem_asli = dbFetchOne("SELECT nama FROM kementerian WHERE id = ?", [$row['kementerian_id']]);
                            if ($kem_asli) {
                                $kem_aktif = dbFetchOne(
                                    "SELECT id FROM kementerian WHERE nama = ? AND periode_id = ?",
                                    [$kem_asli['nama'], $periode_id], "si"
                                );
                                if ($kem_aktif) {
                                    $resolved_kem_id = $kem_aktif['id'];
                                }
                            }
                        }
                        dbInsert("INSERT INTO anggota_kementerian (periode_id, created_by, kementerian_id, user_id, nama, jabatan, file_ttd) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                            $periode_id, $_SESSION['admin_id'], $resolved_kem_id, $user_id, $row['nama_lengkap'], $row['jabatan'], $row['file_ttd']
                        ]);
                    }

                    // [FIX 2026-09-04] Auto-promote TTD ke panitia_tetap untuk surat.
                    // Tanpa ini, dropdown "Ketua/Sekretaris Pelaksana" di buat-surat.php
                    // dan staging-surat.php kosong untuk periode baru (TTD pendaftar
                    // tersimpan di users.file_ttd / anggota_*.foto tapi tidak pernah masuk
                    // ke panitia_tetap yang dibaca oleh picker Panitia Tersimpan).
                    // Hanya jabatan ketua/sekretaris yang di-promote; bendahara/anggota
                    // tidak relevan untuk TTD surat.
                    $jabatan_norm = strtolower(trim($row['jabatan'] ?? ''));
                    $panitia_jabatan = null;
                    if (strpos($jabatan_norm, 'sekretaris') !== false) {
                        $panitia_jabatan = 'sekretaris';
                    } elseif (strpos($jabatan_norm, 'ketua') !== false) {
                        $panitia_jabatan = 'ketua';
                    }
                    if ($panitia_jabatan !== null && !empty($row['file_ttd'])) {
                        // Skip jika sudah ada entry panitia_tetap untuk nama+jabatan+periode
                        // yang sama (cegah duplikat saat re-approval / edit jabatan).
                        $exists = dbFetchOne(
                            "SELECT id FROM panitia_tetap WHERE periode_id = ? AND UPPER(TRIM(nama)) = ? AND jabatan = ?",
                            [$periode_id, strtoupper(trim($row['nama_lengkap'])), $panitia_jabatan],
                            "iss"
                        );
                        if (!$exists) {
                            dbQuery(
                                "INSERT INTO panitia_tetap (periode_id, nama, jabatan, file_ttd) VALUES (?, ?, ?, ?)",
                                [$periode_id, strtoupper(trim($row['nama_lengkap'])), $panitia_jabatan, $row['file_ttd']],
                                "isss"
                            );
                        }
                    }

                    dbQuery("UPDATE pendaftaran_anggota SET status = 'approved' WHERE id = ?", [$id]);
                    dbCommit();

                    // FCM & Web Notification ke pendaftar yang disetujui
                    createNotificationAndPush(
                        $user_id,
                        "🎉 Selamat! Akun BEM Anda Aktif",
                        "Selamat " . $row['nama_lengkap'] . ", pendaftaran Anda disetujui. Akun Anda (" . $row['username'] . ") kini telah aktif.",
                        baseUrl('astawidya/bem.php'),
                        "success"
                    );

                    redirect('admin/konten/pendaftaran.php', 'Pendaftaran disetujui! Akun berhasil dibuat dengan password default.', 'success');
                } catch (Exception $e) {
                    dbRollback();
                    redirect('admin/konten/pendaftaran.php', 'Gagal memproses persetujuan: ' . $e->getMessage(), 'error');
                }
            }
        }
    }
    
    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        dbQuery("UPDATE pendaftaran_anggota SET status = 'rejected' WHERE id = ?", [$id]);
        redirect('admin/konten/pendaftaran.php', 'Pendaftaran berhasil ditolak.', 'success');
    }
}

$pending_list = dbFetchAll("SELECT p.*, k.nama as nama_kementerian FROM pendaftaran_anggota p LEFT JOIN kementerian k ON p.kementerian_id = k.id WHERE p.status = 'pending' ORDER BY p.created_at DESC");
$current_periode_id = getUserPeriode();
$active_def_pw = getDefaultPassword($current_periode_id);
$periode_list = dbFetchAll("SELECT id, nama, tahun_mulai, tahun_selesai, is_active FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --border-color: #2a3545;
    --accent-color: #4A90E2;
}
.page-header {
    background: var(--card-bg);
    padding: 25px 30px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.page-header h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: #fff; }
.page-header p { margin: 0; color: #888; font-size: 0.95rem; }

.table-premium {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 12px;
}
.table-premium th {
    padding: 15px 20px;
    text-transform: uppercase;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: #888;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.table-premium td {
    padding: 18px 20px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.table-premium td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
}
.table-premium td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
}
.table-premium tr:hover td {
    background: rgba(74, 144, 226, 0.05);
}

.badge {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}
.badge-warning { background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3); }
.badge-primary { background: rgba(52, 152, 219, 0.15); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.3); }
.badge-success { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}
.btn-approve {
    background: rgba(46, 204, 113, 0.15);
    color: #2ecc71;
    border: 1px solid rgba(46, 204, 113, 0.3);
}
.btn-approve:hover { background: rgba(46, 204, 113, 0.25); transform: translateY(-2px); }
.btn-reject {
    background: rgba(231, 76, 60, 0.15);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.3);
}
.btn-reject:hover { background: rgba(231, 76, 60, 0.25); transform: translateY(-2px); }

.header-btn {
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.95rem;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}
.btn-tutup { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); }
.btn-tutup:hover { background: rgba(231, 76, 60, 0.2); transform: translateY(-2px); }
.btn-buka { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
.btn-buka:hover { background: rgba(46, 204, 113, 0.2); transform: translateY(-2px); }

/* Floating Labels */
.form-floating {
    position: relative;
    width: 100%;
}
.form-floating input, .form-floating select {
    width: 100%;
    padding: 22px 12px 8px 12px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: #fff;
    font-size: 0.9rem;
    outline: none;
    transition: border-color 0.2s;
    height: 52px;
}
.form-floating input:focus, .form-floating select:focus {
    border-color: var(--accent-color);
}
.form-floating label {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #888;
    font-size: 0.9rem;
    pointer-events: none;
    transition: all 0.2s ease;
}
.form-floating input:focus ~ label,
.form-floating input:not(:placeholder-shown) ~ label,
.form-floating select ~ label {
    top: 14px;
    font-size: 0.7rem;
    color: var(--accent-color);
}
.input-group-floating {
    display: flex;
    align-items: center;
    position: relative;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 8px;
}
.input-group-floating:focus-within {
    border-color: var(--accent-color);
}
.input-group-floating input {
    border: none;
    background: transparent;
    flex: 1;
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    .page-header form { width: 100%; }
    .header-btn { width: 100%; justify-content: center; }
    
    #formGrid {
        grid-template-columns: 1fr !important;
        gap: 10px;
    }
    #formGrid > div {
        width: 100%;
    }
    
    .table-premium thead { display: none; }
    .table-premium tr { display: flex; flex-direction: column; border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 15px; background: var(--card-bg); padding: 10px; }
    .table-premium td { display: flex; justify-content: space-between; align-items: center; border: none !important; border-bottom: 1px solid rgba(255,255,255,0.05) !important; padding: 12px 5px; text-align: right; }
    .table-premium td::before { content: attr(data-label); font-size: 0.8rem; color: #888; font-weight: 600; text-align: left; }
    .table-premium td:last-child { border-bottom: none !important; justify-content: center; margin-top: 10px; }
    .table-premium td:last-child::before { display: none; }
    
    .td-aksi { flex-direction: column; gap: 10px; }
    .td-aksi > div { width: 100%; display: flex; flex-direction: row; gap: 8px; }
    .btn-action { justify-content: center; flex: 1; }
}
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-user-plus"></i> Kelola Pendaftaran Anggota</h1>
    </div>
    <form method="POST" style="margin: 0;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="toggle_status">
        <?php if ($status_pendaftaran === 'buka'): ?>
            <button type="submit" class="header-btn btn-tutup"><i class="fas fa-lock"></i> Tutup Pendaftaran Publik</button>
        <?php else: ?>
            <button type="submit" class="header-btn btn-buka"><i class="fas fa-lock-open"></i> Buka Pendaftaran Publik</button>
        <?php endif; ?>
    </form>
</div>

<?php if (in_array($admin_role, ['superadmin', 'admin'])): ?>
<div class="card" style="padding: 24px; margin-bottom: 25px; border-left: 4px solid var(--accent-color);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 15px;">
        <div>
            <h3 style="margin: 0; color: #fff; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-key text-biru"></i> Pengaturan Password Default
            </h3>
        </div>
        <div style="background: rgba(74, 144, 226, 0.1); border: 1px solid rgba(74, 144, 226, 0.2); padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; color: var(--accent-color);">
            <i class="fas fa-shield-alt"></i> Password Default Saat Ini: <strong id="defPwText" style="font-family: monospace; letter-spacing: 1px; color: #fff; background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($active_def_pw); ?></strong>
        </div>
    </div>

    <form method="POST" id="formGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) 140px; gap: 15px; align-items: end;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="update_default_password">
        
        <?php if ($admin_role === 'superadmin'): ?>
        <div class="form-floating">
            <select name="target_periode_id" required>
                <?php foreach ($periode_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] == $current_periode_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['nama'] . ' (' . $p['tahun_mulai'] . '/' . $p['tahun_selesai'] . ')' . ($p['is_active'] ? ' - AKTIF' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Periode Kepengurusan</label>
        </div>
        <?php else: ?>
        <input type="hidden" name="target_periode_id" value="<?php echo $current_periode_id; ?>">
        <?php endif; ?>

        <div class="form-floating input-group-floating">
            <input type="password" name="new_default_password" id="newDefaultPwInput" required minlength="8" placeholder=" ">
            <label>Password Default Baru (Min. 8 Karakter)</label>
            <button type="button" onclick="toggleDefaultPwVisibility()" style="background: none; border: none; color: #888; cursor: pointer; padding: 0 15px; outline: none;" title="Tampilkan/Sembunyikan">
                <i class="fas fa-eye" id="toggleDefaultPwIcon"></i>
            </button>
        </div>

        <div>
            <button type="submit" class="header-btn" style="background: var(--accent-color); color: #fff; width: 100%; justify-content: center; height: 52px; margin: 0;">
                <i class="fas fa-save"></i> Simpan
            </button>
        </div>
    </form>

    <div style="margin-top: 15px; padding: 10px 14px; background: rgba(241, 196, 15, 0.08); border-left: 3px solid #f1c40f; border-radius: 6px; font-size: 0.8rem; color: #ccc; line-height: 1.4; display: flex; align-items: flex-start; gap: 8px;">
        <i class="fas fa-info-circle" style="color: #f1c40f; font-size: 1.1rem; margin-top: 2px;"></i>
        <div>
            <strong>Info:</strong> Perubahan hanya berlaku untuk pendaftaran baru di masa mendatang.
        </div>
    </div>
</div>

<script>
function toggleDefaultPwVisibility() {
    const input = document.getElementById('newDefaultPwInput');
    const icon = document.getElementById('toggleDefaultPwIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
<?php endif; ?>

<div class="card" style="padding: 20px;">
    <?php if (empty($pending_list)): ?>
        <div style="text-align: center; color: #888; padding: 40px;">
            <i class="fas fa-inbox fa-3x" style="margin-bottom: 10px;"></i>
            <p>Tidak ada pendaftaran baru yang menunggu persetujuan.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                    <tr>
                        <th style="width: 15%;">Tanggal</th>
                        <th style="width: 25%;">Nama Lengkap</th>
                        <th style="width: 20%;">Username Req.</th>
                        <th style="width: 25%;">Penempatan</th>
                        <th style="width: 15%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_list as $row): ?>
                        <tr>
                            <td data-label="Tanggal">
                                <span style="color: #aaa; font-size: 0.9rem;"><i class="fas fa-clock" style="margin-right: 5px; color: #555;"></i><?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                            </td>
                            <td data-label="Nama">
                                <strong style="color: #eee; font-size: 1.05rem; display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($row['nama_lengkap']); ?></strong>
                                <?php if(!empty($row['file_ttd'])): ?>
                                    <span class="badge badge-success" style="font-size: 0.65rem; padding: 4px 8px;"><i class="fas fa-signature"></i> TTD Tersimpan</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Username">
                                <code style="background: rgba(255,255,255,0.05); padding: 6px 10px; border-radius: 6px; color: var(--accent-color); font-size: 0.9rem;"><i class="fas fa-user-circle" style="margin-right: 5px;"></i><?php echo htmlspecialchars($row['username']); ?></code>
                            </td>
                            <td data-label="Penempatan">
                                <?php if ($row['penempatan'] === 'BPH'): ?>
                                    <span class="badge badge-warning" style="margin-bottom: 5px;"><i class="fas fa-crown"></i> BPH</span>
                                <?php else: ?>
                                    <span class="badge badge-primary" style="margin-bottom: 5px;"><i class="fas fa-users"></i> <?php echo htmlspecialchars($row['nama_kementerian']); ?></span>
                                <?php endif; ?>
                                <br>
                                <span style="color: #bbb; font-size: 0.85rem;"><i class="fas fa-briefcase" style="margin-right: 5px; color: #555;"></i><?php echo htmlspecialchars($row['jabatan']); ?></span>
                            </td>
                            <td class="td-aksi">
                                <div style="display: flex; gap: 8px;">
                                    <form method="POST" onsubmit="return confirm('Setujui dan buatkan akun untuk anggota ini? Password default: <?php echo htmlspecialchars(addslashes($active_def_pw)); ?>');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn-action btn-approve" title="Setujui"><i class="fas fa-check-circle"></i> Setujui</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Tolak pendaftaran ini?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn-action btn-reject" title="Tolak"><i class="fas fa-times-circle"></i> Tolak</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
