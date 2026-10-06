<?php

namespace App\Listeners;

use App\Events\AssignmentCompleted;
use App\Services\CommissionCalculationService;

class GenerateCommissionOnAssignmentCompleted
{
    public function __construct(protected CommissionCalculationService $commissionCalculator) {}

    public function handle(AssignmentCompleted $event): void
    {
        $this->commissionCalculator->generateForAssignment($event->assignment);
    }
}
