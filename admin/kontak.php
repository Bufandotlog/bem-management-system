<?php
// admin/kontak.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: sanitizeText() untuk semua input teks
//   CHANGED: sanitizeUrl() untuk semua URL sosmed
//   CHANGED: map_embed — hanya izinkan iframe Google Maps, buang semua lainnya
//   CHANGED: Validasi email di PHP dengan filter_var
//   CHANGED: Batasi max 10 nomor telepon
//   CHANGED: Redirect ke admin/kontak.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$kontak = dbFetchOne("SELECT * FROM kontak WHERE id = 1");

// ============================================
// PROSES SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify()) {
        redirect('admin/kontak.php', 'Request tidak valid.', 'error');
        exit();
    }

    $alamat = sanitizeText($_POST['alamat'] ?? '', 500);

    // Validasi email di PHP
    $emailRaw = sanitizeText($_POST['email'] ?? '', 100);
    $email    = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';

    // map_embed — hanya izinkan iframe dari Google Maps, buang apapun selain itu
    $map_raw   = trim($_POST['map_embed'] ?? '');
    $map_embed = '';
    if (!empty($map_raw)) {
        // Ekstrak src dari iframe Google Maps saja
        if (preg_match('~<iframe[^>]+src=["\']([^"\']*google\.com/maps[^"\']*)["\'][^>]*>~i', $map_raw, $m)) {
            $mapSrc    = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $map_embed = '<iframe src="' . $mapSrc . '" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>';
        }
        // Jika bukan iframe Google Maps yang valid → simpan kosong
    }

    // Batasi max 10 nomor telepon, sanitasi setiap item
    $telepon = array_values(array_filter(
        array_map(
            fn($v) => sanitizeText($v, 20),
            array_slice($_POST['telepon'] ?? [], 0, 10)
        ),
        fn($v) => !empty($v)
    ));

    $jam_kerja = [
        'senin_jumat' => sanitizeText($_POST['jam_senin_jumat'] ?? '09.00 - 16.00', 30),
        'sabtu'       => sanitizeText($_POST['jam_sabtu']       ?? '09.00 - 12.00', 30),
        'minggu'      => sanitizeText($_POST['jam_minggu']      ?? 'Libur',         30),
    ];

    // Sanitasi URL sosmed — hanya http/https
    $sosial_media = [
        'instagram' => sanitizeUrl($_POST['instagram'] ?? ''),
        'tiktok'    => sanitizeUrl($_POST['tiktok']    ?? ''),
        'twitter'   => sanitizeUrl($_POST['twitter']   ?? ''),
        'youtube'   => sanitizeUrl($_POST['youtube']   ?? ''),
        'linkedin'  => sanitizeUrl($_POST['linkedin']  ?? ''),
    ];

    $telepon_json = json_encode($telepon);
    $jam_json     = json_encode($jam_kerja);
    $sosmed_json  = json_encode($sosial_media);

    if (!$kontak) {
        dbQuery(
            "INSERT INTO kontak (id, alamat, telepon, email, jam_kerja, sosial_media, map_embed) VALUES (1,?,?,?,?,?,?)",
            [$alamat, $telepon_json, $email, $jam_json, $sosmed_json, $map_embed],
            "ssssss"
        );
    } else {
        dbQuery(
            "UPDATE kontak SET alamat=?, telepon=?, email=?, jam_kerja=?, sosial_media=?, map_embed=? WHERE id=1",
            [$alamat, $telepon_json, $email, $jam_json, $sosmed_json, $map_embed],
            "ssssss"
        );
    }

    auditLog('UPDATE', 'kontak', 1, 'Edit data kontak');
    redirect('admin/kontak.php', 'Data kontak berhasil diperbarui!', 'success');
    exit();
}

// ============================================
// DECODE DATA
// ============================================
$teleponList = [];
$jamKerja    = [];
$sosmed      = [];

if ($kontak) {
    $teleponList = json_decode($kontak['telepon'],      true) ?? [];
    $jamKerja    = json_decode($kontak['jam_kerja'],    true) ?? [];
    $sosmed      = json_decode($kontak['sosial_media'], true) ?? [];
}

$jamKerja = array_merge([
    'senin_jumat' => '09.00 - 16.00',
    'sabtu'       => '09.00 - 12.00',
    'minggu'      => 'Libur',
], $jamKerja);

$sosmed = array_merge([
    'instagram' => '',
    'tiktok'    => '',
    'twitter'   => '',
    'youtube'   => '',
    'linkedin'  => '',
], $sosmed);
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1><i class="fas fa-address-book"></i> Edit Data Kontak</h1>
    <p>Kelola informasi kontak BEM Kabinet <?php echo htmlspecialchars($kabinet['nama'] ?? 'Astawidya'); ?></p>
</div>

<?php flashMessage(); ?>

<!-- ===== FORM ===== -->
<form method="POST" class="admin-form" id="kontakForm">

    <?php echo csrfField(); ?>

    <!-- Alamat -->
    <div class="form-section">
        <h2><i class="fas fa-map-marker-alt"></i> Alamat</h2>
        <div class="form-group">
            <label for="alamat">Alamat Lengkap</label>
            <textarea id="alamat" name="alamat" rows="4"
                      placeholder="Jl. Siliwangi No. 121, Desa Heuleut, Kecamatan Kadipaten..."><?php echo htmlspecialchars($kontak['alamat'] ?? ''); ?></textarea>
            <small>Alamat lengkap sekretariat BEM. Boleh dikosongkan.</small>
        </div>
    </div>

    <!-- Telepon -->
    <div class="form-section">
        <h2><i class="fas fa-phone"></i> Nomor Telepon</h2>
        <div class="form-group">
            <label>Daftar Nomor Telepon</label>
            <div id="teleponContainer">
                <?php foreach (!empty($teleponList) ? $teleponList : [''] as $i => $telp): ?>
                <div class="telepon-item">
                    <input type="text" name="telepon[]"
                           value="<?php echo htmlspecialchars($telp); ?>"
                           placeholder="Nomor <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusTelepon(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="tambahTelepon()">
                <i class="fas fa-plus"></i> Tambah Nomor
            </button>
            <small>Maksimal 10 nomor. Kosongkan jika tidak ingin menampilkan.</small>
        </div>
    </div>

    <!-- Email -->
    <div class="form-section">
        <h2><i class="fas fa-envelope"></i> Email</h2>
        <div class="form-group">
            <label for="email">Alamat Email</label>
            <input type="email" id="email" name="email"
                   value="<?php echo htmlspecialchars($kontak['email'] ?? ''); ?>"
                   placeholder="bem@example.com">
            <small>Email resmi BEM. Boleh dikosongkan.</small>
        </div>
    </div>

    <!-- Jam Kerja -->
    <div class="form-section">
        <h2><i class="fas fa-clock"></i> Jam Kerja</h2>
        <div class="jam-kerja-grid">
            <div>
                <label for="jam_senin_jumat">Senin – Jumat</label>
                <input type="text" id="jam_senin_jumat" name="jam_senin_jumat"
                       value="<?php echo htmlspecialchars($jamKerja['senin_jumat']); ?>"
                       placeholder="09.00 - 16.00">
            </div>
            <div>
                <label for="jam_sabtu">Sabtu</label>
                <input type="text" id="jam_sabtu" name="jam_sabtu"
                       value="<?php echo htmlspecialchars($jamKerja['sabtu']); ?>"
                       placeholder="09.00 - 12.00">
            </div>
            <div>
                <label for="jam_minggu">Minggu</label>
                <input type="text" id="jam_minggu" name="jam_minggu"
                       value="<?php echo htmlspecialchars($jamKerja['minggu']); ?>"
                       placeholder="Libur">
            </div>
        </div>
        <small>Jam operasional sekretariat. Boleh dikosongkan.</small>
    </div>

    <!-- Sosial Media -->
    <div class="form-section">
        <h2><i class="fas fa-share-alt"></i> Media Sosial</h2>
        <div class="sosmed-grid">
            <div>
                <label for="instagram"><i class="fab fa-instagram"></i> Instagram</label>
                <input type="url" id="instagram" name="instagram"
                       value="<?php echo htmlspecialchars($sosmed['instagram']); ?>"
                       placeholder="https://instagram.com/username">
            </div>
            <div>
                <label for="tiktok"><i class="fab fa-tiktok"></i> TikTok</label>
                <input type="url" id="tiktok" name="tiktok"
                       value="<?php echo htmlspecialchars($sosmed['tiktok']); ?>"
                       placeholder="https://tiktok.com/@username">
            </div>
            <div>
                <label for="twitter"><i class="fab fa-twitter"></i> Twitter</label>
                <input type="url" id="twitter" name="twitter"
                       value="<?php echo htmlspecialchars($sosmed['twitter']); ?>"
                       placeholder="https://twitter.com/username">
            </div>
            <div>
                <label for="youtube"><i class="fab fa-youtube"></i> YouTube</label>
                <input type="url" id="youtube" name="youtube"
                       value="<?php echo htmlspecialchars($sosmed['youtube']); ?>"
                       placeholder="https://youtube.com/@channel">
            </div>
            <div>
                <label for="linkedin"><i class="fab fa-linkedin"></i> LinkedIn</label>
                <input type="url" id="linkedin" name="linkedin"
                       value="<?php echo htmlspecialchars($sosmed['linkedin']); ?>"
                       placeholder="https://linkedin.com/company/username">
            </div>
        </div>
        <small>URL lengkap akun media sosial. Kosongkan jika tidak punya.</small>
    </div>

    <!-- Google Maps -->
    <div class="form-section">
        <h2><i class="fas fa-map"></i> Google Maps</h2>
        <div class="form-group">
            <label for="map_embed">Kode Embed Google Maps</label>
            <textarea id="map_embed" name="map_embed" rows="4"
                      placeholder="<iframe src='https://www.google.com/maps/embed?...'></iframe>"><?php echo htmlspecialchars($kontak['map_embed'] ?? ''); ?></textarea>
            <small><i class="fas fa-info-circle"></i>
                Buka Google Maps → cari lokasi → klik Share → pilih "Embed a map" → copy kode iframe.
                Hanya iframe dari Google Maps yang diizinkan. Boleh dikosongkan.
            </small>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="dashboard.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan Perubahan
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function tambahTelepon() {
    const container = document.getElementById('teleponContainer');
    if (container.children.length >= 10) {
        alert('Maksimal 10 nomor telepon.');
        return;
    }
    const index = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'telepon-item';
    div.innerHTML =
        `<input type="text" name="telepon[]" placeholder="Nomor ${index}">` +
        `<button type="button" class="btn-remove" onclick="hapusTelepon(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}

function hapusTelepon(btn) {
    const item = btn.closest('.telepon-item');
    const container = document.getElementById('teleponContainer');
    if (container.children.length > 1) {
        item.remove();
    } else {
        item.querySelector('input').value = '';
    }
}

document.getElementById('submitBtn').addEventListener('click', function () {
    if (this.classList.contains('loading')) return;
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});
</script>

<link rel="stylesheet" href="css/kontak.css">

<?php require_once __DIR__ . '/footer.php'; ?>