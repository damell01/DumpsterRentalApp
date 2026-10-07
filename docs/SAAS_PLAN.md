# SaaS Plan — Turning Trash Panda Roll-Offs into a Multi-Company Platform

> Working name: **RollOff OS** (placeholder — pick a real name before buying the domain)
> Status: planning draft, Oct 2026

---

## 0. TL;DR

- **Target "waste & site-service rental" companies, not all rentals.** Launch with **roll-offs, dumpster trailers and portable toilets**, each with its own template pack. Add junk removal and storage containers next. Skip garbage collection routes and general party/equipment rental.
- **Your code is a real head start.** ~42k lines of PHP already cover booking, availability, Stripe checkout, invoices, subscriptions, work orders, a dispatch map, a customer portal and a PWA. What's missing is the *SaaS shell*: tenants, signup, platform billing, Stripe Connect, a site editor and domains.
- **Fastest safe path to multi-tenancy is database-per-tenant**: one small "platform" DB plus one DB per customer company. That is the path that does **not** require rewriting ~500 SQL call sites.
- **Stripe:** switch from "paste your secret key" to **Stripe Connect with hosted onboarding**. This is the "one-click connect" you want. You can optionally take a small platform fee on every booking.
- **Website builder:** don't build GoHighLevel's drag-and-drop on day one. Ship **niche templates plus a section editor** (text, photos, colors, sizes and prices are pulled live from inventory). Add GrapesJS later if customers actually ask for it.
- **Domains:** free `company.yourapp.com` subdomain on signup, custom domains via a CNAME using **Cloudflare for SaaS**, and domain *buying* later via the **Name.com reseller API**.
- **Price:** start at **$39/mo** (Starter), with **$79 Pro** and **$149 Fleet**. That undercuts every competitor ($59–$280+/mo), and most of them don't include a real website.

---

## 1. What the current code already gives you

| Area | Status | Notes |
|---|---|---|
| Public website | ✅ | Static HTML pages + `site-config.php`. "Trash Panda" is hardcoded in ~108 files. |
| Online booking + availability | ✅ | `public/book.php`, availability APIs, double-booking prevention. |
| Stripe Checkout, invoices, subscriptions, webhooks | ✅ | `src/Billing/*`, uses **one** secret key from `settings`. |
| Work orders, dispatch map, calendar, photos | ✅ | Strong differentiator vs. cheap competitors. |
| Customer portal (magic link) | ✅ | `public/portal/`. |
| Email templates, SMTP, push notifications, PWA | ✅ | |
| Roles, 2FA, rate limiting, audit/activity log | ✅ | |
| **Multi-tenancy** | ❌ | Single DB, no `tenant_id`, config is global constants. |
| **Signup / onboarding wizard** | ❌ | Installer is a manual web script. |
| **Platform billing (you charging them)** | ❌ | |
| **Stripe Connect** | ❌ | Each owner would have to paste API keys. |
| **Site editor / templates** | ❌ | Content is hardcoded HTML. |
| **Custom domains** | ❌ | |
| Automated tests / CI | ❌ | Needed before you have paying tenants. |

### ⚠️ Fix before anything else: committed credentials

`admin/config/config.php` has real-looking DB host, DB name, user and password hardcoded as fallback defaults, and they are in git history.

1. **Rotate that DB password now**, because the repo history still contains it.
2. Replace the fallbacks with empty strings so the app fails loudly without a `.env` file.
3. If the repo is or ever becomes public, purge it from history or treat the credential as permanently burned.

---

## 2. Niche: "waste & site-service rentals", with a template pack per business type

**Revised recommendation:** don't sell to "any rental business." Do sell to the **cluster of businesses that drop a unit at a job site or home, leave it, service or swap it, and haul it back.** Many operators run several of these lines at once (roll-off + porta-potty is very common), so supporting all of them makes the product more sellable.

| Business type | Fit with current code | Launch? |
|---|---|---|
| **Roll-off dumpsters** | Native | ✅ v1 |
| **Dumpster trailers** | Native (`type` already includes `trailer`) | ✅ v1 |
| **Portable toilets / restroom trailers** | Good. Rentals + recurring service (weekly pumping) map onto bookings + your existing recurring subscriptions. Needs: units-per-order quantity, service-visit work orders, event vs. construction pricing. | ✅ v1, as the 2nd template pack |
| **Junk removal** | Medium. It's a one-time job, not a rental: quote by photo/volume, same trucks and dispatch. | Phase 2 |
| **Storage containers (Conex/PODS-style)** | Good. Long-term monthly rentals already supported. | Phase 2 |
| **Residential/commercial garbage routes** | **Poor.** Weekly route collection, per-stop billing and cart tracking is a different software category (CurbWaste, Routeware). | ❌ Not for now |
| General party/equipment rental | Poor positioning. It's crowded (Booqable, etc.) and has different needs (shopping-cart checkout, many small items). | ❌ |

**How it works in the product: "Business type packs" (your templates)**
- At signup the owner ticks what they offer: ☑ Roll-offs ☑ Portable toilets ☐ Junk removal…
- Each pack installs: default unit types/sizes with suggested prices, terminology ("Dumpster" vs. "Unit" vs. "Restroom"), website template sections and pages, FAQ, email templates, booking form fields (e.g. "debris type" vs. "number of guests/event length"), and work-order status flows (deliver/pick up vs. deliver/service/pick up).
- A company with multiple lines gets one website with a section for each line, and one booking flow where customers choose what they need.

**Marketing:** one product brand with a separate landing page and ads for each vertical ("Portable toilet rental software", "Dumpster rental software"). Each owner should feel the product was built for their business. That's how you get the bigger market without sounding generic.

**Code implications:** rename `dumpsters` to generic `units` with a `unit_type` (keep the old name via a view or alias during migration). Add `quantity` to bookings, a `service_visit` work-order type, and a `vertical_packs/` folder of JSON + template files that the provisioning script applies.

## 3. Competitive landscape & pricing

| Product | Public pricing | Notes |
|---|---|---|
| Dumpster Rental Systems (DRS) | Launch $79.95 / Standard $195.95 / Pro $279.95 per mo | Tiers by fleet size. |
| Docket | Quote only (Capterra lists from ~$55/mo usage-based) | Strong dispatch and route focus. |
| CurbWaste | Quote only | Mid/upper market, enterprise-ish. |
| Reservety, Dropcurb, others | ~$59–$99 flat at the low end | Booking-centric. |

Vendor-published industry guides put the range at **$59 to $1,500+/mo**, with per-truck pricing ($150–$300 per truck) at the high end. **Nobody is clearly winning on "beautiful website + booking + dispatch, flat price, set up in 15 minutes."** That is your wedge.

**Suggested pricing (validate with 5–10 owners before locking it in):**

| Plan | Price | For |
|---|---|---|
| Starter | **$39/mo** | 1 yard, up to ~15 units, website + booking + invoicing |
| Pro | $79/mo | Unlimited units, dispatch map, recurring service, custom domain |
| Fleet | $149/mo | Multiple yards/locations, priority support |
| Optional | +0.5–1% platform fee on online payments | Via Stripe Connect `application_fee_amount`, or waive it on higher tiers |
| Setup / migration | $0 to $499 one-time | "We'll build your site for you" done-for-you, which is great early revenue |

Offer a 14-day trial with no card required, plus a founding-customer price locked for life for the first 25 companies.

---

## 3.5 What to offer

### Included in every plan (the core promise: "your whole business, live in a day")
| Feature | Built today? |
|---|---|
| Website from a business-type template + section editor | ⚠️ site exists, editor/templates to build |
| Free `yourco.app.com` subdomain + connect your own domain | ❌ build |
| Online booking with live availability + online payment (Stripe Connect) | ✅ (Connect to build) |
| Cash/check/manual payments | ✅ |
| Inventory/units, sizes, pricing rules (daily/weekly/monthly/flat + extra days, delivery fees, tax) | ✅ |
| Calendar + work orders (deliver → service → pick up) | ✅ |
| Invoices + payment links + PDF | ✅ |
| Customer database + self-service portal (view bookings, request pickup, pay) | ✅ |
| Branded email notifications | ✅ |
| Leads/quote requests from the website | ✅ |
| Basic reports (revenue, bookings) | ✅ |
| Phone app (PWA) for owner and staff | ✅ |
| **Unlimited users** (no per-seat or per-truck fees, a selling point vs. competitors) | ✅ |

### Plans
| | **Starter $39** | Pro $79 | Fleet $149 |
|---|---|---|---|
| Units | up to 15 | unlimited | unlimited |
| Business-type packs | 1 | all | all |
| Custom domain | — | ✅ | ✅ |
| Dispatch map + driver photos | — | ✅ | ✅ |
| Recurring service contracts (e.g. weekly toilet pumping) | — | ✅ | ✅ |
| Card on file, auto-billing for overdue/extra days | — | ✅ | ✅ |
| Multiple yards/locations | — | — | ✅ |
| Advanced reports + export | — | ✅ | ✅ |
| Priority support / onboarding call | — | — | ✅ |
| Platform fee on online payments | 1% | 0% | 0% |

Annual billing gets 2 months free. Founding customers (first 25) get a price locked for life.

### Why $39 works (and what to watch)
- Low entry price plus "no sales call" means more signups. Upgrades come naturally once they have more than 15 units or want their own domain.
- **The 1% platform fee on Starter matters:** a small company doing $15k/mo in online payments adds about $150/mo, so Starter can earn more than Pro.
- Setup fees ($299+) carry the early cash flow.
- Break-even on hosting is only about 2–6 customers, but support time is the real cost at $39. Keep Starter self-serve (help docs, email support only).
- Raise prices for *new* customers later. That's much easier than lowering them. Founding customers keep their price.

### Add-ons (recurring revenue)
- **Domain purchase:** about $20/yr, bought in-app (later).
- **Extra locations** on Pro: about $29/mo each.
- **Online review requests:** auto-text after pickup asking for a Google review ($19/mo or bundled into Pro).

### Done-for-you services (high margin early, and you learn what owners need)
- **Setup & launch:** $299–$499. You build their site, load their units and prices, and connect Stripe and the domain.
- **Data import** from spreadsheets or another system: $99–$299.
- **Google Business Profile setup + local SEO city pages:** $199 one-time.
- **"Growth" managed marketing:** $299–$999/mo for Google Ads/LSA + monthly SEO pages. This is the GoHighLevel-agency style revenue, only once the software is stable.

### Not at launch (say "on the roadmap")
QuickBooks sync, junk-removal and storage packs, driver route view, weight-ticket/tonnage billing, in-app domain purchase, native iOS/Android apps, AI phone answering, residential garbage routes.

### Why someone picks you (put this on the sales page)
1. Website **included**, and it's built to rank locally, with booking built in.
2. Live in a day, with no sales call needed to get a price.
3. Flat price and unlimited users, with no per-truck "success tax."
4. Built by people who run a roll-off company.

## 4. Target architecture

```
                    ┌──────────────────────────────────────────┐
  *.yourapp.com ──▶ │  Cloudflare (DNS + Cloudflare for SaaS   │ ◀── customer.com (CNAME)
                    │  custom hostnames, TLS, CDN)             │
                    └───────────────────┬──────────────────────┘
                                        ▼
                    ┌──────────────────────────────────────────┐
                    │  PHP app (same codebase as today)        │
                    │  1. Read Host header                     │
                    │  2. Look up tenant in PLATFORM DB        │
                    │  3. Connect to that tenant's DB          │
                    │  4. Run existing admin/public code       │
                    └───────┬──────────────────────┬───────────┘
                            ▼                      ▼
              ┌────────────────────┐   ┌──────────────────────────┐
              │ platform DB        │   │ tenant_acme DB           │
              │ tenants, domains,  │   │ tenant_bobs DB           │
              │ plans, platform    │   │ ... (today's schema,     │
              │ subscriptions,     │   │      one per company)    │
              │ super-admins       │   └──────────────────────────┘
              └────────────────────┘
                            │
                     Stripe (platform account)
                       ├─ Billing: you charge tenants $39–$149/mo
                       └─ Connect: tenants' own accounts take customer payments
```

### 4.1 Multi-tenancy: database-per-tenant (recommended for v1)

The code has roughly **500 SQL call sites across 33 tables** and none of them is tenant-aware. You have two options:

| | Shared DB + `tenant_id` column | **DB per tenant** ✅ |
|---|---|---|
| Code changes | Touch every query; one miss leaks one company's customers to another | Swap which DB `get_db()` connects to; queries stay unchanged |
| Data isolation | Logical (bug-prone) | Physical (strong, easy to explain to customers) |
| Backups / export / delete a customer | Hard | `mysqldump tenant_x` |
| Migrations | Run once | Loop over tenants; you already have `admin/install/upgrade.php` |
| Scales to | Huge | Comfortably hundreds of tenants on one MySQL server, which is plenty for years |

**Concrete changes:**
1. **New `platform` database:** `tenants(id, slug, name, db_name, status, plan, trial_ends_at, stripe_customer_id, stripe_subscription_id, stripe_connect_account_id, vertical)`, `tenant_domains(hostname, tenant_id, verified_at, cf_hostname_id)`, `platform_users` (you/super-admin), `platform_events`.
2. **`admin/includes/bootstrap.php`** gets a tenant resolver that maps the `Host` header to a tenant row, then defines the tenant DB name before `get_db()` runs. Unknown hosts get the marketing site. Suspended tenants get a "billing paused" page.
3. **`admin/config/config.php`:** global `APP_NAME` etc. become per-tenant `settings` reads. You already have a `settings` table with ~69 keys, so most of this exists.
4. **Uploads:** `public/uploads/...` becomes `uploads/{tenant_slug}/...`, or better, S3/R2 object storage.
5. **Cron:** `admin/cron/daily.php` loops over active tenants.
6. **Sessions:** cookie scoped per host (already true when each tenant has its own hostname).
7. **Provisioning script:** create DB, run `schema.sql` plus migrations, seed the vertical pack, create the owner user. This replaces `install.php` for SaaS signups.
8. **Migration runner:** `php scripts/migrate-all.php` applies `admin/install/migrations/*.sql` to every tenant DB and records versions in a `schema_migrations` table per tenant.

Revisit shared-DB later only if you pass ~1,000 tenants. That is a good problem to have.

### 4.2 Stripe: two separate jobs

**Job A: you bill the tenant (Stripe Billing on *your* platform account)**
- Products: Starter / Pro / Fleet, monthly and annual.
- Signup → Stripe Checkout (subscription mode, trial) → webhook sets `tenants.status`.
- Use the Stripe **Customer Portal** for plan changes, card updates and cancellation. That's zero UI for you to build.
- Dunning: when `invoice.payment_failed` keeps failing, mark the tenant `past_due`, show a banner, and after X days set it to `suspended`.

**Job B: the tenant gets paid by *their* customers (Stripe Connect)**
- Replace the "paste your secret key" settings with a **"Connect with Stripe" button**. It creates a connected account and redirects through Stripe-hosted onboarding (Account Links). The owner fills in bank and ID details on Stripe's page, returns, and is done.
- Use **direct charges** on the connected account (the `Stripe-Account` header). The tenant is the merchant of record, so their name shows on customers' card statements and disputes and refunds are theirs. This is what dumpster owners expect.
- Optional platform cut: `application_fee_amount` on each Checkout Session / PaymentIntent.
- **One platform webhook endpoint** receives Connect events. `event.account` tells you which tenant, so you route to that tenant's DB and run today's `WebhookService` logic.
- Code impact: `src/Billing/StripeClientFactory.php` keeps the platform key and passes `['stripe_account' => $tenant->connect_id]` on every call. Most of `CheckoutService`, `InvoiceBillingService` and `SubscriptionService` stay the same.
- **Check Stripe's current Connect docs before building.** Stripe now steers new platforms to "controller properties" / Accounts v2 instead of the classic Standard/Express/Custom types, and an account's type can't be changed after creation. Choose the setup where the tenant gets a full Stripe dashboard and handles their own disputes. That is the Standard-like setup.

### 4.3 Website: templates + section editor (not full drag-and-drop yet)

Today the public site is static `.html` with "Trash Panda" baked in. The plan:

**Phase 1: themed templates (v1)**
- Convert `public/*.html` into PHP templates rendered from tenant data: company info, logo, colors, service areas, FAQ, and **inventory pulled live from the `dumpsters` table**. Sizes and prices then stay in sync with booking automatically, which GHL can't do.
- Ship 2–3 good-looking dumpster templates. A tenant picks one at signup.
- Editor = a settings-style form per page section: hero headline and image, "why choose us" bullets, testimonials, FAQ items, service-area ZIPs/cities and a map, colors and fonts, plus a show/hide toggle and reorder for each section. Store as JSON in a `site_pages` table.
- Local SEO built in: per-city service-area landing pages ("Dumpster rental in Daphne, AL") generated from the service-area list, schema.org `LocalBusiness` markup, sitemap.xml, Google Business Profile link. **This is a huge selling point**, because these owners live and die by local Google rankings.

**Phase 2: real visual builder (only if customers demand it)**
- **GrapesJS** (BSD-licensed, framework-agnostic, ~24k GitHub stars) drops into a PHP app with no React required. Use it for a "custom page" type and keep the booking widget as a locked component.
- **Puck** (MIT) is excellent but React-based. Pick it only if you move the front end to React/Next.js.

**Phase 3: embeddable booking widget**
- `<script src="https://yourapp.com/widget.js" data-tenant="acme">` so companies that already have a WordPress or Wix site can just add booking. This is a cheap way to win customers who won't switch websites.

### 4.4 Domains

| Step | How |
|---|---|
| Free subdomain at signup | `acme.yourapp.com` with a wildcard DNS record and wildcard cert on Cloudflare |
| Connect a domain they own | Tenant enters `acmedumpsters.com` → you call the **Cloudflare for SaaS** custom-hostnames API → show the tenant "add CNAME `www` → `sites.yourapp.com`" → Cloudflare validates and issues TLS automatically. Store the result in `tenant_domains`. |
| Apex domain (no `www`) | Cloudflare for SaaS apex proxying, or tell them to forward the apex to `www` (simplest) |
| Self-hosted alternative | **Caddy on-demand TLS** with an `ask` endpoint that returns 200 only for verified domains in `tenant_domains`. Cheap on a single VPS, and you own more of the stack. |
| Buy a domain in-app (later) | **Name.com reseller API** has no application process, a REST API and a PHP SDK. Search → buy (charge the card on file via Stripe plus your markup) → auto-set DNS → auto-add the custom hostname. This is the real "one click" GHL-style flow. |

The **email deliverability** gap: tenants will want booking emails sent from `@theirdomain.com`. Use a transactional provider with per-domain verification (Postmark, Resend, SES) and show "add these 3 DNS records" in onboarding. That beats making every owner configure SMTP, which is the current approach.

### 4.5 Onboarding wizard (the "15-minute setup" promise)

1. Sign up (email, company name, phone) → tenant provisioned → logged into `acme.yourapp.com/admin`.
2. Business basics: logo upload, colors, service area (ZIPs or radius on a map).
3. Inventory: choose sizes from the dumpster vertical pack (10/15/20/30/40 yd) with suggested prices → edit.
4. Connect Stripe (Connect onboarding), or skip and accept cash/check only.
5. Pick a website template → preview → publish.
6. Optional: connect a domain.
7. Launch checklist. `admin/includes/launch_readiness.php` already exists, so reuse it.

### 4.6 Super-admin (you)

- Tenant list: status, plan, MRR, last login, Stripe Connect status, bookings in the last 30 days.
- Impersonate (log in as tenant for support, recorded in the audit log).
- Suspend/unsuspend, extend trial, change plan.
- Global announcements banner.

---

## 5. Open source: what to use vs. build

| Need | Use | Why |
|---|---|---|
| Core rental/booking/dispatch | **Your existing code** | Already ahead of the open-source rental projects I found. The ones that exist (e.g. a Docker-packaged equipment-rental app on Docker Hub, Odoo rental modules) are generic, have 1–2 maintainers, or are a different stack. Not worth migrating. |
| Visual page builder (phase 2) | **GrapesJS** (BSD-3) | Framework-agnostic, works with PHP, huge plugin ecosystem. |
| | Puck (MIT) | Only if you go React. |
| Custom-domain TLS | **Cloudflare for SaaS** (managed) or **Caddy** on-demand TLS (self-host) | Both proven at thousands of domains. |
| Domain purchase | Name.com API (or Dynadot / OpenSRS reseller) | |
| Payments | Stripe Connect + Billing + Customer Portal | |
| Maps | Leaflet (already in use) + OpenStreetMap/Nominatim, with a paid geocoder at scale | Nominatim's usage policy won't cover hundreds of tenants. |
| Transactional email | Postmark / Resend / Amazon SES | Per-tenant sending domains. |
| SMS (built in, **off at launch**) | Twilio, or Telnyx (cheaper) | Keep a `send_sms()` hook so it can be switched on per tenant later. Not sold yet, because US A2P 10DLC registration per tenant is real onboarding work. |
| Error tracking | Sentry (has a free tier) | |
| Object storage | Cloudflare R2 / S3 | Photos, logos, PDFs out of the web root. |

Riff-off inspiration (not code to copy): GoHighLevel for the agency/sub-account and snapshot model, Booqable for the embeddable rental widget, and Jobber/Housecall Pro for field-service dispatch UX.

### The GoHighLevel angle

GHL's real trick isn't the page builder. It's **"snapshots"** (a pre-built setup an agency clones into each client account) plus **white-label resale**. Your version of that:
- **Vertical packs = snapshots.** One click gives you a dumpster company with sizes, prices, templates, emails and FAQ preconfigured.
- **Later, an agency/reseller tier:** marketing agencies that serve home-service businesses resell your platform under their brand. Don't build this until you have ~30 direct customers.

---

## 6. Roadmap

### Phase 0: Cleanup (1–2 weeks)
- [ ] Rotate the leaked DB password; remove hardcoded credential fallbacks.
- [ ] Add PHPUnit plus a handful of tests around booking pricing and availability (the most bug-prone area, judging by recent commits).
- [ ] Add GitHub Actions: `php -l`, PHPStan level 3–5, tests.
- [ ] Put the dev environment in Docker (PHP 8.2 + MySQL) so it's reproducible.
- [ ] Remove "Trash Panda" hardcoding: every brand string comes from `settings`.

### Phase 1: Multi-tenant core (3–5 weeks)
- [ ] Platform DB + tenant resolver by Host header.
- [ ] Provisioning script + per-tenant migration runner.
- [ ] Per-tenant uploads path, cron loop, logs.
- [ ] Super-admin panel (basic).
- [ ] Run **Trash Panda as tenant #1** on the new platform (dogfood).

### Phase 2: Money (2–3 weeks)
- [ ] Stripe Billing for platform plans + Customer Portal + trial + dunning/suspension.
- [ ] Stripe Connect onboarding; refactor `src/Billing/*` to use `stripe_account`.
- [ ] Single platform webhook endpoint routing Connect events to tenants.
- [ ] Optional `application_fee_amount`.

### Phase 3: Website + onboarding + business-type packs (4–6 weeks)
- [ ] Generalize `dumpsters` → `units`; add booking quantity + service-visit work orders.
- [ ] Packs: Roll-off, Dumpster trailer, Portable toilet.
- [ ] Convert public pages to tenant-rendered templates; 2–3 themes.
- [ ] Section editor (JSON-backed).
- [ ] City landing pages + schema.org + sitemap.
- [ ] Signup → onboarding wizard.
- [ ] Subdomains + custom domains via Cloudflare for SaaS.
- [ ] Transactional email provider with per-tenant sending domain.

### Phase 4: Launch & sell (ongoing)
- [ ] Marketing site at `yourapp.com` with a live demo (`demo.yourapp.com` that resets nightly).
- [ ] 5–10 beta dumpster companies at a founding price. Find them in Facebook groups, local outreach, and YouTube creators in the dumpster niche.
- [ ] Embeddable booking widget.
- [ ] QuickBooks Online sync (the #1 integration owners will ask for).

### Phase 5: Expand
- [ ] Junk removal pack (quote-by-photo, volume-based pricing).
- [ ] Storage containers pack.
- [ ] In-app domain purchase (Name.com).
- [ ] Driver mobile view: route of the day, photo on drop/pick, signature.
- [ ] Weight tickets / tonnage overage billing.
- [ ] GrapesJS custom pages; agency/white-label tier.

Rough total to a sellable v1 (Phases 0–3): **~10–14 weeks** for one focused developer.

---

## 7. Hosting & running costs (early stage)

| Item | Est. monthly |
|---|---|
| VPS (e.g. 4 vCPU / 8 GB) or managed PHP host + managed MySQL | $40–$120 |
| Cloudflare (for SaaS: first 100 custom hostnames free, then a small per-hostname fee; check current pricing) | $0–$25 |
| Transactional email | $15–$35 |
| Backups / object storage | $5–$15 |
| Sentry, uptime monitoring | $0–$30 |
| **Total** | **~$60–$225/mo**. Break-even at 1–2 customers. |

Shared hosting (cPanel), which the README currently targets, won't handle wildcard subdomains, custom-domain TLS and per-tenant DB provisioning well. Plan to move to a VPS or a managed platform.

---

## 8. Open questions to decide

1. **Product name and domain.** Check `.com` availability before you get attached.
2. **Platform fee on payments: yes or no?** It's easy revenue, but some owners hate it. Option: 1% on Starter, 0% on Pro and up.
3. **Keep PHP or rewrite?** Recommendation: **keep PHP.** The code works and is battle-tested on a real business. A rewrite costs 6+ months for zero customer-visible gain.
4. **Done-for-you setup service?** Recommended early. It funds development and you learn what owners actually need.
5. **Legal:** Terms of Service, a DPA/privacy policy (you hold *their* customers' PII), and Stripe Connect platform agreement acceptance.

---

### Sources

- [Dumpster Rental Systems pricing](https://www.dumpsterrentalsystems.com/pricing/)
- [Docket on Capterra](https://www.capterra.com/p/241977/Docket/)
- [Reservety: dumpster rental software cost guide](https://reservety.com/guides/dumpster-rental/dumpster-rental-software-cost.html)
- [Stripe Connect account types](https://docs.stripe.com/docs/connect/accounts) · [Stripe Connect onboarding](https://docs.stripe.com/connect/marketplace/tasks/onboard) · [Taking a cut with Stripe Connect](https://dev.to/stripe/taking-a-cut-with-stripe-connect-1kjk)
- [Cloudflare for SaaS: custom hostnames](https://developers.cloudflare.com/cloudflare-for-saas/domain-support)
- [Caddy on-demand TLS](https://caddyserver.com/on-demand-tls) · [Secure custom domains with Caddy (Honeybadger)](https://www.honeybadger.io/blog/secure-custom-domains-caddy/)
- [Name.com reseller API](https://www.name.com/de-de/api_about) · [Dynadot reseller program](https://www.dynadot.com/domain/reseller-program) · [OpenSRS API](https://opensrs.com/api/overview)
- [Puck editor intro](https://puckeditor.com/blog/building-a-react-page-builder-an-introduction-to-puck) · [GrapesJS overview](https://prompts.brightcoding.dev/blog/stop-wrestling-with-html-templates-use-grapesjs-instead)
- [Open-source equipment rental app (Docker Hub)](https://hub.docker.com/r/synapsr/louez) · [Booqable alternatives](https://alternativeto.net/software/booqable-rental-software)
