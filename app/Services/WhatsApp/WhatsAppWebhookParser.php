<?php

namespace App\Services\WhatsApp;

use App\DTOs\WhatsApp\WhatsAppIncomingMessage;
use App\DTOs\WhatsApp\WhatsAppMessageStatus;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, WhatsAppIncomingMessage>
     */
    public function extractTextMessages(array $payload): array
    {
        $messages = [];

        if (! isset($payload['entry']) || ! is_array($payload['entry'])) {
            return $messages;
        }

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry) || ! isset($entry['changes']) || ! is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (! is_array($change) || ! isset($change['value']) || ! is_array($change['value'])) {
                    continue;
                }

                $value = $change['value'];

                $phoneNumberId = null;
                if (isset($value['metadata']['phone_number_id'])) {
                    $phoneNumberId = (string) $value['metadata']['phone_number_id'];
                }

                if (! isset($value['messages']) || ! is_array($value['messages'])) {
                    continue;
                }

                if (empty($phoneNumberId)) {
                    Log::warning('WhatsApp webhook message missing phone_number_id in metadata.');

                    continue;
                }

                foreach ($value['messages'] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $type = $message['type'] ?? null;
                    if ($type !== 'text') {
                        Log::info("WhatsApp webhook received non-text message of type: [{$type}].");

                        continue;
                    }

                    $body = $message['text']['body'] ?? null;
                    $from = $message['from'] ?? null;
                    $messageId = $message['id'] ?? null;

                    if (! is_string($body) || trim($body) === '' || ! is_string($from) || ! is_string($messageId)) {
                        Log::warning('WhatsApp webhook text message missing required fields (body, from, or id).');

                        continue;
                    }

                    $messages[] = new WhatsAppIncomingMessage(
                        phone: (string) $from,
                        message: $body,
                        whatsappMessageId: $messageId,
                        phoneNumberId: $phoneNumberId,
                        messageType: 'text',
                    );
                }
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, WhatsAppMessageStatus>
     */
    public function extractStatuses(array $payload): array
    {
        $statuses = [];

        if (! isset($payload['entry']) || ! is_array($payload['entry'])) {
            return $statuses;
        }

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry) || ! isset($entry['changes']) || ! is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (! is_array($change) || ! isset($change['value']) || ! is_array($change['value'])) {
                    continue;
                }

                $value = $change['value'];

                $phoneNumberId = null;
                if (isset($value['metadata']['phone_number_id'])) {
                    $phoneNumberId = (string) $value['metadata']['phone_number_id'];
                }

                if (! isset($value['statuses']) || ! is_array($value['statuses'])) {
                    continue;
                }

                if (empty($phoneNumberId)) {
                    Log::warning('WhatsApp webhook status missing phone_number_id in metadata.');

                    continue;
                }

                foreach ($value['statuses'] as $statusItem) {
                    if (! is_array($statusItem)) {
                        continue;
                    }

                    $id = $statusItem['id'] ?? null;
                    $status = $statusItem['status'] ?? null;
                    $timestamp = $statusItem['timestamp'] ?? null;
                    $recipientId = $statusItem['recipient_id'] ?? null;
                    $errors = isset($statusItem['errors']) && is_array($statusItem['errors']) ? $statusItem['errors'] : null;

                    if (! is_string($id) || ! is_string($status) || ! is_numeric($timestamp) || ! is_string($recipientId)) {
                        Log::warning('WhatsApp webhook status missing required fields (id, status, timestamp, or recipient_id).');

                        continue;
                    }

                    $statuses[] = new WhatsAppMessageStatus(
                        whatsappMessageId: $id,
                        status: $status,
                        timestamp: (int) $timestamp,
                        recipientPhone: (string) $recipientId,
                        phoneNumberId: $phoneNumberId,
                        errors: $errors,
                    );
                }
            }
        }

        return $statuses;
    }
}
