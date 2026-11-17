<?php

namespace Webkul\NowPayments\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Eloquent\Repository;
use Webkul\NowPayments\Contracts\PaymentAttempt;

class PaymentAttemptRepository extends Repository
{
    /**
     * Specify the model contract.
     */
    public function model(): string
    {
        return PaymentAttempt::class;
    }

    /**
     * Lock a payment attempt until its transaction completes.
     */
    public function lock(int $id): PaymentAttempt
    {
        return $this->model->newQuery()->lockForUpdate()->findOrFail($id);
    }

    /**
     * Remember an authenticated payment identifier before a potentially unavailable status lookup.
     */
    public function rememberPayment(int $id, string $paymentId): PaymentAttempt
    {
        return DB::transaction(function () use ($id, $paymentId) {
            $attempt = $this->lock($id);

            if ($attempt->payment_id && $attempt->payment_id !== $paymentId) {
                throw new \DomainException('Another payment is already bound to this invoice.');
            }

            return $attempt->payment_id ? $attempt : $this->update(['payment_id' => $paymentId], $id);
        });
    }

    /**
     * Return unsettled payments eligible for reconciliation.
     */
    public function pending(int $afterId, int $limit = 50): Collection
    {
        return $this->model->newQuery()
            ->where('id', '>', $afterId)
            ->whereNull('settled_at')
            ->whereNotIn('status', ['failed', 'expired', 'refunded', 'reservation_expired', 'needs_review'])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
