<?php
/**
 * Centralised error handler – Trash Panda Roll-Offs
 *
 * Call tp_install_error_handler() once near the top of bootstrap.
 * Captures uncaught exceptions and PHP fatal errors, writes them to the
 * app log (filesystem), and sends a throttled admin email alert.
 *
 * Safe to call before helpers.php / mailer.php are loaded — uses
 * function_exists() guards and falls back to error_log() if needed.
 *
 * @param bool $is_api  When true, responds with JSON instead of HTML.
 */
function tp_install_error_handler(bool $is_api = false): void
{
    $debug = defined('APP_DEBUG') && APP_DEBUG === true;

    if (!$debug) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(static function (\Throwable $e) use ($is_api, $debug): void {
        _tp_log_error('exception', $e->getMessage(), [
            'class' => get_class($e),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        if (!$debug) {
            _tp_maybe_email_error($e->getMessage(), $e->getFile(), $e->getLine());
        }
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        $debug ? _tp_debug_html_exception($e) : ($is_api ? _tp_json_500() : _tp_error_html());
    });

    register_shutdown_function(static function () use ($is_api, $debug): void {
        $err = error_get_last();
        if (!$err) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatal, true)) {
            return;
        }
        _tp_log_error('fatal', $err['message'] ?? '', [
            'file' => $err['file'] ?? '',
            'line' => $err['line'] ?? 0,
            'type' => $err['type'],
        ]);
        if (!$debug) {
            _tp_maybe_email_error($err['message'] ?? '', $err['file'] ?? '', (int)($err['line'] ?? 0));
        }
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        $is_api ? _tp_json_500() : _tp_error_html();
    });
}

/**
 * Write a structured error entry to the filesystem and PHP error_log.
 */
function _tp_log_error(string $level, string $message, array $context = []): void
{
    $context['url']    = $_SERVER['REQUEST_URI'] ?? 'cli';
    $context['method'] = $_SERVER['REQUEST_METHOD'] ?? 'cli';
    $context['ip']     = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $context['ua']     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    if (!empty($_SESSION['user_id'])) {
        $context['user_id'] = (int)$_SESSION['user_id'];
    }

    if (function_exists('app_log')) {
        app_log('error_' . $level, $message, $context);
    }

    error_log('[TP ' . strtoupper($level) . '] ' . $message
        . ' in ' . ($context['file'] ?? ($context['class'] ?? '?'))
        . ':' . ($context['line'] ?? 0)
        . ' | ' . $context['url']);
}

/**
 * Send a one-per-5-minutes admin email alert for unique error signatures.
 * Uses a lock file so it works even without a DB connection.
 */
function _tp_maybe_email_error(string $msg, string $file, int $line): void
{
    $dir = defined('APP_LOG_DIR') && APP_LOG_DIR !== ''
        ? APP_LOG_DIR
        : (defined('ROOT_PATH') ? ROOT_PATH . '/logs' : sys_get_temp_dir());

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $sig  = md5($msg . $file . $line);
    $lock = $dir . '/err_' . $sig . '.lock';

    if (file_exists($lock) && (time() - filemtime($lock)) < 300) {
        return; // throttled: same error within 5 minutes
    }
    @touch($lock);

    if (!function_exists('notify_admins')) {
        return;
    }

    $subject = '[TP Error] ' . substr($msg, 0, 100);
    $body    = '<h3 style="color:#ef4444;margin:0 0 .75rem;">Application Error</h3>'
             . '<table cellpadding="5" cellspacing="0" border="0" style="font-size:.85rem;font-family:monospace;">'
             . '<tr><td style="color:#6b7280;padding-right:1rem;">Message</td><td>' . htmlspecialchars($msg) . '</td></tr>'
             . '<tr><td style="color:#6b7280;">File</td><td>' . htmlspecialchars($file) . ':' . $line . '</td></tr>'
             . '<tr><td style="color:#6b7280;">URL</td><td>' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'n/a') . '</td></tr>'
             . '<tr><td style="color:#6b7280;">IP</td><td>' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'n/a') . '</td></tr>'
             . '<tr><td style="color:#6b7280;">Time</td><td>' . date('Y-m-d H:i:s T') . '</td></tr>'
             . '</table>';

    try {
        notify_admins($subject, $body);
    } catch (\Throwable $ignored) {}
}

// ── Private response helpers ─────────────────────────────────────────────────

function _tp_json_500(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}

function _tp_error_html(): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<title>Error – Trash Panda Roll-Offs</title>'
        . '<style>body{font-family:Arial,sans-serif;background:#0f0f0f;color:#f0f0f0;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0;}'
        . '.box{background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:2rem 2.5rem;'
        . 'max-width:520px;text-align:center;}'
        . 'h2{color:#f97316;margin:0 0 .75rem;}p{color:#9ca3af;margin:.5rem 0;}a{color:#f97316;}'
        . '</style></head><body>'
        . '<div class="box"><h2>Something went wrong</h2>'
        . '<p>An unexpected error occurred. Please try again or contact your administrator.</p>'
        . '<p style="margin-top:1.5rem;"><a href="javascript:history.back()">&#8592; Go back</a></p>'
        . '</div></body></html>';
}

function _tp_debug_html_exception(\Throwable $e): void
{
    echo '<pre style="white-space:pre-wrap;background:#111;color:#f5f5f5;padding:16px;border:1px solid #444;">';
    echo "UNCAUGHT EXCEPTION\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    echo '</pre>';
}
