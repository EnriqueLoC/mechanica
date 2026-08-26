<?php

namespace App\Services\WhatsApp;

use App\Models\Workshop;

interface WhatsAppMessageSender
{
    public function sendText(
        Workshop $workshop,
        string $phone,
        string $message
    ): void;
}
