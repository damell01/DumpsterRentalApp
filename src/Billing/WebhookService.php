<?php

namespace TrashPanda\Billing;

use Stripe\Webhook;
use TrashPanda\Billing\Exception\BillingException;

class WebhookService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly WebhookLogService $webhookLogService,
        private readonly PaymentMethodService $paymentMethodService,
        private readonly SubscriptionService $subscriptionService,
        private readonly InvoiceBillingService $invoiceBillingService,
        private readonly BillingNotificationService $notificationService,
    ) {
    }

    public function constructEvent(string $payload, string $signature): object
    {
        $secret = $this->factory->getWebhookSecret();
        if ($secret === '') {
            throw new BillingException('Stripe webhook secret is not configured.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    public function process(object $event, string $payload): array
    {
        $existing = $this->webhookLogService->findByEventId((string)$event->id);
        if ($existing && in_array($existing['processing_status'], ['processed', 'duplicate'], true)) {
            $this->webhookLogService->markDuplicate((int)$existing['id']);
            return ['duplicate' => true];
        }

        $logId = $existing
            ? (int)$existing['id']
            : $this->webhookLogService->createSkeleton(
                (string)$event->id,
                (string)$event->type,
                (bool)($event->livemode ?? false),
                (string)($event->api_version ?? ''),
                $payload
            );

        try {
            $context = \db_transaction(function () use ($event) {
                return $this->dispatch($event);
            });
            $this->webhookLogService->markProcessed($logId, $context);
            return ['duplicate' => false] + $context;
        } catch (\Throwable $e) {
            $this->webhookLogService->markFailed($logId, $e->getMessage());
            $this->sendFailureAlert($event, $e);
            throw $e;
        }
    }

    private function dispatch(object $event): array
    {
        return match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'checkout.session.async_payment_succeeded' => $this->handleCheckoutAsyncSucceeded($event->data->object),
            'checkout.session.async_payment_failed' => $this->handleCheckoutAsyncFailed($event->data->object),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
            'invoice.paid' => $this->handleInvoicePaid($event->data->object),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'
                => $this->handleSubscriptionUpdated($event->data->object),
            'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            default => ['entity_type' => 'webhook', 'entity_id' => 0],
        };
    }

    private function handleCheckoutCompleted(object $session): array
    {
        if (($session->mode ?? 'payment') === 'setup') {
            return $this->handleSetupCompleted($session);
        }

        if (!empty($session->metadata->booking_ids)) {
            $bookingIds = array_filter(array_map('intval', explode(',', (string)$session->metadata->booking_ids)));
            foreach ($bookingIds as $bookingId) {
                $booking = \db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$bookingId]);
                if (!$booking) {
                    continue;
                }
                $isAsync = ($session->payment_status ?? 'paid') === 'unpaid';
                $paymentMethod = $this->resolveSessionPaymentMethod($session);
                $paymentStatus = $isAsync ? 'processing' : 'paid';
                \db_update('bookings', [
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'booking_status' => $paymentMethod === 'ach' ? 'confirmed' : 'confirmed',
                    'stripe_session_id' => $session->id,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id', $bookingId);
                $this->recordPayment([
                    'booking_id' => $bookingId,
                    'customer_id' => $booking['customer_id'] ?? null,
                    'amount' => $booking['total_amount'],
                    'currency' => strtolower(\get_setting('currency', 'usd') ?: 'usd'),
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
                    'stripe_customer_id' => is_string($session->customer ?? null) ? $session->customer : null,
                ]);
                if ($paymentMethod === 'ach') {
                    try {
                        $this->notificationService->sendAchInitiated(
                            ['email' => $booking['customer_email'] ?? ''],
                            $booking['booking_number'] ?? ('Booking ' . $bookingId),
                            (float)$booking['total_amount']
                        );
                    } catch (\Throwable $e) {
                        \error_log('[Webhook] sendAchInitiated failed for booking ' . $bookingId . ': ' . $e->getMessage());
                    }
                } else {
                    // Card payment settled — send customer confirmation + admin email
                    $alreadyEmailed = (bool)\db_value(
                        "SELECT COUNT(*) FROM activity_log WHERE action = 'booking_paid_emailed' AND entity_id = ?",
                        [$bookingId]
                    );
                    if (!$alreadyEmailed) {
                        try {
                            \notify_payment_received($booking, (float)$booking['total_amount'], $paymentMethod);
                            \log_activity('booking_paid_emailed', 'Sent payment received email for ' . ($booking['booking_number'] ?? $bookingId), 'booking', $bookingId);
                        } catch (\Throwable $e) {
                            \error_log('[Webhook] notify_payment_received failed for booking ' . $bookingId . ': ' . $e->getMessage());
                        }
                    }
                }
            }

            return ['entity_type' => 'booking', 'entity_id' => $bookingIds[0] ?? 0];
        }

        if (!empty($session->metadata->invoice_id)) {
            $invoiceId = (int)$session->metadata->invoice_id;
            $invoice = \db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]);
            if ($invoice) {
                $isAsync = ($session->payment_status ?? 'paid') === 'unpaid';
                $paymentMethod = $this->resolveSessionPaymentMethod($session);
                $paymentStatus = $isAsync ? 'processing' : 'paid';
                $invoiceStatus = $paymentMethod === 'ach' ? 'open' : 'paid';
                \db_update('invoices', [
                    'payment_method' => $paymentMethod,
                    'status' => $invoiceStatus,
                    'stripe_session_id' => $session->id,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'paid_at' => $paymentMethod === 'ach' ? null : date('Y-m-d H:i:s'),
                ], 'id', $invoiceId);
                $this->recordPayment([
                    'invoice_id' => $invoiceId,
                    'customer_id' => $invoice['customer_id'] ?? null,
                    'amount' => $invoice['total'],
                    'currency' => strtolower(\get_setting('currency', 'usd') ?: 'usd'),
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'stripe_session_id' => $session->id,
                    'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
                    'stripe_customer_id' => is_string($session->customer ?? null) ? $session->customer : null,
                ]);
                // Sync payment_status on any booking linked via invoice notes
                if ($invoiceStatus === 'paid') {
                    $notes = (string)($invoice['notes'] ?? '');
                    if (preg_match('/\bBooking\s+(\S+)/i', $notes, $m)) {
                        \db_execute(
                            "UPDATE bookings SET payment_status = 'paid', updated_at = NOW()
                             WHERE booking_number = ? AND payment_status != 'paid'",
                            [$m[1]]
                        );
                    }

                    // Email customer + admin — skip if success page already sent it.
                    // Wrapped in try/catch so a mail failure does NOT roll back the DB transaction.
                    $alreadyEmailed = (bool)\db_value(
                        "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice_paid_emailed' AND entity_id = ?",
                        [$invoiceId]
                    );
                    if (!$alreadyEmailed) {
                        $custEmail = trim((string)(\db_value('SELECT email FROM customers WHERE id = ?', [(int)($invoice['customer_id'] ?? 0)]) ?? ''));
                        try {
                            $this->notificationService->sendInvoicePaid(
                                ['email' => $custEmail],
                                $invoice['invoice_number'] ?? ('INV-' . $invoiceId),
                                (float)$invoice['total'],
                                $invoice['cust_name'] ?? '',
                                $invoiceId
                            );
                            \log_activity('invoice_paid_emailed', 'Sent invoice paid notification for ' . ($invoice['invoice_number'] ?? $invoiceId), 'invoice', $invoiceId);
                        } catch (\Throwable $e) {
                            \error_log('[Webhook] sendInvoicePaid failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
                        }
                    }
                }
            }
            return ['entity_type' => 'invoice', 'entity_id' => $invoiceId];
        }

        return ['entity_type' => 'webhook', 'entity_id' => 0];
    }

    private function handleCheckoutAsyncSucceeded(object $session): array
    {
        return $this->markSessionAsSettled($session, 'paid');
    }

    private function handleCheckoutAsyncFailed(object $session): array
    {
        return $this->markSessionAsSettled($session, 'failed');
    }

    private function handlePaymentIntentSucceeded(object $intent): array
    {
        if (!empty($intent->customer) && !empty($intent->payment_method)) {
            $customer = \db_fetch('SELECT id FROM customers WHERE stripe_customer_id = ? LIMIT 1', [(string)$intent->customer]);
            if ($customer) {
                $paymentMethod = $this->factory->requireClient()->paymentMethods->retrieve((string)$intent->payment_method, []);
                $this->paymentMethodService->syncFromStripeObject((int)$customer['id'], $paymentMethod);
            }
        }
        return ['entity_type' => 'payment', 'entity_id' => 0];
    }

    private function handlePaymentIntentFailed(object $intent): array
    {
        $payment = \db_fetch(
            'SELECT * FROM payments WHERE stripe_payment_intent_id = ? ORDER BY id DESC LIMIT 1',
            [(string)$intent->id]
        );
        if ($payment) {
            \db_update('payments', [
                'payment_status' => 'failed',
                'failure_code' => $intent->last_payment_error->code ?? null,
                'failure_message' => $intent->last_payment_error->message ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', (int)$payment['id']);
        }
        return ['entity_type' => 'payment', 'entity_id' => (int)($payment['id'] ?? 0)];
    }

    private function handleInvoicePaid(object $invoice): array
    {
        $subscription = !empty($invoice->subscription)
            ? \db_fetch('SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1', [(string)$invoice->subscription])
            : null;
        $customer = !empty($invoice->customer)
            ? \db_fetch('SELECT * FROM customers WHERE stripe_customer_id = ? LIMIT 1', [(string)$invoice->customer])
            : null;

        $subscriptionId = (int)($subscription['id'] ?? 0) ?: null;
        $customerId = (int)($customer['id'] ?? 0);
        $localInvoiceId = $customerId > 0
            ? $this->invoiceBillingService->createLocalInvoiceFromStripeInvoice($invoice, $customerId, $subscriptionId)
            : 0;

        if ($subscriptionId) {
            \db_update('subscriptions', [
                'status' => 'active',
                'stripe_status' => 'active',
                'next_billing_date' => !empty($invoice->period_end) ? date('Y-m-d', (int)$invoice->period_end) : null,
                'last_paid_at' => date('Y-m-d H:i:s'),
                'retry_count' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', $subscriptionId);

            \db_insert('recurring_invoice_logs', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $localInvoiceId ?: null,
                'stripe_invoice_id' => $invoice->id,
                'billing_date' => date('Y-m-d H:i:s'),
                'amount' => ((int)$invoice->amount_paid) / 100,
                'status' => 'paid',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($localInvoiceId > 0) {
                $alreadyEmailed = (bool)\db_value(
                    "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice_paid_emailed' AND entity_id = ?",
                    [$localInvoiceId]
                );
                $localInvoice = \db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$localInvoiceId]);
                if (!$alreadyEmailed && $localInvoice) {
                    try {
                        $this->notificationService->sendInvoicePaidReceipt(
                            ['email' => $customer['email'] ?? ($localInvoice['cust_email'] ?? '')],
                            $localInvoice['invoice_number'] ?? ('INV-' . $localInvoiceId),
                            (float)($localInvoice['total'] ?? (((int)$invoice->amount_paid) / 100)),
                            $customer['name'] ?? ($localInvoice['cust_name'] ?? ''),
                            $localInvoiceId
                        );
                        $this->notificationService->notifySubscriptionPaymentReceived(
                            $subscription['service_name'] ?? ('Subscription ' . $subscriptionId),
                            (float)($localInvoice['total'] ?? (((int)$invoice->amount_paid) / 100)),
                            $customer['name'] ?? ($localInvoice['cust_name'] ?? '')
                        );
                        \log_activity('invoice_paid_emailed', 'Sent subscription receipt for ' . ($localInvoice['invoice_number'] ?? $localInvoiceId), 'invoice', $localInvoiceId);
                    } catch (\Throwable $e) {
                        \error_log('[Webhook] subscription receipt send failed for invoice ' . $localInvoiceId . ': ' . $e->getMessage());
                    }
                } elseif (!$alreadyEmailed) {
                    $this->notificationService->notifySubscriptionPaymentReceived(
                        $subscription['service_name'] ?? ('Subscription ' . $subscriptionId),
                        ((int)$invoice->amount_paid) / 100,
                        $customer['name'] ?? ''
                    );
                }
            }
        }

        return ['entity_type' => 'invoice', 'entity_id' => $localInvoiceId];
    }

    private function handleInvoicePaymentFailed(object $invoice): array
    {
        $subscription = !empty($invoice->subscription)
            ? \db_fetch('SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1', [(string)$invoice->subscription])
            : null;
        if ($subscription) {
            \db_execute('UPDATE subscriptions SET status = ?, retry_count = retry_count + 1, updated_at = NOW() WHERE id = ?', ['past_due', (int)$subscription['id']]);
            \db_insert('recurring_invoice_logs', [
                'subscription_id' => (int)$subscription['id'],
                'invoice_id' => null,
                'stripe_invoice_id' => $invoice->id,
                'billing_date' => date('Y-m-d H:i:s'),
                'amount' => ((int)$invoice->amount_due) / 100,
                'status' => 'failed',
                'failure_message' => $invoice->last_finalization_error?->message ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $customer = \db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [(int)$subscription['customer_id']]);
            if ($customer) {
                $this->notificationService->sendSubscriptionPaymentFailed(
                    ['email' => $customer['email'] ?? ''],
                    $subscription['service_name'] ?? ('Subscription ' . $subscription['id'])
                );
            }
            return ['entity_type' => 'subscription', 'entity_id' => (int)$subscription['id']];
        }
        return ['entity_type' => 'invoice', 'entity_id' => 0];
    }

    private function handleSubscriptionUpdated(object $subscription): array
    {
        $local = $this->subscriptionService->syncFromStripeObject($subscription);
        return ['entity_type' => 'subscription', 'entity_id' => (int)($local['id'] ?? 0)];
    }

    private function handleChargeRefunded(object $charge): array
    {
        $payment = \db_fetch('SELECT * FROM payments WHERE stripe_charge_id = ? ORDER BY id DESC LIMIT 1', [(string)$charge->id]);
        if ($payment) {
            // A charge can be refunded partially — amount_refunded is the
            // cumulative amount refunded so far, not necessarily the full
            // charge. Only mark the booking/invoice as fully refunded/void
            // when the entire charge has actually come back.
            $isFullRefund = ((int)$charge->amount_refunded) >= ((int)($charge->amount ?? $charge->amount_refunded));

            \db_update('payments', [
                'payment_status' => $isFullRefund ? 'refunded' : $payment['payment_status'],
                'refunded_amount' => ((int)$charge->amount_refunded) / 100,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', (int)$payment['id']);

            if ($isFullRefund) {
                if (!empty($payment['booking_id'])) {
                    \db_update('bookings', ['payment_status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')], 'id', (int)$payment['booking_id']);
                }
                if (!empty($payment['invoice_id'])) {
                    \db_update('invoices', ['status' => 'void', 'updated_at' => date('Y-m-d H:i:s')], 'id', (int)$payment['invoice_id']);
                }
            }
            try {
                \notify_payment_refunded($payment, ((int)$charge->amount_refunded) / 100);
            } catch (\Throwable $e) {
                \error_log('[Webhook] notify_payment_refunded failed: ' . $e->getMessage());
            }
        }

        return ['entity_type' => 'payment', 'entity_id' => (int)($payment['id'] ?? 0)];
    }

    private function markSessionAsSettled(object $session, string $status): array
    {
        $payment = \db_fetch('SELECT * FROM payments WHERE stripe_session_id = ? ORDER BY id DESC LIMIT 1', [(string)$session->id]);
        if ($payment) {
            \db_update('payments', [
                'payment_status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', (int)$payment['id']);

            if (!empty($payment['booking_id'])) {
                \db_update('bookings', [
                    'payment_status' => $status,
                    'booking_status' => $status === 'paid' ? 'confirmed' : 'pending',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id', (int)$payment['booking_id']);
                $booking = \db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [(int)$payment['booking_id']]);
                if ($booking) {
                    if ($status === 'paid') {
                        $this->notificationService->sendAchSucceeded(['email' => $booking['customer_email'] ?? ''], $booking['booking_number'] ?? ('Booking ' . $payment['booking_id']), (float)$booking['total_amount']);
                    } else {
                        $this->notificationService->sendAchFailed(['email' => $booking['customer_email'] ?? ''], $booking['booking_number'] ?? ('Booking ' . $payment['booking_id']));
                    }
                }
                return ['entity_type' => 'booking', 'entity_id' => (int)$payment['booking_id']];
            }

            if (!empty($payment['invoice_id'])) {
                $invoiceId = (int)$payment['invoice_id'];
                \db_update('invoices', [
                    'status'     => $status === 'paid' ? 'paid' : 'open',
                    'paid_at'    => $status === 'paid' ? date('Y-m-d H:i:s') : null,
                    'failed_at'  => $status === 'failed' ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id', $invoiceId);

                if ($status === 'paid') {
                    $invoice = \db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$invoiceId]);
                    if ($invoice) {
                        $alreadyEmailed = (bool)\db_value(
                            "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice_paid_emailed' AND entity_id = ?",
                            [$invoiceId]
                        );
                        if (!$alreadyEmailed) {
                            $custEmail = trim((string)(\db_value('SELECT email FROM customers WHERE id = ?', [(int)($invoice['customer_id'] ?? 0)]) ?? ''));
                            try {
                                $this->notificationService->sendInvoicePaid(
                                    ['email' => $custEmail],
                                    $invoice['invoice_number'] ?? ('INV-' . $invoiceId),
                                    (float)$invoice['total'],
                                    $invoice['cust_name'] ?? '',
                                    $invoiceId
                                );
                                \log_activity('invoice_paid_emailed', 'Sent invoice paid notification for ' . ($invoice['invoice_number'] ?? $invoiceId), 'invoice', $invoiceId);
                            } catch (\Throwable $e) {
                                \error_log('[Webhook] sendInvoicePaid failed (ACH settled) for invoice ' . $invoiceId . ': ' . $e->getMessage());
                            }
                        }
                    }
                }

                return ['entity_type' => 'invoice', 'entity_id' => $invoiceId];
            }
        }

        return ['entity_type' => 'payment', 'entity_id' => (int)($payment['id'] ?? 0)];
    }

    private function handleSetupCompleted(object $session): array
    {
        $customerId = (int)($session->metadata->customer_id ?? 0);
        if ($customerId <= 0) {
            return ['entity_type' => 'webhook', 'entity_id' => 0];
        }

        $setupIntentId = is_string($session->setup_intent ?? null) ? $session->setup_intent : null;
        if (!$setupIntentId) {
            return ['entity_type' => 'customer', 'entity_id' => $customerId];
        }

        $setupIntent   = $this->factory->requireClient()->setupIntents->retrieve($setupIntentId, []);
        $paymentMethod = $setupIntent->payment_method ?? null;

        if (!$paymentMethod) {
            return ['entity_type' => 'customer', 'entity_id' => $customerId];
        }

        if (is_string($paymentMethod)) {
            $paymentMethod = $this->factory->requireClient()->paymentMethods->retrieve($paymentMethod, []);
        }

        $hasDefault   = (bool)\db_value(
            'SELECT COUNT(*) FROM payment_methods WHERE customer_id = ? AND is_default = 1 AND is_active = 1',
            [$customerId]
        );
        $makeDefault  = !$hasDefault;

        $this->paymentMethodService->syncFromStripeObject($customerId, $paymentMethod, $makeDefault);

        if ($makeDefault) {
            $stripeCustomer = \db_fetch('SELECT stripe_customer_id FROM customers WHERE id = ? LIMIT 1', [$customerId]);
            if ($stripeCustomer) {
                $this->factory->requireClient()->customers->update($stripeCustomer['stripe_customer_id'], [
                    'invoice_settings' => ['default_payment_method' => $paymentMethod->id],
                ]);
            }
        }

        return ['entity_type' => 'customer', 'entity_id' => $customerId];
    }

    private function resolveSessionPaymentMethod(object $session): string
    {
        // With automatic_payment_methods, payment_method_types is empty.
        // Use payment_status to detect async (ACH) vs instant (card/wallet).
        if (($session->payment_status ?? 'paid') === 'unpaid') {
            return 'ach';
        }
        $types = $session->payment_method_types ?? [];
        $first = is_array($types) && count($types) > 0 ? $types[0] : 'card';
        return $first === 'us_bank_account' ? 'ach' : 'stripe';
    }

    private function recordPayment(array $data): void
    {
        $existing = null;
        if (!empty($data['stripe_payment_intent_id'])) {
            $existing = \db_fetch('SELECT id FROM payments WHERE stripe_payment_intent_id = ? LIMIT 1', [$data['stripe_payment_intent_id']]);
        }

        if ($existing) {
            \db_update('payments', [
                'payment_method' => $data['payment_method'],
                'payment_status' => $data['payment_status'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', (int)$existing['id']);
            return;
        }

        \db_insert('payments', [
            'booking_id' => $data['booking_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'payment_method' => $data['payment_method'],
            'payment_status' => $data['payment_status'],
            'stripe_session_id' => $data['stripe_session_id'] ?? null,
            'stripe_payment_intent_id' => $data['stripe_payment_intent_id'] ?? null,
            'stripe_customer_id' => $data['stripe_customer_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function sendFailureAlert(object $event, \Throwable $e): void
    {
        try {
            $adminEmail = \get_setting('admin_email', '');
            if (!$adminEmail) {
                return;
            }
            $appName = \get_setting('company_name', 'Trash Panda Roll-Offs');
            $appUrl  = \get_setting('app_url', '');
            $subject = '[' . $appName . '] Webhook failed: ' . $event->type;
            $body    = "A Stripe webhook event failed to process and requires attention.\r\n\r\n"
                     . "Event Type : " . $event->type . "\r\n"
                     . "Event ID   : " . $event->id . "\r\n"
                     . "Error      : " . $e->getMessage() . "\r\n"
                     . "File       : " . $e->getFile() . ':' . $e->getLine() . "\r\n"
                     . "Time       : " . date('Y-m-d H:i:s') . "\r\n\r\n"
                     . ($appUrl ? "Review webhook logs: " . $appUrl . "/modules/webhooks/\r\n" : '');
            $headers = 'From: ' . $adminEmail . "\r\nContent-Type: text/plain; charset=UTF-8";
            \mail($adminEmail, $subject, $body, $headers);
        } catch (\Throwable $ignored) {
            \error_log('[Webhook] sendFailureAlert failed: ' . $ignored->getMessage());
        }
    }
}
