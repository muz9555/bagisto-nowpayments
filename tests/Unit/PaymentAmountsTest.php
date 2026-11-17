<?php

use Webkul\NowPayments\Services\PaymentAmounts;

// ============================================================================
// Exact Payment Coverage
// ============================================================================

it('compares cryptocurrency amounts without rounding an underpayment up', function () {
    $amounts = new PaymentAmounts;

    expect($amounts->compare('0.000000000000000001', '0'))->toBe(1)
        ->and($amounts->compare('0.123456789123456788', '0.123456789123456789'))->toBe(-1)
        ->and($amounts->compare('100.0000', '100', 4))->toBe(0)
        ->and($amounts->compare('100.00001', '100', 4))->toBeNull()
        ->and($amounts->compare('NaN', '1'))->toBeNull()
        ->and($amounts->compare('-1', '1'))->toBeNull();
});
