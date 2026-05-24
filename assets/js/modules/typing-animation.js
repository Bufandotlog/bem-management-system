export function initTypingAnimation() {
    console.log('✏️ Typing animation dimulai');
    
    const heroTitle = document.querySelector('.hero-title');
    const heroSub = document.querySelector('.hero-sub');
    
    if (!heroTitle || !heroSub) {
        console.warn('⚠️ Hero title/sub tidak ditemukan');
        return;
    }
    
    // Simpan teks beserta HTML (innerHTML)
    const titleHTML = heroTitle.innerHTML;
    const subText = heroSub.innerText;
    
    // Kosongkan
    heroTitle.innerHTML = '';
    heroSub.innerHTML = '';
    
    let i = 0;
    let j = 0;
    let timeoutId;
    
    function typeTitle() {
        if (i < titleHTML.length) {
            // Tampilkan HTML apa adanya (termasuk tag)
            heroTitle.innerHTML = titleHTML.substring(0, i + 1);
            i++;
            
            // Kecepatan tetap 30ms untuk title
            timeoutId = setTimeout(typeTitle, 30);
        } else {
            // Delay 150ms sebelum sub judul
            timeoutId = setTimeout(typeSub, 150);
        }
    }
    
    function typeSub() {
        if (j < subText.length) {
            heroSub.innerHTML += subText[j];
            j++;
            
            // Kecepatan tetap 20ms untuk sub (lebih cepat)
            timeoutId = setTimeout(typeSub, 20);
        }
    }
    
    // Mulai animasi dengan delay awal 200ms
    timeoutId = setTimeout(typeTitle, 200);
    
    // Cleanup function jika diperlukan
    return () => {
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
    };
}