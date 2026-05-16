<?php
/**
 * Public API Rate Limiting
 * Trash Panda Roll-Offs
 *
 * Fixed-window rate limiter backed by the api_rate_limits table.
 * Call api_rate_limit() near the top of each public API endpoint;
 * it exits with 429 JSON if the caller is over quota.
 * Fails open if the table doesn't exist (e.g. migration not yet run).
 */

/**
 * @param string $action      Short endpoint identifier, e.g. 'create_booking'.
 * @param int    $max         Max requests allowed within the window.
 * @param int    $window_sec  Window size in seconds.
 * @param int    $lockout_sec Hard lockout after limit exceeded (0 = window-expiry only).
 */
function api_rate_limit(string $action, int $max, int $window_sec, int $lockout_sec = 0): void
{
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $bucket = substr($action . ':' . $ip, 0, 120);
    $now    = time();

    // Opportunistic cleanup: 1% of requests prune expired rows older than 2× the window
    if (rand(1, 100) === 1) {
        try {
            db_execute(
                'DELETE FROM api_rate_limits
                 WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)
                   AND (locked_until IS NULL OR locked_until < NOW())',
                [$window_sec * 2]
            );
        } catch (\Throwable $ignored) {}
    }

    try {
        $row = db_fetch('SELECT * FROM api_rate_limits WHERE bucket = ? LIMIT 1', [$bucket]);
    } catch (\Throwable $e) {
        return; // table missing — fail open
    }

    if ($row) {
        // Hard lockout still active?
        if ($row['locked_until'] && strtotime($row['locked_until']) > $now) {
            _api_rl_abort(max(1, strtotime($row['locked_until']) - $now));
        }

        // Window expired — reset counter and allow
        if ($now - strtotime($row['window_start']) >= $window_sec) {
            db_execute(
                'UPDATE api_rate_limits
                 SET attempts = 1, window_start = NOW(), locked_until = NULL, updated_at = NOW()
                 WHERE bucket = ?',
                [$bucket]
            );
            return;
        }

        // Within window — increment and check
        $attempts = (int)$row['attempts'] + 1;
        if ($attempts > $max) {
            $elapsed  = $now - strtotime($row['window_start']);
            $wait     = $lockout_sec > 0 ? $lockout_sec : max(1, $window_sec - $elapsed);
            $locked   = $lockout_sec > 0 ? date('Y-m-d H:i:s', $now + $lockout_sec) : null;
            db_execute(
                'UPDATE api_rate_limits SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE bucket = ?',
                [$attempts, $locked, $bucket]
            );
            _api_rl_abort($wait);
        }

        db_execute(
            'UPDATE api_rate_limits SET attempts = ?, updated_at = NOW() WHERE bucket = ?',
            [$attempts, $bucket]
        );
    } else {
        try {
            db_execute(
                'INSERT INTO api_rate_limits (bucket, attempts, window_start, locked_until, updated_at)
                 VALUES (?, 1, NOW(), NULL, NOW())',
                [$bucket]
            );
        } catch (\Throwable $ignored) {
            // Concurrent insert race — harmless; next request will update
        }
    }
}

function _api_rl_abort(int $wait_sec): void
{
    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . $wait_sec);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success'     => false,
        'error'       => 'Too many requests. Please try again later.',
        'retry_after' => $wait_sec,
    ]);
    exit;
}
