<?php

namespace TrashPanda\Billing;

class BillingNotificationService
{
    public function sendInvoicePaid(array $recipient, string $invoiceNumber, float $amount, string $customerName = ''): void
    {
        $nameGreeting = $customerName !== '' ? 'Hi ' . \e($customerName) . ',' : 'Hello,';
        $body = '<p>' . $nameGreeting . '</p>'
            . '<p>Your payment of <strong>' . \e(\fmt_money($amount)) . '</strong> for invoice '
            . '<strong>' . \e($invoiceNumber) . '</strong> has been received successfully. Thank you!</p>';

        $this->sendToCustomer($recipient, 'Invoice Paid', $body, 'Invoice Paid — ' . $invoiceNumber);

        $adminBody = '<p>Invoice <strong>' . \e($invoiceNumber) . '</strong> has been paid.'
            . ($customerName !== '' ? ' Customer: <strong>' . \e($customerName) . '</strong>.' : '')
            . ' Amount: <strong>' . \e(\fmt_money($amount)) . '</strong>.</p>';
        \notify_admins(
            'Invoice Paid — ' . $invoiceNumber . ($customerName !== '' ? ' (' . $customerName . ')' : ''),
            $adminBody
        );
    }

    public function sendAchInitiated(array $recipient, string $subjectContext, float $amount): void
    {
        $this->sendToCustomer(
            $recipient,
            'ACH payment initiated',
            '<p>Your ACH payment has been initiated for <strong>' . \e(\fmt_money($amount)) . '</strong>.</p>'
            . '<p>Because ACH payments settle asynchronously, we will email you again once the payment succeeds or fails.</p>',
            'ACH payment initiated - ' . $subjectContext
        );
    }

    public function sendAchSucceeded(array $recipient, string $subjectContext, float $amount): void
    {
        $this->sendToCustomer(
            $recipient,
            'ACH payment succeeded',
            '<p>Your ACH payment for <strong>' . \e(\fmt_money($amount)) . '</strong> has settled successfully.</p>',
            'ACH payment succeeded - ' . $subjectContext
        );
    }

    public function sendAchFailed(array $recipient, string $subjectContext, string $paymentUpdateUrl = ''): void
    {
        $ctaText = $paymentUpdateUrl !== '' ? 'Update Payment Method' : '';
        $this->sendToCustomer(
            $recipient,
            'ACH payment failed',
            '<p>Your ACH payment attempt did not complete successfully.</p><p>Please update your payment method or contact us for assistance.</p>',
            'ACH payment failed - ' . $subjectContext,
            $ctaText,
            $paymentUpdateUrl
        );
    }

    public function sendSubscriptionRenewed(array $recipient, string $subjectContext, float $amount): void
    {
        $this->sendToCustomer(
            $recipient,
            'Subscription renewed',
            '<p>Your recurring service has renewed successfully for <strong>' . \e(\fmt_money($amount)) . '</strong>.</p>',
            'Subscription renewed - ' . $subjectContext
        );
        \notify_admins(
            'Subscription Payment Received — ' . $subjectContext,
            '<p>Subscription <strong>' . \e($subjectContext) . '</strong> renewed successfully.'
            . ' Amount collected: <strong>' . \e(\fmt_money($amount)) . '</strong>.</p>'
        );
    }

    public function sendSubscriptionPaymentFailed(array $recipient, string $subjectContext, string $paymentUpdateUrl = ''): void
    {
        $this->sendToCustomer(
            $recipient,
            'Subscription payment failed',
            '<p>We were unable to process your subscription payment. Your service may pause if payment is not updated.</p>',
            'Subscription payment failed - ' . $subjectContext,
            $paymentUpdateUrl !== '' ? 'Update Payment Method' : '',
            $paymentUpdateUrl
        );
        \notify_admins(
            'Subscription Payment Failed — ' . $subjectContext,
            '<p>A subscription payment failed for <strong>' . \e($subjectContext) . '</strong>.'
            . ' The subscription is now past due. Log in to review and retry the charge.</p>'
        );
    }

    public function sendUpcomingRenewalReminder(array $recipient, string $subjectContext, string $nextDate): void
    {
        $this->sendToCustomer(
            $recipient,
            'Upcoming renewal reminder',
            '<p>Your recurring service is scheduled to renew on <strong>' . \e(\fmt_date($nextDate)) . '</strong>.</p>',
            'Upcoming renewal reminder - ' . $subjectContext
        );
    }

    public function sendSubscriptionCanceled(array $recipient, string $subjectContext): void
    {
        $this->sendToCustomer(
            $recipient,
            'Subscription canceled',
            '<p>Your recurring subscription has been canceled.</p>',
            'Subscription canceled - ' . $subjectContext
        );
    }

    private function sendToCustomer(array $recipient, string $title, string $bodyHtml, string $subject, string $ctaText = '', string $ctaUrl = ''): void
    {
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $html = \email_template($title, $bodyHtml, $ctaText, $ctaUrl);
        \send_email($email, $subject, $html);
    }
}
