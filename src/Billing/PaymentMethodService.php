<?php

namespace TrashPanda\Billing;

use TrashPanda\Billing\Exception\BillingException;

class PaymentMethodService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function createSetupIntent(string $stripeCustomerId, array $paymentMethodTypes = ['card', 'us_bank_account']): array
    {
        $intent = $this->factory->requireClient()->setupIntents->create([
            'customer' => $stripeCustomerId,
            'payment_method_types' => $paymentMethodTypes,
            'usage' => 'off_session',
            'payment_method_options' => [
                'us_bank_account' => [
                    'verification_method' => 'automatic',
                ],
            ],
        ]);

        return [
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
        ];
    }

    public function syncFromStripeObject(int $customerId, object $paymentMethod, bool $isDefault = false): int
    {
        $stripePaymentMethodId = (string)$paymentMethod->id;
        $existing = \db_fetch('SELECT id FROM payment_methods WHERE stripe_payment_method_id = ? LIMIT 1', [$stripePaymentMethodId]);

        $data = [
            'customer_id' => $customerId,
            'stripe_payment_method_id' => $stripePaymentMethodId,
            'type' => $paymentMethod->type,
            'brand' => $paymentMethod->card->brand ?? null,
            'last4' => $paymentMethod->card->last4 ?? $paymentMethod->us_bank_account->last4 ?? null,
            'exp_month' => $paymentMethod->card->exp_month ?? null,
            'exp_year' => $paymentMethod->card->exp_year ?? null,
            'bank_name' => $paymentMethod->us_bank_account->bank_name ?? null,
            'account_holder_type' => $paymentMethod->us_bank_account->account_holder_type ?? null,
            'verification_status' => $paymentMethod->us_bank_account->status_details->blocked?->network_code ?? ($paymentMethod->us_bank_account->status ?? null),
            'is_default' => $isDefault ? 1 : 0,
            'is_active' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            \db_update('payment_methods', $data, 'id', (int)$existing['id']);
            $id = (int)$existing['id'];
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = (int)\db_insert('payment_methods', $data);
        }

        if ($isDefault) {
            \db_execute(
                'UPDATE payment_methods SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE customer_id = ?',
                [$id, $customerId]
            );
            \db_update('customers', [
                'default_payment_method_id' => $id,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id', $customerId);
        }

        return $id;
    }

    public function detach(int $customerId, int $paymentMethodId): void
    {
        $row = \db_fetch('SELECT * FROM payment_methods WHERE id = ? AND customer_id = ? LIMIT 1', [$paymentMethodId, $customerId]);
        if (!$row) {
            throw new BillingException('Payment method not found.');
        }

        $this->factory->requireClient()->paymentMethods->detach($row['stripe_payment_method_id'], []);
        \db_update('payment_methods', [
            'is_active' => 0,
            'is_default' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $paymentMethodId);
        \db_execute(
            'UPDATE customers SET default_payment_method_id = NULL, updated_at = NOW() WHERE id = ? AND default_payment_method_id = ?',
            [$customerId, $paymentMethodId]
        );
        $this->auditLogService->log('payment_method_detached', 'Detached saved payment method #' . $paymentMethodId, 'payment_method', $paymentMethodId);
    }
}
