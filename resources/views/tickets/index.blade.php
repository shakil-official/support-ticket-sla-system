@php
    $viewParam = $closedView ? 'resolved' : 'active';
    $isStaff = auth()->user()->isStaff();
    $hasFilters = collect($filters)->except('view')->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    $slaOptions = $closedView
        ? ['met' => 'SLA met', 'breached' => 'SLA breached']
        : ['within' => 'Within SLA', 'approaching' => 'Approaching breach', 'breached' => 'SLA breached'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">{{ $closedView ? 'Resolved & closed tickets' : 'Active tickets' }}</h1>
            <div class="flex gap-2">
                <x-ui.button :href="route('tickets.index')" :variant="$closedView ? 'secondary' : 'primary'">Active</x-ui.button>
                <x-ui.button :href="route('tickets.index', ['view' => 'resolved'])" :variant="$closedView ? 'primary' : 'secondary'">Resolved</x-ui.button>
                <x-ui.button :href="route('tickets.create')" variant="success">New ticket</x-ui.button>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">

        {{-- Search & filters (GET, so every result page is bookmarkable) --}}
        <x-ui.card class="p-4">
            <form method="GET" action="{{ route('tickets.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <input type="hidden" name="view" value="{{ $viewParam }}">

                <div class="sm:col-span-2">
                    <x-ui.label for="q">Ticket ID or keyword</x-ui.label>
                    <x-ui.input id="q" name="q" :value="$filters['q'] ?? ''" placeholder="TKT-2026-… or words from the subject / description" />
                    <x-ui.error for="q" />
                </div>

                <div>
                    <x-ui.label for="status">Status</x-ui.label>
                    <x-ui.select id="status" name="status">
                        <option value="">Any</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="priority">Priority</x-ui.label>
                    <x-ui.select id="priority" name="priority">
                        <option value="">Any</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="category_id">Category</x-ui.label>
                    <x-ui.select id="category_id" name="category_id">
                        <option value="">Any</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="sla">SLA status</x-ui.label>
                    <x-ui.select id="sla" name="sla">
                        <option value="">Any</option>
                        @foreach ($slaOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['sla'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                @if ($isStaff)
                    <div>
                        <x-ui.label for="agent">Assigned agent</x-ui.label>
                        <x-ui.select id="agent" name="agent">
                            <option value="">Any</option>
                            <option value="unassigned" @selected(($filters['agent'] ?? '') === 'unassigned')>Unassigned</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}" @selected((string) ($filters['agent'] ?? '') === (string) $agent->id)>{{ $agent->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div>
                        <x-ui.label for="customer">Customer (name or e-mail)</x-ui.label>
                        <x-ui.input id="customer" name="customer" :value="$filters['customer'] ?? ''" />
                    </div>
                @endif

                <div>
                    <x-ui.label for="date_from">Created from</x-ui.label>
                    <x-ui.input id="date_from" type="date" name="date_from" :value="$filters['date_from'] ?? ''" />
                </div>

                <div>
                    <x-ui.label for="date_to">Created to</x-ui.label>
                    <x-ui.input id="date_to" type="date" name="date_to" :value="$filters['date_to'] ?? ''" />
                    <x-ui.error for="date_to" />
                </div>

                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit">Search</x-ui.button>
                    @if ($hasFilters)
                        <x-ui.button :href="route('tickets.index', ['view' => $viewParam])" variant="secondary">Clear filters</x-ui.button>
                    @endif
                    <span class="ml-auto text-sm text-gray-500 dark:text-gray-400">{{ $tickets->total() }} ticket(s)</span>
                </div>
            </form>
        </x-ui.card>

        {{-- Results --}}
        <x-ui.card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-700/50 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-3">Ticket</th>
                            <th class="px-3 py-3">Priority</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Category</th>
                            @if ($isStaff)<th class="px-3 py-3">Customer</th>@endif
                            <th class="px-3 py-3">Agent</th>
                            <th class="px-3 py-3">{{ $closedView ? 'Resolved' : 'SLA due' }}</th>
                            <th class="px-3 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($tickets as $ticket)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="max-w-xs px-4 py-3">
                                    <a href="{{ route('tickets.show', $ticket) }}" class="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $ticket->ticket_number }}</a>
                                    <div class="truncate" title="{{ $ticket->subject }}">{{ $ticket->subject }}</div>
                                </td>
                                <td class="px-3 py-3"><x-ticket.priority :priority="$ticket->priority" /></td>
                                <td class="px-3 py-3"><x-ticket.status :status="$ticket->status" /></td>
                                <td class="px-3 py-3">{{ $ticket->category->name }}</td>
                                @if ($isStaff)<td class="px-3 py-3">{{ $ticket->creator->name }}</td>@endif
                                <td class="px-3 py-3">
                                    @if ($ticket->agent)
                                        {{ $ticket->agent->name }}
                                    @else
                                        <span class="text-gray-400">Unassigned</span>
                                        @can('claim', $ticket)
                                            <form method="POST" action="{{ route('tickets.claim', $ticket) }}" class="mt-1">
                                                @csrf
                                                <button type="submit" class="rounded bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white hover:bg-indigo-500">Accept</button>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">
                                    <x-ui.datetime :value="$closedView ? $ticket->resolved_at : $ticket->due_at" format="M j, g:i A" />
                                    <div class="mt-1"><x-ticket.sla :ticket="$ticket" /></div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-500 dark:text-gray-400"><x-ui.datetime :value="$ticket->created_at" format="M j, Y" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isStaff ? 8 : 7 }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    No tickets found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div>{{ $tickets->links() }}</div>
    </div>
</x-app-layout>
