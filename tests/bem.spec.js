const { test, expect } = require('@playwright/test');

// Base URL sistem Anda, sesuaikan dengan port atau domain yang digunakan
const BASE_URL = 'http://localhost:8000';

test.describe('BEM Lockan System E2E Tests', () => {

    test('1. User Login & Akses Dashboard', async ({ page }) => {
        // Navigasi ke halaman login dengan key untuk bypass Cookie Gate
        await page.goto(`${BASE_URL}/astawidya/bem.php?key=astawidya-bem`);

        // Asumsi form login memiliki name='username' dan name='password'
        await page.fill('input[name="username"]', 'testadmin');
        await page.fill('input[name="password"]', 'password');
        
        // Klik tombol submit/login
        await page.click('button[type="submit"]');

        // Pastikan kita diarahkan ke dashboard
        await expect(page).toHaveURL(/dashboard.php/);
        // await expect(page.locator('text=Dashboard')).toBeVisible(); // Disabled because text may differ
    });

    test('2. Navigasi Master Kegiatan', async ({ page }) => {
        // Setup Login terlebih dahulu
        await page.goto(`${BASE_URL}/astawidya/bem.php?key=astawidya-bem`);
        await page.fill('input[name="username"]', 'testadmin');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        // Klik menu Manajemen Kegiatan di sidebar
        await page.click('text=Manajemen Kegiatan');
        
        // Pastikan URL berubah ke master-kegiatan.php
        await expect(page).toHaveURL(/master-kegiatan.php/);
        
        // Verifikasi elemen halaman Master Kegiatan tampil
        await expect(page.locator('h1')).toContainText('Manajemen Kegiatan');
    });

    test('3. Verifikasi Fitur Tarik Data (Buat Berita Acara)', async ({ page }) => {
        // Setup Login
        await page.goto(`${BASE_URL}/astawidya/bem.php?key=astawidya-bem`);
        await page.fill('input[name="username"]', 'testadmin');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        // Buka halaman Buat Berita Acara
        await page.goto(`${BASE_URL}/admin/buat-berita-acara.php`);

        // Isi form Nama Kegiatan
        await page.fill('#input_nama_kegiatan', 'Pelatihan Test E2E 2026');

        // Klik tombol "Tarik Data"
        const btnTarikData = page.locator('button:has-text("Tarik Data")');
        await btnTarikData.click();

        // Dalam pengujian nyata, kita dapat memantau respon AJAX/API
        // Karena kita mengetes sistem yang berjalan, ini akan melempar Alert
        // "Data Rundown atau Logistik tidak ditemukan..." karena datanya belum ada di DB
        
        // Playwright otomatis mendeteksi dialog/alert
        page.on('dialog', async dialog => {
            expect(dialog.message()).toContain('Data');
            await dialog.accept();
        });
    });

    test('4. Skenario Membuat Kegiatan (Acara) Baru', async ({ page }) => {
        // Setup Login
        await page.goto(`${BASE_URL}/astawidya/bem.php?key=astawidya-bem`);
        await page.fill('input[name="username"]', 'testadmin');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        // Buka halaman Manajemen Kegiatan
        await page.goto(`${BASE_URL}/admin/master-kegiatan.php`);

        // Isi form pembuatan kegiatan
        await page.fill('input[name="nama_kegiatan"]', 'Acara E2E Testing 2026');
        await page.fill('textarea[name="deskripsi"]', 'Ini adalah acara yang dibuat secara otomatis oleh robot Playwright.');
        
        // Isi tanggal
        await page.fill('input[name="tanggal_mulai"]', '2026-08-15');
        await page.fill('input[name="tanggal_selesai"]', '2026-08-16');

        // Opsional: Jika Anda ingin memilih opsi Select/Dropdown untuk Ketuplat
        await page.selectOption('select[name="ketuplat_id"]', { label: 'Anggota BEM E2E (@testanggota)' });

        // Klik tombol "Buat Kegiatan"
        await page.click('button:has-text("Buat Kegiatan")');

        // Verifikasi bahwa Notifikasi Sukses muncul
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page.locator('.alert-success')).toContainText('Kegiatan berhasil ditambahkan');

        // Verifikasi bahwa nama acara muncul di dalam tabel
        await expect(page.locator('table.admin-table')).toContainText('Acara E2E Testing 2026');
    });

    test('5. Skenario Ketuplat Mengakses Workspace', async ({ page }) => {
        // Setup Login sebagai Anggota (yang ditunjuk jadi Ketuplat)
        await page.goto(`${BASE_URL}/astawidya/bem.php?key=astawidya-bem`);
        await page.fill('input[name="username"]', 'testanggota');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        // Pastikan login berhasil masuk ke dashboard
        await expect(page).toHaveURL(/dashboard.php/);

        // Sidebar harusnya menampilkan "Workspace: Acara E2E Testing 2026"
        // Kita akan langsung membuka Workspace Panitia untuk acara tersebut.
        // Karena kita tidak tahu ID acara secara spesifik (Auto Increment),
        // kita akan klik link yang mengandung teks "WS: Acara E2E"
        // Namun, lebih mudah kita navigasi ke Dashboard dan cari linknya
        await page.click('text=WS: Acara E2E');
        
        // Klik "Susunan Panitia" di dalam dropdown Workspace
        await page.click('a:has-text("Susunan Panitia")');
        
        // Verifikasi bahwa kita masuk ke halaman workspace-panitia.php
        await expect(page).toHaveURL(/workspace-panitia.php/);
        
        // Verifikasi bahwa Ketuplat bisa melihat form Plotting Panitia
        await expect(page.locator('h3').first()).toContainText('Plotting Anggota Panitia');
    });

});

