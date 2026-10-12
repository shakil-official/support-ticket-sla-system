<?php

namespace App\Http\Requests\Admin;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSlaPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-settings');
    }

    public function rules(): array
    {
        $rules = [];

        foreach (TicketPriority::values() as $priority) {
            $rules["policies.{$priority}.resolution_minutes"] = ['required', 'integer', 'min:1', 'max:525600'];
            $rules["policies.{$priority}.warning_threshold_percent"] = ['required', 'integer', 'min:1', 'max:99'];
        }

        return $rules;
    }
}
