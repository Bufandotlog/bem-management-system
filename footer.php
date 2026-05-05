<?php
// footer.php - File footer
?>
    </main>
    <footer>
        <div class="footer-content">
            <div class="footer-section">
                <h3>BEM Kabinet Astawidya</h3>
                <p>Mewujudkan BEM yang responsif, aspiratif, dan inovatif dalam membangun mahasiswa yang berkarakter, berkualitas, dan bermanfaat bagi masyarakat..</p>
                <div class="social-links">
                    <a href="https://www.instagram.com/beminstbunas?igsh=MTQ3ZmxndmJhZ24xMA=="><i class="fab fa-instagram"></i></a>
                    <a href="https://www.tiktok.com/@bem.instbunas?is_from_webapp=1&sender_device=pc"><i class="fab fa-tiktok"></i></a>
                    <!-- <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-youtube"></i></a> -->
                </div>
            </div>
            <div class="footer-section">
                <h3>Tautan Cepat</h3>
                <a href="index.php">Beranda</a>
                <a href="berita.php">Berita</a>
                <a href="kepengurusan.php">Kepengurusan</a>
                <a href="kontak.php">Kontak</a>
            </div>
            <div class="footer-section">
                <h3>Kontak</h3>
                <p><i class="fas fa-map-marker-alt"></i> Jl. Siliwangi No. 121, Desa Heuleut, Kecamatan Kadipaten, Kabupaten Majalengka, Jawa Barat, 45452</p>
                <p><i class="fas fa-phone"></i> +62 838-0585-3345</p>
                <p><i class="fas fa-envelope"></i> beminstbunasastawidya@gmail.com</p>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; 2025 BEM Kabinet Astawidya.BFN.v1.2.26. Hak Cipta Dilindungi.</p>
        </div>
    </footer>

    <!-- HAPUS SCRIPT LAMA INI:
    <script>
        // Mobile menu toggle
        document.querySelector('.menu-toggle').addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        // Active menu highlight
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-menu a');
        navLinks.forEach(link => {
            const linkPage = link.getAttribute('href');
            if (currentPage === linkPage || (currentPage === '' && linkPage === 'index.php')) {
                link.classList.add('active');
            }
        });
    </script>
    <script src="script.js"></script>
    -->

    <!-- GANTI DENGAN YANG INI - JavaScript Modular -->
    
    <!-- 1. Jquery (jika diperlukan, opsional) -->
    <!-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> -->
    
    <!-- 2. Font Awesome (sudah di header, tapi amannya di sini juga) -->
    <!-- <script src="https://kit.fontawesome.com/your-kit.js"></script> -->
    
    <!-- 3. JavaScript Modular - menggunakan type="module" -->
    <?php $js_script_ver = file_exists(__DIR__ . '/assets/js/script.js') ? filemtime(__DIR__ . '/assets/js/script.js') : '1'; ?>
    <script type="module" src="<?php echo assetUrl('js/script.js'); ?>?v=<?php echo $js_script_ver; ?>"></script>
    
    <!-- 4. Fallback untuk browser lama (jika diperlukan) -->
    <script nomodule src="assets/js/fallback.js"></script>
    
    <!-- 5. Inisialisasi tambahan jika diperlukan (opsional) -->
    <script>
        // Fallback sederhana jika module tidak jalan
        (function() {
            if (!window.fetch) {
                console.warn('Browser lama terdeteksi, menggunakan fallback');
                // Browser lama, fallback sudah disediakan
            }
        })();
    </script>
</body>
</html>