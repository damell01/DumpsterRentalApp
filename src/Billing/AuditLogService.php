<?php

namespace TrashPanda\Billing;

class AuditLogService
{
    public function log(string $action, string $description, string $entityType = '', int $entityId = 0, int $userId = 0): void
    {
        \log_activity($action, $description, $entityType, $entityId, $userId);
        \app_log('billing_audit', $description, [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
        ]);
    }
}
