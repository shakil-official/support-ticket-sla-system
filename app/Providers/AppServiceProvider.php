<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Sla\BusinessSchedule;
use App\Services\Sla\SlaCalculator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // BusinessSchedule needs scalar constructor data, so the container cannot autowire it.
        // `bind` (not singleton): every resolution reads the (cached) schedule, so a settings
        // change is picked up by long running queue workers as soon as the cache is flushed.
        $this->app->bind(SlaCalculator::class, fn () => new SlaCalculator(
            BusinessSchedule::fromDatabase(),
            config('app.timezone'),
        ));
    }

    public function boot(): void
    {
        // N+1 detector: outside production every lazy load is written to the log.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::warning('N+1 / lazy loading detected', ['model' => $model::class, 'relation' => $relation]);
        });

        // Gates for capabilities that are not about one specific record.
        // Record level rules live in App\Policies\TicketPolicy / AttachmentPolicy.
        Gate::define('import-tickets', fn (User $user) => $user->isAdmin());
        Gate::define('manage-settings', fn (User $user) => $user->isAdmin());
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());

        RateLimiter::for('ticket-write', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->id ?: $request->ip())));
    }
}
