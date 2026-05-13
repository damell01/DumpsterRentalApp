<?php

namespace TrashPanda\Billing;

class WebhookLogService
{
    public function findByEventId(string $eventId): ?array
    {
        $row = \db_fetch('SELECT * FROM webhook_logs WHERE stripe_event_id = ? LIMIT 1', [$eventId]);
        return $row ?: null;
    }

    public function createSkeleton(string $eventId, string $eventType, bool $livemode, string $apiVersion, string $payload): int
    {
        $hash = hash('sha256', $payload);
        return (int)\db_insert('webhook_logs', [
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'livemode' => $livemode ? 1 : 0,
            'api_version' => $apiVersion,
            'payload_hash' => $hash,
            'processing_status' => 'received',
            'attempt_count' => 1,
            'first_processed_at' => date('Y-m-d H:i:s'),
            'last_processed_at' => date('Y-m-d H:i:s'),
            'payload_excerpt' => substr($payload, 0, 1000),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markProcessed(int $id, array $context = []): void
    {
        \db_update('webhook_logs', [
            'processing_status' => 'processed',
            'related_entity_type' => $context['entity_type'] ?? null,
            'related_entity_id' => $context['entity_id'] ?? null,
            'error_message' => null,
            'last_processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function markDuplicate(int $id): void
    {
        \db_execute(
            'UPDATE webhook_logs SET processing_status = ?, attempt_count = attempt_count + 1, last_processed_at = NOW(), updated_at = NOW() WHERE id = ?',
            ['duplicate', $id]
        );
    }

    public function markFailed(int $id, string $message): void
    {
        \db_update('webhook_logs', [
            'processing_status' => 'failed',
            'error_message' => substr($message, 0, 1000),
            'last_processed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
        \app_log('stripe_webhook', 'Webhook processing failed', ['webhook_log_id' => $id, 'error' => $message]);
    }
}
