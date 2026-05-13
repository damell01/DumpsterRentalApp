<?php

namespace TrashPanda\Billing;

class CatalogSyncService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function syncDumpster(array $dumpster): array
    {
        $client = $this->factory->requireClient();

        $dumpsterId = (int)($dumpster['id'] ?? 0);
        $unitCode = trim((string)($dumpster['unit_code'] ?? ''));
        $name = trim((string)($dumpster['product_name'] ?? '')) ?: trim((string)($dumpster['size'] ?? '')) . ' Dumpster';
        $description = trim((string)($dumpster['description'] ?? '')) ?: null;
        $currency = strtolower((string)(\get_setting('currency', 'usd') ?: 'usd'));
        $active = !empty($dumpster['active']);

        $productMetadata = [
            'dumpster_id' => (string)$dumpsterId,
            'unit_code' => $unitCode,
            'size' => (string)($dumpster['size'] ?? ''),
            'type' => (string)($dumpster['type'] ?? 'dumpster'),
            'source' => 'inventory',
        ];

        $existingProductId = trim((string)($dumpster['stripe_product_id'] ?? ''));
        if ($existingProductId !== '') {
            $product = $client->products->update($existingProductId, array_filter([
                'name' => $name,
                'description' => $description,
                'active' => $active,
                'metadata' => $productMetadata,
            ], static fn($value) => $value !== null));
        } else {
            $product = $client->products->create(array_filter([
                'name' => $name,
                'description' => $description,
                'active' => $active,
                'metadata' => $productMetadata,
            ], static fn($value) => $value !== null));
        }

        $productId = (string)$product->id;
        $lookupPrefix = $this->lookupPrefix($dumpsterId, $unitCode);
        $syncedPrices = [];

        $bookingPriceId = $this->ensureCatalogPrice(
            $productId,
            $currency,
            $lookupPrefix . '_booking',
            (int)round((float)($dumpster['base_price'] ?? 0) * 100),
            [
                'nickname' => sprintf('%s - Booking base (%d day rental)', $name, max(1, (int)($dumpster['rental_days'] ?? 7))),
                'metadata' => [
                    'dumpster_id' => (string)$dumpsterId,
                    'price_role' => 'booking_base',
                    'rental_days' => (string)max(1, (int)($dumpster['rental_days'] ?? 7)),
                ],
            ]
        );
        if ($bookingPriceId !== null) {
            $syncedPrices['booking_base'] = $bookingPriceId;
        }

        $legacyStoredPriceId = trim((string)($dumpster['stripe_price_id'] ?? ''));
        if ($legacyStoredPriceId !== '' && $bookingPriceId !== null && $legacyStoredPriceId !== $bookingPriceId) {
            try {
                $client->prices->update($legacyStoredPriceId, ['active' => false]);
            } catch (\Throwable) {
            }
        }

        $weeklyRate = (float)($dumpster['weekly_rate'] ?? 0);
        if ($weeklyRate > 0) {
            $weeklyPriceId = $this->ensureCatalogPrice(
                $productId,
                $currency,
                $lookupPrefix . '_weekly',
                (int)round($weeklyRate * 100),
                [
                    'nickname' => $name . ' - Weekly recurring',
                    'metadata' => [
                        'dumpster_id' => (string)$dumpsterId,
                        'price_role' => 'subscription_weekly',
                    ],
                    'recurring' => [
                        'interval' => 'week',
                        'interval_count' => 1,
                    ],
                ]
            );

            if ($weeklyPriceId !== null) {
                $syncedPrices['subscription_weekly'] = $weeklyPriceId;
            }

            $biweeklyPriceId = $this->ensureCatalogPrice(
                $productId,
                $currency,
                $lookupPrefix . '_biweekly',
                (int)round(($weeklyRate * 2) * 100),
                [
                    'nickname' => $name . ' - Bi-weekly recurring',
                    'metadata' => [
                        'dumpster_id' => (string)$dumpsterId,
                        'price_role' => 'subscription_biweekly',
                        'derived_from' => 'weekly_rate_x2',
                    ],
                    'recurring' => [
                        'interval' => 'week',
                        'interval_count' => 2,
                    ],
                ]
            );

            if ($biweeklyPriceId !== null) {
                $syncedPrices['subscription_biweekly'] = $biweeklyPriceId;
            }
        }

        $monthlyRate = (float)($dumpster['monthly_rate'] ?? 0);
        if ($monthlyRate > 0) {
            $monthlyPriceId = $this->ensureCatalogPrice(
                $productId,
                $currency,
                $lookupPrefix . '_monthly',
                (int)round($monthlyRate * 100),
                [
                    'nickname' => $name . ' - Monthly recurring',
                    'metadata' => [
                        'dumpster_id' => (string)$dumpsterId,
                        'price_role' => 'subscription_monthly',
                    ],
                    'recurring' => [
                        'interval' => 'month',
                        'interval_count' => 1,
                    ],
                ]
            );

            if ($monthlyPriceId !== null) {
                $syncedPrices['subscription_monthly'] = $monthlyPriceId;
            }
        }

        $this->auditLogService->log(
            'stripe_catalog_sync',
            'Synced Stripe catalog for dumpster ' . $unitCode,
            'dumpster',
            $dumpsterId
        );

        return [
            'stripe_product_id' => $productId,
            'stripe_price_id' => $bookingPriceId ?? '',
            'price_map' => $syncedPrices,
            'lookup_prefix' => $lookupPrefix,
        ];
    }

    private function ensureCatalogPrice(
        string $productId,
        string $currency,
        string $lookupKey,
        int $amountCents,
        array $options
    ): ?string {
        if ($amountCents <= 0) {
            return null;
        }

        $client = $this->factory->requireClient();
        $existingPrice = $this->findPriceByLookupKey($lookupKey);

        $recurring = $options['recurring'] ?? null;
        if ($existingPrice !== null && $this->priceMatches($existingPrice, $productId, $amountCents, $currency, $recurring)) {
            return (string)$existingPrice->id;
        }

        $params = [
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => $amountCents,
            'lookup_key' => $lookupKey,
            'nickname' => $options['nickname'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ];

        if ($recurring !== null) {
            $params['recurring'] = $recurring;
        }

        if ($existingPrice !== null) {
            $params['transfer_lookup_key'] = true;
        }

        $price = $client->prices->create(array_filter($params, static fn($value) => $value !== null));

        if ($existingPrice !== null && !empty($existingPrice->active)) {
            try {
                $client->prices->update((string)$existingPrice->id, ['active' => false]);
            } catch (\Throwable) {
            }
        }

        return (string)$price->id;
    }

    private function findPriceByLookupKey(string $lookupKey): ?object
    {
        $prices = $this->factory->requireClient()->prices->all([
            'lookup_keys' => [$lookupKey],
            'limit' => 1,
        ]);

        return $prices->data[0] ?? null;
    }

    private function priceMatches(object $price, string $productId, int $amountCents, string $currency, ?array $recurring): bool
    {
        if ((string)($price->product ?? '') !== $productId) {
            return false;
        }

        if ((int)($price->unit_amount ?? 0) !== $amountCents) {
            return false;
        }

        if (strtolower((string)($price->currency ?? '')) !== strtolower($currency)) {
            return false;
        }

        $existingRecurring = $price->recurring ?? null;
        if ($recurring === null) {
            return $existingRecurring === null;
        }

        if ($existingRecurring === null) {
            return false;
        }

        return (string)($existingRecurring->interval ?? '') === (string)($recurring['interval'] ?? '')
            && (int)($existingRecurring->interval_count ?? 1) === (int)($recurring['interval_count'] ?? 1);
    }

    private function lookupPrefix(int $dumpsterId, string $unitCode): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $unitCode), '_'));
        return 'tp_dumpster_' . $dumpsterId . '_' . ($slug !== '' ? $slug : 'item');
    }
}
