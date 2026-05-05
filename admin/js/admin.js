// admin/js/admin.js - JavaScript untuk halaman admin

document.addEventListener('DOMContentLoaded', function() {
    
    // ===== AUTO HIDE FLASH MESSAGE =====
    const flashMessage = document.querySelector('.flash-message');
    if (flashMessage) {
        setTimeout(() => {
            flashMessage.style.opacity = '0';
            setTimeout(() => {
                flashMessage.style.display = 'none';
            }, 300);
        }, 3000);
    }
    
    // ===== ACTIVE MENU HIGHLIGHT =====
    const currentPage = window.location.pathname.split('/').pop();
    const navLinks = document.querySelectorAll('.sidebar-menu a');
    
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href === currentPage) {
            link.classList.add('active');
        }
    });
    
    // ===== CONFIRM DELETE =====
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Apakah Anda yakin ingin menghapus data ini?')) {
                e.preventDefault();
            }
        });
    });
    
    // ===== TOGGLE SIDEBAR ON MOBILE (untuk pengembangan nanti) =====
    const toggleSidebar = () => {
        // Bisa ditambahkan nanti jika perlu responsive
    };
    
});

// admin/js/sidebar.js - Toggle sidebar mobile
// Tambahkan <script src="js/sidebar.js"></script> di admin/footer.php

(function () {
    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('sidebarToggle');
    const closeBtn = document.getElementById('sidebarClose');
    const overlay  = document.getElementById('sidebarOverlay');

    if (!sidebar) return;

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden'; // Cegah scroll body
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (toggle)   toggle.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay)  overlay.addEventListener('click', closeSidebar);

    // Tutup sidebar saat tekan Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // Tutup sidebar otomatis saat resize ke desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });
})();