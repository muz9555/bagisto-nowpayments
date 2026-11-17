<?php

namespace Webkul\NowPayments\Services;

class PaymentAmounts
{
    /**
     * Compare nonnegative amounts without subtracting floating point cryptocurrency values.
     */
    public function compare(mixed $left, mixed $right, int $scale = 18): ?int
    {
        $left = $this->normalize($left, $scale);
        $right = $this->normalize($right, $scale);

        if ($left === null || $right === null) {
            return null;
        }

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right) <=> 0;
    }

    /**
     * Normalize an API decimal into an unsigned integer of the requested scale.
     */
    protected function normalize(mixed $amount, int $scale): ?string
    {
        if (is_float($amount)) {
            $amount = sprintf('%.'.$scale.'F', $amount);
        }

        if (
            ! is_scalar($amount)
            || ! preg_match('/^\d{1,20}(?:\.\d{1,18})?$/D', (string) $amount)
        ) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        if (trim(substr($fraction, $scale), '0') !== '') {
            return null;
        }

        return ltrim($whole.str_pad(substr($fraction, 0, $scale), $scale, '0'), '0') ?: '0';
    }
}
