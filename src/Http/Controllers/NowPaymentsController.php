<?php

namespace Webkul\NowPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Webkul\Checkout\Facades\Cart;
use Webkul\NowPayments\Repositories\PaymentAttemptRepository;
use Webkul\NowPayments\Services\HostedCheckout;
use Webkul\NowPayments\Services\IpnSignature;
use Webkul\NowPayments\Services\NowPaymentsClient;
use Webkul\NowPayments\Services\PaymentSettlement;

class NowPaymentsController extends Controller
{
    /**
     * Create the gateway controller.
     */
    public function __construct(
        protected PaymentAttemptRepository $attempts,
        protected HostedCheckout $checkout,
        protected NowPaymentsClient $client,
        protected IpnSignature $signature,
        protected PaymentSettlement $settlement,
    ) {}

    /**
     * Redirect a validated cart to hosted crypto checkout.
     */
    public function redirect(): RedirectResponse
    {
        if (Cart::requiresSignInToCheckout()) {
            return redirect()->route('shop.checkout.onepage.index', request()->only('buy_now'));
        }

        if (! $cart = Cart::getCart()) {
            return redirect()->route('shop.checkout.cart.index');
        }

        try {
            $attempt = $this->checkout->start($cart);

            return redirect()->away($attempt->invoice_url);
        } catch (\Throwable) {
            logger()->warning('NOWPayments could not create a hosted checkout.');
            session()->flash('error', trans('nowpayments::app.payment-failed'));

            return redirect()->route('shop.checkout.cart.index');
        }
    }

    /**
     * Display progress without accepting browser parameters as proof of payment.
     */
    public function status(string $reference): View
    {
        $attempt = $this->attempts->findOneWhere(['reference' => $reference]);
        abort_unless($attempt, 404);

        return view('nowpayments::status', [
            'attempt' => $attempt,
            'returnUrl' => $this->checkout->returnUrl($attempt),
        ]);
    }

    /**
     * Verify a notification before fetching and settling its payment independently of sessions.
     */
    public function webhook(Request $request, ?string $reference = null): JsonResponse
    {
        $reference ??= $request->input('order_id');
        abort_unless(is_string($reference), 404);
        $attempt = $this->attempts->findOneWhere(['reference' => $reference]);
        abort_unless($attempt, 404);
        $secret = $this->client->setting(($attempt->sandbox ? 'test_' : '').'ipn_secret', $attempt->channel_code);

        if (! $this->signature->verify($request->getContent(), $request->header('x-nowpayments-sig'), (string) $secret)) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $paymentId = $request->json('payment_id');

        if (! preg_match('/^\d{1,30}$/D', is_scalar($paymentId) ? (string) $paymentId : '')) {
            return response()->json(['status' => 'invalid_payment'], 422);
        }

        if (
            $request->json('order_id') !== $attempt->reference
            || (string) $request->json('invoice_id') !== $attempt->invoice_id
        ) {
            return response()->json(['status' => 'payment_mismatch'], 422);
        }

        try {
            $attempt = $this->attempts->rememberPayment($attempt->id, (string) $paymentId);

            return response()->json(['status' => $this->settlement->reconcile($attempt, (string) $paymentId)]);
        } catch (\DomainException) {
            return response()->json(['status' => 'payment_mismatch'], 422);
        } catch (\Throwable) {
            logger()->warning('NOWPayments settlement will need to be retried.', ['reference' => $reference]);

            return response()->json(['status' => 'retry'], 503);
        }
    }
}
