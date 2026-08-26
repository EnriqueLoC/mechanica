<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Models\Service;
use App\Models\Workshop;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AppointmentAvailabilityController extends Controller
{
    /**
     * Consulta los horarios disponibles para un servicio y fecha en un taller.
     */
    public function __invoke(
        AvailabilityRequest $request,
        Workshop $workshop,
        AppointmentService $appointmentService
    ): JsonResponse {
        $service = Service::query()
            ->where('id', $request->validated('service_id'))
            ->firstOrFail();

        $date = CarbonImmutable::createFromFormat(
            'Y-m-d',
            (string) $request->validated('date'),
            $workshop->timezone
        )->startOfDay();

        $slots = $appointmentService->getAvailableSlots(
            $workshop,
            $date,
            $service
        );

        return response()->json([
            'date' => $date->toDateString(),
            'timezone' => $workshop->timezone,
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'duration' => (int) ($service->estimated_minutes ?? 60),
            ],
            'slots' => $slots->map(fn ($slot) => $slot->format('H:i'))->values()->all(),
        ]);
    }
}
