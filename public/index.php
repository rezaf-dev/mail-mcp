<?php
declare(strict_types=1);

use MailMcp\App;

require dirname(__DIR__) . '/vendor/autoload.php';
ini_set('display_errors', '0');

set_exception_handler(static function (Throwable $error): void {
    \MailMcp\Logger::error('uncaught_exception', ['type' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Internal server error', 'request_id' => \MailMcp\Logger::requestId()]);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    \MailMcp\Logger::error('php_error', ['severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line]);
    return false;
});
register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        \MailMcp\Logger::error('php_fatal', ['severity' => $last['type'], 'message' => $last['message'], 'file' => $last['file'], 'line' => $last['line']]);
    }
});

try {
    App::fromEnvironment(dirname(__DIR__))->handle();
} catch (Throwable $error) {
    \MailMcp\Logger::error('request_exception', ['type' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Internal server error', 'request_id' => \MailMcp\Logger::requestId()]);
}
