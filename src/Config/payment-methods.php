<?php

use Webkul\NowPayments\Payment\NowPayments;

return [
    'nowpayments' => [
        'code' => 'nowpayments',
        'class' => NowPayments::class,
        'title' => 'Cryptocurrency (NOWPayments)',
        'description' => 'Pay with cryptocurrency using NOWPayments.',
        'active' => false,
        'sandbox' => true,
        'sort' => 10,
    ],
];
