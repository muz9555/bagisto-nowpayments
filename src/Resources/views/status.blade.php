<x-shop::layouts>
    <x-slot:title>
        @lang('nowpayments::app.payment-status')
    </x-slot>

    <div class="container py-10">
        <h1 class="text-2xl font-medium">@lang('nowpayments::app.payment-status')</h1>

        <p class="mt-4">
            @lang('nowpayments::app.reference'): {{ $attempt->reference }}
        </p>

        <p class="mt-4">
            @if ($attempt->settled_at)
                @lang('nowpayments::app.paid')
            @elseif (in_array($attempt->status, ['needs_review', 'partially_paid', 'refunded']))
                @lang('nowpayments::app.review')
            @elseif (in_array($attempt->status, ['failed', 'expired', 'reservation_expired']))
                @lang('nowpayments::app.unpaid')
            @else
                @lang('nowpayments::app.pending')
            @endif
        </p>

        <div class="mt-6 flex flex-wrap gap-4">
            <a href="{{ $returnUrl }}">@lang('nowpayments::app.refresh')</a>

            @if (! $attempt->settled_at && in_array($attempt->status, ['waiting', 'created']))
                <a href="{{ $attempt->invoice_url }}" rel="noreferrer">@lang('nowpayments::app.continue-payment')</a>
            @endif

            <a href="{{ route('shop.home.index') }}">@lang('nowpayments::app.continue-shopping')</a>
        </div>
    </div>
</x-shop::layouts>
