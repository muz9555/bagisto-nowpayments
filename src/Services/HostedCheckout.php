<?php

namespace Webkul\NowPayments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Webkul\Checkout\Contracts\Cart as CartContract;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\NowPayments\Contracts\PaymentAttempt;
use Webkul\NowPayments\Payment\NowPayments;
use Webkul\NowPayments\Repositories\PaymentAttemptRepository;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\OrderCommentRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;
use Webkul\Shop\Http\Controllers\API\OnepageController;

class HostedCheckout
{
    /**
     * Create the checkout service.
     */
    public function __construct(
        protected PaymentAttemptRepository $attempts,
        protected CartRepository $carts,
        protected OrderRepository $orders,
        protected NowPayments $gateway,
        protected NowPaymentsClient $client,
        protected OrderCommentRepository $comments,
    ) {}

    /**
     * Reserve the order and start one hosted invoice for this cart.
     */
    public function start(CartContract $cart): PaymentAttempt
    {
        return DB::transaction(function () use ($cart) {
            $cart = $this->carts->scopeQuery(fn ($query) => $query->lockForUpdate())->findOrFail($cart->id);
            $this->carts->resetScope();

            if ($attempt = $this->attempts->findOneWhere(['cart_id' => $cart->id])) {
                return $attempt;
            }

            if (
                ! $cart->is_active
                || $cart->payment?->method !== 'nowpayments'
                || ! $this->gateway->isAvailable()
            ) {
                throw new \DomainException('NOWPayments is not available for this cart.');
            }

            Cart::setCart($cart);
            Cart::collectTotals();

            if (Cart::hasError()) {
                throw new \DomainException('The cart cannot be checked out.');
            }

            app(OnepageController::class)->validateOrder();
            $cart = Cart::getCart();

            if ($cart->grand_total <= 0) {
                throw new \DomainException('A crypto payment must have a positive total.');
            }

            $this->carts->update(['is_active' => false], $cart->id);
            $order = $this->orders->create((new OrderResource($cart))->jsonSerialize());
            $this->orders->update(['status' => Order::STATUS_PENDING_PAYMENT], $order->id);

            $attempt = $this->attempts->create([
                'reference' => (string) Str::uuid(),
                'cart_id' => $cart->id,
                'order_id' => $order->id,
                'channel_code' => $cart->channel->code,
                'sandbox' => (bool) $this->gateway->getConfigData('sandbox'),
                'price_amount' => $order->grand_total,
                'price_currency' => strtolower($order->order_currency_code),
            ]);

            $invoice = $this->client->createInvoice($attempt, $this->returnUrl($attempt));
            $url = $invoice['invoice_url'] ?? '';
            $invoiceId = (string) ($invoice['id'] ?? '');

            if (
                ! preg_match('/^\d{1,30}$/D', $invoiceId)
                || ! $this->client->validInvoiceUrl($url, $attempt->sandbox)
            ) {
                throw new \UnexpectedValueException('NOWPayments returned an invalid invoice.');
            }

            $attempt = $this->attempts->update([
                'invoice_id' => $invoiceId,
                'invoice_url' => $url,
                'status' => 'waiting',
            ], $attempt->id);

            $this->comments->create([
                'order_id' => $order->id,
                'comment' => trans('nowpayments::app.order-reference', ['reference' => $attempt->reference]),
                'customer_notified' => false,
            ]);

            Cart::deActivateCart();

            return $attempt;
        });
    }

    /**
     * Sign the order progress URL so it works for guest customers without exposing other orders.
     */
    public function returnUrl(PaymentAttempt $attempt): string
    {
        return URL::temporarySignedRoute('nowpayments.status', now()->addDays(7), ['reference' => $attempt->reference]);
    }
}
