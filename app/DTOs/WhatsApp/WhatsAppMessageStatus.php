<?php

namespace App\DTOs\WhatsApp;

readonly class WhatsAppMessageStatus
{
    /**
     * @param  array<int|string, mixed>|null  $errors
     */
    public function __construct(
        public string $whatsappMessageId,
        public string $status,
        public int $timestamp,
        public string $recipientPhone,
        public string $phoneNumberId,
        public ?array $errors = null,
    ) {}
}
