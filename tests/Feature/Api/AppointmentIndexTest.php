<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function createWorkshop(
        string $timezone = 'America/Chihuahua'
    ): Workshop {
        return Workshop::factory()->create([
            'timezone' => $timezone,
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

    protected function createAppointment(
        Workshop $workshop,
        ?Customer $customer = null,
        ?Vehicle $vehicle = null,
        ?Service $service = null,
        string $date = '2026-08-26',
        string $time = '10:00:00',
        string $status = 'pending'
    ): Appointment {
        $customer = $customer ?? $this->createCustomer($workshop);
        $vehicle = $vehicle ?? $this->createVehicle($customer);
        $service = $service ?? $this->createService($workshop);

        return Appointment::factory()->create([
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'status' => $status,
        ]);
    }

    public function test_it_returns_workshop_appointments_successfully(): void
    {
        $workshop = $this->createWorkshop();
        $this->createAppointment($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_never_returns_appointments_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createAppointment($workshopA);
        $this->createAppointment($workshopB);

        $response = $this->getJson("/api/workshops/{$workshopA->id}/appointments");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workshop_id', $workshopA->id);
    }

    public function test_it_returns_15_records_per_page_by_default(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        for ($i = 0; $i < 20; $i++) {
            $time = sprintf('%02d:00:00', ($i % 10) + 8);
            $date = sprintf('2026-08-%02d', ($i % 28) + 1);
            $this->createAppointment($workshop, $customer, $vehicle, $service, date: $date, time: $time);
        }

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_it_respects_per_page_parameter(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        for ($i = 0; $i < 10; $i++) {
            $this->createAppointment($workshop, $customer, $vehicle, $service);
        }

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?per_page=5");

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 10);
    }

    public function test_it_rejects_per_page_exceeding_maximum(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?per_page=101");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $responseZero = $this->getJson("/api/workshops/{$workshop->id}/appointments?per_page=0");

        $responseZero->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_it_filters_by_date(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-25');
        $appointmentTarget = $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-26');
        $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-27');

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?date=2026-08-26");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentTarget->id)
            ->assertJsonPath('data.0.appointment_date', '2026-08-26');
    }

    public function test_it_filters_by_status(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $this->createAppointment($workshop, $customer, $vehicle, $service, status: 'pending');
        $appointmentConfirmed = $this->createAppointment($workshop, $customer, $vehicle, $service, status: 'confirmed');
        $this->createAppointment($workshop, $customer, $vehicle, $service, status: 'cancelled');

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?status=confirmed");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentConfirmed->id)
            ->assertJsonPath('data.0.status', 'confirmed');
    }

    public function test_it_filters_by_customer_id(): void
    {
        $workshop = $this->createWorkshop();
        $customerA = $this->createCustomer($workshop);
        $customerB = $this->createCustomer($workshop);

        $this->createAppointment($workshop, customer: $customerA);
        $appointmentB = $this->createAppointment($workshop, customer: $customerB);

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?customer_id={$customerB->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentB->id)
            ->assertJsonPath('data.0.customer.id', $customerB->id);
    }

    public function test_it_filters_by_vehicle_id(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customer);
        $vehicleB = $this->createVehicle($customer);

        $this->createAppointment($workshop, $customer, vehicle: $vehicleA);
        $appointmentB = $this->createAppointment($workshop, $customer, vehicle: $vehicleB);

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?vehicle_id={$vehicleB->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentB->id)
            ->assertJsonPath('data.0.vehicle.id', $vehicleB->id);
    }

    public function test_it_combines_multiple_filters(): void
    {
        $workshop = $this->createWorkshop();
        $customerA = $this->createCustomer($workshop);
        $customerB = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);
        $vehicleB = $this->createVehicle($customerB);
        $service = $this->createService($workshop);

        $this->createAppointment($workshop, $customerA, $vehicleA, $service, date: '2026-08-26', status: 'pending');
        $this->createAppointment($workshop, $customerB, $vehicleB, $service, date: '2026-08-27', status: 'confirmed');
        $matchingAppointment = $this->createAppointment($workshop, $customerA, $vehicleA, $service, date: '2026-08-26', status: 'confirmed');

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?date=2026-08-26&status=confirmed&customer_id={$customerA->id}&vehicle_id={$vehicleA->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingAppointment->id);
    }

    public function test_it_rejects_customer_belonging_to_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $customerOfB = $this->createCustomer($workshopB);

        $response = $this->getJson("/api/workshops/{$workshopA->id}/appointments?customer_id={$customerOfB->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_vehicle_belonging_to_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $customerOfB = $this->createCustomer($workshopB);
        $vehicleOfB = $this->createVehicle($customerOfB);

        $response = $this->getJson("/api/workshops/{$workshopA->id}/appointments?vehicle_id={$vehicleOfB->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    public function test_it_rejects_invalid_date(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?date=not-a-date");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $responseFeb31 = $this->getJson("/api/workshops/{$workshop->id}/appointments?date=2026-02-31");

        $responseFeb31->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_it_rejects_invalid_status(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments?status=invalid_status");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_returns_404_when_workshop_does_not_exist(): void
    {
        $response = $this->getJson('/api/workshops/999999/appointments');

        $response->assertNotFound();
    }

    public function test_response_uses_appointment_resource_structure(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $this->createAppointment($workshop, $customer, $vehicle, $service);

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
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
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'links',
                    'path',
                    'per_page',
                    'to',
                    'total',
                ],
            ]);
    }

    public function test_relations_are_available_in_the_response(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, name: 'Alineación y Balanceo');

        $this->createAppointment($workshop, $customer, $vehicle, $service);

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonPath('data.0.customer.id', $customer->id)
            ->assertJsonPath('data.0.customer.name', $customer->name)
            ->assertJsonPath('data.0.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.0.vehicle.brand', $vehicle->brand)
            ->assertJsonPath('data.0.service.id', $service->id)
            ->assertJsonPath('data.0.service.name', 'Alineación y Balanceo');
    }

    public function test_appointments_are_ordered_by_date_and_time_ascending(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $app3 = $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-27', time: '09:00:00');
        $app1 = $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-26', time: '09:00:00');
        $app2 = $this->createAppointment($workshop, $customer, $vehicle, $service, date: '2026-08-26', time: '14:00:00');

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $app1->id)
            ->assertJsonPath('data.1.id', $app2->id)
            ->assertJsonPath('data.2.id', $app3->id);
    }

    public function test_cancelled_appointment_appears_when_no_status_filter_applied(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $cancelledApp = $this->createAppointment($workshop, $customer, $vehicle, $service, status: 'cancelled');
        $pendingApp = $this->createAppointment($workshop, $customer, $vehicle, $service, status: 'pending');

        $response = $this->getJson("/api/workshops/{$workshop->id}/appointments");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertSee($cancelledApp->id)
            ->assertSee($pendingApp->id);
    }
}
