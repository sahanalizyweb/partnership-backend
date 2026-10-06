<?php

namespace App\Events;

use App\Models\Assignment;
use Illuminate\Foundation\Events\Dispatchable;

class AssignmentCompleted
{
    use Dispatchable;

    public function __construct(public Assignment $assignment) {}
}
