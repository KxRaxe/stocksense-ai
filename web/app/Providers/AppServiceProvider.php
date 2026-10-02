<?php

namespace App\Providers;

use App\Services\Inventory\LocationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (and per queued job), so a long-running worker never
        // holds on to a stale location.
        $this->app->scoped(LocationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureEmails();
    }

    /**
     * Email policy: messages never contain personal data. Laravel's password
     * reset link normally carries the recipient's email address in its query
     * string, so build it from the one-time token alone. The reset page asks
     * the person to type their email address instead.
     */
    protected function configureEmails(): void
    {
        ResetPassword::createUrlUsing(
            fn ($notifiable, string $token) => route('password.reset', ['token' => $token]),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
