<?php

namespace App\Exceptions\WhatsApp;

use RuntimeException;
use Throwable;

class WhatsAppApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?int $metaErrorCode = null,
        public readonly ?string $metaErrorType = null,
        public readonly ?string $fbtraceId = null,
        public readonly ?string $errorDetails = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
