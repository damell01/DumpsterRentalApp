<?php
/**
 * Legacy Stripe helper wrappers.
 * The application now routes billing behavior through service classes, while
 * these functions preserve compatibility for older modules.
 */

if (!function_exists('billing_stripe_factory')) {
    require_once __DIR__ . '/billing.php';
}

use TrashPanda\Billing\Exception\BillingException;

function stripe_sdk_available(): bool
{
    return class_exists(\Stripe\StripeClient::class);
}

function stripe_client(): \Stripe\StripeClient
{
    return billing_stripe_factory()->requireClient();
}

function stripe_verify_webhook(string $payload, string $sig_header): \Stripe\Event
{
    /** @var \Stripe\Event $event */
    $event = billing_webhook_service()->constructEvent($payload, $sig_header);
    return $event;
}

function stripe_create_checkout(array $booking, string $success_url, string $cancel_url, string $payment_method = 'stripe'): \Stripe\Checkout\Session
{
    /** @var \Stripe\Checkout\Session $session */
    $session = billing_checkout_service()->createBookingCheckout([$booking], $success_url, $cancel_url, $payment_method);
    return $session;
}

function stripe_create_multi_checkout(array $bookings, string $success_url, string $cancel_url, string $payment_method = 'stripe'): \Stripe\Checkout\Session
{
    /** @var \Stripe\Checkout\Session $session */
    $session = billing_checkout_service()->createBookingCheckout($bookings, $success_url, $cancel_url, $payment_method);
    return $session;
}

function stripe_create_invoice_checkout(array $invoice, string $success_url, string $cancel_url, string $payment_method = 'stripe'): \Stripe\Checkout\Session
{
    /** @var \Stripe\Checkout\Session $session */
    $session = billing_checkout_service()->createInvoiceCheckout($invoice, $success_url, $cancel_url, $payment_method);
    return $session;
}

/**
 * Build a stable, non-expiring link for a customer to pay an invoice.
 * Visiting this URL creates a fresh Stripe Checkout session on demand
 * (see /pay-invoice.php), so the link itself never goes stale sitting
 * in an email — only the Stripe session created at click-time expires.
 */
function invoice_pay_url(int $invoiceId): string
{
    $token = hash_hmac('sha256', 'inv_' . $invoiceId, PORTAL_SIGNING_KEY);
    $publicBase = rtrim((string)preg_replace('#/admin/?$#', '', APP_URL), '/');
    return $publicBase . '/pay-invoice.php?id=' . $invoiceId . '&token=' . urlencode($token);
}

function stripe_issue_refund(string $payment_id, ?int $amount_cents = null, string $reason = 'requested_by_customer'): \Stripe\Refund
{
    $params = ['reason' => $reason];

    if (str_starts_with($payment_id, 'pi_')) {
        $params['payment_intent'] = $payment_id;
    } else {
        $params['charge'] = $payment_id;
    }

    if ($amount_cents !== null && $amount_cents > 0) {
        $params['amount'] = $amount_cents;
    }

    return stripe_client()->refunds->create($params);
}

function stripe_get_balance(): \Stripe\Balance
{
    return stripe_client()->balance->retrieve();
}

function stripe_list_payouts(int $limit = 20): \Stripe\Collection
{
    return stripe_client()->payouts->all(['limit' => $limit]);
}

function stripe_list_charges(int $limit = 50, ?int $created_gte = null, ?int $created_lte = null): \Stripe\Collection
{
    $params = ['limit' => $limit];
    if ($created_gte !== null || $created_lte !== null) {
        $params['created'] = [];
        if ($created_gte !== null) {
            $params['created']['gte'] = $created_gte;
        }
        if ($created_lte !== null) {
            $params['created']['lte'] = $created_lte;
        }
    }
    return stripe_client()->charges->all($params);
}

function stripe_sync_dumpster_product(array $dumpster): array
{
    return billing_catalog_sync_service()->syncDumpster($dumpster);
}

function stripe_create_setup_intent(string $stripe_customer_id, array $payment_method_types = ['card', 'us_bank_account']): array
{
    return billing_payment_method_service()->createSetupIntent($stripe_customer_id, $payment_method_types);
}

function stripe_create_subscription(array $data): int
{
    return billing_subscription_service()->create($data);
}

function stripe_pause_subscription(int $subscription_id): void
{
    billing_subscription_service()->pause($subscription_id);
}

function stripe_resume_subscription(int $subscription_id): void
{
    billing_subscription_service()->resume($subscription_id);
}

function stripe_cancel_subscription(int $subscription_id): void
{
    billing_subscription_service()->cancel($subscription_id);
}

function stripe_update_subscription(int $id, array $data): void
{
    billing_subscription_service()->update($id, $data);
}

function stripe_retry_subscription_invoice(int $subscription_id): void
{
    billing_subscription_service()->retryLatestInvoice($subscription_id);
}
