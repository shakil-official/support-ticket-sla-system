<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\ReplyAdded;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketResolved;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Sla\SlaService;
use App\Services\TicketNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * ~25 back-dated demo tickets so the dashboard, filters, SLA badges and the escalation
 * scheduler have something to show. SLA columns are calculated with the real SlaService,
 * so they follow the configured business hours. Runs only when no tickets exist.
 * Events are faked: seeding must not queue hundreds of e-mails.
 */
class DemoTicketSeeder extends Seeder
{
    public function run(SlaService $sla, TicketNumberGenerator $numbers, ActivityLogger $activity): void
    {
        if (app()->isProduction() || Ticket::query()->exists()) {
            return;
        }

        Event::fake([TicketCreated::class, TicketAssigned::class, ReplyAdded::class, TicketResolved::class]);

        $customers = User::query()->where('role', 'customer')->get();
        $agents = User::query()->agents()->get();
        $categories = Category::query()->get();

        if ($customers->isEmpty() || $agents->isEmpty() || $categories->isEmpty()) {
            return;
        }

        $subjects = [
            'Cannot log in to my account', 'Invoice amount looks wrong', 'App crashes on export', 'Request: dark mode',
            'Password reset e-mail missing', 'Payment failed twice', 'Slow dashboard loading', 'How do I add a team member?',
            'Wrong currency on receipt', 'API returns 500 for large payloads', 'Cannot upload files', 'Two-factor code not working',
            'Refund request', 'Data export is incomplete', 'Typo on the pricing page',
        ];

        $priorities = TicketPriority::cases();
        $statuses = [
            TicketStatus::Open, TicketStatus::Open, TicketStatus::InProgress, TicketStatus::InProgress,
            TicketStatus::WaitingForCustomer, TicketStatus::Resolved, TicketStatus::Closed,
        ];

        for ($i = 0; $i < 25; $i++) {
            $createdAt = now()->subHours(rand(1, 120));
            $priority = $priorities[$i % 4];
            $status = $statuses[$i % count($statuses)];
            $customer = $customers[$i % $customers->count()];
            $agent = $status === TicketStatus::Open && $i % 2 === 0 ? null : $agents[$i % $agents->count()];

            $ticket = new Ticket([
                'subject' => $subjects[$i % count($subjects)],
                'description' => 'Demo ticket: '.$subjects[$i % count($subjects)].'. Please have a look, thank you!',
                'priority' => $priority->value,
                'category_id' => $categories[$i % $categories->count()]->id,
            ]);

            $ticket->forceFill([
                'ticket_number' => $numbers->next($createdAt),
                'created_by' => $customer->id,
                'status' => $status,
                'assigned_agent_id' => $agent?->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'resolved_at' => $status->isClosedState() ? $createdAt->copy()->addHours(2) : null,
                'closed_at' => $status === TicketStatus::Closed ? $createdAt->copy()->addHours(3) : null,
            ] + $sla->calculate($priority, $createdAt))->save();

            $activity->log($ticket, ActivityType::Created, "Ticket created by {$customer->name}", $customer);

            if ($agent) {
                $activity->log($ticket, ActivityType::Assigned, "Assigned to {$agent->name} by Admin User");
                $ticket->replies()->create([
                    'user_id' => $agent->id,
                    'body' => 'Thanks for reaching out, we are looking into this.',
                    'is_internal' => false,
                ]);
                $ticket->replies()->create([
                    'user_id' => $agent->id,
                    'body' => 'Internal: checked the logs, looks like a known issue.',
                    'is_internal' => true,
                ]);
            }
        }
    }
}
