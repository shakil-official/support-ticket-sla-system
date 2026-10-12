<?php

namespace App\Console\Commands;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\TicketAlreadyAssignedException;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaService;
use App\Services\TicketAssignmentService;
use App\Services\TicketNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Live proof of the "two agents accept the same ticket" requirement.
 *
 * Every round creates one unassigned ticket, then forks two REAL operating-system processes
 * (each with its own MySQL connection). Both wait for the same start instant and call
 * TicketAssignmentService::claim() at the same time. Exactly one must win.
 *
 *   docker compose exec app php artisan tickets:race-demo --rounds=25
 *
 * Needs MySQL (SQLite has no row level concurrency), the pcntl extension (installed in the
 * Docker image) and the demo seeders. Events are faked so nobody receives e-mails.
 */
class RaceDemoCommand extends Command
{
    protected $signature = 'tickets:race-demo {--rounds=20 : number of simultaneous-claim rounds}';

    protected $description = 'Demonstrate that two agents claiming the same ticket at the same time never both succeed';

    private const WON = 0;
    private const LOST = 1;
    private const ERROR = 2;

    public function handle(TicketAssignmentService $assignment, TicketNumberGenerator $numbers, SlaService $sla): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        if (! function_exists('pcntl_fork')) {
            $this->error('The pcntl extension is required (it is installed in the Docker image).');

            return self::FAILURE;
        }

        if (DB::getDriverName() !== 'mysql') {
            $this->error('Run this against MySQL; SQLite cannot run two writers at once.');

            return self::FAILURE;
        }

        $agents = User::query()->agents()->orderBy('id')->limit(2)->get();
        $customer = User::query()->where('role', 'customer')->first();
        $category = Category::query()->first();

        if ($agents->count() < 2 || $customer === null || $category === null) {
            $this->error('Need 2 agents, 1 customer and 1 category. Run: php artisan db:seed');

            return self::FAILURE;
        }

        Event::fake(); // no notifications for demo tickets

        $rounds = max(1, (int) $this->option('rounds'));
        $violations = 0;
        $winsPerAgent = [$agents[0]->id => 0, $agents[1]->id => 0];
        $created = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $ticket = new Ticket([
                'subject' => "[race-demo] round {$round}",
                'description' => 'Temporary ticket created by tickets:race-demo',
                'priority' => TicketPriority::Medium->value,
                'category_id' => $category->id,
            ]);
            $ticket->forceFill([
                'ticket_number' => $numbers->next(),
                'created_by' => $customer->id,
                'status' => TicketStatus::Open,
            ] + $sla->calculate(TicketPriority::Medium, now()));
            $ticket->save();
            $created[] = $ticket->id;

            $results = $this->raceForTicket($assignment, $ticket->id, $agents->all());

            $winners = array_keys(array_filter($results, fn (int $r) => $r === self::WON));
            $errors = count(array_filter($results, fn (int $r) => $r === self::ERROR));
            $owner = Ticket::query()->whereKey($ticket->id)->value('assigned_agent_id');

            $ok = count($winners) === 1 && $errors === 0 && (int) $owner === $winners[0];
            $violations += $ok ? 0 : 1;

            if ($ok) {
                $winsPerAgent[$winners[0]]++;
            }

            $this->line(sprintf(
                'round %2d  winner: %s  loser: already assigned  owner in DB: %s  %s',
                $round,
                $ok ? 'agent #'.$winners[0] : 'n/a',
                $owner ?? 'none',
                $ok ? 'OK' : '<error>VIOLATION</error>',
            ));
        }

        // The demo tickets are not real data.
        Ticket::withTrashed()->whereIn('id', $created)->forceDelete();

        $this->newLine();
        foreach ($winsPerAgent as $agentId => $wins) {
            $this->line("agent #{$agentId} won {$wins} round(s)");
        }

        if ($violations > 0) {
            $this->error("{$violations} round(s) violated the rule: a ticket must have exactly one owner.");

            return self::FAILURE;
        }

        $this->info("All {$rounds} rounds: exactly one agent got the ticket every time.");

        return self::SUCCESS;
    }

    /**
     * @param  list<User>  $agents
     * @return array<int, int>  agent id => WON | LOST | ERROR
     */
    private function raceForTicket(TicketAssignmentService $assignment, int $ticketId, array $agents): array
    {
        DB::disconnect(); // children must not share the parent's socket

        $startAt = microtime(true) + 0.3;   // both processes fire at (almost) the same instant
        $pids = [];

        foreach ($agents as $agent) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error('fork failed');

                return [$agents[0]->id => self::ERROR, $agents[1]->id => self::ERROR];
            }

            if ($pid === 0) {          // ---- child process ----
                $code = self::ERROR;

                try {
                    DB::reconnect();
                    $ticket = Ticket::query()->findOrFail($ticketId);

                    while (microtime(true) < $startAt) {
                        usleep(200);
                    }

                    $assignment->claim($ticket, $agent);
                    $code = self::WON;
                } catch (TicketAlreadyAssignedException) {
                    $code = self::LOST;
                } catch (Throwable $e) {
                    fwrite(STDERR, 'child error: '.$e->getMessage().PHP_EOL);
                }

                exit($code);
            }

            $pids[$agent->id] = $pid;
        }

        $results = [];
        foreach ($pids as $agentId => $pid) {
            pcntl_waitpid($pid, $status);
            $results[$agentId] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : self::ERROR;
        }

        DB::reconnect();

        return $results;
    }
}
