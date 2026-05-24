/**
 * MODULE: Utility Functions
 * Fungsi: Fungsi-fungsi global yang bisa dipanggil dari mana saja
 */

// Fungsi untuk konfirmasi sebelum aksi (misal: hapus)
export function confirmAction(message = 'Apakah Anda yakin?') {
    return confirm(message);
}

// Fungsi untuk format tanggal Indonesia
export function formatTanggalIndonesia(tanggal) {
    const options = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    };
    return new Date(tanggal).toLocaleDateString('id-ID', options);
}

// Fungsi untuk copy teks (misal: alamat, email)
export function copyToClipboard(text, showAlert = true) {
    navigator.clipboard.writeText(text).then(() => {
        if (showAlert) {
            alert('Teks berhasil disalin!');
        }
    }).catch(err => {
        console.error('Gagal menyalin:', err);
    });
}

// Fungsi untuk debounce (membatasi frekuensi eksekusi)
export function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Fungsi untuk throttle (membatasi eksekusi)
export function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}