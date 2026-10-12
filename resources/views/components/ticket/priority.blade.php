@props(['priority'])

@php
    $classes = match ($priority->value) {
        'low' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        'medium' => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        'high' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
        default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    };
@endphp

<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $classes }}">{{ $priority->label() }}</span>
