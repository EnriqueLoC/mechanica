<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentStatusTest extends TestCase
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
        int $duration = 60,
        bool $active = true
    ): Service {
        return Service::factory()->create([
            'workshop_id' => $workshop->id,
            'estimated_minutes' => $duration,
            'active' => $active,
        ]);
    }

    protected function createAppointment(
        Workshop $workshop,
        Customer $customer,
        Vehicle $vehicle,
        Service $service,
        AppointmentStatus|string $status = AppointmentStatus::Pending,
        ?string $date = null,
        string $time = '10:00:00'
    ): Appointment {
        $statusValue = $status instanceof AppointmentStatus ? $status : (AppointmentStatus::tryFrom($status) ?? AppointmentStatus::Pending);

        return Appointment::factory()->create([
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_id' => $service->id,
            'appointment_date' => $date ?? '2026-08-26',
            'appointment_time' => $time,
            'status' => $statusValue,
            'confirmed_at' => in_array($statusValue, [AppointmentStatus::Confirmed, AppointmentStatus::Completed], true) ? now()->subHour() : null,
            'cancelled_at' => $statusValue === AppointmentStatus::Cancelled ? now()->subHour() : null,
            'completed_at' => $statusValue === AppointmentStatus::Completed ? now() : null,
        ]);
    }

    /*
     * =========================================================================
     * CONFIRM TESTS
     * =========================================================================
     */

    public function test_it_confirms_a_pending_appointment_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonPath('data.completed_at', null);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNotNull($appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_establishes_confirmed_at_and_keeps_other_timestamps_null_on_confirm(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertOk();

        $appointment->refresh();
        $this->assertNotNull($appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_cannot_confirm_an_already_confirmed_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Confirmed);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_confirm_a_cancelled_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Cancelled);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_confirm_a_completed_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Completed);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    /*
     * =========================================================================
     * CANCEL TESTS
     * =========================================================================
     */

    public function test_it_cancels_a_pending_appointment_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.completed_at', null);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertNull($appointment->confirmed_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_establishes_cancelled_at_and_keeps_other_timestamps_null_on_cancel(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/cancel");

        $response->assertOk();

        $appointment->refresh();
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertNull($appointment->confirmed_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_cannot_cancel_a_confirmed_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Confirmed);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_cancel_an_already_cancelled_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Cancelled);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_cancel_a_completed_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Completed);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    /*
     * =========================================================================
     * COMPLETE TESTS
     * =========================================================================
     */

    public function test_it_completes_a_confirmed_appointment_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Confirmed);
        $confirmedAt = $appointment->confirmed_at;

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/complete");

        $response->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.cancelled_at', null);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $this->assertNotNull($appointment->completed_at);
        $this->assertEquals($confirmedAt, $appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
    }

    public function test_it_cannot_complete_a_pending_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/complete");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_complete_a_cancelled_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Cancelled);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/complete");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    public function test_it_cannot_complete_an_already_completed_appointment_and_returns_422(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Completed);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/complete");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['appointment']);
    }

    /*
     * =========================================================================
     * MULTI-TENANCY TESTS
     * =========================================================================
     */

    public function test_appointment_from_workshop_b_cannot_be_modified_via_workshop_a_endpoint(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $customerB = $this->createCustomer($workshopB);
        $vehicleB = $this->createVehicle($customerB);
        $serviceB = $this->createService($workshopB);
        $appointmentB = $this->createAppointment($workshopB, $customerB, $vehicleB, $serviceB, AppointmentStatus::Pending);

        // Intento de confirmación en Workshop A
        $responseConfirm = $this->postJson("/api/workshops/{$workshopA->id}/appointments/{$appointmentB->id}/confirm");
        $responseConfirm->assertNotFound();

        $appointmentB->refresh();
        $this->assertSame(AppointmentStatus::Pending, $appointmentB->status);
        $this->assertNull($appointmentB->confirmed_at);

        // Intento de cancelación en Workshop A
        $responseCancel = $this->postJson("/api/workshops/{$workshopA->id}/appointments/{$appointmentB->id}/cancel");
        $responseCancel->assertNotFound();

        $appointmentB->refresh();
        $this->assertSame(AppointmentStatus::Pending, $appointmentB->status);
        $this->assertNull($appointmentB->cancelled_at);

        // Intento de finalización en Workshop A
        $responseComplete = $this->postJson("/api/workshops/{$workshopA->id}/appointments/{$appointmentB->id}/complete");
        $responseComplete->assertNotFound();

        $appointmentB->refresh();
        $this->assertSame(AppointmentStatus::Pending, $appointmentB->status);
        $this->assertNull($appointmentB->completed_at);
    }

    /*
     * =========================================================================
     * WORKSHOP / APPOINTMENT INEXISTENTE
     * =========================================================================
     */

    public function test_non_existent_workshop_returns_404(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/99999/appointments/{$appointment->id}/confirm");
        $response->assertNotFound();

        $responseCancel = $this->postJson("/api/workshops/99999/appointments/{$appointment->id}/cancel");
        $responseCancel->assertNotFound();

        $responseComplete = $this->postJson("/api/workshops/99999/appointments/{$appointment->id}/complete");
        $responseComplete->assertNotFound();
    }

    public function test_non_existent_appointment_returns_404(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/99999/confirm");
        $response->assertNotFound();

        $responseCancel = $this->postJson("/api/workshops/{$workshop->id}/appointments/99999/cancel");
        $responseCancel->assertNotFound();

        $responseComplete = $this->postJson("/api/workshops/{$workshop->id}/appointments/99999/complete");
        $responseComplete->assertNotFound();
    }

    /*
     * =========================================================================
     * RESOURCE STRUCTURE TESTS
     * =========================================================================
     */

    public function test_successful_response_contains_expected_resource_structure_and_relations(): void
    {
        $workshop = $this->createWorkshop();
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop, duration: 90);
        $appointment = $this->createAppointment($workshop, $customer, $vehicle, $service, AppointmentStatus::Pending);

        $response = $this->postJson("/api/workshops/{$workshop->id}/appointments/{$appointment->id}/confirm");

        $response->assertOk()
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
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.status', 'confirmed');
    }
}
