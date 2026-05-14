<?php
/**
 * Payment Method Setup – Generate Stripe SetupSession
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/stripe.php';
require_login();
require_role('admin', 'office');
csrf_check();

$customerId = (int)($_POST['customer_id'] ?? 0);
$action     = trim($_POST['action'] ?? 'email'); // 'open' | 'email'
$emailTo    = trim($_POST['email_to'] ?? '');

if ($customerId <= 0) {
    flash_error('No customer selected.');
    redirect('setup.php');
}

$customer = db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
if (!$customer) {
    flash_error('Customer not found.');
    redirect('setup.php');
}

if (trim(get_setting('stripe_secret_key', '')) === '') {
    flash_error('Stripe is not configured. Add your Stripe keys in Settings.');
    redirect('setup.php?customer_id=' . $customerId);
}

try {
    $stripeCustomer = billing_customer_service()->ensureForCustomerId($customerId);
    $adminBase      = rtrim(APP_URL, '/');

    // For customer-facing success, use the public site root (strip /admin from APP_URL if present)
    $publicBase = rtrim(preg_replace('#/admin$#', '', $adminBase), '/');
    $successUrl = $action === 'open'
        ? $adminBase . '/modules/payment_methods/index.php?setup_success=1'
        : $publicBase . '/setup-payment-success.php';
    $cancelUrl = $adminBase . '/modules/payment_methods/setup.php?customer_id=' . $customerId;

    $session = billing_stripe_factory()->requireClient()->checkout->sessions->create([
        'mode'                   => 'setup',
        'customer'               => $stripeCustomer['stripe_customer_id'],
        'payment_method_types'   => ['card', 'us_bank_account'],
        'payment_method_options' => [
            'us_bank_account' => [
                'verification_method'    => 'automatic',
                'financial_connections'  => ['permissions' => ['payment_method']],
            ],
        ],
        'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $cancelUrl,
        'metadata'    => ['customer_id' => (string)$customerId],
    ]);

    if ($action === 'open') {
        // Open Stripe's hosted setup page in the admin's browser (phone-order flow)
        header('Location: ' . $session->url);
        exit;
    }

    // Email flow
    if ($emailTo === '' || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        flash_error('A valid email address is required to send the setup link.');
        redirect('setup.php?customer_id=' . $customerId);
    }

    $name        = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
    $companyName = get_setting('company_name', 'Trash Panda Roll-Offs');

    $body = '<p>Hello ' . $name . ',</p>
<p><strong>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong> has sent you a secure link to save a payment method to your account.</p>
<p>You can add a credit/debit card or connect a bank account for ACH payments. Your payment details are handled securely by Stripe — we never see your full card or account numbers.</p>
<p style="color:#6b7280;font-size:.88rem;">This link expires after 24 hours.</p>';

    $html = email_template('Save Your Payment Method', $body, 'Add Payment Method', $session->url);
    $sent = send_email(
        $emailTo,
        'Add Your Payment Method — ' . $companyName,
        $html
    );

    if ($sent) {
        log_activity('update', "Payment setup link emailed to {$emailTo} for customer {$customer['name']} (#{$customerId})", 'customer', $customerId);
        flash_success("Setup link sent to {$emailTo}. Once {$customer['name']} completes it, their payment method will appear here automatically.");
    } else {
        flash_error('Email failed — share this link with the customer manually: ' . $session->url);
    }

} catch (\Throwable $e) {
    flash_error('Stripe error: ' . $e->getMessage());
}

redirect('setup.php?customer_id=' . $customerId);
