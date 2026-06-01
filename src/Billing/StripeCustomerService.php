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

    /**
     * Strip non-digits and return the last 10 digits (US numbers).
     * "(251) 333-4444" → "2513334444"; "+12513334444" → "2513334444"
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /**
     * Pull the best available street/city/state/zip values from booking data.
     * Supports either explicit structured fields or a full address string.
     *
     * @return array{address:?string,city:?string,state:?string,zip:?string}
     */
    private function extractAddressParts(array $booking): array
    {
        $address = trim((string)($booking['customer_address'] ?? ''));
        $city = trim((string)($booking['customer_city'] ?? ''));
        $state = strtoupper(trim((string)($booking['customer_state'] ?? '')));
        $zip = trim((string)($booking['customer_zip'] ?? ''));

        if (($city === '' || $state === '' || $zip === '') && $address !== '') {
            if (preg_match('/^\s*(.+?),\s*([^,]+),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?\s*$/i', $address, $m)) {
                $address = trim((string)$m[1]);
                if ($city === '') {
                    $city = trim((string)$m[2]);
                }
                if ($state === '') {
                    $state = strtoupper(trim((string)$m[3]));
                }
                if ($zip === '' && !empty($m[4])) {
                    $zip = trim((string)$m[4]);
                }
            }
        }

        return [
            'address' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'zip' => $zip !== '' ? $zip : null,
        ];
    }

    public function findOrCreateByBooking(array $booking): int
    {
        $email     = trim((string)($booking['customer_email']   ?? ''));
        $phone     = trim((string)($booking['customer_phone']   ?? ''));
        $phoneNorm = $this->normalizePhone($phone);
        $addressParts = $this->extractAddressParts($booking);

        $customer = null;

        // 1. Match by email (case-insensitive)
        if ($email !== '') {
            $customer = \db_fetch(
                'SELECT * FROM customers WHERE LOWER(email) = ? LIMIT 1',
                [strtolower($email)]
            );
        }

        // 2. Match by normalized phone — strip formatting on both sides so
        //    "(251) 333-4444" matches "2513334444" or "+12513334444".
        if (!$customer && $phoneNorm !== '') {
            $customer = \db_fetch(
                "SELECT * FROM customers
                 WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') = ?
                    OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') = ?
                 LIMIT 1",
                [$phoneNorm, '1' . $phoneNorm]
            );
        }

        if (!$customer) {
            $customerId = (int)\db_insert('customers', [
                'name'       => $booking['customer_name'],
                'email'      => $email ?: null,
                'phone'      => $phone ?: null,
                'address'    => $addressParts['address'],
                'city'       => $addressParts['city'],
                'state'      => $addressParts['state'],
                'zip'        => $addressParts['zip'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $customerId = (int)$customer['id'];

            // Fill in any blank fields on the existing customer record
            // so data gets richer over time without overwriting known values.
            $updates = [];
            if (empty($customer['phone']) && $phone !== '') {
                $updates['phone'] = $phone;
            }
            if (empty($customer['email']) && $email !== '') {
                $updates['email'] = $email;
            }
            if (empty($customer['address']) && !empty($addressParts['address'])) {
                $updates['address'] = $addressParts['address'];
            }
            if (empty($customer['city']) && !empty($addressParts['city'])) {
                $updates['city'] = $addressParts['city'];
            }
            if (empty($customer['state']) && !empty($addressParts['state'])) {
                $updates['state'] = $addressParts['state'];
            }
            if (empty($customer['zip']) && !empty($addressParts['zip'])) {
                $updates['zip'] = $addressParts['zip'];
            }
            if (!empty($updates)) {
                $updates['updated_at'] = date('Y-m-d H:i:s');
                \db_update('customers', $updates, 'id', $customerId);
            }
        }

        // Best-effort Stripe customer sync — skip silently if Stripe is not configured.
        try {
            $this->ensureForCustomerId($customerId);
        } catch (\Throwable $e) {
            \error_log('[StripeCustomerService] Stripe sync skipped for customer #' . $customerId . ': ' . $e->getMessage());
        }

        return $customerId;
    }
}
