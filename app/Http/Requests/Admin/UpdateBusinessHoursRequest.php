<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateBusinessHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    public function rules(): array
    {
        $rules = [];

        foreach (range(0, 6) as $day) {
            $rules["days.{$day}.is_working"] = ['sometimes', 'boolean'];
            $rules["days.{$day}.start_time"] = ['required', 'date_format:H:i'];
            $rules["days.{$day}.end_time"] = ['required', 'date_format:H:i'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $days = (array) $this->input('days', []);
            $working = 0;

            foreach ($days as $day => $row) {
                if (! filter_var($row['is_working'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $working++;

                if (($row['start_time'] ?? '') >= ($row['end_time'] ?? '')) {
                    $validator->errors()->add("days.{$day}.end_time", 'The closing time must be after the opening time.');
                }
            }

            if ($working === 0) {
                // The SLA calculator needs at least one working day, otherwise no deadline exists.
                $validator->errors()->add('days', 'At least one working day is required.');
            }
        });
    }
}
