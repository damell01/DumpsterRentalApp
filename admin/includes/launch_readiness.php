<?php
/**
 * Launch readiness helper
 */

function launch_readiness_data(): array
{
    $dumpsters_available = 0;
    $active_dumpsters = 0;

    try {
        $dumpsters_available = (int)(db_fetch(
            "SELECT COUNT(*) AS cnt FROM dumpsters WHERE status = 'available'"
        )['cnt'] ?? 0);
    } catch (\Throwable $e) {
        $dumpsters_available = 0;
    }

    try {
        $active_dumpsters = (int)(db_fetch(
            "SELECT COUNT(*) AS cnt FROM dumpsters WHERE active = 1"
        )['cnt'] ?? 0);
    } catch (\Throwable $e) {
        $active_dumpsters = $dumpsters_available;
    }

    $app_env = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'production';
    $stripe_mode = strtolower(trim((string)get_setting('stripe_mode', 'test')));
    if ($stripe_mode !== 'live') {
        $stripe_mode = 'test';
    }

    $stripe_publishable_key = trim((string)get_setting('stripe_publishable_key', ''));
    $stripe_secret_key = trim((string)get_setting('stripe_secret_key', ''));
    $stripe_webhook_secret = trim((string)get_setting('stripe_webhook_secret', ''));
    $portal_signing_key = trim((string)get_setting('portal_signing_key', ''));

    $expected_publishable_prefix = $stripe_mode === 'live' ? 'pk_live_' : 'pk_test_';
    $expected_secret_prefix = $stripe_mode === 'live' ? 'sk_live_' : 'sk_test_';

    $stripe_mode_matches_keys =
        $stripe_publishable_key !== '' &&
        $stripe_secret_key !== '' &&
        str_starts_with($stripe_publishable_key, $expected_publishable_prefix) &&
        str_starts_with($stripe_secret_key, $expected_secret_prefix);

    $checks = [
        [
            'label' => 'Company profile',
            'description' => 'Company name, phone, and email are filled in.',
            'ready' => trim((string)get_setting('company_name', '')) !== '' &&
                trim((string)get_setting('company_phone', '')) !== '' &&
                trim((string)get_setting('company_email', '')) !== '',
            'href' => APP_URL . '/modules/settings/index.php#settings-company',
        ],
        [
            'label' => 'Branding',
            'description' => 'A logo is uploaded or a valid logo path is set.',
            'ready' => trim((string)get_setting('logo_url', '')) !== '' ||
                trim((string)get_setting('logo_path', '')) !== '',
            'href' => APP_URL . '/modules/settings/index.php#settings-branding',
        ],
        [
            'label' => 'Email delivery',
            'description' => 'From email exists and delivery settings are configured.',
            'ready' => trim((string)get_setting('email_from_email', '')) !== '' &&
                (trim((string)get_setting('smtp_host', '')) !== '' || trim((string)get_setting('company_email', '')) !== ''),
            'href' => APP_URL . '/modules/settings/index.php#settings-email',
        ],
        [
            'label' => 'Stripe mode and keys',
            'description' => 'Stripe is in ' . strtoupper($stripe_mode) . ' mode and the saved keys match that mode.',
            'ready' => $stripe_mode_matches_keys,
            'href' => APP_URL . '/modules/settings/index.php#settings-stripe',
        ],
        [
            'label' => 'Stripe webhook',
            'description' => 'A Stripe webhook secret is saved so payment updates can sync automatically.',
            'ready' => $stripe_webhook_secret !== '' && str_starts_with($stripe_webhook_secret, 'whsec_'),
            'href' => APP_URL . '/modules/settings/index.php#settings-stripe',
        ],
        [
            'label' => 'Customer portal security',
            'description' => 'A portal signing key exists for secure passwordless customer portal links.',
            'ready' => $portal_signing_key !== '',
            'href' => APP_URL . '/modules/settings/index.php#settings-stripe',
        ],
        [
            'label' => 'Inventory loaded',
            'description' => 'At least one active dumpster exists in inventory.',
            'ready' => $active_dumpsters > 0,
            'href' => APP_URL . '/modules/dumpsters/index.php',
        ],
        [
            'label' => 'Production environment',
            'description' => 'APP_ENV is set to production on the server.',
            'ready' => $app_env === 'production',
            'href' => APP_URL . '/modules/settings/index.php#settings-stripe',
        ],
    ];

    $ready_count = count(array_filter($checks, static fn(array $check): bool => $check['ready']));
    $total_count = count($checks);
    $score = $total_count > 0 ? (int)round(($ready_count / $total_count) * 100) : 0;

    $status = 'Needs Setup';
    $status_class = 'tp-launch-status-warn';
    if ($score >= 85) {
        $status = 'Launch Ready';
        $status_class = 'tp-launch-status-good';
    } elseif ($score >= 50) {
        $status = 'Almost Ready';
        $status_class = 'tp-launch-status-mid';
    }

    return [
        'score' => $score,
        'status' => $status,
        'status_class' => $status_class,
        'ready_count' => $ready_count,
        'total_count' => $total_count,
        'checks' => $checks,
        'pending_checks' => array_values(array_filter($checks, static fn(array $check): bool => !$check['ready'])),
        'app_env' => $app_env,
        'stripe_mode' => $stripe_mode,
        'active_dumpsters' => $active_dumpsters,
        'dumpsters_available' => $dumpsters_available,
    ];
}
