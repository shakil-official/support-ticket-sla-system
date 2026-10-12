<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the search / filter query string of the ticket lists. */
class IndexTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // visibility is enforced by Ticket::visibleTo() in the controller
    }

    public function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(['active', 'resolved'])],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'category_id' => ['nullable', 'integer'],
            'agent' => ['nullable', 'string', 'max:20'],   // user id or "unassigned"
            'customer' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sla' => ['nullable', Rule::in(['within', 'approaching', 'breached', 'met'])],
        ];
    }
}
