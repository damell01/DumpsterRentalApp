/**
 * Records a full walkthrough of the public website + admin panel.
 * Output: docs/demo-walkthrough.webm  →  converted to docs/demo-walkthrough.mp4 via ffmpeg
 *
 * Usage:  node scripts/record-mp4.js
 */

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const { execSync } = require('child_process');
const path = require('path');
const fs   = require('fs');

const PUBLIC_BASE = 'http://127.0.0.1:8000';
const ADMIN_BASE  = 'http://127.0.0.1:8001';
const DEMO_URL    = 'file:///home/user/DumpsterRentalApp/docs/demo/admin-demo.html';
const MAP_DEMO    = 'file:///home/user/DumpsterRentalApp/docs/demo/dispatch-map-demo.html';
const ROOT        = path.join(__dirname, '..');
const VIDEO_DIR   = path.join(ROOT, 'docs', '.video-tmp');
const WEBM_OUT    = path.join(ROOT, 'docs', 'demo-walkthrough.webm');
const MP4_OUT     = path.join(ROOT, 'docs', 'demo-walkthrough.mp4');
const VIEWPORT    = { width: 1440, height: 900 };

// Get bundled ffmpeg path
let FFMPEG;
try {
  FFMPEG = execSync('python3 -c "import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())"')
    .toString().trim();
  console.log('  ffmpeg:', FFMPEG);
} catch (e) {
  FFMPEG = null;
  console.warn('  ffmpeg not found — will output WebM only');
}

fs.mkdirSync(VIDEO_DIR, { recursive: true });

// ── Helpers ───────────────────────────────────────────────────────────────────
const wait  = ms => new Promise(r => setTimeout(r, ms));

async function load(page, url, opts) {
  try {
    await page.goto(url, { waitUntil: 'networkidle', timeout: 12000, ...opts });
  } catch (_) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 });
  }
  await wait(600);
}

async function scrollBy(page, px, delay) {
  await page.evaluate(p => window.scrollBy({ top: p, behavior: 'smooth' }), px);
  await wait(delay || 900);
}

async function scrollTop(page) {
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
  await wait(300);
}

async function hover(page, selector) {
  try {
    await page.hover(selector, { timeout: 2000 });
    await wait(400);
  } catch (_) {}
}

async function switchView(page, name, delay) {
  await page.evaluate(v => window.showView(v), name);
  await wait(delay || 900);
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  console.log('\n🎬  Recording walkthrough — 1440×900\n');

  const browser = await chromium.launch({
    headless: true,
    executablePath: chromium.executablePath(),
    args: ['--disable-web-security'],
  });

  const context = await browser.newContext({
    viewport: VIEWPORT,
    locale: 'en-US',
    timezoneId: 'America/Chicago',
    recordVideo: { dir: VIDEO_DIR, size: VIEWPORT },
  });

  const page = await context.newPage();

  // ── PART 1: Public Website ─────────────────────────────────────────────────
  console.log('📱  Public website');

  // Home page
  console.log('    Home');
  await load(page, PUBLIC_BASE + '/index.html');
  await wait(1200);
  await scrollBy(page, 500, 1200);
  await scrollBy(page, 500, 1200);
  await scrollBy(page, 600, 1000);
  await scrollBy(page, 500, 1000);
  await scrollTop(page);
  await wait(600);

  // Sizes page
  console.log('    Sizes');
  await load(page, PUBLIC_BASE + '/sizes.html');
  await wait(1200);
  await scrollBy(page, 400, 1000);
  await scrollBy(page, 400, 1000);
  await scrollBy(page, 400, 900);
  await scrollTop(page);
  await wait(400);

  // Services page
  console.log('    Services');
  await load(page, PUBLIC_BASE + '/services.html');
  await wait(1200);
  await scrollBy(page, 500, 1000);
  await scrollBy(page, 500, 900);
  await scrollTop(page);
  await wait(400);

  // FAQ page
  console.log('    FAQ');
  await load(page, PUBLIC_BASE + '/faq.html');
  await wait(1000);
  await scrollBy(page, 400, 900);
  await scrollTop(page);
  await wait(400);

  // Contact page
  console.log('    Contact');
  await load(page, PUBLIC_BASE + '/contact.html');
  await wait(1000);
  await scrollBy(page, 300, 800);
  await scrollTop(page);
  await wait(400);

  // Service Areas
  console.log('    Service Areas');
  await load(page, PUBLIC_BASE + '/service-areas.html');
  await wait(1500);
  await scrollBy(page, 400, 1000);
  await scrollTop(page);
  await wait(600);

  // ── PART 2: Admin Login ────────────────────────────────────────────────────
  console.log('\n🔐  Admin login');
  await load(page, ADMIN_BASE + '/login.php');
  await wait(1500);
  // Simulate typing into the login form
  try {
    await page.fill('input[name="username"], input[type="text"]', 'admin');
    await wait(600);
    await page.fill('input[name="password"], input[type="password"]', '••••••••');
    await wait(600);
  } catch (_) {}
  await wait(1200);

  // ── PART 3: Admin Panel (static demo) ─────────────────────────────────────
  console.log('\n🖥️   Admin panel demo');
  await load(page, DEMO_URL);
  await wait(800);

  // Work Orders
  console.log('    Work Orders');
  await switchView(page, 'work-orders', 1000);
  // Hover a couple rows to show interactivity
  try {
    const rows = await page.$$('.tp-table tbody tr');
    if (rows[1]) { await rows[1].hover(); await wait(500); }
    if (rows[3]) { await rows[3].hover(); await wait(500); }
  } catch (_) {}
  await wait(1500);

  // Work Order Detail
  console.log('    Work Order Detail');
  await switchView(page, 'wo-detail', 1200);
  await scrollBy(page, 200, 800);
  await scrollTop(page);
  await wait(800);

  // Bookings
  console.log('    Bookings');
  await switchView(page, 'bookings', 1000);
  await scrollBy(page, 150, 800);
  await scrollTop(page);
  await wait(800);

  // Invoices
  console.log('    Invoices');
  await switchView(page, 'invoices', 1000);
  await wait(1200);

  // Subscriptions
  console.log('    Subscriptions');
  await switchView(page, 'subscriptions', 1000);
  await wait(1000);
  await scrollBy(page, 400, 1200);
  await scrollTop(page);
  await wait(600);

  // Calendar
  console.log('    Calendar');
  await switchView(page, 'calendar', 1000);
  await wait(1800);

  // Reports
  console.log('    Reports');
  await switchView(page, 'reports', 1000);
  await wait(1000);
  await scrollBy(page, 400, 1200);
  await scrollTop(page);
  await wait(600);

  // Inventory
  console.log('    Inventory');
  await switchView(page, 'inventory', 1000);
  await wait(1500);

  // Size Catalog
  console.log('    Size Catalog');
  await switchView(page, 'size-catalog', 1000);
  await wait(1500);

  // Webhooks
  console.log('    Webhook Logs');
  await switchView(page, 'webhooks', 1000);
  await wait(1800);

  // ── PART 4: Dispatch Map ───────────────────────────────────────────────────
  console.log('\n🗺️   Dispatch map');
  try {
    await load(page, MAP_DEMO);
    await wait(2000);
    await scrollBy(page, 300, 1200);
    await wait(1500);
    await scrollTop(page);
    await wait(800);
  } catch (e) {
    console.warn('    (dispatch map demo skipped)');
  }

  // Brief end-card pause
  await wait(1000);

  // ── Finish recording ───────────────────────────────────────────────────────
  console.log('\n⏹️   Stopping recording...');
  await context.close();
  await browser.close();

  // Find the recorded file
  const files = fs.readdirSync(VIDEO_DIR).filter(f => f.endsWith('.webm'));
  if (!files.length) { console.error('No video file found'); process.exit(1); }

  const src = path.join(VIDEO_DIR, files[0]);
  fs.copyFileSync(src, WEBM_OUT);
  fs.rmSync(VIDEO_DIR, { recursive: true, force: true });

  const webmMB = (fs.statSync(WEBM_OUT).size / 1024 / 1024).toFixed(1);
  console.log(`\n  WebM saved: docs/demo-walkthrough.webm (${webmMB} MB)`);

  // Convert to MP4
  if (FFMPEG) {
    console.log('  Converting to MP4...');
    try {
      execSync(
        `"${FFMPEG}" -y -i "${WEBM_OUT}" ` +
        `-c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p ` +
        `-movflags +faststart "${MP4_OUT}" 2>&1`,
        { stdio: 'pipe' }
      );
      const mp4MB = (fs.statSync(MP4_OUT).size / 1024 / 1024).toFixed(1);
      console.log(`  MP4  saved: docs/demo-walkthrough.mp4  (${mp4MB} MB)`);
    } catch (e) {
      console.error('  MP4 conversion failed:', e.message);
    }
  }

  console.log('\n✅  Done!\n');
})().catch(err => {
  console.error('Recording failed:', err);
  process.exit(1);
});
