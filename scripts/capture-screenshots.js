/**
 * Playwright screenshot capture script
 * Captures public site and admin login page for README documentation.
 *
 * Usage:
 *   1. Start public PHP server:   php -S 127.0.0.1:8000 -t public/
 *   2. Start admin PHP server:    php -S 127.0.0.1:8001 -t admin/
 *   3. Run:                       node scripts/capture-screenshots.js
 */

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
const fs = require('fs');

const OUT_DIR = path.join(__dirname, '..', 'docs', 'screenshots');

const PUBLIC_BASE = 'http://127.0.0.1:8000';
const ADMIN_BASE  = 'http://127.0.0.1:8001';

const VIEWPORT_DESKTOP = { width: 1440, height: 900 };
const VIEWPORT_MOBILE  = { width: 390,  height: 844 };

const PUBLIC_PAGES = [
  { name: 'home',          path: '/index.html',        title: 'Home' },
  { name: 'sizes',         path: '/sizes.html',         title: 'Dumpster Sizes' },
  { name: 'services',      path: '/services.html',      title: 'Services' },
  { name: 'faq',           path: '/faq.html',           title: 'FAQ' },
  { name: 'contact',       path: '/contact.html',       title: 'Contact' },
  { name: 'service-areas', path: '/service-areas.html', title: 'Service Areas' },
];

const ADMIN_PAGES = [
  { name: 'admin-login', path: '/login.php', title: 'Admin Login' },
];

async function waitForFontsAndImages(page) {
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await page.waitForFunction(() => document.fonts.ready).catch(() => {});
  // Brief pause to let any CSS animations settle
  await page.waitForTimeout(600);
}

async function captureDesktopAndMobile(page, url, baseName) {
  // Desktop
  await page.setViewportSize(VIEWPORT_DESKTOP);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
  await waitForFontsAndImages(page);

  const desktopPath = path.join(OUT_DIR, `${baseName}-desktop.png`);
  await page.screenshot({ path: desktopPath, fullPage: false });
  console.log(`  ✓ Desktop → ${path.basename(desktopPath)}`);

  // Mobile
  await page.setViewportSize(VIEWPORT_MOBILE);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
  await waitForFontsAndImages(page);

  const mobilePath = path.join(OUT_DIR, `${baseName}-mobile.png`);
  await page.screenshot({ path: mobilePath, fullPage: false });
  console.log(`  ✓ Mobile  → ${path.basename(mobilePath)}`);
}

async function run() {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    || chromium.executablePath();
  const browser = await chromium.launch({ headless: true, executablePath });
  const context = await browser.newContext({
    locale: 'en-US',
    timezoneId: 'America/Chicago',
  });
  const page = await context.newPage();

  // ── Public site ────────────────────────────────────────────────────────────
  console.log('\n📸 Public site screenshots\n');
  for (const pg of PUBLIC_PAGES) {
    console.log(`  [${pg.title}]`);
    await captureDesktopAndMobile(page, `${PUBLIC_BASE}${pg.path}`, `public-${pg.name}`);
  }

  // ── Admin site ─────────────────────────────────────────────────────────────
  console.log('\n📸 Admin site screenshots\n');
  for (const pg of ADMIN_PAGES) {
    console.log(`  [${pg.title}]`);
    await captureDesktopAndMobile(page, `${ADMIN_BASE}${pg.path}`, pg.name);
  }

  await browser.close();
  console.log(`\n✅ All screenshots saved to: docs/screenshots/\n`);
}

run().catch((err) => {
  console.error('Screenshot capture failed:', err);
  process.exit(1);
});
