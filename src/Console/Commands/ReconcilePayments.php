<?php

namespace Webkul\NowPayments\Console\Commands;

use Illuminate\Console\Command;
use Webkul\NowPayments\Repositories\PaymentAttemptRepository;
use Webkul\NowPayments\Services\PaymentSettlement;

class ReconcilePayments extends Command
{
    /**
     * The reconciliation command and optional manual recovery identifiers.
     */
    protected $signature = 'nowpayments:reconcile {reference?} {--payment-id=}';

    /**
     * The command description.
     */
    protected $description = 'Reconcile known crypto payments or recover one using its reference and NOWPayments payment ID';

    /**
     * Reconcile known payments while reporting failures for later retries.
     */
    public function handle(PaymentAttemptRepository $attempts, PaymentSettlement $settlement): int
    {
        if ($reference = $this->argument('reference')) {
            $attempt = $attempts->findOneWhere(['reference' => $reference]);
            $paymentId = $this->option('payment-id') ?: $attempt?->payment_id;

            if (! $attempt || ! $paymentId) {
                $this->error('A valid reference and payment ID are required.');

                return self::FAILURE;
            }

            try {
                $this->info($settlement->reconcile($attempt, (string) $paymentId));

                return self::SUCCESS;
            } catch (\Throwable) {
                $this->error('Payment reconciliation failed; check the reference, payment ID and credentials.');

                return self::FAILURE;
            }
        }

        $afterId = 0;
        $failed = false;

        while (($batch = $attempts->pending($afterId))->isNotEmpty()) {
            foreach ($batch as $attempt) {
                $afterId = $attempt->id;

                try {
                    if ($attempt->payment_id) {
                        $settlement->reconcile($attempt, $attempt->payment_id);
                    }

                    $settlement->expireReservation($attempt);
                } catch (\Throwable) {
                    $failed = true;
                    $this->warn('Could not reconcile payment reference '.$attempt->reference.'.');
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
