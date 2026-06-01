/**
 * Records a walkthrough demo video of the admin panel using Playwright.
 * Output: docs/demo-video.webm  (converted to .mp4 if ffmpeg is available)
 *
 * Usage:  node scripts/record-demo-video.js
 */

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
const fs   = require('fs');

const DEMO_URL   = 'file:///home/user/DumpsterRentalApp/docs/demo/admin-demo.html';
const PUBLIC_URL = 'http://127.0.0.1:8000';
const OUT_DIR    = path.join(__dirname, '..', 'docs');
const VIDEO_DIR  = path.join(OUT_DIR, '.video-tmp');
const VIEWPORT   = { width: 1440, height: 900 };

fs.mkdirSync(VIDEO_DIR, { recursive: true });

async function pause(ms) {
  return new Promise(r => setTimeout(r, ms));
}

async function switchView(page, name) {
  await page.evaluate(v => window.showView(v), name);
  await pause(800);
}

async function scrollDown(page, px) {
  await page.evaluate(p => window.scrollBy({ top: p, behavior: 'smooth' }), px);
  await pause(700);
}

async function scrollTop(page) {
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await pause(400);
}

(async () => {
  console.log('\n🎬  Recording demo walkthrough...\n');

  const browser = await chromium.launch({
    headless: true,
    executablePath: chromium.executablePath(),
  });

  const context = await browser.newContext({
    viewport: VIEWPORT,
    locale: 'en-US',
    recordVideo: {
      dir: VIDEO_DIR,
      size: VIEWPORT,
    },
  });

  const page = await context.newPage();

  // ── 1. Public website ───────────────────────────────────────────────────
  console.log('  Public website...');
  try {
    await page.goto(PUBLIC_URL + '/index.html', { waitUntil: 'networkidle', timeout: 8000 });
    await pause(1800);
    await scrollDown(page, 500);
    await pause(1200);
    await scrollDown(page, 500);
    await pause(1000);
    await scrollTop(page);

    await page.goto(PUBLIC_URL + '/sizes.html', { waitUntil: 'networkidle', timeout: 8000 });
    await pause(1500);
    await scrollDown(page, 400);
    await pause(1000);

    await page.goto(PUBLIC_URL + '/services.html', { waitUntil: 'networkidle', timeout: 8000 });
    await pause(1500);
  } catch (e) {
    console.warn('  (Public server not running — skipping public pages)');
  }

  // ── 2. Admin login ──────────────────────────────────────────────────────
  console.log('  Admin login...');
  try {
    await page.goto('http://127.0.0.1:8001/login.php', { waitUntil: 'networkidle', timeout: 8000 });
    await pause(1800);
  } catch (e) {
    console.warn('  (Admin server not running — skipping login page)');
  }

  // ── 3. Admin panel demo ─────────────────────────────────────────────────
  console.log('  Admin panel demo...');
  await page.goto(DEMO_URL, { waitUntil: 'domcontentloaded' });
  await pause(1000);

  // Dashboard — Work Orders
  console.log('    Work Orders');
  await switchView(page, 'work-orders');
  await scrollDown(page, 200);
  await pause(1500);

  // Work Order Detail
  console.log('    Work Order Detail');
  await switchView(page, 'wo-detail');
  await pause(1800);

  // Bookings
  console.log('    Bookings');
  await switchView(page, 'bookings');
  await pause(1800);

  // Invoices
  console.log('    Invoices');
  await switchView(page, 'invoices');
  await pause(1800);

  // Subscriptions
  console.log('    Subscriptions');
  await switchView(page, 'subscriptions');
  await pause(1500);
  await scrollDown(page, 350);
  await pause(1800);
  await scrollTop(page);

  // Calendar
  console.log('    Calendar');
  await switchView(page, 'calendar');
  await pause(1800);

  // Reports
  console.log('    Reports');
  await switchView(page, 'reports');
  await pause(1500);
  await scrollDown(page, 400);
  await pause(1500);
  await scrollTop(page);

  // Inventory
  console.log('    Inventory');
  await switchView(page, 'inventory');
  await pause(1500);

  // Size Catalog
  console.log('    Size Catalog');
  await switchView(page, 'size-catalog');
  await pause(1800);

  // Webhook Logs
  console.log('    Webhook Logs');
  await switchView(page, 'webhooks');
  await pause(2000);

  // ── 4. Dispatch map demo ────────────────────────────────────────────────
  console.log('  Dispatch map...');
  const MAP_DEMO = 'file:///home/user/DumpsterRentalApp/docs/demo/dispatch-map-demo.html';
  try {
    await page.goto(MAP_DEMO, { waitUntil: 'domcontentloaded', timeout: 10000 });
    await pause(2000);
    await scrollDown(page, 300);
    await pause(1500);
  } catch (e) {
    console.warn('  (Dispatch map demo not available)');
  }

  // Finish
  await pause(500);
  await context.close();
  await browser.close();

  // Find the recorded file
  const files = fs.readdirSync(VIDEO_DIR).filter(f => f.endsWith('.webm'));
  if (!files.length) {
    console.error('No video file found in', VIDEO_DIR);
    process.exit(1);
  }

  const src  = path.join(VIDEO_DIR, files[0]);
  const dest = path.join(OUT_DIR, 'demo-walkthrough.webm');
  fs.renameSync(src, dest);
  fs.rmdirSync(VIDEO_DIR, { recursive: true });

  const sizeMB = (fs.statSync(dest).size / 1024 / 1024).toFixed(1);
  console.log('\n✅  Video saved: docs/demo-walkthrough.webm (' + sizeMB + ' MB)');
  console.log('    Share directly or convert to MP4 with:');
  console.log('    ffmpeg -i docs/demo-walkthrough.webm -c:v libx264 docs/demo-walkthrough.mp4\n');
})().catch(err => {
  console.error('Recording failed:', err);
  process.exit(1);
});
