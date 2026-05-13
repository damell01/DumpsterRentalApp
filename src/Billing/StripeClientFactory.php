<?php

namespace TrashPanda\Billing;

use Stripe\StripeClient;
use TrashPanda\Billing\Exception\BillingException;

class StripeClientFactory
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return trim((string)\get_setting('stripe_secret_key', '')) !== '';
    }

    public function requireClient(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\Stripe\StripeClient::class)) {
            throw new BillingException('Stripe PHP SDK is not installed.');
        }

        $secret = trim((string)\get_setting('stripe_secret_key', ''));
        if ($secret === '') {
            throw new BillingException('Stripe secret key is not configured.');
        }

        $this->client = new StripeClient($secret);
        return $this->client;
    }

    public function getWebhookSecret(): string
    {
        return trim((string)\get_setting('stripe_webhook_secret', ''));
    }

    public function isLiveMode(): bool
    {
        return \get_setting('stripe_mode', 'test') === 'live';
    }
}
