<?php

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $to = TicketStatus::tryFrom((string) $this->input('to'));

        // An unknown value is rejected by rules() with a 422, not a misleading 403.
        return $to === null || $this->user()->can('transition', [$this->route('ticket'), $to]);
    }

    public function rules(): array
    {
        return [
            'to' => ['required', Rule::enum(TicketStatus::class)],
        ];
    }

    public function target(): TicketStatus
    {
        return TicketStatus::from($this->validated('to'));
    }
}
