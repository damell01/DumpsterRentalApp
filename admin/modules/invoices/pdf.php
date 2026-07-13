<?php
/**
 * Invoices – Stream the generated PDF invoice inline (same PDF attached to emails).
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Not found');
}

$inv = db_fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);
if (!$inv) {
    http_response_code(404);
    exit('Invoice not found');
}

$items = db_fetchall('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC', [$id]);
$attachment = invoice_pdf_attachment($inv, $items);
if ($attachment === null || empty($attachment['content'])) {
    http_response_code(500);
    exit('PDF generation unavailable');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$attachment['name']) . '"');
header('Content-Length: ' . strlen((string)$attachment['content']));
header('X-Content-Type-Options: nosniff');
echo $attachment['content'];
exit;
