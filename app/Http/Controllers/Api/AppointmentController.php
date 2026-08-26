<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentIndexRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\Workshop;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    /**
     * Lista las citas de un taller con filtros y paginación.
     */
    public function index(
        AppointmentIndexRequest $request,
        Workshop $workshop
    ): AnonymousResourceCollection {
        $query = Appointment::query()
            ->where('workshop_id', $workshop->id)
            ->with(['customer', 'vehicle', 'service']);

        if ($request->filled('date')) {
            $query->whereDate('appointment_date', (string) $request->validated('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->validated('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->validated('customer_id'));
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->validated('vehicle_id'));
        }

        $perPage = (int) ($request->validated('per_page') ?? 15);

        $appointments = $query
            ->orderBy('appointment_date', 'asc')
            ->orderBy('appointment_time', 'asc')
            ->paginate($perPage);

        return AppointmentResource::collection($appointments);
    }

    /**
     * Crea una nueva cita para un taller.
     */
    public function store(
        StoreAppointmentRequest $request,
        Workshop $workshop,
        AppointmentService $appointmentService
    ): JsonResponse {
        $customer = Customer::query()->whereKey($request->validated('customer_id'))->firstOrFail();
        $vehicle = Vehicle::query()->whereKey($request->validated('vehicle_id'))->firstOrFail();
        $service = Service::query()->whereKey($request->validated('service_id'))->firstOrFail();

        $date = CarbonImmutable::createFromFormat(
            'Y-m-d',
            (string) $request->validated('date'),
            $workshop->timezone
        )->startOfDay();

        $time = (string) $request->validated('time');

        $appointment = $appointmentService->createAppointment(
            $workshop,
            $customer,
            $vehicle,
            $service,
            $date,
            $time
        );

        $appointment->load(['customer', 'vehicle', 'service']);

        return (new AppointmentResource($appointment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Confirma una cita existente.
     */
    public function confirm(
        Workshop $workshop,
        Appointment $appointment,
        AppointmentService $appointmentService
    ): AppointmentResource {
        if ($appointment->workshop_id !== $workshop->id) {
            abort(404);
        }

        $appointment = $appointmentService->confirm($appointment);
        $appointment->load(['customer', 'vehicle', 'service']);

        return new AppointmentResource($appointment);
    }

    /**
     * Cancela una cita existente.
     */
    public function cancel(
        Workshop $workshop,
        Appointment $appointment,
        AppointmentService $appointmentService
    ): AppointmentResource {
        if ($appointment->workshop_id !== $workshop->id) {
            abort(404);
        }

        $appointment = $appointmentService->cancel($appointment);
        $appointment->load(['customer', 'vehicle', 'service']);

        return new AppointmentResource($appointment);
    }

    /**
     * Completa una cita existente.
     */
    public function complete(
        Workshop $workshop,
        Appointment $appointment,
        AppointmentService $appointmentService
    ): AppointmentResource {
        if ($appointment->workshop_id !== $workshop->id) {
            abort(404);
        }

        $appointment = $appointmentService->complete($appointment);
        $appointment->load(['customer', 'vehicle', 'service']);

        return new AppointmentResource($appointment);
    }
}
