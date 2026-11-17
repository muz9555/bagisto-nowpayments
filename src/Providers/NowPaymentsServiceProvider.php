<?php

namespace Webkul\NowPayments\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Webkul\NowPayments\Console\Commands\InstallNowPayments;
use Webkul\NowPayments\Console\Commands\ReconcilePayments;

class NowPaymentsServiceProvider extends ServiceProvider
{
    /**
     * Register payment methods and admin settings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/payment-methods.php', 'payment_methods');
        $this->mergeConfigFrom(__DIR__.'/../Config/system.php', 'core');
        $this->app->booting(function () {
            $concord = $this->app->make('concord');

            if (! $concord->getModules(true)->contains(fn ($module) => $module instanceof ModuleServiceProvider)) {
                $concord->registerModule(ModuleServiceProvider::class);
            }
        });
    }

    /**
     * Load the gateway and schedule payment reconciliation.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'nowpayments');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'nowpayments');
        $this->commands([InstallNowPayments::class, ReconcilePayments::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('nowpayments:reconcile')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
