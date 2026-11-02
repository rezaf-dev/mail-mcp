<?php
declare(strict_types=1);

namespace MailMcp;

use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class Mailer
{
    public function __construct(private readonly string $root, private readonly array $config) {}

    public function send(array $args): string
    {
        $from = filter_var((string) ($args['from'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$from) { throw new \InvalidArgumentException('from must be a valid email address.'); }
        $domain = strtolower(substr(strrchr($from, '@') ?: '', 1));
        $settings = $this->config['domains'][$domain] ?? null;
        if (!is_array($settings)) { throw new \InvalidArgumentException('The sender domain is not configured.'); }
        foreach (['smtp_host', 'smtp_username', 'smtp_password'] as $key) {
            if (empty($settings[$key])) { throw new \RuntimeException('SMTP configuration is incomplete for this domain.'); }
        }
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
            $mail->isSMTP(); $mail->Host = (string) $settings['smtp_host']; $mail->Port = (int) ($settings['smtp_port'] ?? 587);
            $mail->SMTPAuth = true; $mail->Username = (string) $settings['smtp_username']; $mail->Password = (string) $settings['smtp_password'];
            $encryption = strtolower((string) ($settings['smtp_encryption'] ?? 'tls'));
            $mail->SMTPSecure = match ($encryption) { 'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS, 'none' => '', default => PHPMailer::ENCRYPTION_STARTTLS };
            $mail->CharSet = 'UTF-8'; $mail->setFrom($from, (string) ($settings['from_name'] ?? ''));
            foreach ($to as $address) { $mail->addAddress($address); }
            foreach ($cc as $address) { $mail->addCC($address); }
            foreach ($bcc as $address) { $mail->addBCC($address); }
            if (!empty($args['reply_to'])) { $mail->addReplyTo((string) $args['reply_to']); }
            $mail->Subject = $subject; $mail->Body = (string) ($args['html'] ?? $args['text'] ?? '');
            if (isset($args['text'], $args['html'])) { $mail->AltBody = (string) $args['text']; } elseif (isset($args['text'])) { $mail->isHTML(false); }
            foreach ($attachments as $attachment) { $mail->addStringAttachment($attachment['data'], $attachment['name'], PHPMailer::ENCODING_BASE64, $attachment['mime']); }
            $mail->send();
            return (string) $mail->getLastMessageID();
        } catch (Throwable $e) {
            throw new \RuntimeException('SMTP delivery failed. Check the SMTP host, credentials, sender verification, and TLS settings.');
        }
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
