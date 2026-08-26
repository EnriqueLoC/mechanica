<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConversationMessageRequest;
use App\Models\Workshop;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    /**
     * Endpoint temporal para interactuar con el motor conversacional de un taller.
     */
    public function storeMessage(
        ConversationMessageRequest $request,
        Workshop $workshop,
        ConversationService $conversationService
    ): JsonResponse {
        $phone = $request->validated('phone');
        $message = $request->validated('message');
        $whatsappMessageId = $request->validated('whatsapp_message_id');

        $result = $conversationService->processMessage($workshop, $phone, $message, $whatsappMessageId);

        if ($result->duplicate) {
            return response()->json([
                'message' => $result->response,
                'state' => $result->state->value,
                'duplicate' => true,
            ], 200);
        }

        return response()->json([
            'message' => $result->response,
            'state' => $result->state->value,
        ]);
    }
}
