const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('http://localhost:8000/astawidya/bem.php?key=astawidya-bem');
  await page.fill('input[name="username"]', 'testadmin');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(1000);
  
  await page.goto('http://localhost:8000/admin/master-kegiatan.php');
  await page.fill('input[name="nama_kegiatan"]', 'Acara E2E Testing 2026');
  await page.fill('textarea[name="deskripsi"]', 'Ini adalah acara yang dibuat secara otomatis oleh robot Playwright.');
  await page.fill('input[name="tanggal_mulai"]', '2026-08-15');
  await page.fill('input[name="tanggal_selesai"]', '2026-08-16');
  await page.selectOption('select[name="ketuplat_id"]', { label: 'Anggota BEM E2E (@testanggota)' });
  
  await Promise.all([
    page.waitForNavigation(),
    page.click('button:has-text("Buat Kegiatan")')
  ]);
  
  const body = await page.innerHTML('body');
  console.log(body);
  await browser.close();
})();
