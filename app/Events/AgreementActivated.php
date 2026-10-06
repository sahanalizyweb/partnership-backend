<?php

namespace App\Events;

use App\Models\Agreement;
use Illuminate\Foundation\Events\Dispatchable;

class AgreementActivated
{
    use Dispatchable;

    public function __construct(public Agreement $agreement) {}
}
