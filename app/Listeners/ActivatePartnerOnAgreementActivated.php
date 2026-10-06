<?php

namespace App\Listeners;

use App\Events\AgreementActivated;

class ActivatePartnerOnAgreementActivated
{
    public function handle(AgreementActivated $event): void
    {
        $partner = $event->agreement->partner;

        if (in_array($partner->status, ['agreement_pending', 'verified'], true)) {
            $partner->update(['status' => 'active', 'activated_at' => now()]);
        }
    }
}
