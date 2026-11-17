<?php

namespace Webkul\NowPayments\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;
use Webkul\NowPayments\Models\PaymentAttempt;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    /**
     * The models registered with Concord.
     */
    protected $models = [PaymentAttempt::class];
}
