<?php
// daftar.php - Halaman Pendaftaran Anggota Publik
include 'header.php';
$page_title = 'Pendaftaran Anggota';

// Clear cookie if requested
if (isset($_GET['action']) && $_GET['action'] === 'clear_cookie') {
    setcookie('pendaftar_id', '', time() - 3600, '/');
    unset($_COOKIE['pendaftar_id']);
    header('Location: daftar.php');
    exit;
}

// Cek status pendaftaran
$status_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'status_pendaftaran'");
$status_pendaftaran = $status_row ? $status_row['nilai'] : 'tutup';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status_pendaftaran === 'buka') {
    // Handle form submission
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $penempatan = trim($_POST['penempatan'] ?? '');
    $kementerian_id = $_POST['kementerian_id'] ?? null;
    $jabatan = trim($_POST['jabatan'] ?? '');
    
    if ($penempatan === 'BPH') {
        $kementerian_id = null; // BPH doesnt have kementerian_id
    }
    
    // Validate
    if (empty($nama_lengkap) || empty($username) || empty($penempatan) || empty($jabatan)) {
        $error_msg = "Semua field wajib diisi.";
    } else {
        // Cek apakah username sudah ada di tabel users
        $cek_username = dbFetchOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($cek_username) {
            $error_msg = "Username sudah terdaftar. Silakan pilih username lain.";
        } else {
            // Upload TTD jika ada
            $file_ttd_path = null;
            if (isset($_FILES['file_ttd']) && $_FILES['file_ttd']['error'] === UPLOAD_ERR_OK) {
                $uploaded = uploadFile($_FILES['file_ttd'], 'ttd');
                if ($uploaded) {
                    $file_ttd_path = $uploaded;
                } else {
                    $error_msg = isset($_SESSION['error']) ? $_SESSION['error'] : "Gagal mengunggah tanda tangan.";
                    unset($_SESSION['error']);
                }
            }

            if (empty($error_msg)) {
                // Insert into pendaftaran_anggota
                try {
                    if ($kementerian_id !== null && $kementerian_id !== '') {
                        $pendaftar_id = dbInsert("INSERT INTO pendaftaran_anggota (nama_lengkap, username, penempatan, kementerian_id, jabatan, file_ttd) VALUES (?, ?, ?, ?, ?, ?)", [$nama_lengkap, $username, $penempatan, $kementerian_id, $jabatan, $file_ttd_path]);
                    } else {
                        $pendaftar_id = dbInsert("INSERT INTO pendaftaran_anggota (nama_lengkap, username, penempatan, jabatan, file_ttd) VALUES (?, ?, ?, ?, ?)", [$nama_lengkap, $username, $penempatan, $jabatan, $file_ttd_path]);
                    }
                    
                    // Set cookie anti-spam/tracking
                    setcookie('pendaftar_id', $pendaftar_id, time() + (86400 * 30), '/');
                    $_COOKIE['pendaftar_id'] = $pendaftar_id;
                    
                    $success_msg = "Pendaftaran berhasil dikirim. Silakan tunggu persetujuan dari Admin.";
                } catch (Exception $e) {
                    $error_msg = "Terjadi kesalahan sistem saat menyimpan data.";
                }
            }
        }
    }
}

// Ambil periode aktif
$periode_aktif = dbFetchOne("SELECT id, tahun_mulai, tahun_selesai FROM periode_kepengurusan WHERE is_active = TRUE LIMIT 1");
$periode_id = $periode_aktif ? $periode_aktif['id'] : 0;

// Ambil daftar kementerian aktif
$kementerian_list = [];
if ($periode_id > 0) {
    $kementerian_list = dbFetchAll("SELECT id, nama FROM kementerian WHERE periode_id = ? ORDER BY nama ASC", [$periode_id]);
}
?>

<style>
.daftar-container {
    max-width: 800px;
    margin: 120px auto 50px auto; /* 120px top margin prevents navbar overlap */
    background: #0f1217;
    padding: 40px;
    border-radius: 12px;
    border: 1px solid #2a3545;
    position: relative;
    z-index: 10; /* Ensures it sits above the fixed background */
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #ccc;
}
.form-group input, .form-group select {
    width: 100%;
    padding: 12px;
    background: #1a1e24;
    border: 1px solid #333;
    border-radius: 8px;
    color: #fff;
}
.btn-submit {
    background: linear-gradient(135deg, #e52d27 0%, #b31217 100%);
    color: #fff;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: bold;
    width: 100%;
}
.alert-success { background: rgba(46, 204, 113, 0.2); color: #2ecc71; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.alert-danger { background: rgba(231, 76, 60, 0.2); color: #e74c3c; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
</style>

<div class="container">
    <div class="daftar-container">
        <h2 style="text-align: center; margin-bottom: 30px;">Pendaftaran Pengurus BEM</h2>

        <?php if ($status_pendaftaran === 'tutup'): ?>
            <div style="text-align: center; padding: 50px 20px;">
                <i class="fas fa-lock fa-4x text-merah" style="margin-bottom: 20px;"></i>
                <h3>Mohon Maaf, Pendaftaran Saat Ini Ditutup.</h3>
                <p style="color: #888;">Silakan hubungi administrator jika ini adalah sebuah kesalahan.</p>
            </div>
        <?php else: ?>

            <?php if ($success_msg): ?>
                <div class="alert-success" style="text-align: center;">
                    <i class="fas fa-check-circle fa-3x" style="margin-bottom: 15px;"></i>
                    <h4><?php echo htmlspecialchars($success_msg); ?></h4>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <?php
            $tampilkan_form = true;
            if (isset($_COOKIE['pendaftar_id'])) {
                $p_id = (int)$_COOKIE['pendaftar_id'];
                $cek_status = dbFetchOne("SELECT status, nama_lengkap FROM pendaftaran_anggota WHERE id = ?", [$p_id]);
                if ($cek_status) {
                    $tampilkan_form = false;
                    $st = $cek_status['status'];
                    $nama = htmlspecialchars($cek_status['nama_lengkap']);
                    
                    if ($st === 'pending') {
                        echo '<div style="text-align: center; padding: 40px 20px; background: rgba(241, 196, 15, 0.1); border: 1px solid #f1c40f; border-radius: 12px; margin-top: 20px;">
                                <i class="fas fa-hourglass-half fa-4x" style="color: #f1c40f; margin-bottom: 20px;"></i>
                                <h3 style="color: #f1c40f;">Pendaftaran Sedang Diproses</h3>
                                <p style="color: #ddd;">Halo <strong>'.$nama.'</strong>, pendaftaran Anda dari perangkat ini sedang menunggu peninjauan Admin.</p>
                              </div>';
                    } elseif ($st === 'approved') {
                        echo '<div style="text-align: center; padding: 40px 20px; background: rgba(46, 204, 113, 0.1); border: 1px solid #2ecc71; border-radius: 12px; margin-top: 20px;">
                                <i class="fas fa-check-circle fa-4x" style="color: #2ecc71; margin-bottom: 20px;"></i>
                                <h3 style="color: #2ecc71;">Selamat, Anda Diterima!</h3>
                                <p style="color: #ddd;">Selamat <strong>'.$nama.'</strong>! Pendaftaran Anda telah disetujui. Akun Anda (dengan password default <code>Bem2026!</code>) kini sudah aktif.</p>
                                <a href="?action=clear_cookie" class="btn-submit" style="display: inline-block; margin-top: 20px; background: rgba(255,255,255,0.1); width: auto;">Tutup & Daftar Baru</a>
                              </div>';
                    } elseif ($st === 'rejected') {
                        echo '<div style="text-align: center; padding: 40px 20px; background: rgba(231, 76, 60, 0.1); border: 1px solid #e74c3c; border-radius: 12px; margin-top: 20px;">
                                <i class="fas fa-times-circle fa-4x" style="color: #e74c3c; margin-bottom: 20px;"></i>
                                <h3 style="color: #e74c3c;">Mohon Maaf...</h3>
                                <p style="color: #ddd;">Halo <strong>'.$nama.'</strong>, pendaftaran Anda tidak dapat disetujui saat ini. Jangan menyerah dan tetap semangat!</p>
                                <a href="?action=clear_cookie" class="btn-submit" style="display: inline-block; margin-top: 20px; background: rgba(255,255,255,0.1); width: auto;">Tutup & Daftar Baru</a>
                              </div>';
                    }
                } else {
                    // Jika data tidak ditemukan di DB (mungkin dihapus), clear cookie
                    setcookie('pendaftar_id', '', time() - 3600, '/');
                    $tampilkan_form = true;
                }
            }
            ?>

            <?php if (!$success_msg && $tampilkan_form): ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Nama Lengkap</label>
                    <input type="text" name="nama_lengkap" required placeholder="Masukkan nama lengkap sesuai identitas">
                </div>
                
                <div class="form-group">
                    <label>Username (Untuk Login)</label>
                    <input type="text" name="username" required placeholder="Gunakan huruf kecil tanpa spasi (misal: budiutomo)">
                </div>
                
                <div class="form-group">
                    <label>Penempatan</label>
                    <select name="penempatan" id="penempatan" required onchange="toggleKementerian()">
                        <option value="">-- Pilih Penempatan --</option>
                        <option value="BPH">Badan Pengurus Harian (BPH)</option>
                        <option value="Kementerian">Kementerian</option>
                    </select>
                </div>

                <div class="form-group" id="kementerian_group" style="display: none;">
                    <label>Pilih Kementerian</label>
                    <select name="kementerian_id" id="kementerian_id">
                        <option value="">-- Pilih Kementerian --</option>
                        <?php foreach ($kementerian_list as $kem): ?>
                            <option value="<?php echo $kem['id']; ?>"><?php echo htmlspecialchars($kem['nama']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Jabatan</label>
                    <select name="jabatan" id="jabatan" required onchange="toggleTtdField()">
                        <option value="">-- Pilih Penempatan Terlebih Dahulu --</option>
                    </select>
                </div>

                <div class="form-group" id="ttd_group">
                    <label>Tanda Tangan (Opsional, format PNG/JPG transparan)</label>
                    <input type="file" name="file_ttd" accept="image/png, image/jpeg, image/jpg" onchange="previewTtd(this)">
                    <p style="font-size: 0.8rem; color: #888; margin-top: 5px;">Upload tanda tangan Anda di sini agar sistem persuratan otomatis menggunakan TTD Anda jika Anda ditunjuk menjadi Ketua Pelaksana atau Sekretaris Pelaksana. Maks 5MB.</p>
                    
                    <!-- File Preview & Indicator -->
                    <div id="ttd-preview-container" style="display: none; margin-top: 15px; padding: 15px; background: rgba(74, 144, 226, 0.1); border: 1px dashed #4A90E2; border-radius: 8px; text-align: center;">
                        <img id="ttd-preview-img" src="" style="max-height: 100px; max-width: 100%; object-fit: contain; margin-bottom: 10px;">
                        <div id="ttd-preview-info" style="color: #4A90E2; font-weight: bold; font-size: 0.9rem;"></div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Daftar Sekarang</button>
            </form>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<script>
function previewTtd(input) {
    const container = document.getElementById('ttd-preview-container');
    const img = document.getElementById('ttd-preview-img');
    const info = document.getElementById('ttd-preview-info');
    const btnSubmit = document.querySelector('.btn-submit');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // Validate Size (Maks 5MB)
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('Ukuran file terlalu besar! Maksimal 5MB.');
            input.value = '';
            container.style.display = 'none';
            btnSubmit.disabled = true;
            return;
        }
        
        if (file.size === 0) {
            alert('File kosong (0 byte) atau rusak. Silakan pilih file lain.');
            input.value = '';
            container.style.display = 'none';
            btnSubmit.disabled = true;
            return;
        }
        
        // Preview Image
        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            const sizeKB = (file.size / 1024).toFixed(1);
            info.innerHTML = `<i class="fas fa-check-circle"></i> File valid: ${file.name} (${sizeKB} KB)`;
            container.style.display = 'block';
            btnSubmit.disabled = false;
        };
        reader.readAsDataURL(file);
    } else {
        container.style.display = 'none';
        btnSubmit.disabled = false;
    }
}

function toggleKementerian() {
    var val = document.getElementById('penempatan').value;
    var kemGroup = document.getElementById('kementerian_group');
    var kemSelect = document.getElementById('kementerian_id');
    var jabatanSelect = document.getElementById('jabatan');
    
    // Reset jabatan options
    jabatanSelect.innerHTML = '<option value="">-- Pilih Jabatan --</option>';
    
    if (val === 'Kementerian') {
        kemGroup.style.display = 'block';
        kemSelect.setAttribute('required', 'required');
        
        // Tambahkan opsi jabatan untuk Kementerian
        const opsiKementerian = [
            {value: 'Ketua Umum', text: 'Ketua Umum'},
            {value: 'Sekretaris', text: 'Sekretaris'},
            {value: 'Bendahara', text: 'Bendahara'},
            {value: 'Anggota', text: 'Anggota'}
        ];
        
        opsiKementerian.forEach(opt => {
            let optionElement = document.createElement('option');
            optionElement.value = opt.value;
            optionElement.textContent = opt.text;
            jabatanSelect.appendChild(optionElement);
        });
        
    } else if (val === 'BPH') {
        kemGroup.style.display = 'none';
        kemSelect.removeAttribute('required');
        kemSelect.value = '';
        
        // Tambahkan opsi jabatan untuk BPH
        const opsiBPH = [
            {value: 'Sekretaris Umum I', text: 'Sekretaris Umum I'},
            {value: 'Sekretaris Umum II', text: 'Sekretaris Umum II'},
            {value: 'Bendahara Umum I', text: 'Bendahara Umum I'},
            {value: 'Bendahara Umum II', text: 'Bendahara Umum II'}
        ];
        
        opsiBPH.forEach(opt => {
            let optionElement = document.createElement('option');
            optionElement.value = opt.value;
            optionElement.textContent = opt.text;
            jabatanSelect.appendChild(optionElement);
        });
        
    } else {
        kemGroup.style.display = 'none';
        kemSelect.removeAttribute('required');
        kemSelect.value = '';
        jabatanSelect.innerHTML = '<option value="">-- Pilih Penempatan Terlebih Dahulu --</option>';
    }
    
    toggleTtdField(); // Pastikan field TTD di-update juga saat penempatan berubah
}

function toggleTtdField() {
    var jabatan = document.getElementById('jabatan').value;
    var ttdGroup = document.getElementById('ttd_group');
    var fileTtd = document.getElementById('file_ttd');
    
    // Daftar jabatan yang TIDAK perlu tanda tangan
    var noTtdRoles = ['Sekretaris Umum II', 'Bendahara Umum I', 'Bendahara Umum II'];
    
    if (noTtdRoles.includes(jabatan)) {
        ttdGroup.style.display = 'none';
        fileTtd.value = ''; // Kosongkan file jika disembunyikan
    } else {
        ttdGroup.style.display = 'block';
    }
}
</script>

<?php include 'footer.php'; ?>
