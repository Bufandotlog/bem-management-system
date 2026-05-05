/**
 * MODULE: Navigation
 * Fungsi: Highlight menu aktif berdasarkan halaman
 */

export function initNavigation() {
    function highlightActiveMenu() {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav-menu a');
        
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            
            // Handle halaman utama (index.php)
            if ((currentPage === '' || currentPage === 'index.php') && href === 'index.php') {
                link.classList.add('active');
            } 
            // Handle halaman lain
            else if (href === currentPage) {
                link.classList.add('active');
            } 
            // Hapus active dari yang lain
            else {
                link.classList.remove('active');
            }
        });
    }
    
    highlightActiveMenu();
}