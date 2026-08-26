<?php

use App\Http\Controllers\Api\AppointmentAvailabilityController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('api.webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->name('api.webhooks.whatsapp.handle');

Route::get('/workshops/{workshop}/availability', AppointmentAvailabilityController::class)
    ->name('api.workshops.availability');

Route::get('/workshops/{workshop}/appointments', [AppointmentController::class, 'index'])
    ->name('api.workshops.appointments.index');

Route::post('/workshops/{workshop}/appointments', [AppointmentController::class, 'store'])
    ->name('api.workshops.appointments.store');

Route::post('/workshops/{workshop}/appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])
    ->name('api.workshops.appointments.confirm')
    ->scopeBindings();

Route::post('/workshops/{workshop}/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
    ->name('api.workshops.appointments.cancel')
    ->scopeBindings();

Route::post('/workshops/{workshop}/appointments/{appointment}/complete', [AppointmentController::class, 'complete'])
    ->name('api.workshops.appointments.complete')
    ->scopeBindings();

Route::post('/workshops/{workshop}/conversation/messages', [ConversationController::class, 'storeMessage'])
    ->name('api.workshops.conversation.messages.store');
