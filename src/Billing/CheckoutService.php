<?php

namespace TrashPanda\Billing;

use TrashPanda\Billing\Exception\BillingException;

class CheckoutService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly StripeCustomerService $customerService,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $bookings
     */
    public function createBookingCheckout(array $bookings, string $successUrl, string $cancelUrl, string $paymentMethod): object
    {
        if ($bookings === []) {
            throw new BillingException('No bookings provided for checkout.');
        }

        $customerId = $this->customerService->findOrCreateByBooking($bookings[0]);
        \db_execute('UPDATE bookings SET customer_id = ? WHERE id IN (' . implode(',', array_map('intval', array_column($bookings, 'id'))) . ')', [$customerId]);
        $customer = $this->customerService->ensureForCustomerId($customerId);

        $currency = strtolower(\get_setting('currency', 'usd') ?: 'usd');
        $company = \get_setting('company_name', 'Trash Panda Roll-Offs');
        $lineItems = [];
        $bookingIds = [];
        $bookingNumbers = [];

        foreach ($bookings as $booking) {
            $amountCents = (int)round((float)$booking['total_amount'] * 100);
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => $company . ' - ' . (($booking['unit_code'] ?? '') ?: 'Dumpster Rental'),
                        'description' => \sprintf(
                            '%s %s from %s to %s',
                            $booking['unit_size'] ?? '',
                            ucfirst($booking['unit_type'] ?? 'dumpster'),
                            \fmt_date($booking['rental_start']),
                            \fmt_date($booking['rental_end'])
                        ),
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ];
            $bookingIds[] = (string)$booking['id'];
            $bookingNumbers[] = (string)($booking['booking_number'] ?? '');
        }

        $unitCodes   = array_filter(array_column($bookings, 'unit_code'));
        $unitSizes   = array_filter(array_column($bookings, 'unit_size'));
        $startDates  = array_filter(array_column($bookings, 'rental_start'));
        $endDates    = array_filter(array_column($bookings, 'rental_end'));
        $rentalStart = !empty($startDates) ? min($startDates) : '';
        $rentalEnd   = !empty($endDates)   ? max($endDates)   : '';

        $rentalDays = '';
        if ($rentalStart && $rentalEnd) {
            $diff = (int)round((strtotime($rentalEnd) - strtotime($rentalStart)) / 86400);
            $rentalDays = (string)$diff;
        }

        $piDescription = count($bookings) === 1
            ? \sprintf('Rental: %s (%s) %s–%s', $bookings[0]['unit_code'] ?? '', $bookings[0]['unit_size'] ?? '', $rentalStart, $rentalEnd)
            : \sprintf('%d-unit rental %s–%s', count($bookings), $rentalStart, $rentalEnd);

        $sessionParams = [
            'mode' => 'payment',
            'customer' => $customer['stripe_customer_id'],
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'booking_ids'     => implode(',', $bookingIds),
                'booking_numbers' => implode(',', $bookingNumbers),
                'customer_id'     => (string)$customerId,
                'unit_codes'      => implode(',', $unitCodes),
                'rental_start'    => $rentalStart,
                'rental_end'      => $rentalEnd,
            ],
            'payment_intent_data' => [
                'description' => $piDescription,
                'metadata' => [
                    'booking_ids'     => implode(',', $bookingIds),
                    'booking_numbers' => implode(',', $bookingNumbers),
                    'customer_id'     => (string)$customerId,
                    'unit_codes'      => implode(',', $unitCodes),
                    'unit_sizes'      => implode(',', $unitSizes),
                    'rental_start'    => $rentalStart,
                    'rental_end'      => $rentalEnd,
                    'rental_days'     => $rentalDays,
                ],
            ],
        ];

        if ($paymentMethod === 'card') {
            $sessionParams['payment_method_types'] = ['card'];
        } elseif ($paymentMethod === 'ach') {
            $sessionParams['payment_method_types'] = ['us_bank_account'];
            $sessionParams['payment_method_options'] = $this->buildAchOptions();
        } else {
            // Let Stripe show all enabled methods (card, ACH, Apple Pay, etc.)
            $sessionParams['automatic_payment_methods'] = ['enabled' => true];
        }

        return $this->factory->requireClient()->checkout->sessions->create($sessionParams);
    }

    public function createInvoiceCheckout(array $invoice, string $successUrl, string $cancelUrl, string $paymentMethod): object
    {
        $customerId = (int)($invoice['customer_id'] ?? 0);
        if ($customerId <= 0) {
            $customerId = (int)\db_insert('customers', [
                'name' => $invoice['cust_name'],
                'email' => $invoice['cust_email'] ?: null,
                'phone' => $invoice['cust_phone'] ?: null,
                'address' => $invoice['cust_address'] ?: null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            \db_update('invoices', ['customer_id' => $customerId, 'updated_at' => date('Y-m-d H:i:s')], 'id', (int)$invoice['id']);
        }

        $customer = $this->customerService->ensureForCustomerId($customerId);
        $currency = strtolower(\get_setting('currency', 'usd') ?: 'usd');
        $company = \get_setting('company_name', 'Trash Panda Roll-Offs');
        $amountCents = (int)round((float)$invoice['total'] * 100);

        $sessionParams = [
            'mode' => 'payment',
            'customer' => $customer['stripe_customer_id'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => $company . ' - Invoice ' . ($invoice['invoice_number'] ?? ''),
                        'description' => 'Invoice payment for ' . ($invoice['invoice_number'] ?? ''),
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'invoice_id' => (string)$invoice['id'],
                'invoice_number' => (string)$invoice['invoice_number'],
                'customer_id' => (string)$customerId,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'invoice_id' => (string)$invoice['id'],
                    'customer_id' => (string)$customerId,
                ],
            ],
        ];

        if ($paymentMethod === 'card') {
            $sessionParams['payment_method_types'] = ['card'];
        } elseif ($paymentMethod === 'ach') {
            $sessionParams['payment_method_types'] = ['us_bank_account'];
            $sessionParams['payment_method_options'] = $this->buildAchOptions();
        } else {
            $sessionParams['automatic_payment_methods'] = ['enabled' => true];
        }

        return $this->factory->requireClient()->checkout->sessions->create($sessionParams);
    }

    private function buildAchOptions(): array
    {
        return [
            'us_bank_account' => [
                'verification_method' => 'automatic',
                'financial_connections' => ['permissions' => ['payment_method']],
            ],
        ];
    }
}
