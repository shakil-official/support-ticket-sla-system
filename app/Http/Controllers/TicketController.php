<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filters\TicketFilter;
use App\Http\Requests\IndexTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Thin controller: authorize -> call the service -> redirect/render. No business logic here. */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(IndexTicketRequest $request, TicketFilter $filter): View
    {
        $this->authorize('viewAny', Ticket::class);

        $user = $request->user();
        $filters = $request->validated();
        $closedView = ($filters['view'] ?? 'active') === 'resolved';

        $query = Ticket::query()
            ->visibleTo($user)
            ->with(['creator:id,name', 'agent:id,name', 'category:id,name']) // no N+1 in the list
            ->when(
                $closedView,
                fn ($q) => $q->closed()->orderByDesc('tickets.resolved_at'),
                fn ($q) => $q->active()->orderBy('tickets.due_at'),   // most urgent first (index status,due_at)
            )
            ->orderByDesc('tickets.id');

        $tickets = $filter->apply($query, $filters, $user, $closedView)
            ->paginate(20)
            ->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'closedView' => $closedView,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'agents' => $user->isStaff() ? User::query()->agents()->orderBy('name')->get(['id', 'name']) : collect(),
            'statuses' => $closedView
                ? [TicketStatus::Resolved, TicketStatus::Closed]
                : TicketStatus::activeCases(),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Ticket::class);

        return view('tickets.create', [
            'categories' => Category::query()->active()->orderBy('name')->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $ticket = $this->tickets->create(
            $request->user(),
            $request->validated(),
            $request->file('attachments', []),
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('status', "Ticket {$ticket->ticket_number} was created.");
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $user = $request->user();

        $ticket->load(['creator:id,name,email', 'agent:id,name', 'category:id,name']);

        // Internal notes are removed IN THE QUERY for customers (TicketReply::scopeVisibleTo).
        $replies = $ticket->replies()
            ->visibleTo($user)
            ->with(['author:id,name,role', 'attachments'])
            ->oldest('id')
            ->get();

        $activities = $user->can('viewActivity', $ticket)
            ? $ticket->activities()->with('user:id,name')->oldest('id')->get()
            : collect();

        $transitions = collect($ticket->status->allowedTransitions())
            ->filter(fn (TicketStatus $to) => Gate::forUser($user)->allows('transition', [$ticket, $to]))
            ->values();

        return view('tickets.show', [
            'ticket' => $ticket,
            'replies' => $replies,
            'ticketAttachments' => $ticket->attachments()->whereNull('reply_id')->get(),
            'activities' => $activities,
            'transitions' => $transitions,
            'agents' => $user->can('assign', $ticket)
                ? User::query()->agents()->select('users.id', 'users.name')->orderBy('users.name')
                    ->withCount(['assignedTickets as active_tickets_count' => fn ($q) => $q->active()])
                    ->get()
                : collect(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('tickets.edit', [
            'ticket' => $ticket,
            'categories' => Category::query()->active()->orderBy('name')->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->tickets->update($ticket, $request->user(), $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', 'Ticket updated.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        $this->tickets->delete($ticket);

        return redirect()->route('tickets.index')->with('status', "Ticket {$ticket->ticket_number} was deleted.");
    }
}
