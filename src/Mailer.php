<?php
declare(strict_types=1);

namespace MailMcp;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class Mailer
{
    public function __construct(private readonly string $root, private readonly array $config) {}

    public function send(array $args, array $allowedDomains): string
    {
        $from = filter_var((string) ($args['from'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$from) { throw new \InvalidArgumentException('from must be a valid email address.'); }
        $domain = strtolower(substr(strrchr($from, '@') ?: '', 1));
        if (!in_array($domain, $allowedDomains, true)) { throw new \InvalidArgumentException('This account is not authorized to send from that domain.'); }
        $settings = $this->config['domains'][$domain] ?? null;
        if (!is_array($settings)) { throw new \InvalidArgumentException('The sender domain is not configured.'); }
        $transport = (string) ($settings['transport'] ?? 'smtp');
        $required = match ($transport) { 'ses_api' => ['aws_region', 'aws_access_key_id', 'aws_secret_access_key'], 'resend' => ['resend_api_key'], 'smtp' => ['smtp_host', 'smtp_username', 'smtp_password'], default => [] };
        if (!$required) { throw new \RuntimeException('Unsupported mail transport for this domain.'); }
        foreach ($required as $key) { if (empty($settings[$key])) { throw new \RuntimeException(($transport === 'ses_api' ? 'Amazon SES API' : ($transport === 'resend' ? 'Resend API' : 'SMTP')) . ' configuration is incomplete for this domain.'); } }
        $to = $this->addresses($args['to'] ?? null, 'to', true);
        $cc = $this->addresses($args['cc'] ?? [], 'cc');
        $bcc = $this->addresses($args['bcc'] ?? [], 'bcc');
        $maxRecipients = max(1, (int) ($settings['max_recipients_per_message'] ?? 10));
        if (count($to) + count($cc) + count($bcc) > $maxRecipients) { throw new \InvalidArgumentException('Too many recipients in one message.'); }
        $subject = trim((string) ($args['subject'] ?? ''));
        if ($subject === '' || strlen($subject) > 998) { throw new \InvalidArgumentException('subject is required and must be at most 998 bytes.'); }
        if (!isset($args['text']) && !isset($args['html'])) { throw new \InvalidArgumentException('Provide text and/or html body.'); }
        if (isset($args['reply_to']) && !filter_var((string) $args['reply_to'], FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('reply_to must be a valid email address.'); }

        $attachments = $this->attachments($args['attachments'] ?? []);
        $this->consumeRateLimit($domain, $settings);
        $mail = new PHPMailer(true);
        try {
            if ($transport === 'resend') { return $this->sendResendApi($args, $from, $settings, $attachments); }
            if ($transport === 'smtp') {
                $mail->isSMTP(); $mail->Host = (string) $settings['smtp_host']; $mail->Port = (int) ($settings['smtp_port'] ?? 587);
                $mail->SMTPAuth = true; $mail->Username = (string) $settings['smtp_username']; $mail->Password = (string) $settings['smtp_password'];
                $mail->Timeout = $this->config['smtp_timeout'] ?? 15;
                $encryption = strtolower((string) ($settings['smtp_encryption'] ?? 'tls'));
                $mail->SMTPSecure = match ($encryption) { 'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS, 'none' => '', default => PHPMailer::ENCRYPTION_STARTTLS };
            }
            $mail->CharSet = 'UTF-8'; $mail->setFrom($from, (string) ($settings['from_name'] ?? ''));
            foreach ($to as $address) { $mail->addAddress($address); }
            foreach ($cc as $address) { $mail->addCC($address); }
            foreach ($bcc as $address) { $mail->addBCC($address); }
            if (!empty($args['reply_to'])) { $mail->addReplyTo((string) $args['reply_to']); }
            $mail->Subject = $subject;
            if (isset($args['html'])) {
                $mail->isHTML(true);
                $mail->Body = (string) $args['html'];
                $mail->AltBody = isset($args['text']) ? (string) $args['text'] : trim(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", (string) $args['html'])));
            } else {
                $mail->isHTML(false);
                $mail->Body = (string) $args['text'];
            }
            foreach ($attachments as $attachment) { $mail->addStringAttachment($attachment['data'], $attachment['name'], PHPMailer::ENCODING_BASE64, $attachment['mime']); }
            if ($transport === 'smtp') { $mail->send(); return (string) $mail->getLastMessageID(); }
            $mail->preSend();
            return $this->sendSesApi($mail->getSentMIMEMessage(), $settings);
        } catch (Throwable $e) {
            $providerError = str_replace(array_filter([(string) ($settings['smtp_username'] ?? ''), (string) ($settings['smtp_password'] ?? ''), (string) ($settings['aws_access_key_id'] ?? ''), (string) ($settings['aws_secret_access_key'] ?? ''), (string) ($settings['resend_api_key'] ?? '')]), '[redacted]', $e->getMessage());
            Logger::error(match ($transport) { 'ses_api' => 'ses_api_send_failed', 'resend' => 'resend_send_failed', default => 'smtp_send_failed' }, ['domain' => $domain, 'exception' => $e::class, 'provider_error' => $providerError]);
            $diagnostic = substr(preg_replace('/[\\r\\n]+/', ' ', $providerError) ?? 'Mail provider returned an unknown error.', 0, 1200);
            $provider = match ($transport) { 'ses_api' => 'Amazon SES API', 'resend' => 'Resend API', default => 'SMTP' };
            throw new \RuntimeException($provider . ' delivery failed. Check the application log for request ID ' . Logger::requestId() . ' and confirm provider credentials and verified sender identity.', 0, new \RuntimeException($diagnostic));
        }
    }

    private function sendSesApi(string $mime, array $settings): string
    {
        $region = (string) $settings['aws_region'];
        if (!preg_match('/^[a-z0-9-]+$/', $region)) { throw new \InvalidArgumentException('AWS region is invalid.'); }
        $host = 'email.' . $region . '.amazonaws.com'; $uri = '/v2/email/outbound-emails';
        $body = json_encode(['Content' => ['Raw' => ['Data' => base64_encode($mime)]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $amzDate = gmdate('Ymd\THis\Z'); $date = substr($amzDate, 0, 8); $payloadHash = hash('sha256', $body);
        $canonicalHeaders = "content-type:application/json\n" . "host:$host\n" . "x-amz-content-sha256:$payloadHash\n" . "x-amz-date:$amzDate\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        // CanonicalHeaders ends with a newline; SigV4 then requires the field separator newline before SignedHeaders.
        $canonicalRequest = "POST\n$uri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $scope = "$date/$region/ses/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $settings['aws_secret_access_key'], true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true); $kService = hash_hmac('sha256', 'ses', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true); $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $settings['aws_access_key_id'] . "/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
        $headers = "Content-Type: application/json\r\nHost: $host\r\nX-Amz-Content-Sha256: $payloadHash\r\nX-Amz-Date: $amzDate\r\nAuthorization: $authorization\r\n";
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => $this->config['smtp_timeout'] ?? 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents('https://' . $host . $uri, false, $context); $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\\s2\\d\\d\\s/', $statusLine)) {
            $errorData = is_string($response) ? json_decode($response, true) : null;
            $message = is_array($errorData) ? (string) ($errorData['Message'] ?? $errorData['message'] ?? $errorData['__type'] ?? 'SES API request failed.') : ($statusLine ?: 'Could not connect to Amazon SES API over HTTPS.');
            throw new \RuntimeException($message);
        }
        $result = json_decode($response, true); return (string) ($result['MessageId'] ?? 'accepted-by-ses');
    }

    private function sendResendApi(array $args, string $from, array $settings, array $attachments): string
    {
        $payload = ['from' => trim((string) ($settings['from_name'] ?? '')) !== '' ? trim((string) $settings['from_name']) . ' <' . $from . '>' : $from, 'to' => $args['to'], 'subject' => (string) $args['subject']];
        foreach (['cc', 'bcc'] as $field) { if (!empty($args[$field])) { $payload[$field] = $args[$field]; } }
        if (!empty($args['reply_to'])) { $payload['reply_to'] = (string) $args['reply_to']; }
        if (isset($args['html'])) { $payload['html'] = (string) $args['html']; }
        if (isset($args['text'])) { $payload['text'] = (string) $args['text']; }
        if ($attachments) { $payload['attachments'] = array_map(static fn(array $a): array => ['filename' => $a['name'], 'content' => base64_encode($a['data'])], $attachments); }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = "Authorization: Bearer {$settings['resend_api_key']}\r\nContent-Type: application/json\r\n";
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => $this->config['smtp_timeout'] ?? 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $context); $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            $errorData = is_string($response) ? json_decode($response, true) : null;
            $message = is_array($errorData) ? (string) ($errorData['message'] ?? $errorData['name'] ?? 'Resend API request failed.') : ($statusLine ?: 'Could not connect to Resend API over HTTPS.');
            throw new \RuntimeException($message);
        }
        $result = json_decode($response, true); return (string) ($result['id'] ?? 'accepted-by-resend');
    }

    private function addresses(mixed $value, string $field, bool $required = false): array
    {
        if (!is_array($value) || ($required && $value === [])) { throw new \InvalidArgumentException($field . ' must be a non-empty array of email addresses.'); }
        $out = [];
        foreach ($value as $address) {
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException($field . ' contains an invalid email address.'); }
            $out[] = $address;
        }
        return array_values(array_unique($out));
    }

    private function attachments(mixed $items): array
    {
        if (!is_array($items)) { throw new \InvalidArgumentException('attachments must be an array.'); }
        if (count($items) > $this->config['max_attachments']) { throw new \InvalidArgumentException('Too many attachments.'); }
        $result = []; $total = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['filename'], $item['content_base64'])) { throw new \InvalidArgumentException('Each attachment needs filename and content_base64.'); }
            $name = basename(str_replace('\\', '/', (string) $item['filename']));
            $data = base64_decode((string) $item['content_base64'], true);
            if ($name === '' || $name === '.' || $name === '..' || $data === false) { throw new \InvalidArgumentException('Attachment filename or base64 content is invalid.'); }
            $size = strlen($data); $total += $size;
            if ($size > $this->config['max_attachment'] || $total > $this->config['max_total_attachment']) { throw new \InvalidArgumentException('Attachment size limit exceeded.'); }
            $mime = (string) ($item['mime_type'] ?? 'application/octet-stream');
            if (!preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~', $mime)) { throw new \InvalidArgumentException('Attachment mime_type is invalid.'); }
            $result[] = ['name' => $name, 'data' => $data, 'mime' => $mime];
        }
        return $result;
    }

    private function consumeRateLimit(string $domain, array $settings): void
    {
        $directory = $this->root . '/storage/limits';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Rate limit storage is unavailable.'); }
        $file = $directory . '/' . hash('sha256', $domain) . '.json';
        $handle = fopen($file, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Rate limit storage is unavailable.'); }
        try {
            $raw = stream_get_contents($handle); $state = json_decode($raw ?: '{}', true);
            if (!is_array($state)) { $state = []; }
            $now = time(); $state['hour'] = array_values(array_filter($state['hour'] ?? [], fn($t) => is_int($t) && $t > $now - 3600));
            $state['day'] = array_values(array_filter($state['day'] ?? [], fn($t) => is_int($t) && $t > $now - 86400));
            if (count($state['hour']) >= max(1, (int) ($settings['hourly_limit'] ?? 40))) { throw new \RuntimeException('Hourly sending limit reached for this domain.'); }
            if (count($state['day']) >= max(1, (int) ($settings['daily_limit'] ?? 200))) { throw new \RuntimeException('Daily sending limit reached for this domain.'); }
            $state['hour'][] = $now; $state['day'][] = $now;
            rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state)); fflush($handle);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
