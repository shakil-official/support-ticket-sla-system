<?php

namespace App\Http\Controllers;

use App\Exceptions\TicketAlreadyAssignedException;
use App\Http\Requests\AssignTicketRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketAssignmentController extends Controller
{
    public function __construct(private readonly TicketAssignmentService $assignment) {}

    /** An agent accepts an unassigned ticket. The service resolves the race (atomic UPDATE). */
    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('claim', $ticket);

        try {
            $this->assignment->claim($ticket, $request->user());
        } catch (TicketAlreadyAssignedException $e) {
            // The loser of the race: not an error page, just a clear message.
            return redirect()->route('tickets.index')->with('error', $e->getMessage());
        }

        return redirect()->route('tickets.show', $ticket)->with('status', 'You accepted this ticket.');
    }

    /** Administrator picks (or changes) the agent. */
    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        try {
            $this->assignment->assign($ticket, User::query()->findOrFail($request->validated('agent_id')), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Ticket assigned.');
    }
}
