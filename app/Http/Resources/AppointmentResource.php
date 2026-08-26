<?php

namespace App\Http\Resources;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Appointment $resource
 * @property Customer $customer
 * @property Vehicle $vehicle
 * @property Service $service
 * @property-read int $id
 * @property-read int $workshop_id
 * @property-read CarbonInterface $appointment_date
 * @property-read string $appointment_time
 * @property-read AppointmentStatus|string $status
 * @property-read string|null $customer_notes
 * @property-read string|null $internal_notes
 * @property-read CarbonInterface|null $confirmed_at
 * @property-read CarbonInterface|null $cancelled_at
 * @property-read CarbonInterface|null $completed_at
 * @property-read CarbonInterface|null $created_at
 * @property-read CarbonInterface|null $updated_at
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workshop_id' => $this->workshop_id,
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ],
            'vehicle' => [
                'id' => $this->vehicle->id,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
                'year' => $this->vehicle->year,
                'plates' => $this->vehicle->plates,
            ],
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'estimated_minutes' => (int) ($this->service->estimated_minutes ?? 60),
                'price' => $this->service->price,
            ],
            'appointment_date' => $this->appointment_date->format('Y-m-d'),
            'appointment_time' => $this->appointment_time,
            'status' => $this->status instanceof AppointmentStatus ? $this->status->value : $this->status,
            'customer_notes' => $this->customer_notes,
            'internal_notes' => $this->internal_notes,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
