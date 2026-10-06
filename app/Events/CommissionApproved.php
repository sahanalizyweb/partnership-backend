<?php

namespace App\Events;

use App\Models\Commission;
use Illuminate\Foundation\Events\Dispatchable;

class CommissionApproved
{
    use Dispatchable;

    public function __construct(public Commission $commission) {}
}
