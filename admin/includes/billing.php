<?php

use TrashPanda\Billing\AuditLogService;
use TrashPanda\Billing\BillingNotificationService;
use TrashPanda\Billing\CatalogSyncService;
use TrashPanda\Billing\CheckoutService;
use TrashPanda\Billing\InvoiceBillingService;
use TrashPanda\Billing\PaymentMethodService;
use TrashPanda\Billing\PortalAccessService;
use TrashPanda\Billing\StripeClientFactory;
use TrashPanda\Billing\StripeCustomerService;
use TrashPanda\Billing\SubscriptionService;
use TrashPanda\Billing\WebhookLogService;
use TrashPanda\Billing\WebhookService;

function billing_stripe_factory(): StripeClientFactory
{
    static $service = null;
    return $service ??= new StripeClientFactory();
}

function billing_audit_log_service(): AuditLogService
{
    static $service = null;
    return $service ??= new AuditLogService();
}

function billing_customer_service(): StripeCustomerService
{
    static $service = null;
    return $service ??= new StripeCustomerService(
        billing_stripe_factory(),
        billing_audit_log_service(),
    );
}

function billing_payment_method_service(): PaymentMethodService
{
    static $service = null;
    return $service ??= new PaymentMethodService(
        billing_stripe_factory(),
        billing_audit_log_service(),
    );
}

function billing_checkout_service(): CheckoutService
{
    static $service = null;
    return $service ??= new CheckoutService(
        billing_stripe_factory(),
        billing_customer_service(),
    );
}

function billing_catalog_sync_service(): CatalogSyncService
{
    static $service = null;
    return $service ??= new CatalogSyncService(
        billing_stripe_factory(),
        billing_audit_log_service(),
    );
}

function billing_subscription_service(): SubscriptionService
{
    static $service = null;
    return $service ??= new SubscriptionService(
        billing_stripe_factory(),
        billing_customer_service(),
        billing_audit_log_service(),
        billing_invoice_service(),
        billing_notification_service(),
    );
}

function billing_notification_service(): BillingNotificationService
{
    static $service = null;
    return $service ??= new BillingNotificationService();
}

function billing_invoice_service(): InvoiceBillingService
{
    static $service = null;
    return $service ??= new InvoiceBillingService();
}

function billing_portal_access_service(): PortalAccessService
{
    static $service = null;
    return $service ??= new PortalAccessService();
}

function billing_webhook_service(): WebhookService
{
    static $service = null;
    return $service ??= new WebhookService(
        billing_stripe_factory(),
        new WebhookLogService(),
        billing_payment_method_service(),
        billing_subscription_service(),
        billing_invoice_service(),
        billing_notification_service(),
    );
}
