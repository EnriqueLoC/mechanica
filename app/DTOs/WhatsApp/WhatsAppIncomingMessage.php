<?php

namespace App\DTOs\WhatsApp;

readonly class WhatsAppIncomingMessage
{
    public function __construct(
        public string $phone,
        public string $message,
        public string $whatsappMessageId,
        public string $phoneNumberId,
        public string $messageType = 'text',
    ) {}
}
