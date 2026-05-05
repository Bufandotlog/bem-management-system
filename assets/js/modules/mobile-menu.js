/**
 * MODULE: Mobile Menu Toggle
 * Fungsi: Mengontrol tampilan menu pada perangkat mobile
 */
export function initMobileMenu() {
    const menuToggle = document.querySelector('.menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (!menuToggle || !navMenu) return;

    // Toggle menu saat tombol diklik
    menuToggle.addEventListener('click', function() {
        navMenu.classList.toggle('active');
        menuToggle.classList.toggle('active'); // ✅ DITAMBAH: untuk styling CSS saat terbuka

        // Animasi icon burger ↔ times
        const icon = this.querySelector('i');
        if (icon) {
            if (icon.classList.contains('fa-bars')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        }
    });

    // Tutup menu saat klik di luar
    document.addEventListener('click', function(event) {
        const isClickInside = menuToggle.contains(event.target) || navMenu.contains(event.target);
        
        if (!isClickInside && navMenu.classList.contains('active')) {
            navMenu.classList.remove('active');
            menuToggle.classList.remove('active'); // ✅ DITAMBAH: reset class saat ditutup

            const icon = menuToggle.querySelector('i');
            if (icon) {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        }
    });
}