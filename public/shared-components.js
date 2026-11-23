/* shared-components.js - injects nav, footer, and ticker */

const LOGO_SRC = 'assets/logo.jpeg?v=2';
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
      <li><a href="/book.html" class="nav-cta-btn">Book Now</a></li>
    </ul>
    <a href="tel:+12513334444" id="siteNavPhone" class="nav-phone d-none d-xl-flex"><i class="fas fa-phone"></i><span id="siteNavPhoneText">(251) 333-4444</span></a>
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
    <li><a href="/book.html">Book Now</a></li>
    <li><a href="tel:+12513334444" id="mobNavPhone"><i class="fas fa-phone me-2" style="color:var(--orange)"></i><span id="mobNavPhoneText">(251) 333-4444</span></a></li>
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
        10–40 Yard Roll-Offs <span class="ticker-sep"></span>
        Residential & Commercial <span class="ticker-sep"></span>
        Licensed & Insured <span class="ticker-sep"></span>
        Call <span class="ticker-phone">(251) 333-4444</span> <span class="ticker-sep"></span>
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
          <p id="footerTagline" class="footer-tagline">Baldwin County & Mobile's most trusted<br>dumpster rental crew.</p>
          <div class="social-row mb-4">
            <a href="#" id="footerFacebook" class="social-btn"><i class="fab fa-facebook-f"></i></a>
            <a href="#" id="footerInstagram" class="social-btn"><i class="fab fa-instagram"></i></a>
          </div>
          <a href="tel:+12513334444" id="footerPhone" style="font-family:var(--font-cond);font-weight:900;font-size:1.5rem;color:var(--orange);text-decoration:none;letter-spacing:0.02em;display:flex;align-items:center;gap:8px;"><i class="fas fa-phone" style="font-size:1rem;"></i><span id="footerPhoneText">(251) 333-4444</span></a>
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
      <p id="footerCopyright" class="footer-bottom-text mb-0">Copyright 2025 Trash Panda Roll-Offs. All rights reserved. | Baldwin County & Mobile, AL</p>
      <p class="footer-bottom-text mb-0">Made for the Gulf Coast &nbsp;·&nbsp; <a href="https://www.dbellcreations.com" target="_blank" rel="noopener noreferrer" style="color:var(--orange);text-decoration:none;font-weight:600;">Site by DBell Creations</a></p>
    </div>
  </div>
</footer>`;

function _replacePhoneInTextNodes(root, phone) {
  const phonePattern = /\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode: function(node) {
      var tag = node.parentElement && node.parentElement.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE') return NodeFilter.FILTER_REJECT;
      return phonePattern.test(node.textContent) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
    }
  });
  var nodes = [];
  var n;
  while ((n = walker.nextNode())) nodes.push(n);
  nodes.forEach(function(node) {
    node.textContent = node.textContent.replace(/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/g, phone);
  });
}

function loadSiteConfig() {
  fetch('/api/site-config.php', { headers: { Accept: 'application/json' } })
    .then((r) => r.json())
    .then((cfg) => {
      if (cfg.phone) {
        const raw = cfg.phone.replace(/\D/g, '');
        const tel  = 'tel:+1' + raw;

        // Named nav/footer elements injected by this script
        ['siteNavPhone', 'mobNavPhone', 'footerPhone'].forEach((id) => {
          const el = document.getElementById(id);
          if (el) el.href = tel;
        });
        ['siteNavPhoneText', 'mobNavPhoneText', 'footerPhoneText'].forEach((id) => {
          const el = document.getElementById(id);
          if (el) el.textContent = cfg.phone;
        });

        // Ticker phone spans
        document.querySelectorAll('.ticker-phone').forEach((el) => {
          el.textContent = cfg.phone;
        });

        // All tel: links anywhere on the page
        document.querySelectorAll('a[href^="tel:"]').forEach((el) => {
          el.href = tel;
          el.childNodes.forEach((node) => {
            if (node.nodeType === Node.TEXT_NODE && /\d{3}/.test(node.textContent)) {
              node.textContent = node.textContent.replace(/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/g, cfg.phone);
            }
          });
        });

        // Inline text mentions in paragraphs, headings, etc.
        if (document.body) _replacePhoneInTextNodes(document.body, cfg.phone);

        // Expose for any inline JS that needs it
        window._companyPhone = cfg.phone;
      }
      if (cfg.facebook) {
        const fb = document.getElementById('footerFacebook');
        if (fb) fb.href = cfg.facebook;
      }
      if (cfg.instagram) {
        const ig = document.getElementById('footerInstagram');
        if (ig) ig.href = cfg.instagram;
      }
      if (cfg.tagline) {
        const tl = document.getElementById('footerTagline');
        if (tl) tl.textContent = cfg.tagline;
      }
      if (cfg.name) {
        const cp = document.getElementById('footerCopyright');
        if (cp) cp.textContent = 'Copyright ' + new Date().getFullYear() + ' ' + cfg.name + '. All rights reserved.';
      }
    })
    .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
  const navWrap = document.getElementById('nav-placeholder');
  if (navWrap) navWrap.innerHTML = navHTML;

  const tickerWrap = document.getElementById('ticker-placeholder');
  if (tickerWrap) tickerWrap.innerHTML = tickerHTML;

  const footerWrap = document.getElementById('footer-placeholder');
  if (footerWrap) footerWrap.innerHTML = footerHTML;

  loadSiteConfig();

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
      const href = anchor.getAttribute('href');
      if (!href || href === '#') {
        event.preventDefault();
        return;
      }

      let target = null;
      try {
        target = document.querySelector(href);
      } catch (error) {
        target = null;
      }

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

});
