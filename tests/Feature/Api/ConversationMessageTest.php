<?php

namespace Tests\Feature\Api;

use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function createWorkshop(string $timezone = 'America/Chihuahua'): Workshop
    {
        $workshop = Workshop::factory()->create([
            'timezone' => $timezone,
        ]);

        for ($day = 1; $day <= 6; $day++) {
            BusinessHour::factory()->create([
                'workshop_id' => $workshop->id,
                'day_of_week' => $day,
                'opening_time' => '08:00:00',
                'closing_time' => '18:00:00',
                'closed' => false,
            ]);
        }

        BusinessHour::factory()->create([
            'workshop_id' => $workshop->id,
            'day_of_week' => 0,
            'opening_time' => '08:00:00',
            'closing_time' => '18:00:00',
            'closed' => true,
        ]);

        return $workshop;
    }

    public function test_it_processes_conversation_message_via_http_endpoint_and_returns_200(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $response = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'Quiero una cita',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'state',
            ])
            ->assertJsonPath('state', 'selecting_service');
    }

    public function test_it_validates_required_fields(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'message']);
    }

    public function test_non_existent_workshop_returns_404(): void
    {
        $response = $this->postJson('/api/workshops/99999/conversation/messages', [
            'phone' => '6142120003',
            'message' => 'Hola',
        ]);

        $response->assertNotFound();
    }

    public function test_full_conversation_cycle_via_http_endpoint(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        $service = Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);
        $vehicle = Vehicle::factory()->forCustomer($customer)->create([
            'brand' => 'Mazda',
            'model' => '3',
            'year' => 2023,
        ]);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        // 1. Iniciar
        $res1 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'hola',
        ]);
        $res1->assertOk()->assertJsonPath('state', 'selecting_service');

        // 2. Seleccionar servicio 1
        $res2 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
        ]);
        $res2->assertOk()->assertJsonPath('state', 'selecting_vehicle');

        // 3. Seleccionar vehículo 1
        $res3 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
        ]);
        $res3->assertOk()->assertJsonPath('state', 'selecting_date');

        // 4. Seleccionar fecha
        $res4 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => $futureDate->format('d/m/Y'),
        ]);
        $res4->assertOk()->assertJsonPath('state', 'selecting_time');

        // 5. Seleccionar horario 1
        $res5 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
        ]);
        $res5->assertOk()->assertJsonPath('state', 'confirming_appointment');

        // 6. Confirmar
        $res6 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'sí',
        ]);
        $res6->assertOk()->assertJsonPath('state', 'idle');
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_it_accepts_and_stores_whatsapp_message_id_via_endpoint(): void
    {
        $workshop = $this->createWorkshop();
        Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        $response = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'cita',
            'whatsapp_message_id' => 'wamid.http.001',
        ]);

        $response->assertOk()
            ->assertJsonPath('state', 'selecting_service');

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'wamid.http.001',
            'direction' => 'incoming',
        ]);
    }

    public function test_duplicate_whatsapp_message_id_returns_duplicate_flag_and_does_not_repeat_processing(): void
    {
        $workshop = $this->createWorkshop();
        Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);

        // Primera petición
        $res1 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'cita',
            'whatsapp_message_id' => 'wamid.http.duplicate.test',
        ]);
        $res1->assertOk()
            ->assertJsonPath('state', 'selecting_service')
            ->assertJsonMissing(['duplicate']);

        // Segunda petición idéntica
        $res2 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'cita',
            'whatsapp_message_id' => 'wamid.http.duplicate.test',
        ]);
        $res2->assertOk()
            ->assertJsonPath('state', 'selecting_service')
            ->assertJsonPath('duplicate', true);
    }

    public function test_it_validates_whatsapp_message_id_must_be_string_if_provided(): void
    {
        $workshop = $this->createWorkshop();

        $response = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'cita',
            'whatsapp_message_id' => ['array_not_allowed'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_message_id']);
    }

    public function test_api_full_flow_with_whatsapp_message_ids_and_duplicate_on_confirmation(): void
    {
        $workshop = $this->createWorkshop();
        $customer = Customer::factory()->create([
            'workshop_id' => $workshop->id,
            'phone' => '6142120003',
        ]);
        Service::factory()->create([
            'workshop_id' => $workshop->id,
            'name' => 'Afinación',
            'active' => true,
        ]);
        Vehicle::factory()->create([
            'workshop_id' => $workshop->id,
            'customer_id' => $customer->id,
        ]);

        $futureDate = CarbonImmutable::now($workshop->timezone)->next(CarbonImmutable::MONDAY)->addWeek();

        // 1. Iniciar
        $res1 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'cita',
            'whatsapp_message_id' => 'wamid.api.001',
        ]);
        $res1->assertOk()->assertJsonPath('state', 'selecting_service');

        // 2. Seleccionar servicio 1
        $res2 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
            'whatsapp_message_id' => 'wamid.api.002',
        ]);
        $res2->assertOk()->assertJsonPath('state', 'selecting_vehicle');

        // 3. Seleccionar vehículo 1
        $res3 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
            'whatsapp_message_id' => 'wamid.api.003',
        ]);
        $res3->assertOk()->assertJsonPath('state', 'selecting_date');

        // 4. Seleccionar fecha
        $res4 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => $futureDate->format('d/m/Y'),
            'whatsapp_message_id' => 'wamid.api.004',
        ]);
        $res4->assertOk()->assertJsonPath('state', 'selecting_time');

        // 5. Seleccionar horario 1
        $res5 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => '1',
            'whatsapp_message_id' => 'wamid.api.005',
        ]);
        $res5->assertOk()->assertJsonPath('state', 'confirming_appointment');

        // 6. Confirmar
        $res6 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'sí',
            'whatsapp_message_id' => 'wamid.api.006',
        ]);
        $res6->assertOk()
            ->assertJsonPath('state', 'idle')
            ->assertJsonMissing(['duplicate']);
        $this->assertDatabaseCount('appointments', 1);

        // 7. Reintentar mensaje de confirmación con mismo ID
        $res7 = $this->postJson("/api/workshops/{$workshop->id}/conversation/messages", [
            'phone' => '6142120003',
            'message' => 'sí',
            'whatsapp_message_id' => 'wamid.api.006',
        ]);
        $res7->assertOk()
            ->assertJsonPath('state', 'idle')
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('appointments', 1);
    }
}
