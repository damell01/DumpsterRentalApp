# Trash Panda Roll-Offs Production Readiness, Testing, and Onboarding Guide

This guide is for three audiences:

1. The developer or owner testing the platform before launch.
2. The office staff or client learning the software after setup.
3. The person performing the final production go-live checks.

Use this as the master checklist for QA, launch prep, and client handoff.

## 1. Pre-Launch Setup Checklist

Complete these before formal testing:

- Confirm `admin/config/config.php` or environment values are correct.
- Confirm the production server uses the correct database.
- Change the default admin password.
- Fill out company information in `Settings`.
- Upload the company logo.
- Configure email sending and send a test email.
- Configure Stripe test keys before live keys.
- Run any pending database upgrades.
- Seed or create test dumpsters, customers, bookings, work orders, and invoices.
- Review public contact info, service areas, and footer links.
- Confirm active dumpster inventory has the real sizes you plan to offer publicly.

## 2. Production Readiness Review

Before client onboarding, review these areas:

- Branding: logo, company name, contact info, public messaging.
- Settings: billing defaults, notification defaults, Stripe, email, portal behavior.
- Inventory: sizes, unit codes, pricing, statuses, and active flags are correct.
- Public website: home page and dumpster sizes page reflect live inventory.
- Booking flow: booking CTAs route correctly and create the right records.
- Payments: Stripe links, invoices, manual payments, and webhooks are configured.
- Customer access: `My Bookings` and billing portal flows work with real customer data.
- Security: admin authentication, CSRF checks, portal token handling, and public API restrictions are in place.

## 3. Developer Test Plan

### Public Website

Test these manually:

- Home page loads correctly.
- Sizes, Services, FAQ, Contact, About, and Service Areas pages load.
- Shared header, footer, and mobile nav work correctly.
- Home page size cards show the real active dumpster sizes from inventory.
- `sizes.html` shows the real stock-backed sizes and comparison rows.
- Mobile layout works on phone width.
- No broken icons, missing styles, or obvious console errors.

### Contact and Leads

Submit the public contact form and verify:

- the lead appears in `Admin > Leads`
- lead data saves correctly
- any configured notification email sends
- converting a lead to a customer works

### Booking Flow

Test bookings using each supported path:

- Stripe / card
- cash
- check
- any pending approval flow

Verify:

- booking is created only once
- selected public size matches a real active inventory size
- inventory availability prevents bad double-booking behavior
- booking appears in `Admin > Bookings`
- payment status is correct
- confirmation email sends if configured
- related work order creation works

### Inventory

Test:

- add dumpster
- edit dumpster
- archive or deactivate dumpster
- change status between available, reserved, in use, and maintenance
- add a new size not previously shown on the site
- confirm that new active size appears on the public home and sizes pages
- sync one dumpster to Stripe
- sync all dumpsters to Stripe

### Work Orders

Verify:

- create work order manually
- create work order from booking
- update notes and statuses
- print view works
- invoice creation from work order works
- related invoice links display correctly

### Invoices

Verify:

- create invoice manually
- create invoice from booking or work order
- Stripe payment link generates when enabled
- cash/check invoices still save correctly
- invoice status changes work
- print view works
- linked invoice appears in `My Bookings` when a booking has one

### Payments and Stripe

In Stripe test mode, verify:

- payment link generation
- successful test payment completion
- webhook updates booking or invoice status
- payment appears in `Admin > Payments`
- refund handling if you support it

Recommended Stripe test card:

- `4242 4242 4242 4242`

### Customer Portal and My Bookings

Test:

- `My Bookings` lookup by email
- `My Bookings` lookup by phone
- bookings page only shows records tied to that customer identifier
- invoice chip appears when linked invoice exists
- `Pay Invoice` button opens the payment link
- billing portal request email sends
- secure billing portal link opens
- subscription actions work on test records
- payment method removal works on test records
- pickup request or similar customer actions work

### Email and Notifications

Verify these as applicable:

- new booking email
- booking approval email
- invoice email
- pickup request notice
- portal link email
- contact form email

### Permissions and Security

Verify:

- public pages do not expose admin views
- admin requires login
- office role is limited to intended pages
- invalid CSRF requests fail
- expired portal links fail
- public API endpoints reject invalid methods
- public inventory endpoint does not expose private admin-only data

## 4. Full End-to-End Test Scenarios

Run these in order and record pass/fail notes.

### Scenario A: Lead to Customer

1. Submit the public contact form.
2. Confirm the lead appears in `Admin > Leads`.
3. Convert the lead to a customer.
4. Confirm the customer record contains the lead details.

### Scenario B: Public Booking to Operations

1. Open the public home page.
2. Choose a dumpster size shown from live inventory.
3. Complete a booking with test data.
4. Confirm the booking appears in admin.
5. Confirm inventory or booking status reflects the reservation.
6. Create or approve the related work order.

### Scenario C: Invoice and Payment

1. Create an invoice from a booking or work order.
2. Generate a Stripe payment link.
3. Open `My Bookings` using the customer email or phone.
4. Confirm the invoice chip and payment button appear.
5. Complete payment with Stripe test mode.
6. Confirm payment and invoice statuses update.

### Scenario D: Billing Portal

1. Request a billing portal link.
2. Open the secure portal link from email.
3. Review invoices, payment history, and any subscription state.
4. Test a safe account-management action on a test customer.

### Scenario E: Inventory Publishing

1. Mark one size inactive or move all units of that size to non-public availability.
2. Refresh the public home page and the sizes page.
3. Confirm the public site reflects the change.
4. Add or reactivate a different active size.
5. Refresh again and confirm it now appears publicly.

## 5. Suggested Test Accounts

Keep at least these accounts available:

- one admin account
- one office account
- one customer with email only
- one customer with phone only
- one customer with a saved payment method
- one customer with an active subscription

## 6. Client UAT Checklist

Have the client or office manager personally perform these actions:

- log in and change password
- update company information
- find a lead
- convert a lead
- create a booking
- approve a pending request if used
- create a work order
- create and send an invoice
- mark a cash or check payment
- find a customer and review history
- use `My Bookings`
- open the Help page and Page Guide widgets

Anything confusing here should be treated as a UX issue, not only a training issue.

## 7. Go-Live Checklist

Before switching to real usage:

- replace Stripe test keys with live keys
- confirm live webhook endpoint is configured in Stripe
- confirm email sender and domain are correct
- remove or archive mock/demo data
- remove any test-only copy shown to customers
- confirm backups exist
- confirm a real customer booking works
- confirm a real invoice payment works

## 8. Client Onboarding Plan

Recommended rollout:

### Day 1

- 30-minute admin overview
- explain Bookings, Work Orders, Invoices, Customers, Leads, and Inventory
- show Settings and the Help page
- explain the Page Guide widget

### Day 2

- office staff processes 3 to 5 fake jobs
- create booking
- create work order
- create invoice
- mark manual payment

### Day 3

- client runs real or closely supervised jobs
- collect friction points
- adjust wording, layout, defaults, or guide text

## 9. In-App Guides and Tours Strategy

For future polish, this is the recommended path:

- add first-visit tooltips for core admin pages
- store dismiss state per user
- add a dashboard onboarding checklist
- add contextual "What happens next?" help boxes on multi-step pages
- keep page guides short and tied to the exact workflow on that page

## 10. Recommended Next Improvements

These are the best next usability upgrades:

- tabbed `Settings` page grouped by Company, Billing, Stripe, Email, Notifications, and Users
- onboarding checklist widget on the dashboard
- deeper guided tours for first login
- explicit `booking_id` linkage on invoices instead of note-based invoice matching
- printable SOP or office staff quick-start guide
