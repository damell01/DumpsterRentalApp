/**
 * Playwright screenshot capture — Dispatch Map features
 * Captures the demo page at docs/demo/dispatch-map-demo.html.
 *
 * Usage:
 *   npm run map-screenshots
 */

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
const fs   = require('fs');

const OUT_DIR  = path.join(__dirname, '..', 'docs', 'screenshots');
const DEMO_URL = 'file://' + path.join(__dirname, '..', 'docs', 'demo', 'dispatch-map-demo.html');
const VIEWPORT = { width: 1440, height: 900 };

async function settle(page, ms) {
  await page.waitForTimeout(ms || 800);
}

async function loadDemo(page) {
  await page.goto(DEMO_URL, { waitUntil: 'domcontentloaded', timeout: 15000 });
  // Wait for Leaflet to mount the map container
  await page.waitForSelector('.leaflet-container', { timeout: 8000 });
  // Wait for markers to appear
  await page.waitForSelector('.leaflet-marker-icon', { timeout: 8000 });
  await settle(page, 600);
}

async function shot(page, filename, opts) {
  const p = path.join(OUT_DIR, filename);
  await page.screenshot({ path: p, fullPage: opts && opts.fullPage, clip: opts && opts.clip });
  console.log('  ✓ ' + filename);
}

async function run() {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH
    || chromium.executablePath();
  const browser = await chromium.launch({ headless: true, executablePath });
  const context = await browser.newContext({ locale: 'en-US', timezoneId: 'America/Chicago' });
  const page    = await context.newPage();
  await page.setViewportSize(VIEWPORT);

  console.log('\n📸 Dispatch Map screenshots\n');

  // ── 1. Day view ──────────────────────────────────────────────────────────
  console.log('  [Day view — date picker, job markers, tables]');
  await loadDemo(page);
  await shot(page, 'dispatch-map-day-view.png');

  // ── 2. Job popup (phone + dumpster info + status action buttons) ─────────
  console.log('  [Popup — phone, dumpster, action buttons]');
  await loadDemo(page);
  await page.evaluate(() => window.openJobPopup(0));   // John Smith — scheduled
  await settle(page, 500);
  await shot(page, 'dispatch-map-popup.png');

  // ── 3. Optimized route (polyline + route panel + Google Maps / Print) ────
  console.log('  [Route — polyline, numbered stops, route panel]');
  await loadDemo(page);
  await page.evaluate(() => window.runDemoRoute());
  await settle(page, 600);
  await shot(page, 'dispatch-map-route.png', { fullPage: true });

  // ── 4. All Active view ───────────────────────────────────────────────────
  console.log('  [All Active — every dumpster on-site, full table]');
  await loadDemo(page);
  await page.evaluate(() => window.switchToActiveView());
  await settle(page, 600);
  await shot(page, 'dispatch-map-active.png', { fullPage: true });

  await browser.close();
  console.log('\n✅ All dispatch map screenshots saved to: docs/screenshots/\n');
}

run().catch(err => { console.error('Screenshot capture failed:', err); process.exit(1); });
