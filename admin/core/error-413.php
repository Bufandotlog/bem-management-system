<?php
require_once __DIR__ . '/../core/header.php';
?>
<!-- 413 Error — Request Entity Too Large -->
<style>
.error-page {
  min-height: 70vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  text-align: center;
}
.error-code {
  font-size: 5rem;
  font-weight: 800;
  color: var(--warning, #d97706);
  line-height: 1;
  margin-bottom: 1rem;
}
.error-title {
  font-size: 1.5rem;
  color: var(--text-dark, #1a1a2e);
  margin-bottom: 1rem;
}
.error-desc {
  color: var(--text-muted, #64748b);
  max-width: 600px;
  margin: 0 auto 2rem;
}
.error-tip {
  background: rgba(217, 119, 6, 0.1);
  border: 1px solid rgba(217, 119, 6, 0.2);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  display: inline-block;
  color: var(--warning, #d97706);
  font-size: 0.9rem;
}
</style>
<div class="error-page">
  <div class="error-content">
    <div class="error-code">413</div>
    <div class="error-title">Permintaan Terlalu Besar</div>
    <p class="error-desc">
      Ukuran file atau data yang Anda kirimkan melebihi batas maksimal yang
      diizinkan oleh server.
    </p>
    <div class="error-tip">
      💡 <strong>Tips:</strong> Kompresi gambar dengan <a href="https://tinypng.com"
      target="_blank" style="color:inherit;text-decoration:underline">TinyPNG</a>
      sebelum upload. Maks ukuran file:
      <?php echo round(MAX_FILE_SIZE / 1024 / 1024, 0); ?>MB
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../core/footer.php'; ?>
