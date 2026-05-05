/**
 * MODULE: Back to Top Button
 * Fungsi: Tombol kembali ke atas halaman
 */

export function initBackToTop() {
    // Cek apakah tombol sudah ada
    let backToTop = document.querySelector('.back-to-top');
    
    if (!backToTop) {
        backToTop = document.createElement('div');
        backToTop.className = 'back-to-top';
        backToTop.innerHTML = '<i class="fas fa-arrow-up"></i>';
        backToTop.style.cssText = `
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: #1e3a8a;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        `;
        
        document.body.appendChild(backToTop);
    }

    window.addEventListener('scroll', function() {
        if (window.scrollY > 500) {
            backToTop.style.opacity = '1';
            backToTop.style.visibility = 'visible';
        } else {
            backToTop.style.opacity = '0';
            backToTop.style.visibility = 'hidden';
        }
    });

    backToTop.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}