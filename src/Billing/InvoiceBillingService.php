<?php

namespace TrashPanda\Billing;

class InvoiceBillingService
{
    public function createLocalInvoiceFromStripeInvoice(object $stripeInvoice, int $customerId, ?int $subscriptionId = null): int
    {
        $existing = \db_fetch('SELECT id FROM invoices WHERE stripe_invoice_id = ? LIMIT 1', [$stripeInvoice->id]);
        if ($existing) {
            return (int)$existing['id'];
        }

        $amountDue = ((int)$stripeInvoice->amount_due) / 100;
        $taxAmount = ((int)($stripeInvoice->tax ?? 0)) / 100;
        $subtotal = ((int)$stripeInvoice->subtotal) / 100;
        $customer = \db_fetch('SELECT * FROM customers WHERE id = ? LIMIT 1', [$customerId]) ?: [];

        $invoiceId = (int)\db_insert('invoices', [
            'invoice_number' => \next_number('INV', 'invoices', 'invoice_number'),
            'customer_id' => $customerId,
            'cust_name' => $customer['name'] ?? ($stripeInvoice->customer_name ?? 'Customer'),
            'cust_email' => $customer['email'] ?? null,
            'cust_phone' => $customer['phone'] ?? null,
            'cust_address' => $customer['billing_address'] ?? $customer['address'] ?? null,
            'subtotal' => $subtotal,
            'tax_rate' => 0,
            'tax_amount' => $taxAmount,
            'total' => $amountDue,
            'status' => $stripeInvoice->status === 'paid' ? 'paid' : 'open',
            'due_date' => !empty($stripeInvoice->due_date) ? date('Y-m-d', (int)$stripeInvoice->due_date) : null,
            'stripe_invoice_id' => $stripeInvoice->id,
            'stripe_hosted_invoice_url' => $stripeInvoice->hosted_invoice_url ?? null,
            'stripe_payment_intent_id' => is_string($stripeInvoice->payment_intent ?? null) ? $stripeInvoice->payment_intent : null,
            'subscription_id' => $subscriptionId,
            'collection_method' => $stripeInvoice->collection_method ?? 'charge_automatically',
            'billing_reason' => $stripeInvoice->billing_reason ?? null,
            'paid_at' => $stripeInvoice->status === 'paid' ? date('Y-m-d H:i:s') : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        \db_insert('invoice_items', [
            'invoice_id' => $invoiceId,
            'description' => $stripeInvoice->description ?: 'Subscription renewal',
            'quantity' => 1,
            'unit_price' => $subtotal,
            'amount' => $subtotal,
            'rate_type' => 'monthly',
        ]);

        return $invoiceId;
    }
}
