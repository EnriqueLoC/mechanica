<?php

namespace App\Http\Requests;

use App\Models\Workshop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
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
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('workshop_id', $workshopId),
            ],

            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')
                    ->where('workshop_id', $workshopId),
            ],

            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')
                    ->where('workshop_id', $workshopId),
            ],

            'date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'time' => [
                'required',
                'date_format:H:i',
            ],
        ];
    }
}
