/* ========== MAIN STYLE.CSS ========== */
/* File ini mengimpor semua file CSS lainnya */

@import url('variables.css');
@import url('navbar.css');
@import url('hero.css');
@import url('sambutan.css');
@import url('visi-misi.css');
@import url('cards.css');
@import url('kepengurusan.css');
@import url('detail-menteri.css');
@import url('berita.css');
@import url('kontak.css');
@import url('footer.css');
@import url('responsive.css');
@import url('global-background.css');

/* Animasi Scroll (umum) */
.sambutan, .visi-misi, .card, .kontak-item, .menteri-item {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.6s ease, transform 0.6s ease;
}

/* ===== OVERRIDE UNTUK MEMASTIKAN TIDAK ADA KONFLIK ===== */

/* Pastikan konten dimulai setelah hero */
.sambutan {
    margin-top: 100vh !important;
    position: relative;
    z-index: 10;
    display: block;
    opacity: 1;
    visibility: visible;
}

.visi-misi {
    display: block;
    opacity: 1;
    visibility: visible;
    position: relative;
    z-index: 10;
}

.card-grid, .home-news-grid {
    display: grid;
    opacity: 1;
    visibility: visible;
    position: relative;
    z-index: 10;
}

footer {
    position: relative;
    z-index: 10;
}

/* Hero tetap di belakang */
.hero {
    z-index: -1 !important;
}

/* Pastikan card menggunakan style dari cards.css */
.card {
    width: auto;
    flex: 1 1 auto;
}

/* Pastikan home news card tetap di tengah */
.home-news-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    gap: 2rem;
    justify-content: center;
}

.home-news-card {
    width: 100%;
    max-width: 500px;
    margin: 0 auto;
}