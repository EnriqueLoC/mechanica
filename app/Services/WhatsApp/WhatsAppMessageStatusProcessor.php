<?php

namespace App\Services\WhatsApp;

use App\DTOs\WhatsApp\WhatsAppMessageStatus;
use App\Models\Message;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageStatusProcessor
{
    private const STATUS_RANKS = [
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    /**
     * Process an incoming WhatsApp message status update.
     */
    public function process(Workshop $workshop, WhatsAppMessageStatus $status): ?Message
    {
        $message = Message::query()
            ->with('conversation')
            ->where('whatsapp_message_id', $status->whatsappMessageId)
            ->first();

        if (! $message) {
            Log::info("WhatsApp message status received for unknown message [ID: {$status->whatsappMessageId}].");

            return null;
        }

        if (! $message->conversation || $message->conversation->workshop_id !== $workshop->id) {
            Log::warning("WhatsApp message status ignored because message [ID: {$status->whatsappMessageId}] belongs to another workshop.");

            return null;
        }

        $timestamp = CarbonImmutable::createFromTimestamp($status->timestamp);
        $incomingStatus = strtolower(trim($status->status));
        $currentStatus = $message->status !== null ? strtolower(trim($message->status)) : null;

        switch ($incomingStatus) {
            case 'sent':
                $message->sent_at = $this->updateTimestampMonotonically($message->sent_at, $timestamp);
                if ($this->canAdvanceTo($currentStatus, 'sent')) {
                    $message->status = 'sent';
                }
                break;

            case 'delivered':
                $message->delivered_at = $this->updateTimestampMonotonically($message->delivered_at, $timestamp);
                if ($this->canAdvanceTo($currentStatus, 'delivered')) {
                    $message->status = 'delivered';
                }
                break;

            case 'read':
                $message->read_at = $this->updateTimestampMonotonically($message->read_at, $timestamp);
                if ($this->canAdvanceTo($currentStatus, 'read')) {
                    $message->status = 'read';
                }
                break;

            case 'failed':
                if ($this->canAdvanceTo($currentStatus, 'failed')) {
                    $message->status = 'failed';

                    $metadata = is_array($message->metadata) ? $message->metadata : [];
                    $whatsappMeta = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];

                    $whatsappMeta['status_error'] = [
                        'errors' => $status->errors,
                        'failed_at' => $timestamp->toIso8601String(),
                        'recipient_id' => $status->recipientPhone,
                    ];

                    $metadata['whatsapp'] = $whatsappMeta;
                    $message->metadata = $metadata;

                    Log::error("WhatsApp message [ID: {$status->whatsappMessageId}] failed.", [
                        'workshop_id' => $workshop->id,
                        'whatsapp_message_id' => $status->whatsappMessageId,
                        'recipient_phone' => $status->recipientPhone,
                        'errors' => $status->errors,
                    ]);
                } else {
                    Log::info("WhatsApp message [ID: {$status->whatsappMessageId}] failed status ignored because current status is [{$currentStatus}].");
                }
                break;

            default:
                Log::info("WhatsApp message status [{$incomingStatus}] unrecognized for message [ID: {$status->whatsappMessageId}].");

                return null;
        }

        $message->save();

        Log::info('WhatsApp message status processed.', [
            'workshop_id' => $workshop->id,
            'message_id' => $message->id,
            'whatsapp_message_id' => $status->whatsappMessageId,
            'status' => $incomingStatus,
        ]);

        return $message;
    }

    /**
     * Determine if the status can transition to the new status without regressing.
     */
    private function canAdvanceTo(?string $currentStatus, string $targetStatus): bool
    {
        if ($currentStatus === 'failed') {
            return $targetStatus === 'failed';
        }

        if ($targetStatus === 'failed') {
            return $currentStatus !== 'read';
        }

        if ($currentStatus === null) {
            return true;
        }

        $currentRank = self::STATUS_RANKS[$currentStatus] ?? 0;
        $targetRank = self::STATUS_RANKS[$targetStatus] ?? 0;

        return $targetRank >= $currentRank;
    }

    /**
     * Monotonically update timestamp: if null use incoming, otherwise keep max(existing, incoming).
     */
    private function updateTimestampMonotonically(?\DateTimeInterface $existing, CarbonImmutable $incoming): CarbonImmutable
    {
        if ($existing === null) {
            return $incoming;
        }

        $existingImmutable = CarbonImmutable::instance($existing);

        return $existingImmutable->greaterThan($incoming) ? $existingImmutable : $incoming;
    }
}
