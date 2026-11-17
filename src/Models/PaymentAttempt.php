<?php

namespace Webkul\NowPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\NowPayments\Contracts\PaymentAttempt as PaymentAttemptContract;

class PaymentAttempt extends Model implements PaymentAttemptContract
{
    /**
     * The payment tracking table.
     */
    protected $table = 'nowpayments_payment_attempts';

    /**
     * The attributes available for assignment.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * The attribute casts.
     */
    protected $casts = [
        'sandbox' => 'boolean',
        'price_amount' => 'decimal:4',
        'settled_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
