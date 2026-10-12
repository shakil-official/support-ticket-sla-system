@php
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $minutesHint = fn (int $m) => $m % 60 === 0 ? ($m / 60).' h' : $m.' min';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">SLA settings</h1>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">

        {{-- SLA policies: one row per priority --}}
        <x-ui.card class="p-6">
            <h2 class="text-lg font-semibold">Resolution time per priority</h2>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                Measured in <strong>business</strong> minutes (see business hours below). Changing a value affects tickets created
                or re-prioritised afterwards; existing deadlines are not rewritten.
            </p>

            <form method="POST" action="{{ route('admin.settings.sla') }}" class="space-y-3">
                @csrf @method('PUT')
                <div class="grid gap-3 text-sm font-medium text-gray-500 sm:grid-cols-4">
                    <span>Priority</span><span>Resolution (minutes)</span><span>Warn at (% of time used)</span><span></span>
                </div>
                @foreach ($priorities as $priority)
                    @php($policy = $policies[$priority->value] ?? null)
                    <div class="grid items-start gap-3 sm:grid-cols-4">
                        <div class="pt-2 font-medium">{{ $priority->label() }}</div>
                        <div>
                            <x-ui.input type="number" min="1" name="policies[{{ $priority->value }}][resolution_minutes]"
                                :value="old('policies.'.$priority->value.'.resolution_minutes', $policy?->resolution_minutes)" required />
                            <x-ui.error for="policies.{{ $priority->value }}.resolution_minutes" />
                        </div>
                        <div>
                            <x-ui.input type="number" min="1" max="99" name="policies[{{ $priority->value }}][warning_threshold_percent]"
                                :value="old('policies.'.$priority->value.'.warning_threshold_percent', $policy?->warning_threshold_percent ?? 75)" required />
                            <x-ui.error for="policies.{{ $priority->value }}.warning_threshold_percent" />
                        </div>
                        <div class="pt-2 text-sm text-gray-500">@if ($policy) = {{ $minutesHint($policy->resolution_minutes) }} @endif</div>
                    </div>
                @endforeach
                <div class="pt-2"><x-ui.button type="submit">Save SLA policies</x-ui.button></div>
            </form>
        </x-ui.card>

        {{-- Business hours --}}
        <x-ui.card class="p-6">
            <h2 class="text-lg font-semibold">Business hours</h2>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">Times are in <strong>{{ $timezone }}</strong>. Untick a day to make it a non-working day.</p>

            <form method="POST" action="{{ route('admin.settings.hours') }}" class="space-y-3">
                @csrf @method('PUT')
                <x-ui.error for="days" />
                @foreach ($dayNames as $dow => $name)
                    @php($row = $days[$dow] ?? null)
                    <div class="grid items-center gap-3 sm:grid-cols-4">
                        <label class="flex items-center gap-2 font-medium">
                            <input type="hidden" name="days[{{ $dow }}][is_working]" value="0">
                            <input type="checkbox" name="days[{{ $dow }}][is_working]" value="1" class="rounded border-gray-300"
                                @checked(old('days.'.$dow.'.is_working', $row?->is_working ?? false))>
                            {{ $name }}
                        </label>
                        <div>
                            <x-ui.input type="time" name="days[{{ $dow }}][start_time]"
                                :value="old('days.'.$dow.'.start_time', substr((string) ($row?->start_time ?? '09:00'), 0, 5))" required />
                        </div>
                        <div>
                            <x-ui.input type="time" name="days[{{ $dow }}][end_time]"
                                :value="old('days.'.$dow.'.end_time', substr((string) ($row?->end_time ?? '18:00'), 0, 5))" required />
                            <x-ui.error for="days.{{ $dow }}.end_time" />
                        </div>
                        <div></div>
                    </div>
                @endforeach
                <div class="pt-2"><x-ui.button type="submit">Save business hours</x-ui.button></div>
            </form>
        </x-ui.card>

        {{-- Holidays --}}
        <x-ui.card class="p-6">
            <h2 class="text-lg font-semibold">Holidays</h2>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">The SLA clock does not run on these dates.</p>

            <form method="POST" action="{{ route('admin.settings.holidays.store') }}" class="mb-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <x-ui.label for="holiday_date">Date</x-ui.label>
                    <x-ui.input id="holiday_date" type="date" name="date" :value="old('date')" required />
                    <x-ui.error for="date" />
                </div>
                <div class="min-w-56 flex-1">
                    <x-ui.label for="holiday_name">Name</x-ui.label>
                    <x-ui.input id="holiday_name" name="name" :value="old('name')" required maxlength="120" />
                    <x-ui.error for="name" />
                </div>
                <x-ui.button type="submit">Add holiday</x-ui.button>
            </form>

            <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                @forelse ($holidays as $holiday)
                    <li class="flex items-center justify-between py-2">
                        <span><span class="font-mono">{{ $holiday->date->format('Y-m-d (D)') }}</span> &middot; {{ $holiday->name }}</span>
                        <form method="POST" action="{{ route('admin.settings.holidays.destroy', $holiday) }}" onsubmit="return confirm('Remove this holiday?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-600 hover:underline">Remove</button>
                        </form>
                    </li>
                @empty
                    <li class="py-4 text-gray-500">No holidays configured.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</x-app-layout>
