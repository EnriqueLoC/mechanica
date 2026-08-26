<?php

namespace App\Http\Requests;

use App\Enums\AppointmentStatus;
use App\Models\Workshop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workshop|int|string|null $workshop */
        $workshop = $this->route('workshop');
        $workshopId = $workshop instanceof Workshop ? $workshop->id : $workshop;

        return [
            'date' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'status' => [
                'nullable',
                'string',
                Rule::enum(AppointmentStatus::class),
            ],

            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('workshop_id', $workshopId),
            ],

            'vehicle_id' => [
                'nullable',
                'integer',
                Rule::exists('vehicles', 'id')
                    ->where('workshop_id', $workshopId),
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }
}
