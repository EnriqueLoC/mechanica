<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AppointmentService $appointmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appointmentService = app(
            AppointmentService::class
        );
    }

    protected function createWorkshop(
        int $capacity = 1
    ): Workshop {
        return Workshop::factory()->create([
            'timezone' => 'America/Chihuahua',
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
        int $duration = 60,
        bool $active = true
    ): Service {
        return Service::factory()->create([
            'workshop_id' => $workshop->id,
            'estimated_minutes' => $duration,
            'active' => $active,
        ]);
    }

    protected function test_date(
        Workshop $workshop
    ): CarbonImmutable {
        return CarbonImmutable::create(
            2026,
            8,
            26,
            0,
            0,
            0,
            $workshop->timezone
        );
    }

    public function test_it_returns_available_slots(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $service = $this->createService(
            $workshop,
            duration: 120
        );

        $date = $this->test_date($workshop);

        $slots = $this->appointmentService
            ->getAvailableSlots(
                $workshop,
                $date,
                $service
            );

        $times = $slots
            ->map(fn ($slot) => $slot->format('H:i'))
            ->values()
            ->all();

        $this->assertSame([
            '08:00',
            '08:30',
            '09:00',
            '09:30',
            '10:00',
            '10:30',
            '11:00',
            '11:30',
            '12:00',
            '12:30',
            '13:00',
            '13:30',
            '14:00',
            '14:30',
            '15:00',
            '15:30',
            '16:00',
        ], $times);
    }

    public function test_it_returns_no_slots_when_workshop_is_closed(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $service = $this->createService($workshop);

        // Domingo
        $date = CarbonImmutable::create(
            2026,
            8,
            30,
            0,
            0,
            0,
            $workshop->timezone
        );

        $slots = $this->appointmentService
            ->getAvailableSlots(
                $workshop,
                $date,
                $service
            );

        $this->assertCount(0, $slots);
    }

    public function test_an_existing_appointment_blocks_overlapping_slots(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService(
            $workshop,
            duration: 120
        );

        $date = $this->test_date($workshop);

        $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );

        $slots = $this->appointmentService
            ->getAvailableSlots(
                $workshop,
                $date,
                $service
            );

        $times = $slots
            ->map(fn ($slot) => $slot->format('H:i'))
            ->all();

        $this->assertSame([
            '08:00',
            '12:00',
            '12:30',
            '13:00',
            '13:30',
            '14:00',
            '14:30',
            '15:00',
            '15:30',
            '16:00',
        ], $times);
    }

    public function test_consecutive_appointments_are_allowed(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService(
            $workshop,
            duration: 120
        );

        $date = $this->test_date($workshop);

        $first = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '08:00:00'
        );

        $second = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);

        $this->assertSame(
            2,
            Appointment::count()
        );
    }

    public function test_it_rejects_an_unavailable_time(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService(
            $workshop,
            duration: 120
        );

        $date = $this->test_date($workshop);

        $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '11:00:00'
        );
    }

    public function test_it_rejects_a_time_outside_business_hours(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService(
            $workshop,
            duration: 60
        );

        $date = $this->test_date($workshop);

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '17:30:00'
        );
    }

    public function test_it_rejects_a_customer_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);

        $customer = $this->createCustomer($workshopB);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService($workshopA);

        $date = $this->test_date($workshopA);

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshopA,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );
    }

    public function test_it_rejects_a_vehicle_from_another_customer(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customerA = $this->createCustomer($workshop);
        $customerB = $this->createCustomer($workshop);

        $vehicle = $this->createVehicle($customerB);

        $service = $this->createService($workshop);

        $date = $this->test_date($workshop);

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );
    }

    public function test_it_rejects_a_service_from_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);

        $customer = $this->createCustomer($workshopA);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService($workshopB);

        $date = $this->test_date($workshopA);

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshopA,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );
    }

    public function test_it_rejects_an_inactive_service(): void
    {
        $workshop = $this->createWorkshop();

        $this->createBusinessHours($workshop);

        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);

        $service = $this->createService(
            $workshop,
            duration: 60,
            active: false
        );

        $date = $this->test_date($workshop);

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );
    }

    public function test_workshop_capacity_allows_multiple_overlapping_appointments(): void
    {
        $workshop = $this->createWorkshop(
            capacity: 2
        );

        $this->createBusinessHours($workshop);

        $customerA = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);

        $customerB = $this->createCustomer($workshop);
        $vehicleB = $this->createVehicle($customerB);

        $service = $this->createService(
            $workshop,
            duration: 60
        );

        $date = $this->test_date($workshop);

        $first = $this->appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicleA,
            $service,
            $date,
            '10:00:00'
        );

        $second = $this->appointmentService->createAppointment(
            $workshop,
            $customerB,
            $vehicleB,
            $service,
            $date,
            '10:30:00'
        );

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);
    }

    public function test_workshop_capacity_rejects_the_third_overlapping_appointment(): void
    {
        $workshop = $this->createWorkshop(
            capacity: 2
        );

        $this->createBusinessHours($workshop);

        $service = $this->createService(
            $workshop,
            duration: 60
        );

        $date = $this->test_date($workshop);

        $customerA = $this->createCustomer($workshop);
        $vehicleA = $this->createVehicle($customerA);

        $customerB = $this->createCustomer($workshop);
        $vehicleB = $this->createVehicle($customerB);

        $customerC = $this->createCustomer($workshop);
        $vehicleC = $this->createVehicle($customerC);

        $this->appointmentService->createAppointment(
            $workshop,
            $customerA,
            $vehicleA,
            $service,
            $date,
            '10:00:00'
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customerB,
            $vehicleB,
            $service,
            $date,
            '10:30:00'
        );

        $this->expectException(
            ValidationException::class
        );

        $this->appointmentService->createAppointment(
            $workshop,
            $customerC,
            $vehicleC,
            $service,
            $date,
            '10:15:00'
        );
    }

    public function test_it_can_confirm_a_pending_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertNull($appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->completed_at);

        $appointment = $this->appointmentService->confirm($appointment);

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertNotNull($appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_cannot_confirm_an_already_confirmed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->confirm($appointment);
    }

    public function test_it_cannot_confirm_a_cancelled_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->cancel($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->confirm($appointment);
    }

    public function test_it_cannot_confirm_a_completed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);
        $appointment = $this->appointmentService->complete($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->confirm($appointment);
    }

    public function test_it_can_cancel_a_pending_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->cancel($appointment);

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertNull($appointment->confirmed_at);
        $this->assertNull($appointment->completed_at);
    }

    public function test_it_cannot_cancel_a_confirmed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->cancel($appointment);
    }

    public function test_it_cannot_cancel_an_already_cancelled_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->cancel($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->cancel($appointment);
    }

    public function test_it_cannot_cancel_a_completed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);
        $appointment = $this->appointmentService->complete($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->cancel($appointment);
    }

    public function test_it_can_complete_a_confirmed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);
        $confirmedAt = $appointment->confirmed_at;

        $appointment = $this->appointmentService->complete($appointment);

        $this->assertSame(AppointmentStatus::Completed, $appointment->status);
        $this->assertNotNull($appointment->completed_at);
        $this->assertEquals($confirmedAt, $appointment->confirmed_at);
        $this->assertNull($appointment->cancelled_at);
    }

    public function test_it_cannot_complete_a_pending_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $this->expectException(ValidationException::class);
        $this->appointmentService->complete($appointment);
    }

    public function test_it_cannot_complete_a_cancelled_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->cancel($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->complete($appointment);
    }

    public function test_it_cannot_complete_an_already_completed_appointment(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $customer = $this->createCustomer($workshop);
        $vehicle = $this->createVehicle($customer);
        $service = $this->createService($workshop);

        $appointment = $this->appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $this->test_date($workshop),
            '10:00:00'
        );

        $appointment = $this->appointmentService->confirm($appointment);
        $appointment = $this->appointmentService->complete($appointment);

        $this->expectException(ValidationException::class);
        $this->appointmentService->complete($appointment);
    }
}
