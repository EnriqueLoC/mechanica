<?php

namespace App\Services;

use App\DTOs\ConversationResult;
use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(
        protected AppointmentService $appointmentService
    ) {}

    /**
     * Procesa un mensaje entrante y retorna el resultado para el cliente.
     */
    public function processMessage(
        Workshop $workshop,
        string $phone,
        string $message,
        ?string $whatsappMessageId = null
    ): ConversationResult {
        // Detección inicial de mensaje duplicado por whatsapp_message_id
        if ($whatsappMessageId !== null) {
            $existingMessage = Message::query()
                ->where('whatsapp_message_id', $whatsappMessageId)
                ->first();

            if ($existingMessage) {
                if (! $existingMessage->conversation) {
                    throw new \RuntimeException("El mensaje existente [ID: {$existingMessage->id}] no tiene una conversación asociada.");
                }

                if ($existingMessage->conversation->workshop_id !== $workshop->id) {
                    throw new \RuntimeException("El mensaje de WhatsApp [{$whatsappMessageId}] ya pertenece a otro taller.");
                }

                return new ConversationResult(
                    response: 'Mensaje duplicado.',
                    state: $existingMessage->conversation->state,
                    duplicate: true
                );
            }
        }

        $normalizedPhone = $this->normalizePhone($phone);
        $conversation = $this->getOrCreateConversation($workshop, $normalizedPhone);

        // 1. Guardar mensaje entrante original con whatsapp_message_id y manejo de concurrencia
        try {
            $this->storeMessage($conversation, 'incoming', $message, $whatsappMessageId);
        } catch (UniqueConstraintViolationException $e) {
            $existingMessage = Message::query()
                ->where('whatsapp_message_id', $whatsappMessageId)
                ->first();

            if (! $existingMessage) {
                throw $e;
            }

            if (! $existingMessage->conversation) {
                throw new \RuntimeException("El mensaje existente [ID: {$existingMessage->id}] no tiene una conversación asociada.");
            }

            if ($existingMessage->conversation->workshop_id !== $workshop->id) {
                throw new \RuntimeException("El mensaje de WhatsApp [{$whatsappMessageId}] ya pertenece a otro taller.");
            }

            return new ConversationResult(
                response: 'Mensaje duplicado.',
                state: $existingMessage->conversation->state,
                duplicate: true
            );
        }

        // 2. Proteger contra mensajes vacíos: no modificar estado ni contexto
        if (trim($message) === '') {
            $response = 'Por favor envía un mensaje válido.';
            $this->storeMessage($conversation, 'outgoing', $response);

            return new ConversationResult(
                response: $response,
                state: $conversation->state ?? ConversationState::Idle,
                duplicate: false
            );
        }

        // 3. Comprobar expiración por inactividad
        $expirationMinutes = (int) config('conversation.expiration_minutes', 30);
        if ($conversation->last_message_at !== null && $conversation->state !== ConversationState::Idle) {
            if ($conversation->last_message_at->addMinutes($expirationMinutes)->isPast()) {
                $this->resetConversation($conversation);
            }
        }

        // 4. Normalizar texto del mensaje para procesamiento y comparación
        $normalizedMessage = $this->normalizeText($message);

        // 5. Comandos globales (cancelar, salir, reiniciar)
        if ($this->isGlobalCommand($normalizedMessage)) {
            $this->resetConversation($conversation);
            $response = "De acuerdo, cancelé el proceso actual. Si deseas agendar una cita, escribe 'cita'.";
            $this->storeMessage($conversation, 'outgoing', $response);

            return new ConversationResult(
                response: $response,
                state: $conversation->state ?? ConversationState::Idle,
                duplicate: false
            );
        }

        // 6. Procesar flujo según estado
        $response = $this->handleState($conversation, $workshop, $normalizedPhone, $message, $normalizedMessage);

        // 7. Guardar mensaje saliente
        $this->storeMessage($conversation, 'outgoing', $response);

        // 8. Actualizar timestamp de última actividad
        $conversation->update(['last_message_at' => now()]);

        return new ConversationResult(
            response: $response,
            state: $conversation->state ?? ConversationState::Idle,
            duplicate: false
        );
    }

    /**
     * Normaliza un número telefónico extrayendo dígitos y resolviendo prefijos comunes.
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Si viene con prefijo mexicano WhatsApp +521 (13 dígitos), remover '521'
        if (str_starts_with($digits, '521') && strlen($digits) === 13) {
            return substr($digits, 3);
        }

        // Si viene con código de país mexicano +52 (12 dígitos), remover '52'
        if (str_starts_with($digits, '52') && strlen($digits) === 12) {
            return substr($digits, 2);
        }

        return $digits !== '' ? $digits : trim($phone);
    }

    /**
     * Normaliza texto para comparaciones (trim, minúsculas, sin acentos).
     */
    public function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = mb_strtolower($text, 'UTF-8');

        $replacements = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
        ];

        $text = strtr($text, $replacements);

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Determina si el texto corresponde a un comando global de cancelación/salida.
     */
    public function isGlobalCommand(string $normalized): bool
    {
        return in_array($normalized, [
            'cancelar',
            'salir',
            'reiniciar',
        ], true);
    }

    /**
     * Determina si el texto corresponde a una intención conocida para iniciar una cita.
     */
    public function isAppointmentIntent(string $normalized): bool
    {
        $intents = [
            'hola',
            'buenas',
            'buenos dias',
            'buenas tardes',
            'buenas noches',
            'cita',
            'quiero una cita',
            'agendar',
            'agendar cita',
            'quiero agendar',
            'necesito una cita',
        ];

        return in_array($normalized, $intents, true);
    }

    /**
     * Reinicia la conversación al estado inicial idle y vacía el contexto.
     */
    public function resetConversation(Conversation $conversation): Conversation
    {
        $conversation->state = ConversationState::Idle;
        $conversation->context = [];
        $conversation->last_message_at = now();
        $conversation->save();

        return $conversation;
    }

    /**
     * Obtiene o crea la conversación asociada al taller y teléfono.
     */
    public function getOrCreateConversation(Workshop $workshop, string $phone): Conversation
    {
        $normalizedPhone = $this->normalizePhone($phone);

        $conversation = Conversation::query()
            ->where('workshop_id', $workshop->id)
            ->where(function ($q) use ($normalizedPhone, $phone) {
                $q->where('phone', $normalizedPhone)
                    ->orWhere('phone', $phone);
            })
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'workshop_id' => $workshop->id,
                'phone' => $normalizedPhone,
                'state' => ConversationState::Idle,
                'context' => [],
                'last_message_at' => now(),
            ]);
        }

        $customer = Customer::query()
            ->where('workshop_id', $workshop->id)
            ->where(function ($q) use ($normalizedPhone, $phone) {
                $q->where('phone', $normalizedPhone)
                    ->orWhere('phone', $phone);
            })
            ->first();

        if ($customer && $conversation->customer_id !== $customer->id) {
            $conversation->customer_id = $customer->id;
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * Guarda un mensaje en la conversación.
     */
    protected function storeMessage(
        Conversation $conversation,
        string $direction,
        string $content,
        ?string $whatsappMessageId = null
    ): Message {
        return $conversation->messages()->create([
            'direction' => $direction,
            'whatsapp_message_id' => $whatsappMessageId,
            'type' => 'text',
            'content' => $content,
            'status' => $direction === 'incoming' ? 'received' : 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Manejador central de estados conversacionales.
     */
    protected function handleState(
        Conversation $conversation,
        Workshop $workshop,
        string $phone,
        string $message,
        string $normalizedMessage
    ): string {
        $customer = $conversation->customer;
        if (! $customer) {
            $normalizedPhone = $this->normalizePhone($phone);
            $customer = Customer::query()
                ->where('workshop_id', $workshop->id)
                ->where(function ($q) use ($normalizedPhone, $phone) {
                    $q->where('phone', $normalizedPhone)
                        ->orWhere('phone', $phone);
                })
                ->first();

            if ($customer) {
                $conversation->customer_id = $customer->id;
                $conversation->save();
            }
        }

        if (! $customer) {
            return 'Hola, no encontramos un registro con tu número de teléfono en este taller. Por favor comunícate directamente con nosotros para darte de alta como cliente.';
        }

        $state = $conversation->state ?? ConversationState::Idle;

        return match ($state) {
            ConversationState::Idle => $this->handleIdle($conversation, $workshop, $normalizedMessage),
            ConversationState::SelectingService => $this->handleSelectingService($conversation, $workshop, $customer, $message, $normalizedMessage),
            ConversationState::SelectingVehicle => $this->handleSelectingVehicle($conversation, $workshop, $customer, $message, $normalizedMessage),
            ConversationState::SelectingDate => $this->handleSelectingDate($conversation, $workshop, $customer, $message, $normalizedMessage),
            ConversationState::SelectingTime => $this->handleSelectingTime($conversation, $workshop, $customer, $message, $normalizedMessage),
            ConversationState::ConfirmingAppointment => $this->handleConfirmingAppointment($conversation, $workshop, $customer, $message, $normalizedMessage),
        };
    }

    /**
     * Estado: idle
     */
    protected function handleIdle(
        Conversation $conversation,
        Workshop $workshop,
        string $normalizedMessage
    ): string {
        if (! $this->isAppointmentIntent($normalizedMessage)) {
            return "Hola. Puedo ayudarte a agendar una cita. Escribe 'cita' para comenzar.";
        }

        $services = $this->getActiveServices($workshop);

        if ($services->isEmpty()) {
            return 'Lo sentimos, actualmente no tenemos servicios disponibles.';
        }

        $conversation->state = ConversationState::SelectingService;
        $conversation->context = [];
        $conversation->save();

        $servicesList = $this->formatServicesList($services);

        return "Claro. ¿Qué servicio necesitas?\n\nEstos son nuestros servicios:\n\n{$servicesList}\n\nResponde con el número del servicio.";
    }

    /**
     * Estado: selecting_service
     */
    protected function handleSelectingService(
        Conversation $conversation,
        Workshop $workshop,
        Customer $customer,
        string $message,
        string $normalizedMessage
    ): string {
        $services = $this->getActiveServices($workshop);
        $index = $this->parseIndex($message);

        if ($index === null || $index < 1 || $index > $services->count()) {
            $servicesList = $this->formatServicesList($services);

            return "Por favor selecciona una opción válida.\n\nEstos son nuestros servicios:\n\n{$servicesList}\n\nResponde con el número del servicio.";
        }

        /** @var Service $selectedService */
        $selectedService = $services->get($index - 1);

        $context = $conversation->context ?? [];
        $context['service_id'] = $selectedService->id;

        $vehicles = $this->getCustomerVehicles($workshop, $customer);

        if ($vehicles->isEmpty()) {
            $conversation->state = ConversationState::SelectingVehicle;
            $conversation->context = $context;
            $conversation->save();

            return 'No tienes vehículos registrados. Por favor comunícate con el taller para dar de alta tu vehículo.';
        }

        $conversation->state = ConversationState::SelectingVehicle;
        $conversation->context = $context;
        $conversation->save();

        $vehiclesList = $this->formatVehiclesList($vehicles);

        return "¿Para qué vehículo deseas la cita?\n\n{$vehiclesList}\n\nResponde con el número.";
    }

    /**
     * Estado: selecting_vehicle
     */
    protected function handleSelectingVehicle(
        Conversation $conversation,
        Workshop $workshop,
        Customer $customer,
        string $message,
        string $normalizedMessage
    ): string {
        $context = $conversation->context ?? [];
        $serviceId = $context['service_id'] ?? null;

        $service = $serviceId ? Service::query()
            ->where('workshop_id', $workshop->id)
            ->where('active', true)
            ->find($serviceId) : null;

        if (! $service) {
            $this->resetConversation($conversation);

            return "Ocurrió un problema con los datos de tu reserva. Por favor comencemos de nuevo. Escribe 'cita' para iniciar.";
        }

        $vehicles = $this->getCustomerVehicles($workshop, $customer);

        if ($vehicles->isEmpty()) {
            return 'No tienes vehículos registrados. Por favor comunícate con el taller para dar de alta tu vehículo.';
        }

        $index = $this->parseIndex($message);

        if ($index === null || $index < 1 || $index > $vehicles->count()) {
            $vehiclesList = $this->formatVehiclesList($vehicles);

            return "Por favor selecciona una opción válida.\n\n¿Para qué vehículo deseas la cita?\n\n{$vehiclesList}\n\nResponde con el número.";
        }

        /** @var Vehicle $selectedVehicle */
        $selectedVehicle = $vehicles->get($index - 1);

        $context['vehicle_id'] = $selectedVehicle->id;

        $conversation->state = ConversationState::SelectingDate;
        $conversation->context = $context;
        $conversation->save();

        return "¿Para qué fecha deseas la cita?\n\nPuedes escribirla como:\n26/08/2026";
    }

    /**
     * Estado: selecting_date
     */
    protected function handleSelectingDate(
        Conversation $conversation,
        Workshop $workshop,
        Customer $customer,
        string $message,
        string $normalizedMessage
    ): string {
        $context = $conversation->context ?? [];
        $serviceId = $context['service_id'] ?? null;
        $vehicleId = $context['vehicle_id'] ?? null;

        $service = $serviceId ? Service::query()
            ->where('workshop_id', $workshop->id)
            ->where('active', true)
            ->find($serviceId) : null;

        $vehicle = $vehicleId ? Vehicle::query()
            ->where('workshop_id', $workshop->id)
            ->where('customer_id', $customer->id)
            ->find($vehicleId) : null;

        if (! $service || ! $vehicle) {
            $this->resetConversation($conversation);

            return "Ocurrió un problema con los datos de tu reserva. Por favor comencemos de nuevo. Escribe 'cita' para iniciar.";
        }

        $tz = $workshop->timezone ?: 'America/Chihuahua';
        $parsedDate = $this->parseDate($message, $tz);

        if (! $parsedDate) {
            return "La fecha ingresada no es válida. Por favor escribe una fecha en formato DD/MM/YYYY (ejemplo: 26/08/2026).\n\n¿Para qué fecha deseas la cita?";
        }

        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($parsedDate->lt($today)) {
            return "La fecha no puede ser en el pasado. Por favor elige una fecha a partir de hoy (ejemplo: {$today->format('d/m/Y')}).\n\n¿Para qué fecha deseas la cita?";
        }

        $slots = $this->appointmentService->getAvailableSlots($workshop, $parsedDate, $service)->values();

        if ($slots->isEmpty()) {
            return 'Para esa fecha no tenemos horarios disponibles. ¿Quieres elegir otra fecha?';
        }

        $context['date'] = $parsedDate->format('Y-m-d');

        $conversation->state = ConversationState::SelectingTime;
        $conversation->context = $context;
        $conversation->save();

        $slotsList = $this->formatSlotsList($slots);

        return "Estos horarios están disponibles:\n\n{$slotsList}\n\nResponde con el número.";
    }

    /**
     * Estado: selecting_time
     */
    protected function handleSelectingTime(
        Conversation $conversation,
        Workshop $workshop,
        Customer $customer,
        string $message,
        string $normalizedMessage
    ): string {
        $context = $conversation->context ?? [];
        $serviceId = $context['service_id'] ?? null;
        $vehicleId = $context['vehicle_id'] ?? null;
        $dateString = $context['date'] ?? null;

        $service = $serviceId ? Service::query()
            ->where('workshop_id', $workshop->id)
            ->where('active', true)
            ->find($serviceId) : null;

        $vehicle = $vehicleId ? Vehicle::query()
            ->where('workshop_id', $workshop->id)
            ->where('customer_id', $customer->id)
            ->find($vehicleId) : null;

        if (! $service || ! $vehicle || ! $dateString) {
            $this->resetConversation($conversation);

            return "Ocurrió un problema con los datos de tu reserva. Por favor comencemos de nuevo. Escribe 'cita' para iniciar.";
        }

        $tz = $workshop->timezone ?: 'America/Chihuahua';
        $date = CarbonImmutable::parse($dateString, $tz)->startOfDay();
        $slots = $this->appointmentService->getAvailableSlots($workshop, $date, $service)->values();

        if ($slots->isEmpty()) {
            $conversation->state = ConversationState::SelectingDate;
            unset($context['date']);
            $conversation->context = $context;
            $conversation->save();

            return 'Para esa fecha ya no hay horarios disponibles. Por favor escribe otra fecha (ejemplo: 26/08/2026).';
        }

        $index = $this->parseIndex($message);

        if ($index === null || $index < 1 || $index > $slots->count()) {
            $slotsList = $this->formatSlotsList($slots);

            return "Por favor selecciona una opción válida.\n\nEstos horarios están disponibles:\n\n{$slotsList}\n\nResponde con el número.";
        }

        /** @var CarbonInterface $selectedSlot */
        $selectedSlot = $slots->get($index - 1);
        $timeString = $selectedSlot->format('H:i');

        $context['time'] = $timeString;

        $conversation->state = ConversationState::ConfirmingAppointment;
        $conversation->context = $context;
        $conversation->save();

        $formattedDate = $date->format('d/m/Y');
        $vehicleInfo = trim("{$vehicle->brand} {$vehicle->model} {$vehicle->year}");

        return "Tu cita sería:\n\nServicio: {$service->name}\nVehículo: {$vehicleInfo}\nFecha: {$formattedDate}\nHora: {$timeString}\n\n¿Deseas confirmar?\n\nResponde Sí o No.";
    }

    /**
     * Estado: confirming_appointment
     */
    protected function handleConfirmingAppointment(
        Conversation $conversation,
        Workshop $workshop,
        Customer $customer,
        string $message,
        string $normalizedMessage
    ): string {
        $context = $conversation->context ?? [];
        $serviceId = $context['service_id'] ?? null;
        $vehicleId = $context['vehicle_id'] ?? null;
        $dateString = $context['date'] ?? null;
        $timeString = $context['time'] ?? null;

        $service = $serviceId ? Service::query()
            ->where('workshop_id', $workshop->id)
            ->where('active', true)
            ->find($serviceId) : null;

        $vehicle = $vehicleId ? Vehicle::query()
            ->where('workshop_id', $workshop->id)
            ->where('customer_id', $customer->id)
            ->find($vehicleId) : null;

        if (! $service || ! $vehicle || ! $dateString || ! $timeString) {
            $this->resetConversation($conversation);

            return "Ocurrió un problema con los datos de tu reserva. Por favor comencemos de nuevo. Escribe 'cita' para iniciar.";
        }

        if ($this->isNegativeResponse($normalizedMessage)) {
            $this->resetConversation($conversation);

            return 'De acuerdo, no se creó ninguna cita.';
        }

        if ($this->isAffirmativeResponse($normalizedMessage)) {
            $tz = $workshop->timezone ?: 'America/Chihuahua';
            $date = CarbonImmutable::parse($dateString, $tz)->startOfDay();

            try {
                $this->appointmentService->createAppointment(
                    $workshop,
                    $customer,
                    $vehicle,
                    $service,
                    $date,
                    $timeString
                );

                $this->resetConversation($conversation);

                return 'Tu cita quedó confirmada.';
            } catch (ValidationException) {
                // El horario dejó de estar disponible
                $slots = $this->appointmentService->getAvailableSlots($workshop, $date, $service)->values();

                if ($slots->isEmpty()) {
                    $conversation->state = ConversationState::SelectingDate;
                    unset($context['time'], $context['date']);
                    $conversation->context = $context;
                    $conversation->save();

                    return 'Lo sentimos, ya no hay horarios disponibles para esa fecha. Por favor elige otra fecha.';
                }

                $conversation->state = ConversationState::SelectingTime;
                unset($context['time']);
                $conversation->context = $context;
                $conversation->save();

                $slotsList = $this->formatSlotsList($slots);

                return "Lo siento, ese horario acaba de ocuparse. Te mostraré nuevamente los horarios disponibles:\n\n{$slotsList}\n\nResponde con el número.";
            }
        }

        return "¿Deseas confirmar tu cita?\n\nResponde Sí o No.";
    }

    /**
     * Obtiene los servicios activos del taller ordenados.
     */
    protected function getActiveServices(Workshop $workshop): Collection
    {
        return Service::query()
            ->where('workshop_id', $workshop->id)
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Obtiene los vehículos del cliente en el taller ordenados.
     */
    protected function getCustomerVehicles(Workshop $workshop, Customer $customer): Collection
    {
        return $customer->vehicles()
            ->where('workshop_id', $workshop->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Formatea la lista de servicios.
     */
    protected function formatServicesList(Collection $services): string
    {
        $lines = [];
        $index = 1;

        foreach ($services as $service) {
            $priceFormatted = '$'.number_format((float) $service->price, 2);
            $lines[] = "{$index}. {$service->name} - {$priceFormatted}";
            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Formatea la lista de vehículos.
     */
    protected function formatVehiclesList(Collection $vehicles): string
    {
        $lines = [];
        $index = 1;

        foreach ($vehicles as $vehicle) {
            $plateInfo = $vehicle->plates ? " - {$vehicle->plates}" : '';
            $lines[] = "{$index}. {$vehicle->brand} {$vehicle->model} {$vehicle->year}{$plateInfo}";
            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Formatea la lista de slots de tiempo.
     */
    protected function formatSlotsList(Collection $slots): string
    {
        $lines = [];
        $index = 1;

        foreach ($slots as $slot) {
            $timeFormatted = $slot instanceof CarbonInterface ? $slot->format('H:i') : (string) $slot;
            $lines[] = "{$index}. {$timeFormatted}";
            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Extrae un número entero si el texto es un dígito o número.
     */
    protected function parseIndex(string $message): ?int
    {
        $trimmed = trim($message);

        if (preg_match('/^(\d+)\.?$/', $trimmed, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Parsea una fecha escrita por el usuario.
     */
    protected function parseDate(string $message, string $timezone): ?CarbonImmutable
    {
        $text = trim($message);

        $formats = [
            'd/m/Y',
            'd-m-Y',
            'Y-m-d',
            'j/n/Y',
            'j-n-Y',
            'Y/m/d',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $text, $timezone);
                if ($parsed && $parsed->format($format) === $text) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
            }
        }

        // Intento genérico si coincide con una fecha válida
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $text, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            if (checkdate($month, $day, $year)) {
                return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);
            }
        }

        return null;
    }

    /**
     * Determina si la respuesta es afirmativa.
     */
    protected function isAffirmativeResponse(string $normalized): bool
    {
        return in_array($normalized, [
            'si',
            's',
            'yes',
            'confirmar',
            'confirmo',
            'ok',
            'claro',
            'de acuerdo',
        ], true);
    }

    /**
     * Determina si la respuesta es negativa.
     */
    protected function isNegativeResponse(string $normalized): bool
    {
        return in_array($normalized, [
            'no',
            'n',
            'cancelar',
            'cancelo',
            'rechazar',
        ], true);
    }
}
