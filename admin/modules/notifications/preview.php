<?php
/**
 * Notifications – Preview (JSON)
 * Returns subject + body for a single notification.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ID.']);
    exit;
}

$notif = db_fetch('SELECT * FROM notifications WHERE id = ? LIMIT 1', [$id]);
if (!$notif) {
    http_response_code(404);
    echo json_encode(['error' => 'Notification not found.']);
    exit;
}

echo json_encode([
    'subject'   => $notif['subject']   ?? '',
    'body'      => $notif['body']      ?? '',
    'recipient' => $notif['recipient'] ?? '',
    'type'      => $notif['type']      ?? 'email',
    'status'    => $notif['status']    ?? '',
    'sent_at'   => !empty($notif['sent_at'])
                       ? fmt_datetime($notif['sent_at'])
                       : fmt_datetime($notif['created_at']),
]);
