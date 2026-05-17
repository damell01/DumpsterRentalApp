/**
 * Playwright screenshot capture — Admin Panel views
 * Captures docs/demo/admin-demo.html for all 7 admin views.
 *
 * Usage:
 *   npm run admin-screenshots
 */

const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const path = require('path');
const fs   = require('fs');

const OUT_DIR  = path.join(__dirname, '..', 'docs', 'screenshots');
const DEMO_URL = 'file://' + path.join(__dirname, '..', 'docs', 'demo', 'admin-demo.html');
const VIEWPORT = { width: 1440, height: 900 };

async function settle(page, ms) {
  await page.waitForTimeout(ms || 600);
}

async function loadDemo(page) {
  await page.goto(DEMO_URL, { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.waitForSelector('.sidebar', { timeout: 8000 });
  await settle(page, 400);
}

async function switchView(page, name) {
  await page.evaluate(function (v) { window.showView(v); }, name);
  await settle(page, 400);
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

  console.log('\n📸 Admin Panel screenshots\n');

  // ── 1. Work Orders list ───────────────────────────────────────────────────
  console.log('  [Work Orders — list with statuses, filters, priority badges]');
  await loadDemo(page);
  await switchView(page, 'work-orders');
  await shot(page, 'admin-work-orders.png');

  // ── 2. Work Order detail ──────────────────────────────────────────────────
  console.log('  [Work Order Detail — job info grid, customer info, status timeline]');
  await loadDemo(page);
  await switchView(page, 'wo-detail');
  await shot(page, 'admin-wo-detail.png');

  // ── 3. Bookings ───────────────────────────────────────────────────────────
  console.log('  [Bookings — requests with payment method, filter tabs]');
  await loadDemo(page);
  await switchView(page, 'bookings');
  await shot(page, 'admin-bookings.png');

  // ── 4. Invoices ───────────────────────────────────────────────────────────
  console.log('  [Invoices — draft/sent/paid statuses, Stripe payment links]');
  await loadDemo(page);
  await switchView(page, 'invoices');
  await shot(page, 'admin-invoices.png');

  // ── 5. Inventory ──────────────────────────────────────────────────────────
  console.log('  [Inventory — dumpster fleet, availability, assignments]');
  await loadDemo(page);
  await switchView(page, 'inventory');
  await shot(page, 'admin-inventory.png');

  // ── 6. Calendar ───────────────────────────────────────────────────────────
  console.log('  [Calendar — monthly grid with delivery/pickup events]');
  await loadDemo(page);
  await switchView(page, 'calendar');
  await shot(page, 'admin-calendar.png');

  // ── 7. Reports ────────────────────────────────────────────────────────────
  console.log('  [Reports — KPI cards, revenue charts, status breakdown]');
  await loadDemo(page);
  await switchView(page, 'reports');
  await shot(page, 'admin-reports.png', { fullPage: true });

  await browser.close();
  console.log('\n✅ All admin screenshots saved to: docs/screenshots/\n');
}

run().catch(err => { console.error('Screenshot capture failed:', err); process.exit(1); });
