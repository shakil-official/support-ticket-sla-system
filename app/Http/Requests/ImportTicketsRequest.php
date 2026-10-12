<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ImportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('import-tickets');
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('tickets.import.max_kb')],
        ];
    }
}
