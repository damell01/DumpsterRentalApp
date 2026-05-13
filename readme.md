# Trash Panda Roll-Offs — Dumpster Rental Website & Work Order System

A complete dumpster rental business platform including a public-facing website and an internal admin work order management system. Payments are handled outside the system by the business.

## Project Structure

```
/public/        — Client-facing public website (Home, Sizes, Services, Contact, FAQ, Service Areas)
/public/api/    — Backend API endpoint (contact form → saves lead + emails admin)
/admin/         — Internal staff admin panel (protected, login required)
```

A root-level `.htaccess` file ensures that visiting the bare domain URL (e.g. `https://yourdomain.com/`) automatically serves the public website from `/public/`. The `/admin/` path continues to work independently.

---

## Features

### Public Website (`/public/`)
- Full branded multi-page site: Home, Sizes, Services, FAQ, Contact, Service Areas
- **Online Booking Flow** — customers pick a unit, select dates, and pay (Stripe Checkout, cash, or check)
- **Real-time availability** — units show as unavailable once booked; date-based double-booking prevention
- Contact / quote-request form that saves directly to the admin Leads module and triggers email notifications
- Interactive Leaflet.js service-area map (no API key required)
- Fully responsive — built with Bootstrap 5.3 and vanilla JavaScript
- Shared navigation and footer injected via `shared-components.js`

### Admin Panel (`/admin/`)
- **Bookings Management** — full booking list, detail view, payment status updates, cancellation
- **Stripe Checkout Integration** — server-side checkout session creation + webhook handler
- **Cash / Check payments** — manual payment recording with pending/paid statuses
- **Inventory Blocks** — admin can block any unit from being booked for specific date ranges
- **Leads Management** — contact form submissions land here automatically
- **Customer Database**
- **Quote Builder** with print layout
- **Work Order Management** with printable invoice
- **Dumpster Inventory** tracking
- **Scheduling Calendar**
- **Reports & Revenue Tracking**
- **User & Role Management** (admin, office, dispatcher, readonly)
- **Email Notifications** via PHPMailer (SMTP) or PHP `mail()` fallback
- **Two-Factor Authentication** per user (TOTP — Google Authenticator, Authy, etc.)

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, Bootstrap 5.3, Font Awesome 6, Vanilla JS, Leaflet.js |
| Backend | PHP 8.1+, PDO |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Email | PHPMailer 6 (optional SMTP) or PHP `mail()` fallback |
| Payments | Stripe Checkout (server-side via stripe/stripe-php SDK) |
| Maps | Leaflet.js (no API key needed) |

---

## ① Server Requirements

- PHP **8.1** or higher
- MySQL **5.7+** or MariaDB **10.3+**
- PDO + `pdo_mysql` extension enabled
- `mod_rewrite` enabled (Apache)
- Composer (optional — required only for PHPMailer SMTP)

---

## ② Upload the Files

Upload both folders to your hosting public directory. Typical layout on cPanel hosting:

```
public_html/
  ├── .htaccess           ← root redirect (included in repo root)
  ├── public/             ← public website files
  │   ├── index.html
  │   ├── about.html
  │   ├── contact.html
  │   ├── faq.html
  │   ├── services.html
  │   ├── service-areas.html
  │   ├── sizes.html
  │   ├── shared.css
  │   ├── shared-components.js
  │   ├── assets/
  │   └── api/
  │       └── contact.php
  └── admin/              ← admin panel
        ├── config/
        ├── includes/
        ├── modules/
        └── ...
```

**Option A — Deploy the whole repo directly**

Upload the entire repository to `public_html/`. The root `.htaccess` will redirect the domain URL to the `public/` website automatically.

**Option B — Copy files manually**
```bash
# From repo root
cp .htaccess          /home/youraccount/public_html/.htaccess
cp -r public/         /home/youraccount/public_html/public/
cp -r admin/          /home/youraccount/public_html/admin/
```

**Option C — Flatten public files to web root (traditional)**
```bash
cp -r public/.        /home/youraccount/public_html/
cp -r admin/          /home/youraccount/public_html/admin/
```
> If you choose Option C you do not need the root `.htaccess`.

---

## ③ VPS Installation (Apache or Nginx)

If you host this on a VPS under a domain such as `bellflowapp.com`, use this section instead of the cPanel-style upload flow.

### Recommended VPS Layout

Clone or upload the project to a dedicated app directory such as:

```bash
/var/www/bellflowapp
```

Recommended structure:

```bash
/var/www/bellflowapp/
  ├── .htaccess
  ├── public/
  ├── admin/
  ├── .env
  └── ...
```

### 1. Install System Packages

Example for Ubuntu/Debian:

```bash
sudo apt update
sudo apt install -y apache2 mysql-server unzip git curl software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl composer
```

If you prefer Nginx with PHP-FPM:

```bash
sudo apt install -y nginx php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-intl composer
```

### 2. Upload or Clone the App

```bash
cd /var/www
sudo git clone YOUR_REPO_URL bellflowapp
cd /var/www/bellflowapp
```

Or upload the project archive and extract it there.

### 3. Set Ownership and Permissions

```bash
sudo chown -R www-data:www-data /var/www/bellflowapp
sudo find /var/www/bellflowapp -type d -exec chmod 755 {} \;
sudo find /var/www/bellflowapp -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/bellflowapp/admin/uploads
```

### 4. Create the `.env` File

Because this app now expects real production secrets to come from `.env`, create:

```bash
sudo nano /var/www/bellflowapp/.env
```

Example starter config:

```env
APP_ENV=production
APP_DEBUG=false
APP_NAME="Trash Panda Roll-Offs"
APP_VERSION=1.0.0

DB_HOST=127.0.0.1
DB_NAME=bellflowapp
DB_USER=bellflowapp
DB_PASS=CHANGE_THIS_DATABASE_PASSWORD
DB_CHARSET=utf8mb4

SESSION_LIFETIME=7200
CRON_KEY=CHANGE_THIS_TO_A_LONG_RANDOM_SECRET
PORTAL_SIGNING_KEY=CHANGE_THIS_TO_A_LONG_RANDOM_PORTAL_SECRET
APP_INSTALLED=false
```

Generate secure random values with:

```bash
openssl rand -hex 32
```

### 5. Create the Database

```sql
CREATE DATABASE bellflowapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bellflowapp'@'localhost' IDENTIFIED BY 'CHANGE_THIS_DATABASE_PASSWORD';
GRANT ALL PRIVILEGES ON bellflowapp.* TO 'bellflowapp'@'localhost';
FLUSH PRIVILEGES;
```

### 6. Install Composer Dependencies

```bash
cd /var/www/bellflowapp/admin
composer install --no-dev --optimize-autoloader
```

### 7. Apache Virtual Host Example

Enable rewrite first:

```bash
sudo a2enmod rewrite
```

Create `/etc/apache2/sites-available/bellflowapp.com.conf`:

```apache
<VirtualHost *:80>
    ServerName bellflowapp.com
    ServerAlias www.bellflowapp.com
    DocumentRoot /var/www/bellflowapp

    <Directory /var/www/bellflowapp>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/bellflowapp-error.log
    CustomLog ${APACHE_LOG_DIR}/bellflowapp-access.log combined
</VirtualHost>
```

Then enable it:

```bash
sudo a2ensite bellflowapp.com.conf
sudo systemctl reload apache2
```

### 8. Nginx Server Block Example

Create `/etc/nginx/sites-available/bellflowapp.com`:

```nginx
server {
    listen 80;
    server_name bellflowapp.com www.bellflowapp.com;
    root /var/www/bellflowapp;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /public/index.html;
    }

    location /admin/ {
        try_files $uri $uri/ /admin/index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Then enable it:

```bash
sudo ln -s /etc/nginx/sites-available/bellflowapp.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 9. Run the Installer

Open:

```text
https://bellflowapp.com/admin/install/install.php
```

After install, log in, then run the database upgrade from **Admin → Settings → Run Database Upgrade** so the ACH/subscription tables are applied on the live database too.

### 10. Enable SSL

If using Apache:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d bellflowapp.com -d www.bellflowapp.com
```

If using Nginx:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d bellflowapp.com -d www.bellflowapp.com
```

### 11. Configure Cron on the VPS

Edit the web server user's crontab:

```bash
sudo crontab -e
```

Add:

```bash
0 8 * * * /usr/bin/php /var/www/bellflowapp/admin/cron/daily.php >> /var/log/bellflowapp-cron.log 2>&1
```

### 12. Stripe URLs for `bellflowapp.com`

Use these production URLs in Stripe:

- Webhook endpoint: `https://bellflowapp.com/public/api/stripe-webhook.php`
- Customer portal link request page: `https://bellflowapp.com/public/portal/request-link.php`
- Booking page: `https://bellflowapp.com/book.php`
- Admin login: `https://bellflowapp.com/admin/login.php`

### 13. VPS Go-Live Notes

- Keep `.env` outside version control.
- Confirm the web server can write to `admin/uploads/`.
- Use the domain name in Stripe webhook configuration, not the server IP.
- Set `APP_INSTALLED=true` after installation is complete.
- Test one full booking, one ACH payment, one invoice payment, and one webhook delivery before launch.

---

## ④ Configure the Admin

Edit `admin/config/config.php`:

```php
define('DB_HOST', 'localhost');       // usually localhost on shared hosting
define('DB_NAME', 'your_db_name');   // create this DB first in cPanel → MySQL Databases
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
// APP_URL is now auto-detected from the server's host — no manual edit needed.

define('CRON_KEY', 'change-me-to-a-random-secret'); // used to secure the cron URL
```

---

## ⑤ Run the Installer

Navigate to your site's installer URL, for example:

```
https://your-actual-domain.com/admin/install/install.php
```

The installer will:
- Verify PHP version, PDO, and DB credentials
- Create the database (if it doesn't exist)
- Import the full schema (all tables)
- Seed default settings, dumpster inventory, and the admin user

**First Login Credentials**

| Field    | Value             |
|----------|-------------------|
| Email    | admin@example.com |
| Password | ChangeMe123!      |

> You will be forced to change the password on first login.

**Lock the installer** after setup — open `admin/config/config.php` and set:

```php
define('APP_INSTALLED', true);
```

---

## ⑥ Set Up the Booking & Payment System

### Run the Booking Schema Migration

After the main installer completes, run the booking schema SQL to add the bookings tables and enhance the inventory:

```bash
mysql -u your_user -p your_db < admin/install/booking_schema.sql
```

Or paste the contents of `admin/install/booking_schema.sql` into cPanel's phpMyAdmin query box.

This adds:
- **`bookings`** table (full rental bookings with payment tracking)
- **`inventory_blocks`** table (admin-created unavailability periods)
- `type`, `daily_rate`, and `active` columns to `dumpsters`
- Default Stripe settings rows in `settings`

### Configure Stripe for Online Payments

1. Log in to the admin panel → **Settings** → scroll to **Stripe & Booking Configuration**
2. Set **Stripe Mode** to `test` for playground/testing or `live` for production
3. Enter your **Publishable Key** (starts with `pk_test_` or `pk_live_`)
4. Enter your **Secret Key** (starts with `sk_test_` or `sk_live_`) — this is stored server-side only and never exposed to the browser
5. Set a **Webhook Secret** (`whsec_…`) after creating the webhook in Stripe Dashboard

### Install Stripe SDK via Composer

```bash
cd /path/to/public_html/admin
composer install
```

Without Composer, Stripe Checkout is disabled and customers are shown a cash/check confirmation instead.

### Register the Stripe Webhook

In the [Stripe Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks):

1. Add endpoint: `https://yourdomain.com/public/api/stripe-webhook.php`
2. Select event: `checkout.session.completed`
3. Copy the **Signing Secret** and paste it into Admin → Settings → Stripe Webhook Secret

### Test the Booking Flow (Playground Mode)

With `stripe_mode = test`:
1. Visit `https://yourdomain.com/book.php`
2. Choose a unit, select dates, fill in customer info
3. Select **Pay Online with Stripe** → you'll be redirected to Stripe's test checkout
4. Use test card `4242 4242 4242 4242`, any future expiry, any CVC
5. On success, you'll land on the confirmation page
6. The booking appears in Admin → Bookings with payment status **Paid**

### Configure Inventory Pricing

1. Admin → Inventory → click **Edit** on any dumpster
2. Set **Type** (Dumpster or Trailer)
3. Set **Daily Rate** (e.g. `$85.00`)
4. Ensure **Active** is checked to make it bookable online

### Admin Booking Management

| Page | Purpose |
|------|---------|
| Admin → Bookings | Full bookings list with filters |
| Admin → Bookings → New Booking | Manually create a booking (admin/office roles) |
| Admin → Bookings → View | Full booking detail, payment update, cancel |
| Admin → Bookings → Block Dates | Block a unit from online booking for a date range |

---

## ⑦ PHPMailer (Recommended for Reliable Email)

PHPMailer is **not required** — the system falls back to PHP's built-in `mail()` — but SMTP via PHPMailer is strongly recommended for reliable delivery (Gmail, Mailgun, SendGrid, etc.).

### Install via Composer

```bash
cd /path/to/public_html/admin
composer install
```

If Composer is not available on your server:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
```

Many cPanel/shared hosts also offer Composer under **cPanel → Software → PHP Composer**.

### Configure SMTP in the Admin Panel

1. Log in → **Settings** → **Email Configuration**
2. Fill in SMTP Host, Port, Username, Password, and Encryption (TLS recommended)
3. Set **From Name** and **From Email**
4. Set **Notification Email(s)** — comma-separated addresses that receive an alert each time the contact form is submitted
5. Click **Send Test Email** to verify

> Without Composer / SMTP configured, leave `smtp_host` blank and the system uses PHP's `mail()`.

---

## ⑧ Set Folder Permissions

```bash
chmod 755 admin/assets/img/
```

On some shared hosts the web server runs as a different user in the same group. If PHP cannot write to the directory (e.g. uploads fail), try:

```bash
chmod 775 admin/assets/img/
```

---

## ⑨ Set Up the Daily Cron Job (cPanel)

Go to **cPanel → Cron Jobs** and add:

```
0 8 * * * php /home/youraccount/public_html/admin/cron/daily.php >> /dev/null 2>&1
```

Or call it via URL with the secure key:

```
https://yourdomain.com/admin/cron/daily.php?key=YOUR_CRON_KEY
```

Replace `yourdomain.com` with your actual domain.

The cron job:
- Sends delivery reminder emails (for tomorrow's scheduled deliveries)
- Sends overdue pickup alerts to the company email
- Auto-activates work orders whose delivery date has passed

---

## ⑩ Enable HTTPS

In `admin/.htaccess`, uncomment the HTTPS redirect block:

```apache
<IfModule mod_rewrite.c>
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</IfModule>
```

---

## Contact Form → Admin Leads Integration

When a visitor fills out the quote request form on the public website:

1. The form POSTs to `public/api/contact.php`
2. The submission is saved as a **Lead** in the admin database (source: *Website Contact Form*)
3. An email notification is sent to every address configured in **Settings → Notification Email(s)**
4. The admin can view and manage the lead under **Admin → Leads**

No additional configuration is needed beyond setting the notification email(s) in Settings.

---

## Role Descriptions

| Role       | Access Level |
|------------|--------------|
| admin      | Full access including user management |
| office     | Create/edit leads, customers, quotes, work orders. No user management. |
| dispatcher | View all records; can update work order statuses only |
| readonly   | View-only access |

---

## Two-Factor Authentication

1. Log in → **Settings → Two-Factor Authentication**
2. Scan the QR code with Google Authenticator, Authy, or any TOTP app
3. Enter the 6-digit code to confirm
4. Save your 8 backup codes in a safe place (shown only once)
5. Admins can disable 2FA for any user via **Settings → Users**

---

## Work Order Module — Step-by-Step Guide

The Work Order module is the day-to-day operational hub. It tracks every rental from scheduling through completion and generates a printable work order document / invoice for each job.

### Accessing Work Orders

Log in to the admin panel → click **Work Orders** in the left sidebar.

- The list view shows all work orders with status tabs, search, and date-range filters.
- Tabs: **All · Scheduled · Delivered · Active · Pickup Requested · Picked Up · Completed · Canceled**
- Search by WO number, customer name, or service address.

---

### Creating a Work Order

**Admin → Work Orders → New Work Order** (or click the **+ New Work Order** button on the list page).

Roles that can create work orders: `admin`, `office`, `dispatcher`.

| Field | Required | Notes |
|-------|----------|-------|
| Customer Name | ✅ | |
| Phone / Email | optional | Contact details for the customer |
| Service Address | ✅ | Street address where the dumpster is dropped |
| City / State / ZIP | optional | Used on the printed work order |
| Dumpster Size | optional | e.g. 10 yard, 20 yard |
| Project Type | optional | e.g. Residential Clean-Out, Construction |
| Assign Dumpster | optional | Picks a specific unit from inventory; sets that unit to **Reserved** |
| Delivery Date | ✅ | Scheduled drop-off date |
| Pickup Date | optional | Expected pickup; can be left blank and updated later |
| Assigned Driver | optional | Driver/dispatcher user who handles delivery |
| Amount | optional | Total charge for the job (displayed on the invoice) |
| Priority | optional | Normal / High / Urgent — shown as a badge on the list |
| Internal Notes | optional | Staff-only — not shown on printed documents |
| Footer / WO Notes | optional | Printed at the bottom of the work order document |

> **Tip:** You can pre-fill a work order directly from a Quote. Open the quote and click **Convert to Work Order** — customer info, size, and amount are carried over automatically.

> **Default Footer:** Set a company-wide default footer text in **Admin → Settings → Work Order Footer**. It auto-fills every new work order.

---

### Work Order Status Lifecycle

Each work order moves through the following statuses. Dispatchers can advance any status; the system automatically updates the assigned dumpster's inventory state.

```
Scheduled → Delivered → Active → Pickup Requested → Picked Up → Completed
                                                                      ↑
                                                             (or Canceled at any point)
```

| Status | Meaning | Dumpster Inventory Effect |
|--------|---------|--------------------------|
| **Scheduled** | Job is booked, delivery not yet done | Dumpster marked **Reserved** |
| **Delivered** | Dumpster has been dropped at the site | Dumpster marked **In Use** |
| **Active** | Rental is in progress on-site | Dumpster marked **In Use** |
| **Pickup Requested** | Customer has requested pickup | Dumpster stays **In Use** |
| **Picked Up** | Dumpster has been retrieved | Dumpster returned to **Available**; actual pickup date stamped automatically |
| **Completed** | Job is fully done and closed | Dumpster returned to **Available** |
| **Canceled** | Job was canceled | Dumpster returned to **Available** |

**To change a status:** Open the work order → use the **Update Status** dropdown on the detail page and click **Update**. Every status change is automatically logged in the activity timeline.

---

### Viewing a Work Order

**Admin → Work Orders → click any WO number (or the eye icon).**

The detail page shows:

- **Header:** WO number, status badge, priority badge, creation date.
- **Job & Schedule panel:** Dumpster size, project type, assigned unit, delivery/pickup dates, assigned driver.
- **Customer panel:** Name, phone, email, service address.
- **Financial panel:** Amount charged.
- **Notes / Timeline:** A chronological log of every status change and manually added staff note.
- **Action buttons:** Edit · Update Status · Add Note · Print Work Order · Generate Invoice · Delete (admin only).

---

### Adding Notes to a Work Order

On the work order detail page, scroll to the **Notes** panel and type in the **Add Note** box, then click **Add Note**. Notes are visible to all staff and are stamped with the author and timestamp.

---

### Printing the Work Order

Click **Print Work Order** on the detail page to open a print-formatted view of the job. This includes:
- Company letterhead (name, phone, email, address)
- WO number, delivery/pickup dates, status
- Customer info and service address
- Job details (size, project type, assigned dumpster, driver)
- Footer notes (from the work order or company default)

Click the **Print** button in your browser or on the page to send to a printer.

---

### Generating an Invoice

Click **Invoice** on the detail page to view a customer-ready invoice document. The invoice includes:
- Company header with contact info
- Bill-to section with customer details
- Line item: dumpster rental amount
- Subtotal, optional tax (configured in Settings → Tax Rate), and total
- Payment instructions
- Footer notes

Click **Print** on the invoice page to print or save as PDF.

---

### Editing a Work Order

Click the **Edit** button (pencil icon) on the list or the **Edit** button on the detail page. All fields are editable. If you change the **assigned dumpster**, the system automatically releases the old unit back to **Available** and marks the new unit as **Reserved**.

---

### Deleting a Work Order

Only users with the `admin` role can delete work orders. Deletion is permanent and cannot be undone. The assigned dumpster (if any) is automatically returned to **Available** on delete.

---

### Work Order Settings (Admin → Settings)

| Setting | Where | Purpose |
|---------|-------|---------|
| Work Order Footer | Settings → Work Order Footer | Default footer text pre-filled on every new work order |
| Tax Rate | Settings → Tax Rate | Applied to invoice totals (e.g. enter `8.25` for 8.25%) |
| Company Name / Phone / Email / Address | Settings → Company Info | Printed on work orders and invoices |

---

## Production Readiness Checklist

Complete all items below before going live with real customers.

### Configuration
- [ ] Configure production secrets in `.env` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `CRON_KEY`, `PORTAL_SIGNING_KEY`)
- [ ] Change `CRON_KEY` to a long random string (e.g. `openssl rand -hex 32`)
- [ ] Change `PORTAL_SIGNING_KEY` to a long random string
- [ ] Set `APP_INSTALLED = true` in `config.php` after running the installer
- [ ] Run the main installer: `https://yourdomain.com/admin/install/install.php`
- [ ] Run the booking schema migration (`admin/install/booking_schema.sql`)
- [ ] Run the ACH/subscription billing upgrade from Admin → Settings → Run Database Upgrade

### Security
- [ ] Enable HTTPS — uncomment the redirect block in `admin/.htaccess`
- [ ] Change the default admin password immediately after first login (forced on first login)
- [ ] Enable 2FA for all admin-level accounts
- [ ] Verify that `/admin/install/` returns a 403 or redirect (controlled by `APP_INSTALLED` flag)
- [ ] Login rate limiting is built-in (10 failed attempts → 15-minute IP lock) — no extra config needed

### Email
- [ ] Set **Company Email** and **Notification Email(s)** in Admin → Settings
- [ ] (Recommended) Configure SMTP via Admin → Settings → Email Configuration and click **Send Test Email**

### Payments (if using Stripe)
- [ ] Set Stripe Mode to `live` in Admin → Settings → Stripe & Booking Configuration
- [ ] Enter live Publishable Key and Secret Key
- [ ] Register the webhook endpoint in Stripe Dashboard and paste the Signing Secret
- [ ] Test end-to-end with a live card before announcing to customers

### Inventory & Pricing
- [ ] Review default dumpster units in Admin → Inventory; update unit codes, sizes, daily rates
- [ ] Set each unit **Active** to make it bookable online
- [ ] Set a default Work Order footer in Admin → Settings → Work Order Footer

### Cron Job
- [ ] Add the daily cron job in cPanel or your VPS crontab (see **⑨ Set Up the Daily Cron Job** section above and the VPS section)

### Final Checks
- [ ] Visit the public website and confirm all pages load correctly
- [ ] Submit a test contact/quote form and verify the lead appears in Admin → Leads
- [ ] Create a test work order end-to-end: create → deliver → complete → print invoice
- [ ] Remove or restrict access to any test data created during setup

---

## Security Checklist

- [ ] Change `CRON_KEY` in `config.php` before going live
- [ ] Set `APP_INSTALLED = true` after installation
- [ ] Enable HTTPS (uncomment block in `admin/.htaccess`)
- [ ] Enable 2FA for admin accounts
- [ ] Login rate limiting is active — 10 failed attempts locks the IP for 15 minutes
- [ ] `/admin/install/` is blocked from repeat runs by the `APP_INSTALLED` flag

---

## Default Dumpster Units (seeded by installer)

| Unit ID | Size    |
|---------|---------|
| TP-001  | 10 yard |
| TP-002  | 10 yard |
| TP-003  | 15 yard |
| TP-004  | 15 yard |
| TP-005  | 20 yard |
| TP-006  | 20 yard |
| TP-007  | 30 yard |
| TP-008  | 40 yard |

Manage inventory via **Admin → Inventory** after first login.


---

## 📲 How to Install the App on a Phone (PWA)

This app is a **Progressive Web App (PWA)** — customers and staff can install it directly to their phone's home screen without going through an app store.

### Install on iPhone / iPad (Safari)
1. Open your site in **Safari** (must be Safari, not Chrome)
2. Tap the **Share** button (box with arrow pointing up) at the bottom of the screen
3. Scroll down and tap **"Add to Home Screen"**
4. Confirm the name and tap **Add**
5. The app icon now appears on your home screen — tap it to open in full-screen mode

### Install on Android (Chrome)
1. Open your site in **Google Chrome**
2. Tap the **three-dot menu** (⋮) in the top-right corner
3. Tap **"Add to Home screen"** or **"Install app"**
4. Tap **Install** to confirm
5. The app icon appears on your home screen

### Install on Desktop (Chrome / Edge)
1. Open your site in Chrome or Edge
2. Look for the **install icon** (➕ or computer icon) in the address bar
3. Click it and select **Install**
4. The app opens in its own window without browser UI

> **Note:** For the PWA to install, your site must be served over **HTTPS**. HTTP will not work.

---

## 🗄️ Database Upgrade Script

If you are **updating an existing installation** (not doing a fresh install), run the upgrade script to apply new database columns and tables added in recent updates.

### What the upgrade script adds:
| Table | Column / Change |
|-------|----------------|
| `dumpsters` | `type` (dumpster or trailer) |
| `dumpsters` | `daily_rate` (per-day pricing) |
| `dumpsters` | `active` (show/hide from booking page) |
| `dumpsters` | `image` (photo path for booking page) |
| `bookings` | Creates full table if missing |
| `inventory_blocks` | Creates full table if missing |
| `settings` | Adds Stripe & booking default keys |

### How to run:

**Step 1 — Edit the secret**

Open `admin/install/upgrade.php` and change this line:
```php
define('UPGRADE_SECRET', 'change-this-to-a-random-string-before-use');
```
Replace it with something like:
```php
define('UPGRADE_SECRET', 'myUpgrade2026!abc');
```

**Step 2 — Run it**

*Option A — Browser:*
```
https://your-domain.com/admin/install/upgrade.php?secret=myUpgrade2026!abc
```

*Option B — Command line (SSH/CLI):*
```bash
php admin/install/upgrade.php
```

**Step 3 — Delete the file after use**
```bash
rm admin/install/upgrade.php
# or rename it:
mv admin/install/upgrade.php admin/install/upgrade.php.done
```

> The script is **safe to run multiple times** — it skips steps that have already been applied.

---

## 💳 Stripe Setup Guide

### Mode Overview
| Mode | Use For | Real Money? |
|------|---------|-------------|
| **Test (Sandbox)** | Development & testing — use fake card numbers | ❌ No |
| **Live (Production)** | Real customers paying real money | ✅ Yes |

---

### Step 1 — Create a Stripe Account
1. Go to [https://dashboard.stripe.com/register](https://dashboard.stripe.com/register) and create a free account
2. Verify your email address
3. For **Live mode** (taking real payments): complete Stripe's identity verification and add a bank account under **Settings → Bank Accounts**

---

### Step 2 — Get Your API Keys

#### Test / Sandbox Keys
1. In the Stripe Dashboard, make sure the **"Test mode"** toggle (top-right) is **ON** (shows orange "TEST" label)
2. Go to **Developers → API keys**
3. Copy:
   - **Publishable key** — starts with `pk_test_...`
   - **Secret key** — click "Reveal test key", starts with `sk_test_...`

#### Live / Production Keys
1. Turn **Test mode OFF** in the Stripe Dashboard (toggle off)
2. Go to **Developers → API keys**
3. Copy:
   - **Publishable key** — starts with `pk_live_...`
   - **Secret key** — click "Reveal live key", starts with `sk_live_...`

> ⚠️ **Never commit secret keys to Git.** The keys go in Admin → Settings, not in code files.

---

### Step 3 — Enter Keys in Admin Settings
1. Log in to your admin panel
2. Go to **Settings → Stripe & Booking Configuration**
3. Fill in:
   | Field | Value |
   |-------|-------|
   | Stripe Mode | `test` (sandbox) or `live` (production) |
   | Publishable Key | `pk_test_...` or `pk_live_...` |
   | Secret Key | `sk_test_...` or `sk_live_...` |
   | Webhook Signing Secret | (from Step 4 below) |
4. Click **Save Settings**

---

### Step 4 — Set Up the Stripe Webhook

The webhook tells your app when a payment is completed so bookings get marked as paid automatically.

#### For Test Mode (local/staging)

Use the **Stripe CLI** to forward webhooks to your local machine:

```bash
# Install Stripe CLI: https://stripe.com/docs/stripe-cli
stripe login
stripe listen --forward-to https://your-domain.com/api/stripe-webhook.php
```

The CLI will print a **webhook signing secret** starting with `whsec_...` — copy it into Admin → Settings → Webhook Signing Secret.

#### For Live Mode (production)

1. In Stripe Dashboard → **Developers → Webhooks**
2. Click **"Add endpoint"**
3. Enter your endpoint URL:
   ```
   https://your-domain.com/api/stripe-webhook.php
   ```
4. Under **"Events to listen to"**, add:
   - `checkout.session.completed`
   - `checkout.session.expired`
5. Click **"Add endpoint"**
6. Click on the new endpoint → **"Reveal"** the Signing Secret (`whsec_...`)
7. Paste it into **Admin → Settings → Webhook Signing Secret**

---

### Step 5 — Install the Stripe PHP SDK (if not already installed)

The Stripe SDK must be installed via Composer:

```bash
# Navigate to the admin folder
cd admin/

# Install Stripe SDK
composer require stripe/stripe-php

# Or if you already have a composer.json, just run:
composer install
```

If you don't have Composer installed:
- **Windows:** Download from [https://getcomposer.org/download/](https://getcomposer.org/download/)
- **Linux/Mac:** `curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer`
- **Hostinger/cPanel:** Use the cPanel Terminal or File Manager to upload a pre-built `vendor/` folder

> If Stripe SDK is missing, the app automatically falls back to **Cash payment** so bookings still work — but card payments won't be processed until the SDK is installed.

---

### Step 6 — Test Stripe Payments (Sandbox)

Use these **test card numbers** in Stripe sandbox mode — they don't charge real money:

| Card Number | Result |
|-------------|--------|
| `4242 4242 4242 4242` | ✅ Payment succeeds |
| `4000 0000 0000 0002` | ❌ Payment declined |
| `4000 0025 0000 3155` | 🔐 Requires 3D Secure (extra auth step) |

For all test cards:
- Use any future **expiry date** (e.g. `12/29`)
- Use any 3-digit **CVC** (e.g. `123`)
- Use any 5-digit **ZIP** (e.g. `12345`)

---

### Step 7 — Go Live Checklist

Before switching to live mode, confirm:

- [ ] Stripe account is **fully verified** (identity + bank account added)
- [ ] You tested at least one **sandbox payment** end-to-end
- [ ] Live **publishable key** and **secret key** are entered in Admin → Settings
- [ ] Stripe Mode is set to **`live`** in Admin → Settings
- [ ] Live **webhook endpoint** is registered in Stripe Dashboard with correct URL
- [ ] **Webhook Signing Secret** (`whsec_live_...`) is entered in Admin → Settings
- [ ] HTTPS is enabled on your domain (required by Stripe for live payments)
- [ ] Tested a **real $1 charge** with your own card and confirmed it shows in Stripe Dashboard

---

### Stripe Troubleshooting

| Problem | Likely Cause | Fix |
|---------|-------------|-----|
| Booking saved but no Stripe redirect | Stripe SDK not installed | Run `composer install` in `admin/` folder |
| Webhook not firing | Wrong endpoint URL or not registered | Check Stripe Dashboard → Webhooks |
| `Invalid API Key` error | Wrong key entered or wrong mode | Check key matches mode (test vs live) |
| Payment succeeds but booking still "pending" | Webhook not reaching server | Check webhook signing secret; check server firewall allows Stripe IPs |
| `Stripe\Exception\AuthenticationException` | Secret key is wrong or missing | Re-enter secret key in Admin → Settings |
