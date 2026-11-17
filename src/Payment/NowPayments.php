<?php

namespace Webkul\NowPayments\Payment;

use Illuminate\Support\Facades\Storage;
use Webkul\Payment\Payment\Payment;

class NowPayments extends Payment
{
    /**
     * The registered payment method code.
     */
    protected $code = 'nowpayments';

    /**
     * Return the hosted checkout entry point.
     */
    public function getRedirectUrl(): string
    {
        return route('nowpayments.redirect');
    }

    /**
     * Offer crypto checkout only after credentials are configured.
     */
    public function isAvailable(): bool
    {
        $prefix = $this->getConfigData('sandbox') ? 'test_' : '';

        return (bool) $this->getConfigData('active')
            && filled($this->getConfigData($prefix.'api_key'))
            && filled($this->getConfigData($prefix.'ipn_secret'));
    }

    /**
     * Return an optional merchant-uploaded payment image.
     */
    public function getImage(): string
    {
        $image = $this->getConfigData('image');

        return $image ? Storage::url($image) : 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 55 55"><circle cx="27.5" cy="27.5" r="25" fill="#102c49"/><path d="M35 18a13 13 0 1 0 0 19" fill="none" stroke="#fff" stroke-width="4"/><path d="M24 10v35m7-35v35" stroke="#fff" stroke-width="2"/></svg>'
        );
    }
}
