<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\ConversationState;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\ConversationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ConversationService $conversationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversationService = app(ConversationService::class);
    }

    protected function createWorkshop(string $timezone = 'America/Chihuahua', int $capacity = 1): Workshop
    {
        $workshop = Workshop::factory()->create([
            'timezone' => $timezone,
            'appointment_capacity' => $capacity,
        ]);

        // Crear horarios de lunes a viernes (1 a 5) y sábado (6)
        for ($day = 1; $day <= 6; $day++) {
            BusinessHour::factory()->create([
                'workshop_id' => $workshop->id,
                'day_of_week' => $day,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'closed' => false,
            ]);
        }

        // Domingo cerrado
        BusinessHour::factory()->create([
            'workshop_id' => $workshop->id,
            'day_of_week' => 0,
            'opening_time' => '08:00:00',
            'closing_time' => '18:00:00',
            'closed' => true,
        ]);

        return $workshop;
    }

    protected function createCustomer(Workshop $workshop, string $phone = '6141234567'): Customer
    {
        return Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => $phone,
        ]);
    }

    protected function createVehicle(Customer $customer, array $attributes = []): Vehicle
    {
        return Vehicle::factory()
            ->forCustomer($customer)
            ->create($attributes);
    }

    protected function createService(Workshop $workshop, array $attributes = []): Service
    {
        return Service::factory()->create(array_merge([
            'workshop_id' => $workshop->id,
            'active' => true,
            'estimated_minutes' => 60,
            'price' => 500.00,
        ], $attributes));
    }

    public function test_1_new_conversation_starts_in_idle(): void
    {
        $workshop = $this->createWorkshop();
        $phone = '6141112233';

        $conversation = $this->conversationService->getOrCreateConversation($workshop, $phone);

        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
    }

    public function test_2_hola_starts_appointment_flow(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop, ['name' => 'Afinación']);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $response);
    }

    public function test_3_active_services_are_shown(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop, ['name' => 'Afinación', 'price' => 694.21, 'active' => true]);
        $this->createService($workshop, ['name' => 'Cambio de aceite', 'price' => 450.00, 'active' => true]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        $this->assertStringContainsString('1. Afinación - $694.21', $response);
        $this->assertStringContainsString('2. Cambio de aceite - $450.00', $response);
    }

    public function test_4_inactive_services_are_not_shown(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop, ['name' => 'Afinación Activa', 'active' => true]);
        $this->createService($workshop, ['name' => 'Servicio Inactivo', 'active' => false]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        $this->assertStringContainsString('Afinación Activa', $response);
        $this->assertStringNotContainsString('Servicio Inactivo', $response);
    }

    public function test_5_valid_service_selection_saves_service_id_and_prompts_for_vehicle(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service1 = $this->createService($workshop, ['name' => 'Afinación']);
        $service2 = $this->createService($workshop, ['name' => 'Frenos']);
        $vehicle = $this->createVehicle($customer, ['brand' => 'Mazda', 'model' => '3', 'year' => 2023]);

        // Iniciar
        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        // Seleccionar servicio 2
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '2');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingVehicle, $conversation->state);
        $this->assertSame($service2->id, $conversation->context['service_id']);
        $this->assertStringContainsString('¿Para qué vehículo deseas la cita?', $response);
        $this->assertStringContainsString('1. Mazda 3 2023', $response);
    }

    public function test_6_invalid_service_selection_does_not_change_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop, ['name' => 'Afinación']);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        // Opción inválida 99
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '99');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Por favor selecciona una opción válida', $response);
    }

    public function test_7_shows_customer_vehicles(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle1 = $this->createVehicle($customer, ['brand' => 'Mazda', 'model' => '3', 'year' => 2023, 'plates' => 'UFC-9210']);
        $vehicle2 = $this->createVehicle($customer, ['brand' => 'Nissan', 'model' => 'Versa', 'year' => 2020, 'plates' => 'ABC-1234']);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $this->assertStringContainsString('1. Mazda 3 2023 - UFC-9210', $response);
        $this->assertStringContainsString('2. Nissan Versa 2020 - ABC-1234', $response);
    }

    public function test_8_other_customers_vehicles_are_not_shown(): void
    {
        $workshop = $this->createWorkshop();
        $customer1 = $this->createCustomer($workshop, '6141112233');
        $customer2 = $this->createCustomer($workshop, '6149998877');
        $service = $this->createService($workshop);

        $vehicle1 = $this->createVehicle($customer1, ['brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2022]);
        $vehicle2 = $this->createVehicle($customer2, ['brand' => 'Ford', 'model' => 'Mustang', 'year' => 2021]);

        $this->conversationService->processMessage($workshop, $customer1->phone, 'hola');
        $response = $this->conversationService->processMessage($workshop, $customer1->phone, '1');

        $this->assertStringContainsString('Toyota Corolla 2022', $response);
        $this->assertStringNotContainsString('Ford Mustang', $response);
    }

    public function test_9_valid_vehicle_selection_saves_vehicle_id_and_asks_for_date(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer, ['brand' => 'Mazda', 'model' => '3', 'year' => 2023]);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertSame($vehicle->id, $conversation->context['vehicle_id']);
        $this->assertStringContainsString('¿Para qué fecha deseas la cita?', $response);
    }

    public function test_10_valid_date_changes_to_selecting_time_and_shows_slots(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        // Flujo hasta fecha
        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        // Fecha futura miércoles
        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::WEDNESDAY)->addWeek();
        $dateFormatted = $futureDate->format('d/m/Y');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, $dateFormatted);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingTime, $conversation->state);
        $this->assertSame($futureDate->format('Y-m-d'), $conversation->context['date']);
        $this->assertStringContainsString('Estos horarios están disponibles:', $response);
        $this->assertStringContainsString('1. 08:00', $response);
    }

    public function test_11_invalid_date_remains_in_selecting_date(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'fecha-invalida');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertStringContainsString('La fecha ingresada no es válida', $response);
    }

    public function test_12_past_date_is_rejected(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $pastDate = CarbonImmutable::now($workshop->timezone)->subDay()->format('d/m/Y');
        $response = $this->conversationService->processMessage($workshop, $customer->phone, $pastDate);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertStringContainsString('La fecha no puede ser en el pasado', $response);
    }

    public function test_13_date_without_availability_remains_in_selecting_date(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        // Domingo (cerrado)
        $sunday = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::SUNDAY)->addWeek();
        $response = $this->conversationService->processMessage($workshop, $customer->phone, $sunday->format('d/m/Y'));

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertStringContainsString('Para esa fecha no tenemos horarios disponibles', $response);
    }

    public function test_14_date_with_availability_shows_slots(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();
        $response = $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));

        $this->assertStringContainsString('1. 08:00', $response);
        $this->assertStringContainsString('2. 08:30', $response);
    }

    public function test_15_valid_time_selection_saves_time(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));

        // Seleccionar slot 1 (08:00)
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame('08:00', $conversation->context['time']);
    }

    public function test_16_selecting_time_changes_state_to_confirming_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::ConfirmingAppointment, $conversation->state);
    }

    public function test_17_confirming_shows_summary(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop, ['name' => 'Afinación Mayor']);
        $vehicle = $this->createVehicle($customer, ['brand' => 'Mazda', 'model' => '3', 'year' => 2023]);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $this->assertStringContainsString('Tu cita sería:', $response);
        $this->assertStringContainsString('Servicio: Afinación Mayor', $response);
        $this->assertStringContainsString('Vehículo: Mazda 3 2023', $response);
        $this->assertStringContainsString('Fecha: '.$futureDate->format('d/m/Y'), $response);
        $this->assertStringContainsString('Hora: 08:00', $response);
        $this->assertStringContainsString('¿Deseas confirmar?', $response);
    }

    public function test_18_negative_response_does_not_create_appointment_and_resets(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'no');

        $this->assertDatabaseCount('appointments', 0);
        $this->assertStringContainsString('De acuerdo, no se creó ninguna cita', $response);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
    }

    public function test_19_20_21_positive_response_creates_appointment_clears_context_and_resets_to_idle(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'sí');

        $this->assertStringContainsString('Tu cita quedó confirmada', $response);
        $this->assertDatabaseCount('appointments', 1);

        $appointment = Appointment::first();
        $this->assertSame($workshop->id, $appointment->workshop_id);
        $this->assertSame($customer->id, $appointment->customer_id);
        $this->assertSame($vehicle->id, $appointment->vehicle_id);
        $this->assertSame($service->id, $appointment->service_id);
        $this->assertSame($futureDate->format('Y-m-d'), $appointment->appointment_date->format('Y-m-d'));
        $this->assertSame('08:00:00', $appointment->appointment_time);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
    }

    public function test_22_slot_becoming_occupied_before_confirmation_fails_gracefully_and_prompts_again(): void
    {
        $workshop = $this->createWorkshop(capacity: 1);
        $customer1 = $this->createCustomer($workshop, '6141112233');
        $customer2 = $this->createCustomer($workshop, '6149998877');
        $service = $this->createService($workshop);
        $vehicle1 = $this->createVehicle($customer1);
        $vehicle2 = $this->createVehicle($customer2);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        // Customer 1 llega hasta la confirmación para el slot 08:00
        $this->conversationService->processMessage($workshop, $customer1->phone, 'hola');
        $this->conversationService->processMessage($workshop, $customer1->phone, '1');
        $this->conversationService->processMessage($workshop, $customer1->phone, '1');
        $this->conversationService->processMessage($workshop, $customer1->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer1->phone, '1');

        // Mientras tanto, se reserva externamente ese mismo slot de las 08:00
        Appointment::factory()->create([
            'workshop_id' => $workshop->id,
            'customer_id' => $customer2->id,
            'vehicle_id' => $vehicle2->id,
            'service_id' => $service->id,
            'appointment_date' => $futureDate->format('Y-m-d'),
            'appointment_time' => '08:00:00',
            'status' => AppointmentStatus::Pending,
        ]);

        // Customer 1 intenta confirmar
        $response = $this->conversationService->processMessage($workshop, $customer1->phone, 'sí');

        $this->assertStringContainsString('ese horario acaba de ocuparse', $response);
        $this->assertStringContainsString('Te mostraré nuevamente los horarios disponibles', $response);
        $this->assertStringNotContainsString('1. 08:00', $response);
        $this->assertStringContainsString('1. 09:00', $response);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer1->phone)->first();
        $this->assertSame(ConversationState::SelectingTime, $conversation->state);
    }

    public function test_23_and_24_incoming_and_outgoing_messages_are_stored(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $messages = $conversation->messages()->orderBy('id')->get();

        $this->assertCount(2, $messages);
        $this->assertSame('incoming', $messages[0]->direction);
        $this->assertSame('hola', $messages[0]->content);
        $this->assertSame('outgoing', $messages[1]->direction);
        $this->assertSame((string) $response, $messages[1]->content);
    }

    public function test_25_conversations_with_same_phone_in_different_workshops_are_independent(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();
        $phone = '6141112233';

        $customerA = $this->createCustomer($workshopA, $phone);
        $customerB = $this->createCustomer($workshopB, $phone);

        $serviceA = $this->createService($workshopA, ['name' => 'Servicio Taller A']);
        $serviceB = $this->createService($workshopB, ['name' => 'Servicio Taller B']);

        $responseA = $this->conversationService->processMessage($workshopA, $phone, 'hola');
        $responseB = $this->conversationService->processMessage($workshopB, $phone, 'hola');

        $convA = Conversation::where('workshop_id', $workshopA->id)->where('phone', $phone)->first();
        $convB = Conversation::where('workshop_id', $workshopB->id)->where('phone', $phone)->first();

        $this->assertNotEquals($convA->id, $convB->id);
        $this->assertSame($workshopA->id, $convA->workshop_id);
        $this->assertSame($workshopB->id, $convB->workshop_id);
        $this->assertStringContainsString('Servicio Taller A', $responseA);
        $this->assertStringContainsString('Servicio Taller B', $responseB);
    }

    public function test_26_customer_belongs_to_correct_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();
        $phone = '6141112233';

        // Cliente registrado solo en Workshop A
        $customerA = $this->createCustomer($workshopA, $phone);
        $this->createService($workshopA);
        $this->createService($workshopB);

        // Mensaje en Workshop A reconoce al cliente
        $responseA = $this->conversationService->processMessage($workshopA, $phone, 'hola');
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $responseA);

        // Mensaje en Workshop B indica que no está registrado
        $responseB = $this->conversationService->processMessage($workshopB, $phone, 'hola');
        $this->assertStringContainsString('no encontramos un registro con tu número', $responseB);
    }

    public function test_27_conversation_cannot_use_vehicle_of_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();
        $customerA = $this->createCustomer($workshopA, '6141112233');
        $customerB = $this->createCustomer($workshopB, '6149998877');

        $serviceA = $this->createService($workshopA);
        $vehicleB = $this->createVehicle($customerB, ['brand' => 'Ferrari', 'model' => 'F40']);

        $this->conversationService->processMessage($workshopA, $customerA->phone, 'hola');
        $response = $this->conversationService->processMessage($workshopA, $customerA->phone, '1');

        $this->assertStringNotContainsString('Ferrari', $response);
    }

    public function test_28_conversation_cannot_use_service_of_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();
        $customerA = $this->createCustomer($workshopA, '6141112233');

        $serviceB = $this->createService($workshopB, ['name' => 'Servicio Exclusivo Taller B']);
        $serviceA = $this->createService($workshopA, ['name' => 'Servicio Taller A']);

        $response = $this->conversationService->processMessage($workshopA, $customerA->phone, 'hola');

        $this->assertStringContainsString('Servicio Taller A', $response);
        $this->assertStringNotContainsString('Servicio Exclusivo Taller B', $response);
    }

    public function test_29_unknown_message_in_idle_does_not_start_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, '¿Cuánto cuesta?');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString("Hola. Puedo ayudarte a agendar una cita. Escribe 'cita' para comenzar.", $response);
    }

    public function test_30_cita_starts_appointment_flow(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cita');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $response);
    }

    public function test_31_quiero_una_cita_starts_appointment_flow(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'Quiero una Cita');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $response);
    }

    public function test_32_s_i_uppercase_works_as_confirmation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'SI');

        $this->assertStringContainsString('Tu cita quedó confirmada', $response);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_33_si_accented_works_as_confirmation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'Sí');

        $this->assertStringContainsString('Tu cita quedó confirmada', $response);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_34_s_i_mixed_accent_and_spaces_works_as_confirmation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, ' sÍ ');

        $this->assertStringContainsString('Tu cita quedó confirmada', $response);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_35_cancelar_works_in_selecting_service(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $response);
    }

    public function test_36_cancelar_works_in_selecting_vehicle(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $response);
    }

    public function test_37_cancelar_works_in_selecting_date(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $response);
    }

    public function test_38_cancelar_works_in_selecting_time(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $response);
    }

    public function test_39_cancelar_works_in_confirming_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $response);
    }

    public function test_40_cancelar_does_not_create_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, '1');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'));
        $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_41_reset_conversation_clears_state_and_context(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingTime,
            'context' => ['service_id' => 1, 'vehicle_id' => 2],
            'last_message_at' => now()->subHour(),
        ]);

        $reset = $this->conversationService->resetConversation($conversation);

        $this->assertSame(ConversationState::Idle, $reset->state);
        $this->assertEquals([], $reset->context);
        $this->assertTrue($reset->last_message_at->isToday());
    }

    public function test_42_incomplete_context_in_selecting_vehicle_resets_conversation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createVehicle($customer);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingVehicle,
            'context' => [], // Falta service_id
        ]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('Ocurrió un problema con los datos de tu reserva', $response);
    }

    public function test_43_incomplete_context_in_selecting_date_resets_conversation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingDate,
            'context' => ['service_id' => $service->id], // Falta vehicle_id
        ]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, '26/08/2026');

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('Ocurrió un problema con los datos de tu reserva', $response);
    }

    public function test_44_incomplete_context_in_selecting_time_resets_conversation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingTime,
            'context' => ['service_id' => $service->id, 'vehicle_id' => $vehicle->id], // Falta date
        ]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('Ocurrió un problema con los datos de tu reserva', $response);
    }

    public function test_45_incomplete_context_in_confirming_appointment_resets_conversation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::ConfirmingAppointment,
            'context' => [
                'service_id' => $service->id,
                'vehicle_id' => $vehicle->id,
                'date' => '2026-08-26',
                // Falta time
            ],
        ]);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, 'si');

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString('Ocurrió un problema con los datos de tu reserva', $response);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_46_expired_conversation_resets_flow_on_new_message(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingService,
            'context' => ['service_id' => $service->id],
            'last_message_at' => now()->subMinutes(35), // Excedió 30 minutos
        ]);

        // Mensaje que no es intención ("1")
        $response = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertStringContainsString("Hola. Puedo ayudarte a agendar una cita. Escribe 'cita' para comenzar.", $response);

        // Ahora mensaje válido ("cita") inicia nuevo flujo
        $response2 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $response2);
    }

    public function test_47_message_history_is_preserved_after_expiration(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $conversation = Conversation::factory()->forCustomer($customer)->create([
            'state' => ConversationState::SelectingService,
            'context' => ['service_id' => $service->id],
            'last_message_at' => now()->subMinutes(60),
        ]);

        $conversation->messages()->create([
            'direction' => 'incoming',
            'type' => 'text',
            'content' => 'Mensaje previo',
            'sent_at' => now()->subMinutes(60),
        ]);

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola');

        $conversation->refresh();
        $this->assertGreaterThanOrEqual(3, $conversation->messages()->count());
    }

    public function test_48_empty_message_does_not_modify_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);

        $response = $this->conversationService->processMessage($workshop, $customer->phone, '   ');

        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertStringContainsString('Por favor envía un mensaje válido', $response);
    }

    public function test_49_phone_is_processed_through_normalization_mechanism(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141234567');
        $this->createService($workshop);

        $this->assertSame('6141234567', $this->conversationService->normalizePhone('+52 614 123 4567'));
        $this->assertSame('6141234567', $this->conversationService->normalizePhone('+5216141234567'));
        $this->assertSame('6141234567', $this->conversationService->normalizePhone('(614) 123-4567'));

        // Mensaje enviado con formato internacional encuentra al cliente registrado con 10 dígitos
        $response = $this->conversationService->processMessage($workshop, '+52 614 123 4567', 'cita');

        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $response);
    }

    public function test_50_original_messages_preserve_uppercase_and_accents_in_messages_table(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $rawInput = '¡¡QuIéRo UnA CíTa PoR FaVoR!!';
        $this->conversationService->processMessage($workshop, $customer->phone, $rawInput);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $incomingMessage = $conversation->messages()->where('direction', 'incoming')->first();

        $this->assertNotNull($incomingMessage);
        $this->assertSame($rawInput, $incomingMessage->content);
    }

    public function test_51_cancelar_command_works_regardless_of_casing_and_accents(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        // Prueba con " CaNcElAr "
        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $res1 = $this->conversationService->processMessage($workshop, $customer->phone, ' CaNcElAr ');
        $conv1 = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conv1->state);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $res1);

        // Prueba con "SALIR"
        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $res2 = $this->conversationService->processMessage($workshop, $customer->phone, 'SALIR');
        $conv1->refresh();
        $this->assertSame(ConversationState::Idle, $conv1->state);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $res2);

        // Prueba con "Reiniciar"
        $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $res3 = $this->conversationService->processMessage($workshop, $customer->phone, 'Reiniciar');
        $conv1->refresh();
        $this->assertSame(ConversationState::Idle, $conv1->state);
        $this->assertStringContainsString('De acuerdo, cancelé el proceso actual', $res3);
    }

    public function test_52_message_with_whatsapp_message_id_is_processed_normally(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $result = $this->conversationService->processMessage(
            $workshop,
            $customer->phone,
            'cita',
            'wamid.test.001'
        );

        $this->assertFalse($result->duplicate);
        $this->assertSame(ConversationState::SelectingService, $result->state);
        $this->assertStringContainsString('Claro. ¿Qué servicio necesitas?', $result->response);
    }

    public function test_53_incoming_message_is_stored_with_whatsapp_message_id(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $this->conversationService->processMessage(
            $workshop,
            $customer->phone,
            'cita',
            'wamid.test.002'
        );

        $incomingMessage = Message::where('whatsapp_message_id', 'wamid.test.002')->first();
        $this->assertNotNull($incomingMessage);
        $this->assertSame('incoming', $incomingMessage->direction);
        $this->assertSame('cita', $incomingMessage->content);

        $outgoingMessage = Message::where('conversation_id', $incomingMessage->conversation_id)
            ->where('direction', 'outgoing')
            ->first();
        $this->assertNotNull($outgoingMessage);
        $this->assertNull($outgoingMessage->whatsapp_message_id);
    }

    public function test_54_same_whatsapp_message_id_sent_second_time_does_not_create_another_message(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.003');
        $this->assertSame(2, Message::count()); // 1 incoming, 1 outgoing

        // Segundo envío con el mismo whatsapp_message_id
        $result = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.003');

        $this->assertTrue($result->duplicate);
        $this->assertSame(2, Message::count()); // Siguen siendo 2
    }

    public function test_55_second_request_with_same_whatsapp_message_id_does_not_change_conversation_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.004');
        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);

        // Segundo request con el mismo ID pero intentando cambiar estado con "1"
        $result = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.test.004');

        $conversation->refresh();
        $this->assertTrue($result->duplicate);
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
    }

    public function test_56_second_request_with_same_whatsapp_message_id_does_not_modify_context(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.005a');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.test.005b');

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(['service_id' => $service->id], $conversation->context);

        // Repetir el ID del primer mensaje con otro contenido
        $result = $this->conversationService->processMessage($workshop, $customer->phone, 'cancelar', 'wamid.test.005a');

        $conversation->refresh();
        $this->assertTrue($result->duplicate);
        $this->assertSame(['service_id' => $service->id], $conversation->context);
    }

    public function test_57_second_request_with_same_whatsapp_message_id_does_not_create_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.flow.01');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.flow.02');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.flow.03');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'), 'wamid.flow.04');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.flow.05');

        // Confirmar cita
        $result1 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.flow.06');
        $this->assertFalse($result1->duplicate);
        $this->assertSame(1, Appointment::count());

        // Reintentar confirmación con el mismo whatsapp_message_id
        $result2 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.flow.06');
        $this->assertTrue($result2->duplicate);
        $this->assertSame(1, Appointment::count());
    }

    public function test_58_second_request_is_identified_as_duplicate(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $result1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.007');
        $this->assertFalse($result1->duplicate);

        $result2 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.test.007');
        $this->assertTrue($result2->duplicate);
    }

    public function test_59_two_different_messages_with_different_whatsapp_ids_are_processed_normally(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $result1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.id.001');
        $result2 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.id.002');

        $this->assertFalse($result1->duplicate);
        $this->assertFalse($result2->duplicate);
        $this->assertSame(ConversationState::SelectingVehicle, $result2->state);
    }

    public function test_60_message_without_whatsapp_message_id_maintains_current_behavior(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $result = $this->conversationService->processMessage($workshop, $customer->phone, 'cita');

        $this->assertFalse($result->duplicate);
        $incoming = Message::where('direction', 'incoming')->first();
        $this->assertNotNull($incoming);
        $this->assertNull($incoming->whatsapp_message_id);
    }

    public function test_61_two_messages_without_whatsapp_message_id_can_coexist_without_conflict(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $result1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita');
        $result2 = $this->conversationService->processMessage($workshop, $customer->phone, '1');

        $this->assertFalse($result1->duplicate);
        $this->assertFalse($result2->duplicate);
        $this->assertSame(2, Message::where('direction', 'incoming')->whereNull('whatsapp_message_id')->count());
    }

    public function test_62_critical_full_flow_idempotency_scenario(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop, ['name' => 'Afinación']);
        $vehicle = $this->createVehicle($customer, ['brand' => 'Mazda', 'model' => '3']);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        // 1. Iniciar conversación
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.001');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingService, $r1->state);

        // 2. Seleccionar servicio
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.002');
        $this->assertFalse($r2->duplicate);
        $this->assertSame(ConversationState::SelectingVehicle, $r2->state);

        // 3. Seleccionar vehículo
        $r3 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.003');
        $this->assertFalse($r3->duplicate);
        $this->assertSame(ConversationState::SelectingDate, $r3->state);

        // 4. Seleccionar fecha
        $r4 = $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'), 'wamid.004');
        $this->assertFalse($r4->duplicate);
        $this->assertSame(ConversationState::SelectingTime, $r4->state);

        // 5. Seleccionar horario
        $r5 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.005');
        $this->assertFalse($r5->duplicate);
        $this->assertSame(ConversationState::ConfirmingAppointment, $r5->state);

        // 6. Confirmar
        $r6 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.006');
        $this->assertFalse($r6->duplicate);
        $this->assertSame(ConversationState::Idle, $r6->state);

        // Verificaciones del estado final
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());
        $this->assertSame(1, Appointment::count());

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);

        // Repetir el mensaje wamid.006
        $repeatResult = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.006');

        $this->assertTrue($repeatResult->duplicate);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());

        $conversation->refresh();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
    }

    public function test_63_race_condition_unique_constraint_violation_is_handled_as_duplicate(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $conversation = $this->conversationService->getOrCreateConversation($workshop, $customer->phone);

        // Crear mensaje en segundo plano con wamid.race.001 simulando inserción concurrente
        Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.race.001',
            'direction' => 'incoming',
            'type' => 'text',
            'content' => 'hola',
            'status' => 'received',
            'sent_at' => now(),
        ]);

        // Al procesar el mensaje con el mismo ID, se detecta o captura la duplicidad y retorna duplicate = true
        $result = $this->conversationService->processMessage($workshop, $customer->phone, 'hola', 'wamid.race.001');

        $this->assertTrue($result->duplicate);
        $this->assertSame(1, Message::where('whatsapp_message_id', 'wamid.race.001')->count());
    }

    public function test_64_duplicate_message_after_creating_appointment_with_wamid_confirmation_001(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.c.01');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.c.02');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.c.03');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'), 'wamid.c.04');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.c.05');

        // Confirmar con ID específico wamid.confirmation.001
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.confirmation.001');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::Idle, $r1->state);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());

        // Reenviar exactamente el mismo mensaje y ID
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.confirmation.001');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(6, Message::where('direction', 'incoming')->count());
        $this->assertSame(6, Message::where('direction', 'outgoing')->count());

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::Idle, $conversation->state);
        $this->assertEquals([], $conversation->context);
    }

    public function test_65_duplicate_in_selecting_service_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        // Primer mensaje entra en selecting_service
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.state.service.01');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingService, $r1->state);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $incomingCount = Message::where('direction', 'incoming')->count();
        $outgoingCount = Message::where('direction', 'outgoing')->count();

        // Repetir el mismo ID
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.service.01');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(ConversationState::SelectingService, $r2->state);

        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingService, $conversation->state);
        $this->assertEquals([], $conversation->context);
        $this->assertSame($incomingCount, Message::where('direction', 'incoming')->count());
        $this->assertSame($outgoingCount, Message::where('direction', 'outgoing')->count());
    }

    public function test_66_duplicate_in_selecting_vehicle_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.state.veh.01');
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.veh.02');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingVehicle, $r1->state);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingVehicle, $conversation->state);
        $this->assertSame(['service_id' => $service->id], $conversation->context);
        $incomingCount = Message::where('direction', 'incoming')->count();
        $outgoingCount = Message::where('direction', 'outgoing')->count();

        // Repetir el mismo ID
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.veh.02');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(ConversationState::SelectingVehicle, $r2->state);

        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingVehicle, $conversation->state);
        $this->assertSame(['service_id' => $service->id], $conversation->context);
        $this->assertSame($incomingCount, Message::where('direction', 'incoming')->count());
        $this->assertSame($outgoingCount, Message::where('direction', 'outgoing')->count());
    }

    public function test_67_duplicate_in_selecting_date_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.state.date.01');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.date.02');
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.date.03');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingDate, $r1->state);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertSame(['service_id' => $service->id, 'vehicle_id' => $vehicle->id], $conversation->context);
        $incomingCount = Message::where('direction', 'incoming')->count();
        $outgoingCount = Message::where('direction', 'outgoing')->count();

        // Repetir el ID del mensaje 03
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, '26/08/2026', 'wamid.state.date.03');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(ConversationState::SelectingDate, $r2->state);

        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingDate, $conversation->state);
        $this->assertSame(['service_id' => $service->id, 'vehicle_id' => $vehicle->id], $conversation->context);
        $this->assertSame($incomingCount, Message::where('direction', 'incoming')->count());
        $this->assertSame($outgoingCount, Message::where('direction', 'outgoing')->count());
    }

    public function test_68_duplicate_in_selecting_time_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.state.time.01');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.time.02');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.time.03');
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'), 'wamid.state.time.04');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingTime, $r1->state);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::SelectingTime, $conversation->state);
        $this->assertSame([
            'service_id' => $service->id,
            'vehicle_id' => $vehicle->id,
            'date' => $futureDate->format('Y-m-d'),
        ], $conversation->context);
        $incomingCount = Message::where('direction', 'incoming')->count();
        $outgoingCount = Message::where('direction', 'outgoing')->count();

        // Repetir el ID del mensaje 04
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.time.04');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(ConversationState::SelectingTime, $r2->state);

        $conversation->refresh();
        $this->assertSame(ConversationState::SelectingTime, $conversation->state);
        $this->assertSame($incomingCount, Message::where('direction', 'incoming')->count());
        $this->assertSame($outgoingCount, Message::where('direction', 'outgoing')->count());
    }

    public function test_69_duplicate_in_confirming_appointment_state(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $service = $this->createService($workshop);
        $vehicle = $this->createVehicle($customer);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.state.conf.01');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.conf.02');
        $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.conf.03');
        $this->conversationService->processMessage($workshop, $customer->phone, $futureDate->format('d/m/Y'), 'wamid.state.conf.04');
        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, '1', 'wamid.state.conf.05');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::ConfirmingAppointment, $r1->state);

        $conversation = Conversation::where('workshop_id', $workshop->id)->where('phone', $customer->phone)->first();
        $this->assertSame(ConversationState::ConfirmingAppointment, $conversation->state);
        $incomingCount = Message::where('direction', 'incoming')->count();
        $outgoingCount = Message::where('direction', 'outgoing')->count();

        // Repetir el ID del mensaje 05
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, 'sí', 'wamid.state.conf.05');
        $this->assertTrue($r2->duplicate);
        $this->assertSame(ConversationState::ConfirmingAppointment, $r2->state);

        $conversation->refresh();
        $this->assertSame(ConversationState::ConfirmingAppointment, $conversation->state);
        $this->assertSame($incomingCount, Message::where('direction', 'incoming')->count());
        $this->assertSame($outgoingCount, Message::where('direction', 'outgoing')->count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_70_different_ids_with_same_phone_and_content_are_both_processed(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $this->createService($workshop);

        $r1 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.diff.001');
        $this->assertFalse($r1->duplicate);
        $this->assertSame(ConversationState::SelectingService, $r1->state);

        // Enviar otro mensaje con mismo phone y mismo texto "cita" pero con ID diferente wamid.diff.002
        $r2 = $this->conversationService->processMessage($workshop, $customer->phone, 'cita', 'wamid.diff.002');
        $this->assertFalse($r2->duplicate);
        // Como estaba en SelectingService y envió "cita" (no es un número de servicio), responde que no es opción válida
        $this->assertStringContainsString('Por favor selecciona una opción válida.', $r2->response);
        $this->assertSame(2, Message::where('direction', 'incoming')->count());
    }

    public function test_71_existing_message_without_conversation_throws_runtime_exception(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop, '6141112233');
        $conversation = $this->conversationService->getOrCreateConversation($workshop, $customer->phone);

        Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.orphan.001',
            'direction' => 'incoming',
            'type' => 'text',
            'content' => 'test',
            'status' => 'received',
            'sent_at' => now(),
        ]);

        // Simular inconsistencia donde el mensaje existe pero no tiene conversación asociada
        Message::retrieved(function (Message $message) {
            if ($message->whatsapp_message_id === 'wamid.orphan.001') {
                $message->setRelation('conversation', null);
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tiene una conversación asociada');

        $this->conversationService->processMessage($workshop, $customer->phone, 'hola', 'wamid.orphan.001');
    }

    public function test_72_existing_message_from_different_workshop_throws_runtime_exception(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $customerA = $this->createCustomer($workshopA, '6141112233');
        $customerB = $this->createCustomer($workshopB, '6141112233');

        $this->createService($workshopA);
        $this->createService($workshopB);

        // Procesar mensaje en taller A con ID wamid.shared.001
        $r1 = $this->conversationService->processMessage($workshopA, $customerA->phone, 'cita', 'wamid.shared.001');
        $this->assertFalse($r1->duplicate);

        // Intentar procesar en taller B con el mismo ID wamid.shared.001
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ya pertenece a otro taller');

        $this->conversationService->processMessage($workshopB, $customerB->phone, 'cita', 'wamid.shared.001');
    }
}
