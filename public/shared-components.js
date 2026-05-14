/* shared-components.js - injects nav, footer, ticker, and live dumpster sizes */

const LOGO_SRC = 'assets/logo.jpeg';
const FONT_CSS_HREF = 'https://fonts.googleapis.com/css2?family=Black+Han+Sans&family=Barlow+Condensed:ital,wght@0,300;0,400;0,600;0,700;0,800;0,900;1,700&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap';

if (!document.querySelector('link[href*="fonts.googleapis.com/css2?family=Black+Han+Sans"]')) {
  const fontLink = document.createElement('link');
  fontLink.rel = 'stylesheet';
  fontLink.href = FONT_CSS_HREF;
  document.head.appendChild(fontLink);
}

const navHTML = `
<nav class="site-nav" id="siteNav">
  <div class="nav-inner">
    <a href="index.html" class="nav-logo">
      <img src="${LOGO_SRC}" alt="Trash Panda Roll-Offs">
      <div class="nav-logo-text">TRASH PANDA<br><em>ROLL-OFFS</em></div>
    </a>
    <ul class="nav-links">
      <li><a href="index.html">Home</a></li>
      <li><a href="services.html">Services</a></li>
      <li><a href="sizes.html">Dumpster Sizes</a></li>
      <li><a href="service-areas.html">Service Areas</a></li>
      <li><a href="about.html">About</a></li>
      <li><a href="faq.html">FAQ</a></li>
      <li><a href="/my-bookings.php">My Bookings</a></li>
      <li><a href="/book.php" class="nav-cta-btn">Book Now</a></li>
    </ul>
    <a href="tel:+12513334444" class="nav-phone d-none d-xl-flex"><i class="fas fa-phone"></i>(251) 333-4444</a>
    <button class="nav-hamburger" id="navHamburger" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>
<div class="mobile-nav" id="mobileNav">
  <ul>
    <li><a href="index.html">Home</a></li>
    <li><a href="services.html">Services</a></li>
    <li><a href="sizes.html">Dumpster Sizes</a></li>
    <li><a href="service-areas.html">Service Areas</a></li>
    <li><a href="about.html">About</a></li>
    <li><a href="faq.html">FAQ</a></li>
    <li><a href="/my-bookings.php">My Bookings</a></li>
    <li><a href="/book.php">Book Now</a></li>
    <li><a href="tel:+12513334444"><i class="fas fa-phone me-2" style="color:var(--orange)"></i>(251) 333-4444</a></li>
  </ul>
</div>`;

const tickerHTML = `
<div class="ticker-wrap">
  <div class="ticker-track">
    ${Array(2).fill(`
      <div class="ticker-item">
        Same-Day Delivery Available <span class="ticker-sep"></span>
        Baldwin County & Mobile Area <span class="ticker-sep"></span>
        No Hidden Fees - Ever <span class="ticker-sep"></span>
        Live Dumpster Inventory <span class="ticker-sep"></span>
        Residential & Commercial <span class="ticker-sep"></span>
        Licensed & Insured <span class="ticker-sep"></span>
        Call (251) 333-4444 <span class="ticker-sep"></span>
        Eco-Responsible Disposal <span class="ticker-sep"></span>
      </div>
    `).join('')}
  </div>
</div>`;

const footerHTML = `
<footer class="site-footer">
  <div class="footer-top">
    <div class="container" style="max-width:1300px;">
      <div class="row gy-5">
        <div class="col-lg-4">
          <div class="footer-logo-text">TRASH PANDA <em>ROLL-OFFS</em></div>
          <p class="footer-tagline">Baldwin County & Mobile's most trusted<br>dumpster rental crew.</p>
          <div class="social-row mb-4">
            <a href="#" class="social-btn"><i class="fab fa-facebook-f"></i></a>
            <a href="#" class="social-btn"><i class="fab fa-instagram"></i></a>
            <a href="#" class="social-btn"><i class="fab fa-google"></i></a>
            <a href="#" class="social-btn"><i class="fab fa-yelp"></i></a>
          </div>
          <a href="tel:+12513334444" style="font-family:var(--font-cond);font-weight:900;font-size:1.5rem;color:var(--orange);text-decoration:none;letter-spacing:0.02em;display:flex;align-items:center;gap:8px;"><i class="fas fa-phone" style="font-size:1rem;"></i>(251) 333-4444</a>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <div class="footer-heading">Services</div>
          <ul class="footer-links">
            <li><a href="services.html">Residential Rentals</a></li>
            <li><a href="services.html">Commercial Rentals</a></li>
            <li><a href="services.html">Construction Cleanup</a></li>
            <li><a href="services.html">Estate Cleanouts</a></li>
            <li><a href="services.html">Storm Debris</a></li>
            <li><a href="services.html">Roofing Projects</a></li>
          </ul>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
          <div class="footer-heading">Company</div>
          <ul class="footer-links">
            <li><a href="about.html">About Us</a></li>
            <li><a href="faq.html">FAQ</a></li>
            <li><a href="service-areas.html">Service Areas</a></li>
            <li><a href="contact.html">Contact</a></li>
            <li><a href="sizes.html">Dumpster Sizes</a></li>
            <li><a href="/my-bookings.php">My Bookings</a></li>
          </ul>
        </div>
        <div class="col-lg-4">
          <div class="footer-heading">Service Areas</div>
          <ul class="footer-links">
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Gulf Shores, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Orange Beach, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Foley, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Daphne, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Fairhope, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Mobile, AL</a></li>
            <li><a href="service-areas.html"><i class="fas fa-map-marker-alt" style="color:var(--orange);width:14px;"></i> Spanish Fort, AL</a></li>
          </ul>
          <a href="service-areas.html" style="font-family:var(--font-cond);font-weight:700;font-size:0.8rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--orange);text-decoration:none;margin-top:0.75rem;display:inline-block;">View All Areas -></a>
        </div>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2" style="max-width:1300px;">
      <p class="footer-bottom-text mb-0">Copyright 2025 Trash Panda Roll-Offs. All rights reserved. | Baldwin County & Mobile, AL</p>
      <p class="footer-bottom-text mb-0">Made for the Gulf Coast</p>
    </div>
  </div>
</footer>`;

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatMoney(value) {
  const num = Number(value || 0);
  if (!Number.isFinite(num) || num <= 0) return null;
  return `$${num.toFixed(2)}`;
}

function extractSizeNumber(size) {
  const match = String(size || '').match(/\d+/);
  return match ? match[0] : String(size || '');
}

function summarizeSizeUse(size, description) {
  const normalized = String(size || '').toLowerCase();
  if (description) return String(description);
  if (normalized.includes('10')) return 'Best for small cleanouts, garage purge jobs, and light remodeling.';
  if (normalized.includes('15')) return 'Great for medium room remodels, flooring jobs, and compact property cleanups.';
  if (normalized.includes('20')) return 'Ideal for remodels, roofing, and all-around residential renovation work.';
  if (normalized.includes('30')) return 'Built for larger renovations, contractor jobs, and major property cleanouts.';
  if (normalized.includes('40')) return 'Best for demolition, commercial work, and the biggest debris loads.';
  return 'Available now from current live inventory.';
}

function rentalLabel(days) {
  const count = Number(days || 0);
  if (!Number.isFinite(count) || count <= 0) return 'Flexible rental';
  return `${count} day rental`;
}

function buildPricingLine(size) {
  const parts = [];
  const basePrice = formatMoney(size.base_price);
  const dailyRate = formatMoney(size.daily_rate);
  const extraDay = formatMoney(size.extra_day_price);

  if (basePrice) parts.push(`From ${basePrice}`);
  if (dailyRate) parts.push(`${dailyRate}/day`);
  if (extraDay) parts.push(`${extraDay} extra day`);

  return parts.join(' | ');
}

function buildStockLine(size) {
  const available = Number(size.available_count || 0);
  const total = Number(size.unit_count || 0);
  if (total <= 0) return 'No units configured';
  return `${available} available now | ${total} active units`;
}

function renderHomeSizePreview(sizes) {
  const container = document.getElementById('home-size-preview');
  if (!container) return;

  if (!Array.isArray(sizes) || !sizes.length) {
    container.innerHTML = `
      <div class="col-12">
        <div class="size-preview-card">
          <div class="size-body">
            <p class="mb-0" style="color:var(--gray-light);">No active dumpster sizes are currently published.</p>
          </div>
        </div>
      </div>`;
    return;
  }

  const featuredIndex = sizes.reduce((bestIndex, item, index, arr) => {
    if (bestIndex === -1) return index;
    if ((item.available_count || 0) > (arr[bestIndex].available_count || 0)) return index;
    return bestIndex;
  }, -1);

  container.innerHTML = sizes.map((size, index) => {
    const featured = index === featuredIndex;
    const priceLine = buildPricingLine(size) || 'Call for pricing';
    const stockLine = buildStockLine(size);
    const sizeLabel = escapeHtml(size.size || 'Dumpster');
    const description = escapeHtml(summarizeSizeUse(size.size, size.description));
    const buttonClass = featured ? 'btn-panda' : 'btn-panda-outline';

    return `
      <div class="col-md-6 col-xl-3">
        <div class="size-preview-card${featured ? ' featured' : ''}">
          <div class="size-head${featured ? ' featured-bg' : ''}">
            ${featured ? '<span class="most-popular">Best Stocked</span>' : ''}
            <div class="size-num">${escapeHtml(extractSizeNumber(size.size))}</div>
            <div class="size-yd">${sizeLabel}</div>
          </div>
          <div class="size-body">
            <ul class="size-uses">
              <li><i class="fas fa-circle"></i> ${description}</li>
              <li><i class="fas fa-circle"></i> ${escapeHtml(stockLine)}</li>
              <li><i class="fas fa-circle"></i> ${escapeHtml(rentalLabel(size.rental_days))}</li>
              <li><i class="fas fa-circle"></i> ${escapeHtml(priceLine)}</li>
            </ul>
            <a href="/book.php?size=${encodeURIComponent(size.size)}" class="${buttonClass} w-100 justify-content-center">Book Now</a>
          </div>
        </div>
      </div>`;
  }).join('');
}

function renderSizesPage(sizes) {
  const list = document.getElementById('dynamic-sizes-list');
  const compare = document.getElementById('dynamic-size-comparison');
  if (!list && !compare) return;

  if (!Array.isArray(sizes) || !sizes.length) {
    if (list) {
      list.innerHTML = `
        <div class="size-full-card">
          <div class="size-full-body">
            <p class="mb-0" style="color:var(--gray-light);">No active dumpster sizes are currently available to display.</p>
          </div>
        </div>`;
    }
    if (compare) {
      compare.innerHTML = '<tr><td colspan="6" class="compare-loading">No live inventory is available right now.</td></tr>';
    }
    return;
  }

  const featuredIndex = sizes.reduce((bestIndex, item, index, arr) => {
    if (bestIndex === -1) return index;
    if ((item.available_count || 0) > (arr[bestIndex].available_count || 0)) return index;
    return bestIndex;
  }, -1);

  if (list) {
    list.innerHTML = sizes.map((size, index) => {
      const featured = index === featuredIndex;
      const available = Number(size.available_count || 0);
      const total = Number(size.unit_count || 0);
      const reserved = Number(size.reserved_count || 0);
      const inUse = Number(size.in_use_count || 0);
      const maintenance = Number(size.maintenance_count || 0);
      const unitCodes = Array.isArray(size.unit_codes) ? size.unit_codes.filter(Boolean).join(', ') : '';
      const description = escapeHtml(summarizeSizeUse(size.size, size.description));
      const priceLine = escapeHtml(buildPricingLine(size) || 'Call for pricing');
      const buttonAttrs = featured
        ? 'class="btn-panda" style="background:var(--black);color:var(--white);"'
        : 'class="btn-panda-outline"';

      return `
        <div class="size-full-card${featured ? ' featured' : ''}">
          <div class="size-banner${featured ? ' featured-banner' : ''}">
            <div>
              <div class="size-mega">${escapeHtml(extractSizeNumber(size.size))}</div>
              <div class="size-banner-label">
                ${escapeHtml(size.size || 'Dumpster')}
                ${featured ? '<span class="pop-badge"><i class="fas fa-star"></i> Most Available</span>' : ''}
              </div>
            </div>
            <div>
              <div class="size-best-for">Best For</div>
              <div class="size-ideal-text">${description}</div>
            </div>
            <a href="/book.php?size=${encodeURIComponent(size.size)}" ${buttonAttrs}>Book This Size</a>
          </div>
          <div class="size-full-body">
            <div class="size-specs mb-3">
              <div class="spec-item"><div class="spec-val">${escapeHtml(extractSizeNumber(size.size))}</div><div class="spec-key">Cubic Yards</div></div>
              <div class="spec-item"><div class="spec-val">${available}</div><div class="spec-key">Available Now</div></div>
              <div class="spec-item"><div class="spec-val">${total}</div><div class="spec-key">Active Units</div></div>
              <div class="spec-item"><div class="spec-val">${escapeHtml(String(size.rental_days || 0))}</div><div class="spec-key">Rental Days</div></div>
              <div class="spec-item"><div class="spec-val">${escapeHtml(formatMoney(size.base_price) || 'Call')}</div><div class="spec-key">Starting Price</div></div>
            </div>
            <div class="size-cols">
              <div>
                <div class="size-col-label">Live Inventory Snapshot</div>
                <ul class="use-list">
                  <li><i class="fas fa-check-circle"></i>${escapeHtml(buildStockLine(size))}</li>
                  <li><i class="fas fa-check-circle"></i>${priceLine}</li>
                  <li><i class="fas fa-check-circle"></i>${escapeHtml(rentalLabel(size.rental_days))}</li>
                  <li><i class="fas fa-check-circle"></i>${escapeHtml(unitCodes ? `Units: ${unitCodes}` : 'Units are assigned when booked.')}</li>
                </ul>
              </div>
              <div>
                <div class="size-col-label">Current Status Mix</div>
                <ul class="use-list">
                  <li><i class="fas fa-info-circle"></i>${available} available</li>
                  <li><i class="fas fa-info-circle"></i>${reserved} reserved</li>
                  <li><i class="fas fa-info-circle"></i>${inUse} in use</li>
                  <li><i class="fas fa-info-circle"></i>${maintenance} in maintenance</li>
                </ul>
              </div>
            </div>
          </div>
        </div>`;
    }).join('');
  }

  if (compare) {
    compare.innerHTML = sizes.map((size) => {
      const available = Number(size.available_count || 0);
      const total = Number(size.unit_count || 0);
      const bestFor = escapeHtml(summarizeSizeUse(size.size, size.description));
      const residentialFit = available > 0 ? '<i class="fas fa-check" style="color:var(--orange);"></i>' : '<i class="fas fa-times"></i>';
      const multiUnit = total > 1 ? '<i class="fas fa-check" style="color:var(--orange);"></i>' : '<i class="fas fa-times"></i>';
      const inStock = available > 0 ? '<i class="fas fa-check" style="color:var(--orange);"></i>' : '<i class="fas fa-times"></i>';

      return `
        <tr>
          <td>${escapeHtml(extractSizeNumber(size.size))} yd</td>
          <td>${escapeHtml(extractSizeNumber(size.size))} cu. yd.</td>
          <td>${bestFor}</td>
          <td>${inStock}</td>
          <td>${multiUnit}</td>
          <td>${residentialFit}</td>
        </tr>`;
    }).join('');
  }
}

function loadLiveDumpsterSizes() {
  if (!document.getElementById('home-size-preview') &&
      !document.getElementById('dynamic-sizes-list') &&
      !document.getElementById('dynamic-size-comparison')) {
    return;
  }

  fetch('/api/available-dumpsters.php', {
    headers: { 'Accept': 'application/json' },
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      return response.json();
    })
    .then((payload) => {
      const sizes = Array.isArray(payload && payload.sizes) ? payload.sizes : [];
      renderHomeSizePreview(sizes);
      renderSizesPage(sizes);
    })
    .catch(() => {
      renderHomeSizePreview([]);
      renderSizesPage([]);
    });
}

document.addEventListener('DOMContentLoaded', () => {
  const navWrap = document.getElementById('nav-placeholder');
  if (navWrap) navWrap.innerHTML = navHTML;

  const tickerWrap = document.getElementById('ticker-placeholder');
  if (tickerWrap) tickerWrap.innerHTML = tickerHTML;

  const footerWrap = document.getElementById('footer-placeholder');
  if (footerWrap) footerWrap.innerHTML = footerHTML;

  const nav = document.getElementById('siteNav');
  const onScroll = () => {
    if (window.scrollY > 30) nav?.classList.add('scrolled');
    else nav?.classList.remove('scrolled');
  };
  window.addEventListener('scroll', onScroll);
  onScroll();

  const ham = document.getElementById('navHamburger');
  const mob = document.getElementById('mobileNav');
  ham?.addEventListener('click', () => mob?.classList.toggle('open'));

  const page = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mobile-nav a').forEach((anchor) => {
    if (anchor.getAttribute('href') === page) {
      anchor.classList.add('active');
    }
  });

  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (event) => {
      const target = document.querySelector(anchor.getAttribute('href'));
      if (target) {
        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  document.querySelectorAll('img').forEach((img) => {
    if (img.loading) return;
    const top = img.getBoundingClientRect().top;
    if (top > window.innerHeight * 1.2) {
      img.loading = 'lazy';
      img.decoding = 'async';
    } else {
      img.decoding = 'async';
    }
  });

  loadLiveDumpsterSizes();
});
