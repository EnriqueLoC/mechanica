<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use App\Services\ConversationService;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppMessageStatusProcessor;
use App\Services\WhatsApp\WhatsAppWebhookParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Handle the verification request from Meta WhatsApp Cloud API.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode')
            ?? $request->query('hub.mode')
            ?? $request->input('hub_mode')
            ?? $request->input('hub.mode');

        $token = $request->query('hub_verify_token')
            ?? $request->query('hub.verify_token')
            ?? $request->input('hub_verify_token')
            ?? $request->input('hub.verify_token');

        $challenge = $request->query('hub_challenge')
            ?? $request->query('hub.challenge')
            ?? $request->input('hub_challenge')
            ?? $request->input('hub.challenge');

        $expectedToken = config('whatsapp.webhook_verify_token');

        if (
            $mode === 'subscribe'
            && ! empty($expectedToken)
            && is_string($token)
            && hash_equals((string) $expectedToken, $token)
        ) {
            Log::info('WhatsApp webhook verification succeeded.');

            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed.');

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming webhook notifications from Meta WhatsApp Cloud API.
     */
    public function handle(
        Request $request,
        WhatsAppWebhookParser $parser,
        ConversationService $conversationService,
        WhatsAppMessageSender $messageSender,
        WhatsAppMessageStatusProcessor $statusProcessor,
    ): JsonResponse {
        $appSecret = config('whatsapp.app_secret');

        if (! empty($appSecret)) {
            $signature = $request->header('X-Hub-Signature-256');

            if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
                Log::warning('WhatsApp webhook signature missing or malformed.');

                return response()->json(['error' => 'Invalid signature'], 403);
            }

            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

            if (! hash_equals($expectedSignature, $signature)) {
                Log::warning('WhatsApp webhook signature verification failed.');

                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        Log::info('WhatsApp webhook event received.');

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $incomingMessages = $parser->extractTextMessages($payload);
        $incomingStatuses = $parser->extractStatuses($payload);

        if (empty($incomingMessages) && empty($incomingStatuses)) {
            Log::info('WhatsApp webhook event contained no actionable text messages or statuses.');

            return response()->json(['status' => 'ignored'], 200);
        }

        foreach ($incomingMessages as $incomingMessage) {
            $workshop = Workshop::query()
                ->where('whatsapp_phone_number_id', $incomingMessage->phoneNumberId)
                ->first();

            if (! $workshop) {
                Log::warning("Workshop not found for WhatsApp phone_number_id [{$incomingMessage->phoneNumberId}].");

                continue;
            }

            Log::info("Processing WhatsApp message for workshop [ID: {$workshop->id}].");

            $result = $conversationService->processMessage(
                $workshop,
                $incomingMessage->phone,
                $incomingMessage->message,
                $incomingMessage->whatsappMessageId
            );

            if ($result->duplicate) {
                Log::info("Duplicate WhatsApp message [ID: {$incomingMessage->whatsappMessageId}] ignored.");
            } else {
                Log::info("WhatsApp message processed successfully. Next state: {$result->state->value}.");

                if (! empty($result->response)) {
                    try {
                        $messageSender->sendText(
                            $workshop,
                            $incomingMessage->phone,
                            $result->response
                        );
                    } catch (\Throwable $e) {
                        Log::error("Failed to send WhatsApp response to [{$incomingMessage->phone}]: {$e->getMessage()}", [
                            'workshop_id' => $workshop->id,
                            'phone' => $incomingMessage->phone,
                        ]);
                    }
                }
            }
        }

        foreach ($incomingStatuses as $status) {
            $workshop = Workshop::query()
                ->where('whatsapp_phone_number_id', $status->phoneNumberId)
                ->first();

            if (! $workshop) {
                Log::warning("Workshop not found for WhatsApp status phone_number_id [{$status->phoneNumberId}].");

                continue;
            }

            Log::info("Processing WhatsApp status update for workshop [ID: {$workshop->id}].");

            $statusProcessor->process($workshop, $status);
        }

        return response()->json(['status' => 'success'], 200);
    }
}
