<?php

namespace Webkul\NowPayments\Services;

use Illuminate\Support\Facades\DB;
use Webkul\NowPayments\Contracts\PaymentAttempt;
use Webkul\NowPayments\Repositories\PaymentAttemptRepository;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderCommentRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\OrderTransactionRepository;

class PaymentSettlement
{
    /**
     * Create the payment settlement service.
     */
    public function __construct(
        protected NowPaymentsClient $client,
        protected PaymentAmounts $amounts,
        protected PaymentAttemptRepository $attempts,
        protected OrderRepository $orders,
        protected InvoiceRepository $invoices,
        protected OrderTransactionRepository $transactions,
        protected OrderCommentRepository $comments,
    ) {}

    /**
     * Fetch authoritative payment data and apply it exactly once under database locks.
     */
    public function reconcile(PaymentAttempt $attempt, string $paymentId): string
    {
        $payment = $this->client->payment($attempt, $paymentId);
        $this->validatePayment($attempt, $paymentId, $payment);

        return DB::transaction(function () use ($attempt, $paymentId, $payment) {
            $attempt = $this->attempts->lock($attempt->id);

            if ($attempt->settled_at) {
                return 'already_settled';
            }

            if ($attempt->payment_id && $attempt->payment_id !== $paymentId) {
                throw new \DomainException('Another payment is already bound to this invoice.');
            }

            $order = $this->orders->scopeQuery(fn ($query) => $query->lockForUpdate())->findOrFail($attempt->order_id);
            $this->orders->resetScope();
            $status = (string) ($payment['payment_status'] ?? 'unknown');
            $previousStatus = $attempt->status;
            $attempt = $this->attempts->update([
                'payment_id' => $paymentId,
                'status' => $status,
                'checked_at' => now(),
            ], $attempt->id);

            if ($status !== $previousStatus) {
                $this->recordStatus($attempt, $status);
            }

            if (in_array($status, ['failed', 'expired'], true)) {
                if ($order->status === Order::STATUS_PENDING_PAYMENT) {
                    $this->orders->cancel($order);
                }

                return $status;
            }

            if ($status !== 'finished') {
                return $status;
            }

            if (
                $order->status !== Order::STATUS_PENDING_PAYMENT
                || $order->payment?->method !== 'nowpayments'
                || strtolower($order->order_currency_code) !== $attempt->price_currency
                || $this->amounts->compare($order->grand_total, $attempt->price_amount, 4) !== 0
                || ! $this->coversPayment($payment)
                || ! $order->canInvoice()
                || $order->invoices->isNotEmpty()
            ) {
                $this->attempts->update(['status' => 'needs_review'], $attempt->id);
                $this->recordStatus($attempt, 'needs_review');

                return 'needs_review';
            }

            $items = [];

            foreach ($order->items as $item) {
                if ($item->qty_to_invoice > 0) {
                    $items[$item->id] = $item->qty_to_invoice;
                }
            }

            $invoice = $this->invoices->create([
                'order_id' => $order->id,
                'invoice' => ['items' => $items],
            ]);

            $this->transactions->create([
                'transaction_id' => $paymentId,
                'status' => 'finished',
                'type' => 'nowpayments',
                'payment_method' => 'nowpayments',
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'amount' => $order->base_grand_total,
                'data' => json_encode([
                    'invoice_id' => $attempt->invoice_id,
                    'payment_id' => $paymentId,
                    'pay_currency' => $payment['pay_currency'] ?? null,
                    'actually_paid' => $payment['actually_paid'] ?? null,
                ], JSON_THROW_ON_ERROR),
            ]);

            $this->attempts->update(['status' => 'finished', 'settled_at' => now()], $attempt->id);

            return 'settled';
        });
    }

    /**
     * Release overdue stock reservations while holding any later payment for manual review.
     */
    public function expireReservation(PaymentAttempt $attempt): void
    {
        $hours = max(1, (int) ($this->client->setting('reservation_hours', $attempt->channel_code) ?: 24));

        if ($attempt->created_at->copy()->addHours($hours)->isFuture()) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $attempt = $this->attempts->lock($attempt->id);

            if ($attempt->settled_at) {
                return;
            }

            $order = $this->orders->scopeQuery(fn ($query) => $query->lockForUpdate())->findOrFail($attempt->order_id);
            $this->orders->resetScope();

            if ($order->status === Order::STATUS_PENDING_PAYMENT) {
                $this->orders->cancel($order);
                $this->attempts->update([
                    'status' => $attempt->status === 'partially_paid' ? 'needs_review' : 'reservation_expired',
                ], $attempt->id);
                $this->recordStatus($attempt, 'reservation_expired');
            }
        });
    }

    /**
     * Reject payments that do not belong to this exact invoice and fiat quote.
     */
    protected function validatePayment(PaymentAttempt $attempt, string $paymentId, array $payment): void
    {
        if (
            (string) ($payment['payment_id'] ?? '') !== $paymentId
            || (string) ($payment['invoice_id'] ?? '') !== $attempt->invoice_id
            || ($payment['order_id'] ?? '') !== $attempt->reference
            || strtolower((string) ($payment['price_currency'] ?? '')) !== $attempt->price_currency
            || $this->amounts->compare($payment['price_amount'] ?? null, $attempt->price_amount, 4) !== 0
        ) {
            throw new \DomainException('The NOWPayments payment does not match its invoice.');
        }
    }

    /**
     * Require the full quoted cryptocurrency amount, independently of settlement wallet fees.
     */
    protected function coversPayment(array $payment): bool
    {
        $positive = $this->amounts->compare($payment['pay_amount'] ?? null, '0');
        $coverage = $this->amounts->compare($payment['actually_paid'] ?? null, $payment['pay_amount'] ?? null);

        return $positive === 1
            && $coverage !== null
            && $coverage >= 0;
    }

    /**
     * Keep gateway status changes visible in the existing admin order comments.
     */
    protected function recordStatus(PaymentAttempt $attempt, string $status): void
    {
        $this->comments->create([
            'order_id' => $attempt->order_id,
            'comment' => trans('nowpayments::app.order-status', [
                'reference' => $attempt->reference,
                'status' => $status,
            ]),
            'customer_notified' => false,
        ]);
    }
}
