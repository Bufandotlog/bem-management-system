const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto('http://localhost:8000/astawidya/bem.php?key=astawidya-bem');
  await page.fill('input[name="username"]', 'superadmin');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2000);
  const body = await page.innerHTML('body');
  console.log(body);
  await browser.close();
})();
