<?php
// kontak.php - Halaman Kontak (Terintegrasi Database)
include 'header.php';
$page_title = 'Kontak';

// Ambil data kontak dari database
$kontak_data = dbFetchOne("SELECT * FROM kontak WHERE id = 1");

// Jika tidak ada data, redirect atau tampilkan default
if (!$kontak_data) {
    // Fallback data (opsional)
    $kontak_data = [
        'alamat' => 'Jl. Siliwangi No. 121, Desa Heuleut, Kecamatan Kadipaten, Kabupaten Majalengka, Jawa Barat, 45452',
        'telepon' => json_encode(['+62 838-0585-3345', '+62 857-2209-0532']),
        'email' => 'beminstbunasastawidya@gmail.com',
        'jam_kerja' => json_encode(['senin_jumat' => '09.00 - 16.00', 'sabtu' => '09.00 - 12.00', 'minggu' => 'Libur']),
        'sosial_media' => json_encode([
            'instagram' => 'https://www.instagram.com/beminstbunas',
            'tiktok' => 'https://www.tiktok.com/@bem.instbunas',
            'twitter' => '#',
            'youtube' => '#',
            'linkedin' => '#'
        ]),
        'map_embed' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3960.986245449879!2d108.15525657410822!3d-6.882119767326242!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e6f3c7b8b8b8b8b%3A0x2b2b2b2b2b2b2b2b!2sJl.%20Siliwangi%20No.121%2C%20Heuleut%2C%20Kec.%20Kadipaten%2C%20Kabupaten%20Majalengka%2C%20Jawa%20Barat%2045452!5e0!3m2!1sid!2sid!4v1700000000000!5m2!1sid!2sid'
    ];
} else {
    // Decode JSON fields
    $kontak_data['telepon'] = json_decode($kontak_data['telepon'], true) ?: [];
    $kontak_data['jam_kerja'] = json_decode($kontak_data['jam_kerja'], true) ?: [];
    $kontak_data['sosial_media'] = json_decode($kontak_data['sosial_media'], true) ?: [];
}
?>

<div class="kontak-container">
    <div class="kontak-info">
        <h2 style="font-size: 2rem; margin-bottom: 2rem;">Hubungi <span class="text-merah">Kami</span></h2>
        
        <!-- Alamat -->
        <div class="kontak-item">
            <i class="fas fa-map-marker-alt"></i>
            <div>
                <h3>Alamat</h3>
                <p><?php echo htmlspecialchars($kontak_data['alamat']); ?></p>
            </div>
        </div>
        
        <!-- Telepon (Looping karena bisa lebih dari 1) -->
        <div class="kontak-item">
            <i class="fas fa-phone"></i>
            <div>
                <h3>Telepon</h3>
                <?php if (!empty($kontak_data['telepon'])): ?>
                    <?php foreach($kontak_data['telepon'] as $telp): ?>
                        <p><?php echo htmlspecialchars($telp); ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>-</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Email -->
        <div class="kontak-item">
            <i class="fas fa-envelope"></i>
            <div>
                <h3>Email</h3>
                <p><?php echo htmlspecialchars($kontak_data['email']); ?></p>
            </div>
        </div>
        
        <!-- Jam Kerja -->
        <div class="kontak-item">
            <i class="fas fa-clock"></i>
            <div>
                <h3>Jam Kerja</h3>
                <?php if (!empty($kontak_data['jam_kerja'])): ?>
                    <p>Senin - Jumat: <?php echo htmlspecialchars($kontak_data['jam_kerja']['senin_jumat'] ?? '-'); ?></p>
                    <p>Sabtu: <?php echo htmlspecialchars($kontak_data['jam_kerja']['sabtu'] ?? '-'); ?></p>
                    <?php if(isset($kontak_data['jam_kerja']['minggu'])): ?>
                        <p>Minggu: <?php echo htmlspecialchars($kontak_data['jam_kerja']['minggu']); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p>-</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Media Sosial -->
        <div style="margin-top: 3rem;">
            <h3 style="margin-bottom: 1rem;">Media Sosial</h3>
            <div class="social-links" style="justify-content: flex-start;">
                <?php if(!empty($kontak_data['sosial_media']['instagram']) && $kontak_data['sosial_media']['instagram'] != '#'): ?>
                <a href="<?php echo htmlspecialchars($kontak_data['sosial_media']['instagram']); ?>" target="_blank"><i class="fab fa-instagram"></i></a>
                <?php endif; ?>
                
                <?php if(!empty($kontak_data['sosial_media']['tiktok']) && $kontak_data['sosial_media']['tiktok'] != '#'): ?>
                <a href="<?php echo htmlspecialchars($kontak_data['sosial_media']['tiktok']); ?>" target="_blank"><i class="fab fa-tiktok"></i></a>
                <?php endif; ?>
                
                <?php if(!empty($kontak_data['sosial_media']['twitter']) && $kontak_data['sosial_media']['twitter'] != '#'): ?>
                <a href="<?php echo htmlspecialchars($kontak_data['sosial_media']['twitter']); ?>" target="_blank"><i class="fab fa-twitter"></i></a>
                <?php endif; ?>
                
                <?php if(!empty($kontak_data['sosial_media']['youtube']) && $kontak_data['sosial_media']['youtube'] != '#'): ?>
                <a href="<?php echo htmlspecialchars($kontak_data['sosial_media']['youtube']); ?>" target="_blank"><i class="fab fa-youtube"></i></a>
                <?php endif; ?>
                
                <?php if(!empty($kontak_data['sosial_media']['linkedin']) && $kontak_data['sosial_media']['linkedin'] != '#'): ?>
                <a href="<?php echo htmlspecialchars($kontak_data['sosial_media']['linkedin']); ?>" target="_blank"><i class="fab fa-linkedin"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Google Maps -->
    <div class="map-container">
        <?php if (!empty($kontak_data['map_embed'])): ?>
            <iframe src="<?php echo htmlspecialchars($kontak_data['map_embed']); ?>" width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        <?php else: ?>
            <div style="width:100%; height:450px; background:#111; display:flex; align-items:center; justify-content:center; color:#666;">
                <p>Peta tidak tersedia</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>