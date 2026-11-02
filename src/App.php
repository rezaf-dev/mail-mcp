<?php
declare(strict_types=1);

namespace MailMcp;

use Throwable;

final class App
{
    private Store $store;
    private Panel $panel;
    private ?int $currentAccountId = null;
    public function __construct(private readonly string $root, private readonly array $config) {}

    public static function fromEnvironment(string $root): self
    {
        Logger::configure($root);
        self::loadEnv($root . '/.env');
        $baseUrl = rtrim((string) self::env('MCP_BASE_URL', 'https://mailmcp.on-forge.com'), '/');
        $appKey = (string) self::env('APP_KEY', '');
        if ($appKey === '') { throw new \RuntimeException('APP_KEY is required. Generate one with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'); }
        $store = new Store($root . '/storage/mail-mcp.sqlite');
        $config = [
            'token' => (string) self::env('MCP_BEARER_TOKEN', ''),
            'auth_mode' => 'oauth',
            'base_url' => $baseUrl,
            'resource_url' => $baseUrl,
            'app_key' => $appKey,
            'origins' => array_values(array_filter(array_map('trim', explode(',', (string) self::env('ALLOWED_ORIGINS', ''))))),
            'domains' => self::decryptDomains($store->domains(), $appKey),
            'max_attachment' => max(1, (int) self::env('MAX_ATTACHMENT_BYTES', '10485760')),
            'max_total_attachment' => max(1, (int) self::env('MAX_TOTAL_ATTACHMENT_BYTES', '20971520')),
            'max_attachments' => max(1, (int) self::env('MAX_ATTACHMENTS', '5')),
            'smtp_timeout' => max(5, min(60, (int) self::env('SMTP_TIMEOUT_SECONDS', '15'))),
        ];
        $app = new self($root, $config);
        $app->store = $store;
        $app->panel = new Panel($store, $config);
        return $app;
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        header('X-Request-ID: ' . Logger::beginRequest($path));
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($this->panel->handle($path, $method)) { return; }
        if ($method === 'GET' && $this->serveOAuthMetadata($path)) { return; }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && $this->config['origins'] !== []) {
            if (!in_array($origin, $this->config['origins'], true)) {
                $this->httpError(403, 'Origin is not allowed.');
                return;
            }
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); return; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { $this->httpError(405, 'Use POST for MCP requests.'); return; }
        if (!$this->authorized()) {
            if ($this->config['auth_mode'] === 'oauth') {
                $metadata = ($this->config['resource_url'] ?: $this->requestBaseUrl()) . '/.well-known/oauth-protected-resource';
                header('WWW-Authenticate: Bearer resource_metadata="' . $metadata . '", error="invalid_token"');
            } else { header('WWW-Authenticate: Bearer'); }
            $this->httpError(401, 'Unauthorized.'); return;
        }

        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) > 30_000_000) { $this->httpError(413, 'Request body is too large.'); return; }
        try { $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { Logger::warning('mcp_parse_error', ['message' => $e->getMessage()]); $this->rpcError(null, -32700, 'Parse error.'); return; }
        if (!is_array($request) || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0' || !isset($request['method'])) {
            $this->rpcError($request['id'] ?? null, -32600, 'Invalid Request.'); return;
        }
        $id = $request['id'] ?? null;
        $method = $request['method'];
        if (!is_string($method)) { $this->rpcError($id, -32600, 'Invalid Request.'); return; }
        if ($id === null) { http_response_code(202); return; }
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        try {
            $result = match ($method) {
                'initialize' => ['protocolVersion' => $params['protocolVersion'] ?? '2025-03-26', 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => ['name' => 'mail-mcp', 'version' => '1.0.0'], 'instructions' => 'Send mail through an explicitly configured sender domain. The sender address domain selects its SMTP credentials and rate limits.'],
                'notifications/initialized', 'ping' => [],
                'tools/list' => ['tools' => [$this->sendTool()]],
                'tools/call' => $this->callTool($params),
                default => throw new RpcException(-32601, 'Method not found.'),
            };
            $this->rpcResult($id, $result);
        } catch (RpcException $e) {
            $this->rpcError($id, $e->rpcCode, $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('mcp_request_failed', ['type' => $e::class, 'message' => $e->getMessage()]);
            $this->rpcError($id, -32603, 'Internal error. Request ID: ' . Logger::requestId());
        }
    }

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? '';
        if ($name !== 'send_email') { throw new RpcException(-32602, 'Unknown tool.'); }
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) { throw new RpcException(-32602, 'Tool arguments must be an object.'); }
        try {
            $allowedDomains = $this->store->accountDomains($this->currentAccountId ?? 0);
            $messageId = (new Mailer($this->root, $this->config))->send($args, $allowedDomains);
            return ['content' => [['type' => 'text', 'text' => 'Email sent successfully. Message ID: ' . $messageId]], 'isError' => false];
        } catch (\InvalidArgumentException $e) {
            Logger::warning('mcp_tool_rejected', ['account_id' => $this->currentAccountId, 'reason' => $e->getMessage()]);
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (\RuntimeException $e) {
            Logger::error('mcp_tool_failed', ['account_id' => $this->currentAccountId, 'reason' => $e->getMessage()]);
            return ['content' => [['type' => 'text', 'text' => $e->getMessage() . ' Request ID: ' . Logger::requestId()]], 'isError' => true];
        }
    }

    private function sendTool(): array
    {
        $allowedDomains = $this->store->accountDomains($this->currentAccountId ?? 0);
        return ['name' => 'send_email', 'description' => 'Send one formatted email through the SMTP account selected by the sender domain. This account can use: ' . ($allowedDomains ? implode(', ', $allowedDomains) : 'no domains assigned') . '. Use text for plain email, html for formatted HTML (headings, paragraphs, lists, links, and emphasis), or supply both for multipart/alternative output. Each attachment is a filename plus raw base64 file content and optional MIME type.', 'inputSchema' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['from', 'to', 'subject'],
            'anyOf' => [['required' => ['text']], ['required' => ['html']]],
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'email', 'description' => 'Must use a domain assigned to the signed-in account.'],
                'to' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'format' => 'email']],
                'cc' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                'bcc' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'email']],
                'subject' => ['type' => 'string', 'maxLength' => 998],
                'text' => ['type' => 'string', 'description' => 'Plain-text body or accessible fallback for the HTML body.'],
                'html' => ['type' => 'string', 'description' => 'Formatted HTML email body, for example paragraphs, headings, lists, links, and inline emphasis.'],
                'reply_to' => ['type' => 'string', 'format' => 'email'],
                'attachments' => ['type' => 'array', 'maxItems' => $this->config['max_attachments'], 'description' => 'Optional file attachments. content_base64 must contain raw base64-encoded file bytes (no data: URL prefix). Per-file and total decoded size limits are configured on the server.', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['filename', 'content_base64'], 'properties' => ['filename' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'mime_type' => ['type' => 'string', 'description' => 'MIME type such as application/pdf or image/png. Defaults to application/octet-stream.']]]],
            ],
        ]];
    }

    private function authorized(): bool
    {
        if ($this->config['auth_mode'] === 'none') { return true; }
        $provided = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($provided, 'Bearer ')) { Logger::warning('mcp_auth_failed', ['reason' => 'missing_bearer']); return false; }
        $token = substr($provided, 7);
        if ($this->config['auth_mode'] === 'oauth') { $this->currentAccountId = $this->store->accountIdForToken($token);if($this->currentAccountId===null)Logger::warning('mcp_auth_failed',['reason'=>'invalid_or_expired_token']);return $this->currentAccountId !== null; }
        if ($this->config['auth_mode'] !== 'bearer') { return false; }
        $expected = $this->config['token'];
        if ($expected === '') { return false; }
        return hash_equals($expected, $token);
    }

    private function serveOAuthMetadata(string $path): bool
    {
        if ($this->config['auth_mode'] !== 'oauth') { return false; }
        $resource = $this->config['resource_url'] ?: $this->requestBaseUrl();
        $resourcePath = parse_url($resource, PHP_URL_PATH) ?: '';
        $metadataPath = '/.well-known/oauth-protected-resource' . $resourcePath;
        if ($path !== '/.well-known/oauth-protected-resource' && $path !== $metadataPath) { return false; }
        echo json_encode(['resource' => $resource, 'authorization_servers' => [$this->config['base_url']], 'scopes_supported' => ['mail.send'], 'bearer_methods_supported' => ['header']], JSON_UNESCAPED_SLASHES);
        return true;
    }

    private function validOAuthToken(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !$this->config['oauth_issuer'] || !$this->config['oauth_jwks_url'] || !$this->config['oauth_audience']) { return false; }
        [$head, $payload, $signature] = $parts;
        $header = json_decode($this->base64UrlDecode($head), true);
        $claims = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) { return false; }
        if (($claims['iss'] ?? '') !== $this->config['oauth_issuer'] || !in_array($this->config['oauth_audience'], (array) ($claims['aud'] ?? []), true)) { return false; }
        if (!isset($claims['exp']) || (int) $claims['exp'] <= time() || (isset($claims['nbf']) && (int) $claims['nbf'] > time())) { return false; }
        $scopes = preg_split('/\s+/', (string) ($claims['scope'] ?? $claims['scp'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array($this->config['oauth_scope'], $scopes, true)) { return false; }
        try { $keys = $this->fetchJwks(); } catch (Throwable) { return false; }
        foreach ($keys as $key) {
            if (($key['kid'] ?? '') !== $header['kid'] || ($key['kty'] ?? '') !== 'RSA' || empty($key['n']) || empty($key['e'])) { continue; }
            $publicKey = $this->rsaJwkToPem($key['n'], $key['e']);
            return openssl_verify($head . '.' . $payload, $this->base64UrlDecode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
        }
        return false;
    }

    private function fetchJwks(): array
    {
        $url = $this->config['oauth_jwks_url'];
        if (!str_starts_with($url, 'https://')) { throw new \RuntimeException('JWKS URL must use HTTPS.'); }
        $cachePath = $this->root . '/storage/jwks-' . hash('sha256', $url) . '.json';
        if (is_file($cachePath) && filemtime($cachePath) > time() - 300) { $data = json_decode((string) file_get_contents($cachePath), true); if (is_array($data['keys'] ?? null)) { return $data['keys']; } }
        $context = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => false], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $raw = @file_get_contents($url, false, $context);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) { throw new \RuntimeException('Could not retrieve OAuth JWKS.'); }
        if (!is_dir($this->root . '/storage')) { @mkdir($this->root . '/storage', 0700, true); }
        @file_put_contents($cachePath, json_encode($data), LOCK_EX);
        return $data['keys'];
    }

    private function rsaJwkToPem(string $n, string $e): string
    {
        $modulus = $this->base64UrlDecode($n); $exponent = $this->base64UrlDecode($e);
        $integer = static function (string $bytes): string { if (ord($bytes[0]) > 0x7f) { $bytes = "\0" . $bytes; } return "\x02" . self::derLength(strlen($bytes)) . $bytes; };
        $rsa = "\x30" . self::derLength(strlen($integer($modulus) . $integer($exponent))) . $integer($modulus) . $integer($exponent);
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03" . self::derLength(strlen($rsa) + 1) . "\0" . $rsa;
        $spki = "\x30" . self::derLength(strlen($algorithm . $bitString)) . $algorithm . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) { return chr($length); }
        $bytes = ltrim(pack('N', $length), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
    private function base64UrlDecode(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true); return $decoded === false ? '' : $decoded; }
    private function requestBaseUrl(): string { $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'; return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'); }

    private function rpcResult(mixed $id, array $result): void { echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES); }
    private function rpcError(mixed $id, int $code, string $message): void { echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES); }
    private function httpError(int $status, string $message): void { http_response_code($status); echo json_encode(['error' => $message]); }
    private static function env(string $name, string $default): string { $v = getenv($name); return $v === false ? $default : $v; }
    private static function decryptDomains(array $domains, string $appKey): array
    {
        $key = base64_decode($appKey, true);
        if (strlen((string) $key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new \RuntimeException('APP_KEY must be base64-encoded 32 bytes.'); }
        foreach ($domains as &$domain) {
            $raw = base64_decode((string) ($domain['password_enc'] ?? ''), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { $domain['smtp_password'] = ''; continue; }
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $password = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
            $domain['smtp_password'] = $password === false ? '' : $password;
        }
        unset($domain);
        return $domains;
    }
    private static function loadEnv(string $file): void
    {
        if (!is_file($file)) { return; }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) { continue; }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key); $value = trim($value);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) { $value = substr($value, 1, -1); }
            if (getenv($key) === false) { putenv($key . '=' . $value); $_ENV[$key] = $value; }
        }
    }
}
