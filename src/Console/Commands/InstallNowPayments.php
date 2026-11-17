<?php

namespace Webkul\NowPayments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallNowPayments extends Command
{
    /**
     * Install only this gateway's migrations without enabling real payments.
     */
    protected $signature = 'nowpayments:install {--force : Run package migrations in production}';

    /**
     * Describe the installation command.
     */
    protected $description = 'Install the NOWPayments payment tracking table in an existing Bagisto store';

    /**
     * Migrate package records and clear stale application caches.
     */
    public function handle(): int
    {
        if (! Schema::hasTable('orders')) {
            $this->error('Install and migrate Bagisto before installing NOWPayments.');

            return self::FAILURE;
        }

        $result = $this->call('migrate', [
            '--path' => realpath(__DIR__.'/../../Database/Migrations'),
            '--realpath' => true,
            '--force' => (bool) $this->option('force'),
        ]);

        if ($result !== self::SUCCESS) {
            return $result;
        }

        $this->call('optimize:clear');
        $this->info('NOWPayments installed. Configure API credentials and the IPN secret under Sales > Payment Methods.');
        $this->info('Payment settings and existing orders were preserved. No gateway was enabled automatically.');

        return self::SUCCESS;
    }
}
