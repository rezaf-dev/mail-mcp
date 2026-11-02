<?php
declare(strict_types=1);
namespace MailMcp;
final class RpcException extends \RuntimeException { public function __construct(public readonly int $rpcCode, string $message) { parent::__construct($message); } }
