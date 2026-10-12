@php
    $isStaff = auth()->user()->isStaff();

    $tiles = [
        ['Open tickets', $stats['open_total'], route('tickets.index'), 'text-indigo-600 dark:text-indigo-400', true],
        ['Unassigned', $stats['unassigned'], route('tickets.index', ['agent' => 'unassigned']), 'text-amber-600 dark:text-amber-400', $isStaff],
        ['Critical', $stats['critical'], route('tickets.index', ['priority' => 'critical']), 'text-red-600 dark:text-red-400', true],
        ['SLA breached', $stats['breached'], route('tickets.index', ['sla' => 'breached']), 'text-red-600 dark:text-red-400', true],
        ['Approaching SLA breach', $stats['approaching'], route('tickets.index', ['sla' => 'approaching']), 'text-amber-600 dark:text-amber-400', true],
        ['Resolved / closed', $stats['resolved'], route('tickets.index', ['view' => 'resolved']), 'text-green-600 dark:text-green-400', true],
    ];

    $priorityTotal = max(1, array_sum($stats['by_priority']));
    $agentMax = max(1, collect($stats['by_agent'])->max('total') ?? 1);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Dashboard</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($tiles as [$label, $value, $url, $color, $visible])
                @if ($visible)
                    <a href="{{ $url }}" class="block rounded-lg bg-white p-4 shadow-sm transition hover:shadow-md dark:bg-gray-800">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-3xl font-bold {{ $color }}">{{ $value }}</div>
                    </a>
                @endif
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card class="p-5">
                <h2 class="mb-4 font-semibold">Active tickets by priority</h2>
                <ul class="space-y-3">
                    @foreach ($stats['by_priority'] as $priority => $count)
                        <li>
                            <div class="mb-1 flex justify-between text-sm">
                                <a class="hover:underline" href="{{ route('tickets.index', ['priority' => $priority]) }}">{{ ucfirst($priority) }}</a>
                                <span class="text-gray-500 dark:text-gray-400">{{ $count }}</span>
                            </div>
                            <div class="h-2 rounded bg-gray-100 dark:bg-gray-700">
                                <div class="h-2 rounded bg-indigo-500" style="width: {{ round($count / $priorityTotal * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            @if ($isStaff)
                <x-ui.card class="p-5">
                    <h2 class="mb-4 font-semibold">Active tickets by agent</h2>
                    @forelse ($stats['by_agent'] as $row)
                        <div class="mb-3">
                            <div class="mb-1 flex justify-between text-sm">
                                <span>{{ $row['name'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['total'] }}</span>
                            </div>
                            <div class="h-2 rounded bg-gray-100 dark:bg-gray-700">
                                <div class="h-2 rounded bg-emerald-500" style="width: {{ round($row['total'] / $agentMax * 100) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">No assigned active tickets.</p>
                    @endforelse
                </x-ui.card>
            @endif
        </div>

        @if ($isStaff)
            <x-ui.card>
                <div class="flex items-center justify-between p-5 pb-3">
                    <h2 class="font-semibold">Needs attention: most overdue tickets</h2>
                    <a class="text-sm text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('tickets.index', ['sla' => 'breached']) }}">View all</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($attention as $ticket)
                                <tr>
                                    <td class="px-5 py-3 font-mono text-xs"><a class="text-indigo-600 hover:underline dark:text-indigo-400" href="{{ route('tickets.show', $ticket) }}">{{ $ticket->ticket_number }}</a></td>
                                    <td class="px-3 py-3">{{ \Illuminate\Support\Str::limit($ticket->subject, 60) }}</td>
                                    <td class="px-3 py-3"><x-ticket.priority :priority="$ticket->priority" /></td>
                                    <td class="px-3 py-3">{{ $ticket->agent?->name ?? 'Unassigned' }}</td>
                                    <td class="px-3 py-3 text-red-600 dark:text-red-400">overdue {{ $ticket->due_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-5 py-6 text-center text-gray-500 dark:text-gray-400">No SLA breaches. 🎉</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <p class="text-xs text-gray-400">Figures are cached for {{ \App\Services\DashboardService::TTL_SECONDS }} seconds.</p>
    </div>
</x-app-layout>
