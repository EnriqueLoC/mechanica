<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    /**
     * Obtiene el horario de atención del taller para una fecha.
     */
    public function getBusinessHours(
        Workshop $workshop,
        CarbonInterface $date
    ): ?BusinessHour {
        $date = $date->setTimezone($workshop->timezone);

        return $workshop->businessHours()
            ->where('day_of_week', $date->dayOfWeek)
            ->first();
    }

    /**
     * Obtiene los slots disponibles para un servicio en una fecha.
     *
     * @return Collection<int, CarbonInterface>
     */
    public function getAvailableSlots(
        Workshop $workshop,
        CarbonInterface $date,
        Service $service
    ): Collection {
        $date = $date->setTimezone($workshop->timezone);

        $this->validateServiceBelongsToWorkshop(
            $service,
            $workshop
        );

        $businessHours = $this->getBusinessHours(
            $workshop,
            $date
        );

        if (! $businessHours || $businessHours->closed) {
            return collect();
        }

        $appointments = $this->getAppointmentsForDate(
            $workshop,
            $date
        );

        return $this->calculateAvailableSlots(
            $workshop,
            $date,
            $service,
            $businessHours,
            $appointments
        );
    }

    /**
     * Obtiene las citas activas de un taller para una fecha.
     */
    protected function getAppointmentsForDate(
        Workshop $workshop,
        CarbonInterface $date
    ): Collection {
        return Appointment::query()
            ->where('workshop_id', $workshop->id)
            ->whereDate('appointment_date', $date->toDateString())
            ->whereNotIn('status', [
                AppointmentStatus::Cancelled->value,
            ])
            ->with('service')
            ->orderBy('appointment_time')
            ->get();
    }

    /**
     * Calcula los slots disponibles.
     *
     * @return Collection<int, CarbonInterface>
     */
    protected function calculateAvailableSlots(
        Workshop $workshop,
        CarbonInterface $date,
        Service $service,
        BusinessHour $businessHours,
        Collection $appointments
    ): Collection {
        $opening = CarbonImmutable::parse(
            $date->toDateString().' '.$businessHours->opening_time,
            $workshop->timezone
        );

        $closing = CarbonImmutable::parse(
            $date->toDateString().' '.$businessHours->closing_time,
            $workshop->timezone
        );

        $duration = $service->estimated_minutes ?? 60;

        /*
         * Intervalo entre posibles horas de inicio.
         *
         * Posteriormente podemos convertirlo en una
         * configuración del taller.
         */
        $slotInterval = 30;

        $slots = collect();

        for (
            $slot = $opening;
            $slot->addMinutes($duration)->lte($closing);
            $slot = $slot->addMinutes($slotInterval)
        ) {
            $slotEnd = $slot->addMinutes($duration);

            if (
                $this->slotIsAvailable(
                    $slot,
                    $slotEnd,
                    $appointments,
                    $workshop->appointment_capacity
                )
            ) {
                $slots->push($slot);
            }
        }

        return $slots;
    }

    /**
     * Determina si un intervalo tiene capacidad disponible.
     */
    protected function slotIsAvailable(
        CarbonInterface $start,
        CarbonInterface $end,
        Collection $appointments,
        int $capacity
    ): bool {
        $overlappingAppointments = 0;

        foreach ($appointments as $appointment) {
            $appointmentStart = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $appointment->appointment_date->format('Y-m-d')
                .' '
                .$appointment->appointment_time,
                $start->getTimezone()
            );

            $appointmentDuration =
                $appointment->service?->estimated_minutes ?? 60;

            $appointmentEnd = $appointmentStart->addMinutes(
                $appointmentDuration
            );

            /*
             * Hay solapamiento cuando:
             *
             * start < appointmentEnd
             * &&
             * end > appointmentStart
             */
            if (
                $start->lt($appointmentEnd) &&
                $end->gt($appointmentStart)
            ) {
                $overlappingAppointments++;

                if ($overlappingAppointments >= $capacity) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Crea una cita de forma segura contra reservas simultáneas.
     */
    public function createAppointment(
        Workshop $workshop,
        Customer $customer,
        Vehicle $vehicle,
        Service $service,
        CarbonInterface $date,
        string $time,
        AppointmentStatus|string $status = AppointmentStatus::Pending
    ): Appointment {
        $date = $date->setTimezone($workshop->timezone);

        $this->validateCustomerBelongsToWorkshop(
            $customer,
            $workshop
        );

        $this->validateVehicleBelongsToWorkshop(
            $vehicle,
            $workshop
        );

        $this->validateVehicleBelongsToCustomer(
            $vehicle,
            $customer
        );

        $this->validateServiceBelongsToWorkshop(
            $service,
            $workshop
        );

        if (! $service->active) {
            throw ValidationException::withMessages([
                'service' => 'El servicio seleccionado no está disponible.',
            ]);
        }

        $statusEnum = is_string($status)
            ? (AppointmentStatus::tryFrom($status) ?? AppointmentStatus::Pending)
            : $status;

        return DB::transaction(function () use (
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            $time,
            $statusEnum
        ) {
            /*
             * Bloqueamos el taller durante la transacción.
             *
             * Esto serializa las reservas de un mismo taller
             * y evita que dos solicitudes simultáneas puedan
             * reservar el mismo recurso.
             */
            $lockedWorkshop = Workshop::query()
                ->whereKey($workshop->id)
                ->lockForUpdate()
                ->firstOrFail();

            $businessHours = $this->getBusinessHours(
                $lockedWorkshop,
                $date
            );

            if (! $businessHours || $businessHours->closed) {
                throw ValidationException::withMessages([
                    'appointment_date' => 'El taller está cerrado en la fecha seleccionada.',
                ]);
            }

            $start = CarbonImmutable::parse(
                $date->toDateString().' '.$time,
                $lockedWorkshop->timezone
            );

            /*
             * Verificamos que la hora tenga formato y que
             * el servicio completo pueda realizarse dentro
             * del horario de atención.
             */
            $closing = CarbonImmutable::parse(
                $date->toDateString().' '.$businessHours->closing_time,
                $lockedWorkshop->timezone
            );

            $duration = $service->estimated_minutes ?? 60;

            $end = $start->addMinutes($duration);

            $opening = CarbonImmutable::parse(
                $date->toDateString().' '.$businessHours->opening_time,
                $lockedWorkshop->timezone
            );

            if (
                $start->lt($opening) ||
                $end->gt($closing)
            ) {
                throw ValidationException::withMessages([
                    'appointment_time' => 'El horario seleccionado está fuera del horario de atención.',
                ]);
            }

            /*
             * Obtenemos las citas DESPUÉS de adquirir el lock.
             */
            $appointments = $this->getAppointmentsForDate(
                $lockedWorkshop,
                $date
            );

            /*
             * Comprobamos directamente el intervalo solicitado.
             */
            if (
                ! $this->slotIsAvailable(
                    $start,
                    $end,
                    $appointments,
                    $lockedWorkshop->appointment_capacity
                )
            ) {
                throw ValidationException::withMessages([
                    'appointment_time' => 'El horario seleccionado ya no está disponible.',
                ]);
            }

            return Appointment::create([
                'workshop_id' => $lockedWorkshop->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'service_id' => $service->id,
                'appointment_date' => $date->toDateString(),
                'appointment_time' => $start->format('H:i:s'),
                'status' => $statusEnum,
            ]);
        });
    }

    /**
     * Confirma una cita.
     */
    public function confirm(Appointment $appointment): Appointment
    {
        if (! $appointment->canTransitionTo(AppointmentStatus::Confirmed)) {
            throw ValidationException::withMessages([
                'appointment' => 'No se puede confirmar la cita en su estado actual.',
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $appointment->refresh();
    }

    /**
     * Cancela una cita.
     */
    public function cancel(Appointment $appointment): Appointment
    {
        if (! $appointment->canTransitionTo(AppointmentStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'appointment' => 'No se puede cancelar la cita en su estado actual.',
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $appointment->refresh();
    }

    /**
     * Marca una cita como completada.
     */
    public function complete(Appointment $appointment): Appointment
    {
        if (! $appointment->canTransitionTo(AppointmentStatus::Completed)) {
            throw ValidationException::withMessages([
                'appointment' => 'No se puede completar la cita en su estado actual.',
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::Completed,
            'completed_at' => now(),
        ]);

        return $appointment->refresh();
    }

    protected function validateCustomerBelongsToWorkshop(
        Customer $customer,
        Workshop $workshop
    ): void {
        if ($customer->workshop_id !== $workshop->id) {
            throw ValidationException::withMessages([
                'customer' => 'El cliente no pertenece a este taller.',
            ]);
        }
    }

    protected function validateVehicleBelongsToWorkshop(
        Vehicle $vehicle,
        Workshop $workshop
    ): void {
        if ($vehicle->workshop_id !== $workshop->id) {
            throw ValidationException::withMessages([
                'vehicle' => 'El vehículo no pertenece a este taller.',
            ]);
        }
    }

    protected function validateVehicleBelongsToCustomer(
        Vehicle $vehicle,
        Customer $customer
    ): void {
        if ($vehicle->customer_id !== $customer->id) {
            throw ValidationException::withMessages([
                'vehicle' => 'El vehículo no pertenece al cliente.',
            ]);
        }
    }

    protected function validateServiceBelongsToWorkshop(
        Service $service,
        Workshop $workshop
    ): void {
        if ($service->workshop_id !== $workshop->id) {
            throw ValidationException::withMessages([
                'service' => 'El servicio no pertenece a este taller.',
            ]);
        }
    }
}
