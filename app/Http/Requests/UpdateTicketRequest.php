<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ticket'));
    }

    /**
     * Customers may only change the text. Priority/category are simply not in their rule set,
     * so they are absent from validated() and silently ignored even if a client sends them
     * (a customer must not be able to push their own ticket to "Critical" after creation).
     */
    public function rules(): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
        ];

        if ($this->user()->isStaff()) {
            $rules['priority'] = ['required', Rule::enum(TicketPriority::class)];
            $rules['category_id'] = ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)];
        }

        return $rules;
    }
}
