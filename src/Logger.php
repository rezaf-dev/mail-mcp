<?php
declare(strict_types=1);
namespace MailMcp;

final class Logger
{
    private static string $root = '';
    private static string $requestId = '';
    private static string $path = '/';
    private static string $method = '';

    public static function configure(string $root): void { self::$root = $root; }
    public static function beginRequest(string $path): string
    {
        self::$path = $path;
        self::$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        self::$requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,80}$/', $incoming) ? $incoming : bin2hex(random_bytes(12));
        return self::$requestId;
    }
    public static function requestId(): string { return self::$requestId ?: 'startup'; }
    public static function error(string $event, array $context = []): void { self::write('error', $event, $context); }
    public static function warning(string $event, array $context = []): void { self::write('warning', $event, $context); }

    private static function write(string $level, string $event, array $context): void
    {
        $record = ['time' => gmdate('c'), 'level' => $level, 'event' => $event, 'request_id' => self::requestId(), 'method' => self::$method, 'path' => self::$path, 'context' => $context];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) { $line = '{"level":"error","event":"log_encoding_failed"}'; }
        $directory = self::$root !== '' ? self::$root . '/storage/logs' : '';
        if ($directory !== '' && (is_dir($directory) || @mkdir($directory, 0700, true))) {
            $file = $directory . '/mail-mcp-' . gmdate('Y-m-d') . '.log';
            $handle = @fopen($file, 'ab');
            if ($handle !== false) {
                @chmod($file, 0600);
                if (flock($handle, LOCK_EX)) { fwrite($handle, $line . PHP_EOL); fflush($handle); flock($handle, LOCK_UN); }
                fclose($handle);
                return;
            }
        }
        error_log('mail-mcp ' . $line);
    }
}
