<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function createWorkshop(
        string $timezone = 'America/Chihuahua',
        int $capacity = 1
    ): Workshop {
        return Workshop::factory()->create([
            'timezone' => $timezone,
            'appointment_capacity' => $capacity,
        ]);
    }

    protected function createBusinessHours(
        Workshop $workshop,
        bool $sundayClosed = true
    ): void {
        for ($day = 1; $day <= 6; $day++) {
            BusinessHour::create([
                'workshop_id' => $workshop->id,
                'day_of_week' => $day,
                'opening_time' => '08:00',
                'closing_time' => '18:00',
                'closed' => false,
            ]);
        }

        BusinessHour::create([
            'workshop_id' => $workshop->id,
            'day_of_week' => 0,
            'opening_time' => $sundayClosed ? null : '08:00',
            'closing_time' => $sundayClosed ? null : '18:00',
            'closed' => $sundayClosed,
        ]);
    }

    protected function createCustomer(
        Workshop $workshop
    ): Customer {
        return Customer::factory()->create([
            'workshop_id' => $workshop->id,
        ]);
    }

    protected function createVehicle(
        Customer $customer
    ): Vehicle {
        return Vehicle::factory()
            ->forCustomer($customer)
            ->create();
    }

    protected function createService(
        Workshop $workshop,
        string $name = 'Afinación',
        int $duration = 60,
        bool $active = true
    ): Service {
        return Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => $name,
            'estimated_minutes' => $duration,
            'active' => $active,
        ]);
    }

    public function test_it_creates_an_appointment_successfully(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, duration: 120);

        $payload = [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('appointments', [
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'appointment_date' => '2026-08-26 00:00:00',
            'appointment_time' => '10:00:00',
            'status' => 'pending',
        ]);
    }

    public function test_it_returns_expected_json_structure_and_http_201(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, name: 'Cambio de Aceite', duration: 60);

        $payload = [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'workshop_id',
                    'customer' => [
                        'id',
                        'name',
                        'phone',
                        'email',
                    ],
                    'vehicle' => [
                        'id',
                        'brand',
                        'model',
                        'year',
                        'plates',
                    ],
                    'service' => [
                        'id',
                        'name',
                        'estimated_minutes',
                        'price',
                    ],
                    'appointment_date',
                    'appointment_time',
                    'status',
                    'customer_notes',
                    'internal_notes',
                    'confirmed_at',
                    'cancelled_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.workshop_id', $workshop->id)
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.appointment_date', '2026-08-26')
            ->assertJsonPath('data.appointment_time', '10:00:00')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_it_rejects_customer_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);

        $customer = $this->createCustomer($workshopB);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshopA);

        $payload = [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshopA->id}/appointments", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_vehicle_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);

        $customerA = $this->createCustomer($workshopA);
        $customerB = $this->createCustomer($workshopB);
        $vehicleOfB = $this->createVehicle($customerB);
        $service = $this->createService($workshopA);

        $payload = [
            'customer_id' => $customerA->id,
            'vehicle_id' => $vehicleOfB->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshopA->id}/appointments", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_it_rejects_vehicle_from_another_customer(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customerA = $this->createCustomer($workshop);
        $customerB = $this->createCustomer($workshop);
        $vehicleOfB = $this->createVehicle($customerB);
        $service = $this->createService($workshop);

        $payload = [
            'customer_id' => $customerA->id,
            'vehicle_id' => $vehicleOfB->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle']);
    }

    public function test_it_rejects_service_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);

        $customer = $this->createCustomer($workshopA);
        $vehicle = $this->createVehicle($customer);
        $serviceOfB = $this->createService($workshopB);

        $payload = [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $serviceOfB->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshopA->id}/appointments", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_it_rejects_inactive_service(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, active: false);

        $payload = [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ];

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service']);
    }

    public function test_it_rejects_invalid_date(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $responseInvalid = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => 'not-a-date',
            'time' => '10:00',
        ]);

        $responseInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $responseFeb31 = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-02-31',
            'time' => '10:00',
        ]);

        $responseFeb31->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_it_rejects_invalid_time(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $response25 = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '25:00',
        ]);

        $response25->assertStatus(422)
            ->assertJsonValidationErrors(['time']);

        $responseWithSeconds = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00:00',
        ]);

        $responseWithSeconds->assertStatus(422)
            ->assertJsonValidationErrors(['time']);

        $responseInvalid = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => 'invalid-time',
        ]);

        $responseInvalid->assertStatus(422)
            ->assertJsonValidationErrors(['time']);
    }

    public function test_it_rejects_appointment_when_workshop_is_closed(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop, sundayClosed: true);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        // 2026-08-30 is Sunday
        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-30',
            'time' => '10:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_date']);
    }

    public function test_it_rejects_appointment_outside_business_hours(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, duration: 60);

        // 17:30 with 60 min duration exceeds 18:00 closing time
        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '17:30',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_time']);
    }

    public function test_it_rejects_appointment_when_time_is_already_booked(): void
    {
        $workshop = $this->createWorkshop(capacity: 1);
        $this->createBusinessHours($workshop);

        $customerA = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);

        $customerB = $this->createCustomer($workshop);
        $vehicleB = $this->createVehicle($customerB);

        $service = $this->createService($workshop, duration: 120);

        // Existing appointment 10:00 - 12:00
        $appointmentService = app(AppointmentService::class);
        $appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicleA,
            $service,
            CarbonImmutable::create(2026, 8, 26, 0, 0, 0, $workshop->timezone),
            '10:00:00'
        );

        // Try booking at 11:00 (overlapping)
        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customerB->id,
            'vehicle_id' => $vehicleB->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '11:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_time']);
    }

    public function test_it_rejects_appointment_when_workshop_capacity_is_exceeded(): void
    {
        $workshop = $this->createWorkshop(capacity: 2);
        $this->createBusinessHours($workshop);

        $service = $this->createService($workshop, duration: 60);

        $customerA = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);

        $customerB = $this->createCustomer($workshop);
        $vehicleB = $this->createVehicle($customerB);

        $customerC = $this->createCustomer($workshop);
        $vehicleC = $this->createVehicle($customerC);

        $appointmentService = app(AppointmentService::class);
        $date = CarbonImmutable::create(2026, 8, 26, 0, 0, 0, $workshop->timezone);

        $appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicleA,
            $service,
            $date,
            '10:00:00'
        );

        $appointmentService->createAppointment(
            $workshop,
            $customerB,
            $vehicleB,
            $service,
            $date,
            '10:30:00'
        );

        // 3rd appointment during overlap (10:15) exceeds capacity of 2
        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customerC->id,
            'vehicle_id' => $vehicleC->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:15',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment_time']);
    }

    public function test_it_allows_consecutive_appointments(): void
    {
        $workshop = $this->createWorkshop(capacity: 1);
        $this->createBusinessHours($workshop);

        $customerA = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);

        $customerB = $this->createCustomer($workshop);
        $vehicleB = $this->createVehicle($customerB);

        $service = $this->createService($workshop, duration: 120);

        // First appointment: 08:00 - 10:00
        $appointmentService = app(AppointmentService::class);
        $appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicleA,
            $service,
            CarbonImmutable::create(2026, 8, 26, 0, 0, 0, $workshop->timezone),
            '08:00:00'
        );

        // Second appointment starting at exactly 10:00
        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => $customerB->id,
            'vehicle_id' => $vehicleB->id,
            'service_id' => $service->id,
            'date' => '2026-08-26',
            'time' => '10:00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.appointment_time', '10:00:00');

        $this->assertSame(2, Appointment::count());
    }

    public function test_it_validates_required_fields(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'customer_id',
                'vehicle_id',
                'service_id',
                'date',
                'time',
            ]);
    }

    public function test_it_returns_422_when_entities_do_not_exist(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments", [
            'customer_id' => 999999,
            'vehicle_id' => 999999,
            'service_id' => 999999,
            'date' => '2026-08-26',
            'time' => '10:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'customer_id',
                'vehicle_id',
                'service_id',
            ]);
    }

    public function test_it_returns_404_when_workshop_does_not_exist(): void
    {
        $response = $this->postJson('/api/workshops/999999/appointments', [
            'customer_id' => 1,
            'vehicle_id' => 1,
            'service_id' => 1,
            'date' => '2026-08-26',
            'time' => '10:00',
        ]);

        $response->assertNotFound();
    }
}
