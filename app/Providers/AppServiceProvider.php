<?php

namespace App\Providers;

use App\Interfaces\PaymentGatewayInterface;
use App\Modules\Employees\Models\Employee;
use App\Routing\UnicodeJsonResponseFactory;
use App\Services\MoyasarPaymentService;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    /**
     * `App\Models\*` classes that are only `class_alias()` shims for module models.
     * PHP does NOT autoload a class for `instanceof` / type checks, so e.g.
     * `$request->user() instanceof \App\Models\Employee` was `false` (→ 403) unless some
     * earlier code in the same request happened to load the alias file. Load them eagerly.
     *
     * @var list<string>
     */
    private const MODEL_ALIASES = [
        'BankAccount', 'City', 'Contract', 'ContractPeriod', 'Employee', 'EmployeeRefreshToken',
        'NotesEmployee', 'Paperwork', 'PaymentType', 'Permission', 'ReaEstatType', 'ReaEstatUsage',
        'Region', 'Role', 'Salary', 'ServicesPricing', 'TenantRole', 'UnitType', 'UnitUsage',
        'UsageUnit', 'User',
    ];

    public function register(): void
    {
        foreach (self::MODEL_ALIASES as $alias) {
            class_exists('App\\Models\\'.$alias); // triggers the alias file via the autoloader
        }

        $this->app->singleton(ResponseFactoryContract::class, function ($app) {
            return new UnicodeJsonResponseFactory(
                $app->make(ViewFactory::class),
                $app->make(Redirector::class)
            );
        });

        $this->app->alias(ResponseFactoryContract::class, 'Illuminate\Routing\ResponseFactory');

        $this->app->bind(PaymentGatewayInterface::class, MoyasarPaymentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short alias for tokens issued after this map existed. FQCN tokenable
        // types (App\Models\Employee / module class) still resolve as-is.
        Relation::morphMap([
            'employee' => Employee::class,
        ]);

        Validator::extend('valid_contract_start_date', function ($attribute, $value, $parameters, $validator) {
            $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $value);

            return $startDate->gte(now()->subDays(280));
        });

        // Password-reset emails link to the website's reset page (SPA), not a
        // backend Blade route. Verification links use the named backend route
        // `verification.verify`, which then redirects back to the site.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return $frontend.'/auth/reset-password?token='.$token.'&email='.$email;
        });
    }
}

