<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Http\Requests\Admin\UpdateBusinessHoursRequest;
use App\Http\Requests\Admin\UpdateSlaPoliciesRequest;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\SlaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * SLA rules, business hours and holidays are DATA. Editing them here changes the behaviour of
 * the whole application without touching code. (Models flush the config caches on save.)
 * Existing tickets keep the deadline they were created with (snapshot); a new priority on an
 * existing ticket is recalculated with the rules valid at that moment.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        $policies = SlaPolicy::query()->get()->keyBy(fn (SlaPolicy $p) => $p->priority->value);

        return view('admin.settings', [
            'priorities' => TicketPriority::cases(),
            'policies' => $policies,
            'days' => BusinessHour::query()->orderBy('day_of_week')->get()->keyBy('day_of_week'),
            'holidays' => Holiday::query()->orderBy('date')->get(),
            'timezone' => config('tickets.timezone'),
        ]);
    }

    public function updateSla(UpdateSlaPoliciesRequest $request): RedirectResponse
    {
        foreach ($request->validated('policies') as $priority => $values) {
            SlaPolicy::query()->updateOrCreate(['priority' => $priority], [
                'resolution_minutes' => $values['resolution_minutes'],
                'warning_threshold_percent' => $values['warning_threshold_percent'],
            ]);
        }

        return back()->with('status', 'SLA policies saved. They apply to tickets created or re-prioritised from now on.');
    }

    public function updateHours(UpdateBusinessHoursRequest $request): RedirectResponse
    {
        foreach ($request->validated('days') as $day => $row) {
            BusinessHour::query()->updateOrCreate(['day_of_week' => (int) $day], [
                'is_working' => (bool) ($row['is_working'] ?? false),
                'start_time' => $row['start_time'].':00',
                'end_time' => $row['end_time'].':00',
            ]);
        }

        return back()->with('status', 'Business hours saved.');
    }

    public function storeHoliday(StoreHolidayRequest $request): RedirectResponse
    {
        Holiday::query()->create($request->validated());

        return back()->with('status', 'Holiday added.');
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('status', 'Holiday removed.');
    }
}
