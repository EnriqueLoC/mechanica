<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workshop_id',
        'customer_id',
        'vehicle_id',
        'service_id',
        'appointment_date',
        'appointment_time',
        'status',
        'customer_notes',
        'internal_notes',
        'confirmed_at',
        'cancelled_at',
        'completed_at',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'status' => AppointmentStatus::class,
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Determina si la cita puede transicionar a un nuevo estado.
     */
    public function canTransitionTo(AppointmentStatus|string $newStatus): bool
    {
        $target = is_string($newStatus)
            ? AppointmentStatus::tryFrom($newStatus)
            : $newStatus;

        if ($target === null) {
            return false;
        }

        $current = is_string($this->status)
            ? AppointmentStatus::tryFrom($this->status)
            : $this->status;

        if (! $current instanceof AppointmentStatus) {
            return false;
        }

        return $current->canTransitionTo($target);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
