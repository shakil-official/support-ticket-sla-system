@props(['value' => null, 'format' => 'M j, Y g:i A'])

{{-- Timestamps are stored in UTC; people read them in the business timezone. --}}
@if ($value)
    <time datetime="{{ $value->toIso8601String() }}" title="{{ $value->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $value->copy()->timezone(config('tickets.timezone'))->format($format) }}</time>
@else
    <span class="text-gray-400">-</span>
@endif
