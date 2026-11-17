<?php

use Webkul\NowPayments\Tests\NowPaymentsTestCase;

if (getenv('BAGISTO_PATH')) {
    require_once __DIR__.'/bootstrap.php';

    uses(NowPaymentsTestCase::class)->in('Feature');
}
