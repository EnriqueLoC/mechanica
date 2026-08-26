<?php

namespace Tests\Feature\Api;

use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentAvailabilityTest extends TestCase
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

    protected function createService(
        Workshop $workshop,
        string $name = 'Afinación',
        int $duration = 120,
        bool $active = true
    ): Service {
        return Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => $name,
            'estimated_minutes' => $duration,
            'active' => $active,
        ]);
    }

    public function test_it_returns_available_slots_successfully(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $service = $this->createService($workshop, name: 'Afinación', duration: 120);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26&service_id={$service->id}");

        $response->assertOk()
            ->assertExactJson([
                'date' => '2026-08-26',
                'timezone' => 'America/Chihuahua',
                'service' => [
                    'id' => $service->id,
                    'name' => 'Afinación',
                    'duration' => 120,
                ],
                'slots' => [
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
                ],
            ]);
    }

    public function test_it_respects_workshop_timezone(): void
    {
        $workshop = $this->createWorkshop(timezone: 'America/Mexico_City');
        $this->createBusinessHours($workshop);
        $service = $this->createService($workshop, duration: 60);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26&service_id={$service->id}");

        $response->assertOk()
            ->assertJsonPath('timezone', 'America/Mexico_City')
            ->assertJsonPath('date', '2026-08-26');
    }

    public function test_it_returns_empty_slots_when_workshop_is_closed(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop, sundayClosed: true);
        $service = $this->createService($workshop);

        // 2026-08-30 is Sunday
        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-30&service_id={$service->id}");

        $response->assertOk()
            ->assertJsonPath('slots', []);
    }

    public function test_it_rejects_service_belonging_to_another_workshop(): void
    {
        $workshopA = $this->createWorkshop();
        $workshopB = $this->createWorkshop();

        $this->createBusinessHours($workshopA);
        $this->createBusinessHours($workshopB);

        $serviceOfWorkshopB = $this->createService($workshopB);

        $response = $this->getJson("/api/workshops/{$workshopA->id}/availability?date=2026-08-26&service_id={$serviceOfWorkshopB->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service']);
    }

    public function test_it_returns_422_when_service_does_not_exist(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26&service_id=999999");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_it_returns_422_when_date_is_invalid(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $service = $this->createService($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=not-a-date&service_id={$service->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $responseFeb31 = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-02-31&service_id={$service->id}");

        $responseFeb31->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_it_returns_422_when_date_is_missing(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);
        $service = $this->createService($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?service_id={$service->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_it_returns_422_when_service_id_is_missing(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_it_returns_422_when_service_id_is_not_integer(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26&service_id=abc");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_id']);
    }

    public function test_an_existing_appointment_removes_overlapping_slots(): void
    {
        $workshop = $this->createWorkshop();
        $this->createBusinessHours($workshop);

        $customer = Customer::factory()->create(['workshop_id' => $workshop->id]);
        $vehicle = Vehicle::factory()->forCustomer($customer)->create();
        $service = $this->createService($workshop, duration: 120);

        $appointmentService = app(AppointmentService::class);
        $date = CarbonImmutable::create(2026, 8, 26, 0, 0, 0, $workshop->timezone);

        $appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            '10:00:00'
        );

        $response = $this->getJson("/api/workshops/{$workshop->id}/availability?date=2026-08-26&service_id={$service->id}");

        $response->assertOk()
            ->assertJsonPath('slots', [
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
            ]);
    }

    public function test_it_returns_404_when_workshop_does_not_exist(): void
    {
        $response = $this->getJson('/api/workshops/999999/availability?date=2026-08-26&service_id=1');

        $response->assertNotFound();
    }
}
