<?php
/**
 * Help & Onboarding Guide
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();

layout_start('Help &amp; Guide', 'help');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa-solid fa-circle-question me-2" style="color:#f97316;"></i>Help &amp; Guide</h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(APP_URL) ?>/../docs/TESTING_AND_ONBOARDING_GUIDE.md" target="_blank" rel="noopener" class="btn-tp-ghost btn-tp-sm">
            <i class="fa-solid fa-book me-1"></i> Testing Guide
        </a>
        <a href="<?= e(APP_URL) ?>/modules/settings/index.php" class="btn-tp-primary btn-tp-sm">
            <i class="fa-solid fa-gear me-1"></i> Open Settings
        </a>
    </div>
</div>

<!-- Quick nav pills -->
<ul class="nav nav-pills mb-4 flex-wrap gap-1" id="helpNav">
    <li class="nav-item">
        <a class="nav-link active" href="#getting-started" onclick="showSection('getting-started',this)">
            <i class="fa-solid fa-rocket me-1"></i> Getting Started
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#stripe" onclick="showSection('stripe',this)">
            <i class="fa-brands fa-stripe me-1"></i> Stripe Setup
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#bookings" onclick="showSection('bookings',this)">
            <i class="fa-solid fa-calendar-check me-1"></i> Bookings
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#invoices" onclick="showSection('invoices',this)">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Invoices
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#work-orders" onclick="showSection('work-orders',this)">
            <i class="fa-solid fa-clipboard-list me-1"></i> Work Orders
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#email" onclick="showSection('email',this)">
            <i class="fa-solid fa-envelope me-1"></i> Email Setup
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#inventory" onclick="showSection('inventory',this)">
            <i class="fa-solid fa-dumpster me-1"></i> Inventory
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#testing" onclick="showSection('testing',this)">
            <i class="fa-solid fa-vial-circle-check me-1"></i> Testing
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#client-onboarding" onclick="showSection('client-onboarding',this)">
            <i class="fa-solid fa-person-chalkboard me-1"></i> Client Onboarding
        </a>
    </li>
</ul>

<!-- ── Getting Started ─────────────────────────────────────────────────────── -->
<div id="section-getting-started" class="help-section">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-rocket me-2" style="color:#f97316;"></i>Getting Started</h5>
        <p>Welcome to the Trash Panda Roll-Offs Admin Backend! Here's what to do first:</p>
        <ol class="mb-3" style="line-height:2;">
            <li><strong>Configure Company Info</strong> — Go to <a href="<?= e(APP_URL) ?>/modules/settings/index.php">Settings</a> and fill in your company name, phone, email, and address. This information appears on invoices, work orders, and emails.</li>
            <li><strong>Upload your Logo</strong> — In Settings → Branding, upload your logo image. It will appear on the sidebar, invoices, and work orders.</li>
            <li><strong>Set up Email</strong> — Configure your SMTP settings so the system can send booking confirmations, invoices, and notifications. See the <a href="#email" onclick="showSection('email',null)">Email Setup</a> guide.</li>
            <li><strong>Set up Stripe</strong> — If you want to accept online payments, connect your Stripe account. See the <a href="#stripe" onclick="showSection('stripe',null)">Stripe Setup</a> guide.</li>
            <li><strong>Add your Inventory</strong> — Go to <a href="<?= e(APP_URL) ?>/modules/dumpsters/index.php">Inventory</a> and add all your dumpsters with sizes, rates, and descriptions.</li>
            <li><strong>Run the Database Upgrade</strong> — In Settings → Database Maintenance, click "Run Database Upgrade" to apply any pending schema updates.</li>
        </ol>
        <div class="alert alert-warning" style="font-size:.88rem;">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            <strong>First time setup?</strong> Make sure to change the default admin password immediately.
            Go to <a href="<?= e(APP_URL) ?>/modules/settings/change_password.php">Change Password</a>.
        </div>
    </div>

    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-map-signs me-2" style="color:#f97316;"></i>Admin Navigation Overview</h5>
        <div class="table-responsive">
        <table class="table tp-table">
            <thead><tr><th>Section</th><th>What it does</th></tr></thead>
            <tbody>
                <tr><td><i class="fa-solid fa-gauge me-1"></i><strong>Dashboard</strong></td><td>Overview of today's bookings, recent activity, revenue summary, and quick actions.</td></tr>
                <tr><td><i class="fa-solid fa-calendar-check me-1"></i><strong>Bookings</strong></td><td>All dumpster rental bookings — view, edit, update payment status, confirm or cancel. New bookings from the public site appear here.</td></tr>
                <tr><td><i class="fa-solid fa-clipboard-list me-1"></i><strong>Work Orders</strong></td><td>Internal work orders for delivery, pickup, and service. Can be created from bookings or independently.</td></tr>
                <tr><td><i class="fa-solid fa-calendar-days me-1"></i><strong>Calendar</strong></td><td>Visual calendar view of all scheduled deliveries and pickups.</td></tr>
                <tr><td><i class="fa-solid fa-users me-1"></i><strong>Customers</strong></td><td>Customer database — view booking history, create invoices, and manage contact info.</td></tr>
                <tr><td><i class="fa-solid fa-file-invoice-dollar me-1"></i><strong>Invoices</strong></td><td>Create and manage custom invoices with line items. Generate Stripe payment links for online payment.</td></tr>
                <tr><td><i class="fa-solid fa-money-bill-wave me-1"></i><strong>Payments</strong></td><td>Revenue reports, payment records from bookings and invoices, Stripe live data.</td></tr>
                <tr><td><i class="fa-solid fa-chart-bar me-1"></i><strong>Reports</strong></td><td>Revenue reports by method (Stripe/Cash/Check) and date range.</td></tr>
                <tr><td><i class="fa-solid fa-dumpster me-1"></i><strong>Inventory</strong></td><td>Dumpster fleet management — add units, set pricing, sync with Stripe products.</td></tr>
                <tr><td><i class="fa-solid fa-bell me-1"></i><strong>Notifications</strong></td><td>Send SMS/email notifications to customers about their bookings.</td></tr>
                <tr><td><i class="fa-solid fa-gear me-1"></i><strong>Settings</strong></td><td>Company info, email/SMTP config, Stripe keys, document templates.</td></tr>
                <tr><td><i class="fa-solid fa-user-group me-1"></i><strong>Users</strong></td><td>Manage admin user accounts and roles (admin or office).</td></tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-list-check me-2" style="color:#f97316;"></i>Recommended First-Day Tour</h5>
        <div class="row g-3">
            <div class="col-md-6">
                <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:8px;padding:1rem;height:100%;">
                    <h6 class="mb-2">Owner / Admin Tour</h6>
                    <ol style="font-size:.9rem;line-height:1.8;margin-bottom:0;">
                        <li>Settings</li>
                        <li>Inventory</li>
                        <li>Bookings</li>
                        <li>Work Orders</li>
                        <li>Invoices</li>
                        <li>Payments</li>
                        <li>Users</li>
                    </ol>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:8px;padding:1rem;height:100%;">
                    <h6 class="mb-2">Office Staff Tour</h6>
                    <ol style="font-size:.9rem;line-height:1.8;margin-bottom:0;">
                        <li>Dashboard</li>
                        <li>Leads</li>
                        <li>Bookings</li>
                        <li>Work Orders</li>
                        <li>Customers</li>
                        <li>Invoices</li>
                        <li>Help &amp; Guide</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Stripe Setup ─────────────────────────────────────────────────────────── -->
<div id="section-stripe" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-brands fa-stripe me-2" style="color:#635bff;"></i>Stripe Setup &amp; Onboarding</h5>

        <div class="alert alert-info" style="font-size:.88rem;">
            <i class="fa-solid fa-circle-info me-1"></i>
            Stripe allows you to accept online payments from customers via credit/debit card.
            You need a Stripe account to use this feature.
        </div>

        <h6 class="mt-3 mb-2">Step 1 — Create a Stripe Account</h6>
        <ol style="line-height:1.9;">
            <li>Go to <a href="https://stripe.com" target="_blank" rel="noopener">stripe.com</a> and click "Start now" or "Sign in".</li>
            <li>Complete the onboarding — enter your business info, bank account for payouts, and verify your identity.</li>
            <li>Once onboarded, your dashboard will show as <strong>Active</strong>.</li>
        </ol>

        <h6 class="mt-3 mb-2">Step 2 — Get Your API Keys</h6>
        <ol style="line-height:1.9;">
            <li>In Stripe Dashboard, go to <strong>Developers → API Keys</strong>.</li>
            <li>Copy the <strong>Publishable key</strong> (starts with <code>pk_live_</code> or <code>pk_test_</code>).</li>
            <li>Click "Reveal" to copy the <strong>Secret key</strong> (starts with <code>sk_live_</code> or <code>sk_test_</code>).</li>
            <li>In <a href="<?= e(APP_URL) ?>/modules/settings/index.php">Settings → Stripe</a>, paste both keys and click Save.</li>
        </ol>

        <div class="alert alert-warning" style="font-size:.88rem;margin-top:.5rem;">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            <strong>Test vs Live Mode:</strong> Use <code>pk_test_</code> / <code>sk_test_</code> keys while testing.
            Switch to <code>pk_live_</code> / <code>sk_live_</code> keys when ready for real payments.
            Set the Stripe Mode dropdown to match.
        </div>

        <h6 class="mt-3 mb-2">Step 3 — Set Up Webhooks (for automatic payment confirmation)</h6>
        <p style="font-size:.9rem;">Webhooks allow Stripe to automatically update booking/invoice status when a customer pays online.</p>
        <ol style="line-height:1.9;">
            <li>In Stripe Dashboard, go to <strong>Developers → Webhooks → Add Endpoint</strong>.</li>
            <li>Enter this endpoint URL:<br>
                <code style="background:var(--dk3);padding:.2rem .5rem;border-radius:4px;word-break:break-all;">
                    <?= e(rtrim(preg_replace('#/admin$#', '', APP_URL), '/')) ?>/public/api/stripe-webhook.php
                </code>
            </li>
            <li>Select events to listen for:
                <ul style="margin-top:.3rem;">
                    <li><code>checkout.session.completed</code></li>
                    <li><code>payment_intent.succeeded</code></li>
                    <li><code>payment_intent.payment_failed</code></li>
                    <li><code>charge.refunded</code></li>
                </ul>
            </li>
            <li>After creating the webhook, click "Reveal" on the Signing Secret and copy it.</li>
            <li>Paste it into <a href="<?= e(APP_URL) ?>/modules/settings/index.php">Settings → Stripe → Webhook Secret</a>.</li>
        </ol>

        <h6 class="mt-3 mb-2">Step 4 — Install the Stripe PHP SDK</h6>
        <p style="font-size:.9rem;">The Stripe SDK must be installed via Composer on your server:</p>
        <pre style="background:#111827;color:#d1fae5;padding:1rem;border-radius:6px;font-size:.82rem;">cd /path/to/your/admin/
composer install</pre>
        <p style="font-size:.9rem;">If Composer is not installed, follow instructions at <a href="https://getcomposer.org" target="_blank" rel="noopener">getcomposer.org</a>.</p>

        <h6 class="mt-3 mb-2">Testing Payments</h6>
        <p style="font-size:.9rem;">In test mode, use the card number <code>4242 4242 4242 4242</code> with any future expiry and any CVC to simulate a successful payment.</p>
    </div>
</div>

<!-- ── Bookings ────────────────────────────────────────────────────────────── -->
<div id="section-bookings" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-calendar-check me-2" style="color:#f97316;"></i>Bookings</h5>

        <h6 class="mb-2">Booking Workflow</h6>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
            <?php foreach ([
                ['Pending','badge-pending','Customer submitted, awaiting confirmation'],
                ['Confirmed','badge-confirmed','Booking confirmed, awaiting delivery'],
                ['Paid','badge-paid','Payment received'],
                ['Completed','badge-completed','Dumpster picked up, job done'],
                ['Canceled','badge-canceled','Booking canceled'],
            ] as [$s,$b,$d]): ?>
            <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:6px;padding:.5rem .75rem;min-width:180px;">
                <span class="tp-badge <?= $b ?>"><?= $s ?></span>
                <div style="font-size:.8rem;color:var(--gy);margin-top:.3rem;"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h6 class="mb-2">Payment Statuses</h6>
        <ul style="font-size:.9rem;line-height:1.9;">
            <li><strong>Unpaid</strong> — No payment attempted yet.</li>
            <li><strong>Pending</strong> — Stripe checkout started but not completed.</li>
            <li><strong>Paid</strong> — Paid via Stripe online payment.</li>
            <li><strong>Paid Cash / Paid Check</strong> — Manually marked as paid. Use Quick Actions on the booking page.</li>
            <li><strong>Refunded</strong> — Payment was refunded in Stripe.</li>
        </ul>

        <h6 class="mb-2">Creating a Work Order from a Booking</h6>
        <p style="font-size:.9rem;">Open any booking, scroll to the bottom, and click "Create Work Order". This pre-fills the work order with booking details.</p>
    </div>
</div>

<!-- ── Invoices ────────────────────────────────────────────────────────────── -->
<div id="section-invoices" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-file-invoice-dollar me-2" style="color:#f97316;"></i>Invoices</h5>

        <h6 class="mb-2">Creating an Invoice</h6>
        <ol style="line-height:1.9;font-size:.9rem;">
            <li>Go to <strong>Invoices → New Invoice</strong>.</li>
            <li>Fill in the customer info (or select an existing customer for auto-fill).</li>
            <li>Add line items — description, quantity, unit price, and rate type (fixed/daily/weekly/monthly).</li>
            <li>Set the status: <em>Draft</em> (internal only), <em>Sent</em> (emailed to customer), <em>Paid</em> (for immediate manual payment entry).</li>
            <li>Save — the invoice is created. If Stripe is configured, a payment link is automatically generated.</li>
        </ol>

        <h6 class="mb-2">Invoice Statuses</h6>
        <ul style="font-size:.9rem;line-height:1.8;">
            <li><strong>Draft</strong> — Not yet sent to the customer. Internal use only.</li>
            <li><strong>Sent</strong> — Invoice has been sent; payment is outstanding.</li>
            <li><strong>Paid</strong> — Invoice has been paid (via Stripe, cash, or check).</li>
            <li><strong>Void</strong> — Invoice is cancelled / no longer valid.</li>
        </ul>

        <div class="alert alert-info" style="font-size:.88rem;">
            <i class="fa-solid fa-circle-info me-1"></i>
            <strong>Note:</strong> Creating an invoice does NOT automatically create a pending payment record.
            An invoice only appears in the Payments section once it has been marked as paid.
        </div>

        <h6 class="mb-2">Marking an Invoice Paid</h6>
        <p style="font-size:.9rem;">Open the invoice and use the <strong>Quick Actions</strong> panel on the right side to mark it as paid (Cash or Check), or the customer can pay online via the Stripe payment link.</p>

        <h6 class="mb-2">Sending an Invoice</h6>
        <p style="font-size:.9rem;">Copy the Stripe payment link from the invoice and send it to your customer via email, text, or your preferred method. The system does not auto-email invoices — you control when and how to send them.</p>
    </div>
</div>

<!-- ── Work Orders ─────────────────────────────────────────────────────────── -->
<div id="section-work-orders" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-clipboard-list me-2" style="color:#f97316;"></i>Work Orders</h5>

        <h6 class="mb-2">Work Order Statuses</h6>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
            <?php foreach ([
                ['Scheduled','badge-scheduled','Delivery scheduled'],
                ['Delivered','badge-confirmed','Dumpster on site'],
                ['Active','badge-confirmed','In use'],
                ['Pickup Requested','badge-pending','Customer requested pickup'],
                ['Picked Up','badge-paid','Dumpster retrieved'],
                ['Completed','badge-completed','Job completed'],
                ['Canceled','badge-canceled','Canceled'],
            ] as [$s,$b,$d]): ?>
            <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:6px;padding:.5rem .75rem;min-width:170px;">
                <span class="tp-badge <?= $b ?>"><?= $s ?></span>
                <div style="font-size:.8rem;color:var(--gy);margin-top:.3rem;"><?= $d ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <h6 class="mb-2">Adding Notes to a Work Order</h6>
        <p style="font-size:.9rem;">Open any work order and use the "Add Note" section at the bottom. Notes are timestamped and tied to the user who added them. Use this for driver instructions, site notes, or status updates.</p>

        <h6 class="mb-2">Creating an Invoice from a Work Order</h6>
        <p style="font-size:.9rem;">Open a completed work order and click "Create Invoice". The work order's customer info and dumpster details are pre-filled. Add any additional line items before saving.</p>

        <h6 class="mb-2">Printing a Work Order</h6>
        <p style="font-size:.9rem;">Open the work order and click "Print / PDF". The printout includes your company info, logo, job details, and footer text (set in Settings → Work Order Footer).</p>
    </div>
</div>

<!-- ── Email Setup ─────────────────────────────────────────────────────────── -->
<div id="section-email" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-envelope me-2" style="color:#f97316;"></i>Email Setup</h5>

        <h6 class="mb-2">Option A — PHP mail() (default, no config needed)</h6>
        <p style="font-size:.9rem;">If your hosting server has PHP mail() configured, emails will work out of the box without any SMTP settings. However, deliverability can be poor and emails may end up in spam.</p>

        <h6 class="mt-3 mb-2">Option B — SMTP (recommended)</h6>
        <p style="font-size:.9rem;">Use a dedicated email service for reliable delivery:</p>

        <div class="table-responsive">
        <table class="table tp-table" style="font-size:.88rem;">
            <thead><tr><th>Service</th><th>SMTP Host</th><th>Port</th><th>Notes</th></tr></thead>
            <tbody>
                <tr><td><strong>Gmail</strong></td><td>smtp.gmail.com</td><td>587</td><td>Requires App Password (not your regular password). Enable 2-Step Verification first.</td></tr>
                <tr><td><strong>Mailgun</strong></td><td>smtp.mailgun.org</td><td>587</td><td>Excellent deliverability. Free tier available.</td></tr>
                <tr><td><strong>SendGrid</strong></td><td>smtp.sendgrid.net</td><td>587</td><td>Use "apikey" as username, API key as password.</td></tr>
                <tr><td><strong>Postmark</strong></td><td>smtp.postmarkapp.com</td><td>587</td><td>High deliverability. Paid service.</td></tr>
            </tbody>
        </table>
        </div>

        <h6 class="mt-3 mb-2">Install PHPMailer</h6>
        <p style="font-size:.9rem;">For SMTP to work, PHPMailer must be installed:</p>
        <pre style="background:#111827;color:#d1fae5;padding:1rem;border-radius:6px;font-size:.82rem;">cd /path/to/your/admin/
composer install</pre>

        <h6 class="mt-3 mb-2">Email Notifications Sent by the System</h6>
        <div class="table-responsive">
        <table class="table tp-table" style="font-size:.88rem;">
            <thead><tr><th>Event</th><th>Who Receives It</th><th>Content</th></tr></thead>
            <tbody>
                <tr><td>New booking (online)</td><td>Customer + Admin notification emails</td><td>Booking confirmation with dates, dumpster info, payment link if applicable</td></tr>
                <tr><td>Booking confirmed</td><td>Customer</td><td>Confirmation that booking is approved</td></tr>
                <tr><td>Booking cancelled</td><td>Customer</td><td>Cancellation notice</td></tr>
                <tr><td>Payment received (Stripe)</td><td>Customer + Admin</td><td>Payment confirmation and receipt</td></tr>
                <tr><td>Pickup request</td><td>Admin notification emails</td><td>Customer has requested dumpster pickup</td></tr>
                <tr><td>Contact form submission</td><td>Admin notification emails</td><td>Contact info and message from public form</td></tr>
                <tr><td>Test email (manual)</td><td>Company email address</td><td>Verify email config is working</td></tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── Inventory ───────────────────────────────────────────────────────────── -->
<div id="section-inventory" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-dumpster me-2" style="color:#f97316;"></i>Inventory &amp; Dumpsters</h5>

        <h6 class="mb-2">Adding a Dumpster</h6>
        <ol style="font-size:.9rem;line-height:1.9;">
            <li>Go to <strong>Inventory → Add Dumpster</strong>.</li>
            <li>Enter the unit code (e.g. "UNIT-01"), size (e.g. "10 Yard"), type (Roll-Off, etc.).</li>
            <li>Set daily, weekly, and/or monthly rates.</li>
            <li>Optionally add a delivery fee, pickup fee, and mileage fee.</li>
            <li>Add a description for the online booking page.</li>
        </ol>

        <h6 class="mt-3 mb-2">Dumpster Statuses</h6>
        <ul style="font-size:.9rem;line-height:1.8;">
            <li><strong>Available</strong> — Ready to be booked/assigned.</li>
            <li><strong>Reserved</strong> — Booked but not yet delivered.</li>
            <li><strong>In Use</strong> — Currently on a job site.</li>
            <li><strong>Maintenance</strong> — Out of service for repairs.</li>
        </ul>

        <h6 class="mt-3 mb-2">Syncing with Stripe</h6>
        <p style="font-size:.9rem;">Each dumpster can be linked to a Stripe Product and Price for online booking checkout. Use the "Sync to Stripe" button on each dumpster's edit page, or click <strong>"Sync All to Stripe"</strong> on the Inventory list page to update all dumpsters at once.</p>
    </div>
</div>

<!-- Testing -->
<div id="section-testing" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-vial-circle-check me-2" style="color:#f97316;"></i>Developer Testing Checklist</h5>
        <p style="font-size:.9rem;">Use this page for quick validation, then use the full written guide for deeper launch testing.</p>

        <div class="row g-3">
            <div class="col-lg-6">
                <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:8px;padding:1rem;height:100%;">
                    <h6 class="mb-2">Public Site</h6>
                    <ul style="font-size:.9rem;line-height:1.8;margin-bottom:0;">
                        <li>Home, FAQ, Contact, Services, Sizes all load</li>
                        <li>Booking form works for card, cash, and check</li>
                        <li>Contact form creates a lead</li>
                        <li>My Bookings lookup works by email and phone</li>
                        <li>Billing portal request email sends</li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div style="background:var(--dk3);border:1px solid var(--st2);border-radius:8px;padding:1rem;height:100%;">
                    <h6 class="mb-2">Admin</h6>
                    <ul style="font-size:.9rem;line-height:1.8;margin-bottom:0;">
                        <li>Create and approve bookings</li>
                        <li>Create work orders</li>
                        <li>Create invoices from bookings and work orders</li>
                        <li>Mark manual payments</li>
                        <li>Verify email, Stripe, and webhook behavior</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="alert alert-info mt-3" style="font-size:.88rem;">
            <i class="fa-solid fa-circle-info me-1"></i>
            Full written guide: <strong><code>docs/TESTING_AND_ONBOARDING_GUIDE.md</code></strong>
        </div>

        <h6 class="mt-3 mb-2">Production Readiness Checks</h6>
        <ul style="font-size:.9rem;line-height:1.9;">
            <li>Use Stripe test keys until launch day, then switch to live keys.</li>
            <li>Run a real end-to-end payment test before handing off to the client.</li>
            <li>Remove or archive demo/mock data before go-live.</li>
            <li>Verify user roles and admin passwords.</li>
            <li>Verify portal links expire correctly and cannot be reused indefinitely.</li>
        </ul>
    </div>
</div>

<!-- Client Onboarding -->
<div id="section-client-onboarding" class="help-section" style="display:none;">
    <div class="tp-card mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-person-chalkboard me-2" style="color:#f97316;"></i>Client Onboarding Plan</h5>

        <h6 class="mb-2">Suggested Training Order</h6>
        <ol style="font-size:.9rem;line-height:1.9;">
            <li>Show the Dashboard so they understand what the system tracks.</li>
            <li>Show Leads and how website requests come in.</li>
            <li>Show Bookings and the request approval flow.</li>
            <li>Show Work Orders and how drivers / office use them.</li>
            <li>Show Invoices, payment links, and manual payment handling.</li>
            <li>Show Customers and history lookups.</li>
            <li>Show Help &amp; Guide so they know where to self-serve answers.</li>
        </ol>

        <h6 class="mt-3 mb-2">Recommended Live Practice</h6>
        <div class="table-responsive">
            <table class="table tp-table" style="font-size:.88rem;">
                <thead><tr><th>Exercise</th><th>What the client should do</th></tr></thead>
                <tbody>
                    <tr><td>Lead Intake</td><td>Open a new lead, review details, and convert it to a customer.</td></tr>
                    <tr><td>Booking Flow</td><td>Create a booking, approve it, and verify the invoice/work order path.</td></tr>
                    <tr><td>Manual Payment</td><td>Mark an invoice paid by cash or check and confirm it appears in Payments.</td></tr>
                    <tr><td>Customer Support</td><td>Use My Bookings and the Billing Portal as if they were the customer.</td></tr>
                    <tr><td>Inventory Ops</td><td>Update a dumpster status and confirm availability behavior.</td></tr>
                </tbody>
            </table>
        </div>

        <h6 class="mt-3 mb-2">Best Practice</h6>
        <p style="font-size:.9rem;margin-bottom:0;">
            If the client gets confused during training, treat that as a software UX signal, not just a training problem.
            Tighten labels, defaults, and page structure until the common tasks feel obvious.
        </p>
    </div>
</div>

<script>
function showSection(id, el) {
    document.querySelectorAll('.help-section').forEach(function(s) {
        s.style.display = 'none';
    });
    var sec = document.getElementById('section-' + id);
    if (sec) sec.style.display = 'block';

    document.querySelectorAll('#helpNav .nav-link').forEach(function(a) {
        a.classList.remove('active');
    });
    if (el) {
        el.classList.add('active');
    } else {
        document.querySelectorAll('#helpNav .nav-link').forEach(function(a) {
            if (a.getAttribute('href') === '#' + id) a.classList.add('active');
        });
    }
}

// Handle direct hash links (e.g. from settings page)
(function() {
    var hash = window.location.hash.replace('#', '');
    if (hash) {
        var link = document.querySelector('#helpNav a[href="#' + hash + '"]');
        showSection(hash, link);
    }
})();
</script>

<?php layout_end(); ?>
