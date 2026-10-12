<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidTicketTransitionException;
use App\Http\Requests\TransitionTicketRequest;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;

class TicketStatusController extends Controller
{
    public function __invoke(TransitionTicketRequest $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        try {
            $ticket = $tickets->changeStatus($ticket, $request->target(), $request->user());
        } catch (InvalidTicketTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Status changed to {$ticket->status->label()}.");
    }
}
