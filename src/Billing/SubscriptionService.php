<?php

namespace TrashPanda\Billing;

use TrashPanda\Billing\Exception\BillingException;

class SubscriptionService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly StripeCustomerService $customerService,
        private readonly AuditLogService $auditLogService,
        private readonly InvoiceBillingService $invoiceBillingService,
        private readonly BillingNotificationService $notificationService,
    ) {
    }

    public function create(array $data): int
    {
        $customer = $this->customerService->ensureForCustomerId((int)$data['customer_id']);
        $priceId = $this->ensureRecurringPrice(
            (string)$data['service_name'],
            (int)round((float)$data['amount'] * 100),
            (string)$data['interval_unit'],
            (int)$data['interval_count']
        );

        $params = [
            'customer' => $customer['stripe_customer_id'],
            'items' => [['price' => $priceId]],
            'collection_method' => 'charge_automatically',
            'default_payment_method' => $data['stripe_payment_method_id'] ?: null,
            'expand' => ['latest_invoice'],
            'metadata' => [
                'customer_id' => (string)$data['customer_id'],
                'service_name' => (string)$data['service_name'],
            ],
        ];

        if (!empty($data['billing_anchor'])) {
            $params['billing_cycle_anchor'] = strtotime((string)$data['billing_anchor']);
            $params['proration_behavior'] = 'none';
        }

        $stripeSubscription = $this->factory->requireClient()->subscriptions->create(array_filter($params, static fn($value) => $value !== null && $value !== ''));

        $subscriptionId = (int)\db_insert('subscriptions', [
            'customer_id' => $data['customer_id'],
            'service_name' => $data['service_name'],
            'service_address' => $data['service_address'] ?: null,
            'amount' => $data['amount'],
            'interval_unit' => $data['interval_unit'],
            'interval_count' => $data['interval_count'],
            'billing_anchor' => $data['billing_anchor'] ?: null,
            'next_billing_date' => !empty($stripeSubscription->current_period_end) ? date('Y-m-d', (int)$stripeSubscription->current_period_end) : null,
            'stripe_subscription_id' => $stripeSubscription->id,
            'stripe_price_id' => $priceId,
            'stripe_payment_method_id' => $data['stripe_payment_method_id'] ?: null,
            'status' => $stripeSubscription->status,
            'stripe_status' => $stripeSubscription->status,
            'autopay_enabled' => !empty($data['autopay_enabled']) ? 1 : 0,
            'retry_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->backfillLatestInvoiceForSubscription([
            'id' => $subscriptionId,
            'customer_id' => (int)$data['customer_id'],
            'service_name' => (string)$data['service_name'],
            'stripe_subscription_id' => (string)$stripeSubscription->id,
        ], $stripeSubscription);

        $this->auditLogService->log('subscription_created', 'Created subscription #' . $subscriptionId, 'subscription', $subscriptionId);
        return $subscriptionId;
    }

    public function update(int $id, array $data): void
    {
        $subscription = $this->requireLocalSubscription($id);

        $localUpdates = [
            'service_name'    => trim((string)$data['service_name']),
            'service_address' => trim((string)($data['service_address'] ?? '')) ?: null,
            'autopay_enabled' => !empty($data['autopay_enabled']) ? 1 : 0,
            'updated_at'      => date('Y-m-d H:i:s'),
        ];

        $newAmount        = (float)$data['amount'];
        $newIntervalUnit  = trim((string)$data['interval_unit']);
        $newIntervalCount = max(1, (int)$data['interval_count']);

        $amountChanged   = abs($newAmount - (float)$subscription['amount']) > 0.001;
        $intervalChanged = $newIntervalUnit !== (string)$subscription['interval_unit']
                        || $newIntervalCount !== (int)$subscription['interval_count'];

        if ($amountChanged || $intervalChanged) {
            $newPriceId = $this->ensureRecurringPrice(
                trim((string)$data['service_name']),
                (int)round($newAmount * 100),
                $newIntervalUnit,
                $newIntervalCount
            );

            $stripeSub   = $this->factory->requireClient()->subscriptions->retrieve(
                $subscription['stripe_subscription_id'],
                ['expand' => ['items']]
            );
            $firstItemId = $stripeSub->items->data[0]->id ?? null;
            if ($firstItemId === null) {
                throw new BillingException('Could not retrieve subscription item from Stripe.');
            }

            $this->factory->requireClient()->subscriptions->update($subscription['stripe_subscription_id'], [
                'items'              => [['id' => $firstItemId, 'price' => $newPriceId]],
                'proration_behavior' => 'none',
            ]);

            $localUpdates['amount']         = $newAmount;
            $localUpdates['interval_unit']  = $newIntervalUnit;
            $localUpdates['interval_count'] = $newIntervalCount;
            $localUpdates['stripe_price_id'] = $newPriceId;
        }

        \db_update('subscriptions', $localUpdates, 'id', $id);
        $this->auditLogService->log('subscription_updated', 'Updated subscription #' . $id, 'subscription', $id);
    }

    public function syncFromStripeObject(object $subscription): ?array
    {
        $row = \db_fetch('SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1', [$subscription->id]);
        if (!$row) {
            return null;
        }

        \db_update('subscriptions', [
            'status' => $subscription->status,
            'stripe_status' => $subscription->status,
            'cancel_at' => !empty($subscription->cancel_at) ? date('Y-m-d H:i:s', (int)$subscription->cancel_at) : null,
            'canceled_at' => !empty($subscription->canceled_at) ? date('Y-m-d H:i:s', (int)$subscription->canceled_at) : null,
            'paused_at' => ($subscription->pause_collection ?? null) ? date('Y-m-d H:i:s') : null,
            'next_billing_date' => !empty($subscription->current_period_end) ? date('Y-m-d', (int)$subscription->current_period_end) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', (int)$row['id']);

        return \db_fetch('SELECT * FROM subscriptions WHERE id = ? LIMIT 1', [(int)$row['id']]) ?: null;
    }

    public function pause(int $id): void
    {
        $subscription = $this->requireLocalSubscription($id);
        $this->factory->requireClient()->subscriptions->update($subscription['stripe_subscription_id'], [
            'pause_collection' => ['behavior' => 'mark_uncollectible'],
        ]);
        \db_update('subscriptions', [
            'status' => 'paused',
            'paused_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function resume(int $id): void
    {
        $subscription = $this->requireLocalSubscription($id);
        $this->factory->requireClient()->subscriptions->update($subscription['stripe_subscription_id'], [
            'pause_collection' => '',
        ]);
        \db_update('subscriptions', [
            'status' => 'active',
            'paused_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function cancel(int $id): void
    {
        $subscription = $this->requireLocalSubscription($id);
        $this->factory->requireClient()->subscriptions->cancel($subscription['stripe_subscription_id'], []);
        \db_update('subscriptions', [
            'status' => 'canceled',
            'canceled_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function retryLatestInvoice(int $id): void
    {
        $subscription = $this->requireLocalSubscription($id);
        $invoiceId = \db_value(
            'SELECT stripe_invoice_id FROM recurring_invoice_logs WHERE subscription_id = ? AND stripe_invoice_id IS NOT NULL ORDER BY id DESC LIMIT 1',
            [$id]
        );
        if (!$invoiceId) {
            throw new BillingException('No retryable invoice was found for this subscription.');
        }
        $this->factory->requireClient()->invoices->pay((string)$invoiceId, []);
        \db_execute('UPDATE subscriptions SET retry_count = retry_count + 1, updated_at = NOW() WHERE id = ?', [$id]);
    }

    public function repairMissingHistory(?int $subscriptionId = null): void
    {
        $params = [];
        $where = '';
        if ($subscriptionId !== null && $subscriptionId > 0) {
            $where = 'WHERE s.id = ?';
            $params[] = $subscriptionId;
        } else {
            $where = 'WHERE NOT EXISTS (
                SELECT 1 FROM recurring_invoice_logs ril WHERE ril.subscription_id = s.id
            )';
        }

        $subscriptions = \db_fetchall(
            "SELECT s.*
             FROM subscriptions s
             {$where}
             ORDER BY s.id ASC",
            $params
        );

        if (!$subscriptions) {
            return;
        }

        $client = $this->factory->requireClient();
        foreach ($subscriptions as $subscription) {
            if (empty($subscription['stripe_subscription_id'])) {
                continue;
            }

            try {
                $stripeSubscription = $client->subscriptions->retrieve(
                    (string)$subscription['stripe_subscription_id'],
                    ['expand' => ['latest_invoice']]
                );
                $this->backfillLatestInvoiceForSubscription($subscription, $stripeSubscription);
            } catch (\Throwable $e) {
                \error_log('[SubscriptionService] repairMissingHistory failed for subscription '
                    . (int)$subscription['id'] . ': ' . $e->getMessage());
            }
        }
    }

    private function requireLocalSubscription(int $id): array
    {
        $subscription = \db_fetch('SELECT * FROM subscriptions WHERE id = ? LIMIT 1', [$id]);
        if (!$subscription) {
            throw new BillingException('Subscription not found.');
        }
        return $subscription;
    }

    private function ensureRecurringPrice(string $serviceName, int $amountCents, string $intervalUnit, int $intervalCount): string
    {
        $lookupKey = 'tp_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $serviceName)) . '_' . $intervalUnit . '_' . $intervalCount . '_' . $amountCents;
        $client = $this->factory->requireClient();
        $prices = $client->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1]);
        if (!empty($prices->data[0])) {
            return $prices->data[0]->id;
        }

        $product = $client->products->create([
            'name' => $serviceName,
            'metadata' => ['service_name' => $serviceName],
        ]);

        $price = $client->prices->create([
            'currency' => strtolower(\get_setting('currency', 'usd') ?: 'usd'),
            'unit_amount' => $amountCents,
            'product' => $product->id,
            'recurring' => [
                'interval' => $intervalUnit,
                'interval_count' => max(1, $intervalCount),
            ],
            'lookup_key' => $lookupKey,
        ]);

        return $price->id;
    }

    private function backfillLatestInvoiceForSubscription(array $subscription, ?object $stripeSubscription = null): void
    {
        $subscriptionId = (int)($subscription['id'] ?? 0);
        $customerId = (int)($subscription['customer_id'] ?? 0);
        if ($subscriptionId <= 0 || $customerId <= 0) {
            return;
        }

        $stripeSubscription ??= $this->factory->requireClient()->subscriptions->retrieve(
            (string)$subscription['stripe_subscription_id'],
            ['expand' => ['latest_invoice']]
        );

        $latestInvoice = $stripeSubscription->latest_invoice ?? null;
        if (!$latestInvoice) {
            return;
        }

        if (is_string($latestInvoice)) {
            $latestInvoice = $this->factory->requireClient()->invoices->retrieve($latestInvoice, []);
        }

        if (!is_object($latestInvoice) || empty($latestInvoice->id)) {
            return;
        }

        $localInvoiceId = $this->invoiceBillingService->createLocalInvoiceFromStripeInvoice(
            $latestInvoice,
            $customerId,
            $subscriptionId
        );

        $existingLog = \db_fetch(
            'SELECT id FROM recurring_invoice_logs WHERE subscription_id = ? AND stripe_invoice_id = ? LIMIT 1',
            [$subscriptionId, (string)$latestInvoice->id]
        );

        if ($existingLog) {
            return;
        }

        $status = $this->normalizeInvoiceStatus((string)($latestInvoice->status ?? 'open'));
        $amount = $status === 'paid'
            ? ((int)($latestInvoice->amount_paid ?? 0)) / 100
            : ((int)($latestInvoice->amount_due ?? 0)) / 100;

        \db_insert('recurring_invoice_logs', [
            'subscription_id' => $subscriptionId,
            'invoice_id' => $localInvoiceId ?: null,
            'stripe_invoice_id' => $latestInvoice->id,
            'billing_date' => date('Y-m-d H:i:s', (int)($latestInvoice->created ?? time())),
            'amount' => $amount,
            'status' => $status,
            'failure_message' => $latestInvoice->last_finalization_error->message ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        \db_update('subscriptions', [
            'status' => $status === 'paid' ? 'active' : ((string)($subscription['status'] ?? 'active')),
            'stripe_status' => (string)($stripeSubscription->status ?? ($subscription['stripe_status'] ?? 'active')),
            'next_billing_date' => !empty($stripeSubscription->current_period_end)
                ? date('Y-m-d', (int)$stripeSubscription->current_period_end)
                : ($subscription['next_billing_date'] ?? null),
            'last_paid_at' => $status === 'paid'
                ? date('Y-m-d H:i:s', (int)($latestInvoice->status_transitions->paid_at ?? $latestInvoice->created ?? time()))
                : ($subscription['last_paid_at'] ?? null),
            'retry_count' => $status === 'paid' ? 0 : (int)($subscription['retry_count'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $subscriptionId);

        if ($status !== 'paid') {
            return;
        }

        $customer = \db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
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
                        (float)($localInvoice['total'] ?? $amount),
                        $customer['name'] ?? ($localInvoice['cust_name'] ?? '')
                    );
                    $this->notificationService->notifySubscriptionPaymentReceived(
                        (string)($subscription['service_name'] ?? ('Subscription ' . $subscriptionId)),
                        (float)($localInvoice['total'] ?? $amount),
                        $customer['name'] ?? ($localInvoice['cust_name'] ?? '')
                    );
                    \log_activity('invoice_paid_emailed', 'Sent subscription receipt for ' . ($localInvoice['invoice_number'] ?? $localInvoiceId), 'invoice', $localInvoiceId);
                } catch (\Throwable $e) {
                    \error_log('[SubscriptionService] subscription receipt send failed for invoice '
                        . $localInvoiceId . ': ' . $e->getMessage());
                }
            } elseif (!$alreadyEmailed) {
                $this->notificationService->notifySubscriptionPaymentReceived(
                    (string)($subscription['service_name'] ?? ('Subscription ' . $subscriptionId)),
                    $amount,
                    $customer['name'] ?? ''
                );
            }
        }
    }

    private function normalizeInvoiceStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid' => 'paid',
            'uncollectible', 'failed' => 'failed',
            'void', 'voided' => 'void',
            'draft' => 'draft',
            default => 'open',
        };
    }
}
