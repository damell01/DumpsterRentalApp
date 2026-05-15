<?php
/**
 * Resend booking/customer emails from admin.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INC_PATH . '/mailer.php';
require_once INC_PATH . '/billing.php';

require_login();
require_role('admin', 'office');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['email_action'] ?? ''));
$redirectTo = trim((string)($_POST['redirect_to'] ?? ''));

if ($id <= 0) {
    flash_error('Invalid booking ID.');
    redirect('index.php');
}

$booking = db_fetch('SELECT * FROM bookings WHERE id = ? LIMIT 1', [$id]);
if (!$booking) {
    flash_error('Booking not found.');
    redirect('index.php');
}

$go = $redirectTo !== '' ? $redirectTo : 'view.php?id=' . $id;

try {
    switch ($action) {
        case 'request_received':
            notify_booking_request_received($booking);
            log_activity('booking_request_resent', 'Resent booking request email for ' . ($booking['booking_number'] ?? '') . '.', 'booking', $id);
            flash_success('Booking request email resent.');
            break;

        case 'approval':
            $invoice = db_fetch(
                'SELECT * FROM invoices WHERE notes LIKE ? ORDER BY id DESC LIMIT 1',
                ['%Booking ' . ($booking['booking_number'] ?? '') . '%']
            ) ?: [];
            if (notify_booking_approved($booking, $invoice)) {
                log_activity('booking_approval_resend', 'Resent booking approval email for ' . ($booking['booking_number'] ?? '') . '.', 'booking', $id);
                flash_success('Booking approval email resent.');
            } else {
                flash_error('No customer email found for this booking.');
            }
            break;

        case 'invoice':
            $invoice = db_fetch(
                'SELECT * FROM invoices WHERE notes LIKE ? ORDER BY id DESC LIMIT 1',
                ['%Booking ' . ($booking['booking_number'] ?? '') . '%']
            );
            if (!$invoice) {
                throw new RuntimeException('No invoice found for this booking.');
            }
            if (send_invoice_email_to_customer($invoice)) {
                log_activity('invoice_resend', 'Resent invoice ' . ($invoice['invoice_number'] ?? '') . ' for booking ' . ($booking['booking_number'] ?? '') . '.', 'booking', $id);
                flash_success('Invoice email resent.');
            } else {
                flash_error('Invoice email could not be sent.');
            }
            break;

        case 'portal_link':
            $customerId = (int)($booking['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw new RuntimeException('This booking is not linked to a customer record yet.');
            }
            $customer = db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]);
            if (!$customer) {
                throw new RuntimeException('Customer record not found.');
            }
            if (send_customer_portal_link_email($customer)) {
                log_activity('portal_link_resend', 'Resent customer portal link for booking ' . ($booking['booking_number'] ?? '') . '.', 'booking', $id);
                flash_success('Customer portal link resent.');
            } else {
                flash_error('Portal link email could not be sent.');
            }
            break;

        default:
            flash_error('Unknown resend action.');
            break;
    }
} catch (Throwable $e) {
    flash_error('Could not resend email: ' . $e->getMessage());
}

redirect($go);
