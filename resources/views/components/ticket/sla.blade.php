@props(['ticket'])

@php
    $state = $ticket->sla_state;   // computed from status + due_at + warning point, never stored
    $classes = match ($state?->value) {
        'within' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'approaching' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'breached' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'met' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    };
@endphp

@if ($state)
    <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $classes }}">
        {{ $state->label() }}@if ($ticket->escalated_at) &middot; escalated @endif
    </span>
@endif
