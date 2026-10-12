<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $user = $request->user();

        // "Needs attention" list: the most overdue active tickets the user may see.
        $attention = Ticket::query()
            ->visibleTo($user)
            ->slaBreached()
            ->with(['creator:id,name', 'agent:id,name', 'category:id,name'])
            ->orderBy('tickets.due_at')
            ->limit(5)
            ->get();

        return view('dashboard', [
            'stats' => $dashboard->stats($user),
            'attention' => $attention,
        ]);
    }
}
