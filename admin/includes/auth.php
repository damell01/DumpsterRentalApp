<?php
/**
 * Authentication & session helpers
 * Trash Panda Roll-Offs
 */

/**
 * Configure and start the PHP session if not already active.
 */
function session_init(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name(SESSION_NAME);
    session_start();
}

/**
 * Redirect to login if the visitor has no active session.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

/**
 * Return the currently logged-in user row, with a static cache.
 *
 * @return array|null
 */
function current_user(): ?array
{
    static $user = false;   // false = not yet loaded

    if ($user === false) {
        if (empty($_SESSION['user_id'])) {
            $user = null;
        } else {
            $row  = db_fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$_SESSION['user_id']]);
            $user = $row ?: null;
        }
    }

    return $user;
}

/**
 * Return true if the current user has one of the specified roles.
 *
 * @param string ...$roles
 * @return bool
 */
function has_role(string ...$roles): bool
{
    $user = current_user();
    if ($user === null) {
        return false;
    }
    return in_array($user['role'], $roles, true);
}

/**
 * Enforce login and role; send 403 if the role requirement is not met.
 *
 * @param string ...$roles
 */
function require_role(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
        http_response_code(403);
        die(
            '<h1>403 Forbidden</h1>' .
            '<p>You do not have permission to access this page. ' .
            'If you believe this is an error, please contact your administrator.</p>'
        );
    }
}

/**
 * Mark a user as logged in: regenerate session ID, store session data,
 * record activity, and update last_login timestamp.
 *
 * @param array $user  Row from the users table
 */
function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];

    log_activity('login', 'User logged in', 'user', (int)$user['id'], (int)$user['id']);

    db_execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
}

/**
 * Destroy the current session and redirect to the login page.
 */
function logout_user(): void
{
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

/**
 * Return the CSRF token for the current session, generating one if needed.
 *
 * @return string
 */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Return a hidden HTML input carrying the CSRF token.
 *
 * @return string
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf_token() . '">';
}

/**
 * Compare the submitted CSRF token against the session token.
 *
 * @return bool
 */
function csrf_verify(): bool
{
    $submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
    $stored    = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    if ($stored === '' || $submitted === '') {
        return false;
    }

    return hash_equals($stored, $submitted);
}

/**
 * Terminate the request with an error message if the CSRF token is invalid.
 */
function csrf_check(): void
{
    if (!csrf_verify()) {
        http_response_code(400);
        die('Invalid security token. Please refresh the page and try again.');
    }
}
