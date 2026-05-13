<?php

namespace TrashPanda\Billing;

use TrashPanda\Billing\Exception\BillingException;

class StripeCustomerService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function ensureForCustomerId(int $customerId): array
    {
        $customer = \db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
        if (!$customer) {
            throw new BillingException('Customer not found.');
        }

        $stripeCustomerId = trim((string)($customer['stripe_customer_id'] ?? ''));
        if ($stripeCustomerId !== '') {
            return $customer;
        }

        $stripeCustomer = $this->factory->requireClient()->customers->create([
            'name' => $customer['name'],
            'email' => $customer['email'] ?: null,
            'phone' => $customer['phone'] ?: null,
            'address' => array_filter([
                'line1' => $customer['billing_address'] ?: $customer['address'] ?: null,
                'city' => $customer['billing_city'] ?: $customer['city'] ?: null,
                'state' => $customer['billing_state'] ?: $customer['state'] ?: null,
                'postal_code' => $customer['billing_zip'] ?: $customer['zip'] ?: null,
            ]),
            'metadata' => [
                'customer_id' => (string)$customerId,
            ],
        ]);

        \db_update('customers', [
            'stripe_customer_id' => $stripeCustomer->id,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $customerId);

        $this->auditLogService->log('billing_customer_sync', 'Created Stripe customer for local customer #' . $customerId, 'customer', $customerId);
        $customer['stripe_customer_id'] = $stripeCustomer->id;
        return $customer;
    }

    public function findOrCreateByBooking(array $booking): int
    {
        $email = trim((string)($booking['customer_email'] ?? ''));
        $phone = trim((string)($booking['customer_phone'] ?? ''));

        $customer = null;
        if ($email !== '') {
            $customer = \db_fetch('SELECT * FROM customers WHERE LOWER(email) = ? LIMIT 1', [strtolower($email)]);
        }
        if (!$customer && $phone !== '') {
            $customer = \db_fetch('SELECT * FROM customers WHERE phone = ? LIMIT 1', [$phone]);
        }

        if (!$customer) {
            $customerId = (int)\db_insert('customers', [
                'name' => $booking['customer_name'],
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'address' => $booking['customer_address'] ?? null,
                'city' => $booking['customer_city'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $customerId = (int)$customer['id'];
        }

        $this->ensureForCustomerId($customerId);
        return $customerId;
    }
}
