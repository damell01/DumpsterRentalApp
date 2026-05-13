<?php

namespace TrashPanda\Billing;

use TrashPanda\Billing\Exception\BillingException;

class PortalAccessService
{
    public function issueTokenForCustomer(int $customerId, int $minutes = 30): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $rawToken, \PORTAL_SIGNING_KEY);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $minutes . ' minutes'));

        \db_update('customers', [
            'portal_access_token_hash' => $hash,
            'portal_access_expires_at' => $expiresAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $customerId);

        return $rawToken;
    }

    public function validateToken(int $customerId, string $token): array
    {
        $customer = \db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
        if (!$customer) {
            throw new BillingException('Customer not found.');
        }

        $expected = trim((string)($customer['portal_access_token_hash'] ?? ''));
        $expiresAt = trim((string)($customer['portal_access_expires_at'] ?? ''));
        $candidate = hash_hmac('sha256', $token, \PORTAL_SIGNING_KEY);

        if ($expected === '' || !hash_equals($expected, $candidate)) {
            throw new BillingException('Portal link is invalid.');
        }
        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            throw new BillingException('Portal link has expired.');
        }

        return $customer;
    }
}
