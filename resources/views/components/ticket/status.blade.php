@props(['status'])

@php
    $classes = match ($status->value) {
        'open' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'in_progress' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'waiting_for_customer' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'resolved' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        default => 'bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
    };
@endphp

<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $classes }}">{{ $status->label() }}</span>
