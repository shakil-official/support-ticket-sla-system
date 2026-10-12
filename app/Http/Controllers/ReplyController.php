<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReplyRequest;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;

class ReplyController extends Controller
{
    public function store(StoreReplyRequest $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $internal = $request->boolean('is_internal');

        $tickets->addReply(
            $ticket,
            $request->user(),
            $request->validated('body'),
            $internal,
            $request->file('attachments', []),
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('status', $internal ? 'Internal note added.' : 'Reply sent.');
    }
}
