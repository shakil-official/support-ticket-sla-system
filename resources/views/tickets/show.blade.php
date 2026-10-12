@php
    $transitionLabels = [
        'open' => ['Reopen ticket', 'secondary'],
        'in_progress' => ['Set to In Progress', 'secondary'],
        'waiting_for_customer' => ['Wait for customer', 'secondary'],
        'resolved' => ['Mark as resolved', 'success'],
        'closed' => ['Close ticket', 'secondary'],
    ];
    $authUser = auth()->user();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-sm text-gray-500 dark:text-gray-400">{{ $ticket->ticket_number }}</div>
                <h1 class="text-xl font-semibold">{{ $ticket->subject }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <x-ticket.status :status="$ticket->status" />
                <x-ticket.priority :priority="$ticket->priority" />
                <x-ticket.sla :ticket="$ticket" />
            </div>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-3 lg:px-8">

        {{-- ================= Conversation ================= --}}
        <div class="space-y-6 lg:col-span-2">

            <x-ui.card class="p-5">
                <div class="mb-2 flex justify-between text-sm text-gray-500 dark:text-gray-400">
                    <span><strong class="text-gray-800 dark:text-gray-200">{{ $ticket->creator->name }}</strong> opened this ticket</span>
                    <x-ui.datetime :value="$ticket->created_at" />
                </div>
                <div class="whitespace-pre-wrap break-words">{{ $ticket->description }}</div>

                @if ($ticketAttachments->isNotEmpty())
                    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-700">
                        @include('tickets._attachments', ['attachments' => $ticketAttachments])
                    </div>
                @endif
            </x-ui.card>

            @foreach ($replies as $reply)
                @php $staffAuthor = $reply->author->isStaff(); @endphp
                <div @class([
                    'rounded-lg p-5 shadow-sm',
                    'bg-yellow-50 ring-1 ring-yellow-300 dark:bg-yellow-900/30 dark:ring-yellow-700' => $reply->is_internal,
                    'bg-indigo-50 dark:bg-indigo-900/30' => ! $reply->is_internal && $staffAuthor,
                    'bg-white dark:bg-gray-800' => ! $reply->is_internal && ! $staffAuthor,
                ])>
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>
                            <strong>{{ $reply->author->name }}</strong>
                            <span class="text-gray-500 dark:text-gray-400">({{ $reply->author->role->label() }})</span>
                            @if ($reply->is_internal)
                                <span class="ml-2 rounded bg-yellow-200 px-1.5 py-0.5 text-xs font-semibold text-yellow-900">Internal note · hidden from the customer</span>
                            @endif
                        </span>
                        <span class="text-gray-500 dark:text-gray-400"><x-ui.datetime :value="$reply->created_at" /></span>
                    </div>
                    <div class="whitespace-pre-wrap break-words">{{ $reply->body }}</div>

                    @if ($reply->attachments->isNotEmpty())
                        <div class="mt-3 border-t border-black/10 pt-3 dark:border-white/10">
                            @include('tickets._attachments', ['attachments' => $reply->attachments])
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Reply form --}}
            @can('reply', $ticket)
                <x-ui.card class="p-5">
                    <h2 class="mb-3 font-semibold">{{ $ticket->status === \App\Enums\TicketStatus::Resolved ? 'Reply (this reopens the ticket)' : 'Reply' }}</h2>
                    <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <x-ui.label for="body" class="sr-only">Message</x-ui.label>
                            <x-ui.textarea id="body" name="body" rows="5" required maxlength="10000" placeholder="Write your message…">{{ old('body') }}</x-ui.textarea>
                            <x-ui.error for="body" />
                        </div>

                        <div>
                            <input type="file" name="attachments[]" multiple class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-gray-200 dark:file:bg-gray-700">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Up to {{ config('tickets.attachments.max_files') }} files, {{ number_format(config('tickets.attachments.max_kb') / 1024) }} MB each ({{ implode(', ', config('tickets.attachments.mimes')) }}).</p>
                            <x-ui.error for="attachments" />
                            @foreach ($errors->get('attachments.*') as $messages)
                                @foreach ($messages as $message)
                                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @endforeach
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            @can('addInternalNote', $ticket)
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="is_internal" value="1" @checked(old('is_internal')) class="rounded border-gray-300 text-yellow-600 focus:ring-yellow-500">
                                    Internal note (customers never see it)
                                </label>
                            @else
                                <span></span>
                            @endcan
                            <x-ui.button type="submit">Send</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endcan

            {{-- Audit history: staff only --}}
            @if ($activities->isNotEmpty())
                <x-ui.card class="p-5">
                    <h2 class="mb-4 font-semibold">Activity history</h2>
                    <ol class="space-y-3 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                        @foreach ($activities as $activity)
                            <li class="relative text-sm">
                                <span class="absolute -left-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                                <span class="mr-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400"><x-ui.datetime :value="$activity->created_at" format="M j, g:i:s A" /></span>
                                {{ $activity->description }}
                                @if ($activity->user)<span class="text-gray-500 dark:text-gray-400">· {{ $activity->user->name }}</span>@endif
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endif
        </div>

        {{-- ================= Sidebar ================= --}}
        <aside class="space-y-6">
            <x-ui.card class="p-5">
                <h2 class="mb-3 font-semibold">Details</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Customer</dt><dd class="text-right">{{ $ticket->creator->name }}@if ($authUser->isStaff())<div class="text-xs text-gray-500">{{ $ticket->creator->email }}</div>@endif</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Category</dt><dd>{{ $ticket->category->name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Assigned agent</dt><dd>{{ $ticket->agent?->name ?? 'Unassigned' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Created</dt><dd><x-ui.datetime :value="$ticket->created_at" /></dd></div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">SLA due</dt>
                        <dd class="text-right"><x-ui.datetime :value="$ticket->due_at" />
                            @if ($ticket->status->isActive())<div class="text-xs text-gray-500 dark:text-gray-400">{{ $ticket->due_at->isPast() ? 'overdue by' : 'in' }} {{ $ticket->due_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>@endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">SLA target</dt><dd>{{ round($ticket->sla_resolution_minutes / 60, 1) }} business hours</dd></div>
                    @if ($authUser->isStaff())
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">First response</dt><dd><x-ui.datetime :value="$ticket->first_response_at" /></dd></div>
                        @if ($ticket->escalated_at)
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Escalated</dt><dd class="text-red-600 dark:text-red-400">level {{ $ticket->escalation_level }} · <x-ui.datetime :value="$ticket->escalated_at" /></dd></div>
                        @endif
                    @endif
                    @if ($ticket->resolved_at)
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Resolved</dt><dd><x-ui.datetime :value="$ticket->resolved_at" /></dd></div>
                    @endif
                </dl>
            </x-ui.card>

            @php
                $canClaim = $authUser->can('claim', $ticket);
                $canAssign = $authUser->can('assign', $ticket);
                $canEdit = $authUser->can('update', $ticket);
                $canDelete = $authUser->can('delete', $ticket);
            @endphp

            @if ($canClaim || $canAssign || $transitions->isNotEmpty() || $canEdit || $canDelete)
                <x-ui.card class="space-y-4 p-5">
                    <h2 class="font-semibold">Actions</h2>

                    @if ($canClaim)
                        <form method="POST" action="{{ route('tickets.claim', $ticket) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Accept this ticket</x-ui.button>
                        </form>
                    @endif

                    @if ($canAssign)
                        <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="space-y-2">
                            @csrf
                            <x-ui.label for="agent_id">{{ $ticket->agent ? 'Reassign to' : 'Assign to' }}</x-ui.label>
                            <x-ui.select id="agent_id" name="agent_id" required>
                                <option value="">Select an agent…</option>
                                @foreach ($agents as $agent)
                                    <option value="{{ $agent->id }}" @selected($ticket->assigned_agent_id === $agent->id)>{{ $agent->name }} ({{ $agent->active_tickets_count }} active)</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.error for="agent_id" />
                            <x-ui.button type="submit" variant="secondary" class="w-full">Assign</x-ui.button>
                        </form>
                    @endif

                    @foreach ($transitions as $to)
                        @php [$label, $variant] = $transitionLabels[$to->value]; @endphp
                        <form method="POST" action="{{ route('tickets.status', $ticket) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="to" value="{{ $to->value }}">
                            <x-ui.button type="submit" :variant="$variant" class="w-full">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach

                    @if ($canEdit)
                        <x-ui.button :href="route('tickets.edit', $ticket)" variant="secondary" class="w-full">Edit ticket</x-ui.button>
                    @endif

                    @if ($canDelete)
                        <form method="POST" action="{{ route('tickets.destroy', $ticket) }}" onsubmit="return confirm('Delete this ticket? It can be restored from the database only.');">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger" class="w-full">Delete ticket</x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            @endif
        </aside>
    </div>
</x-app-layout>
