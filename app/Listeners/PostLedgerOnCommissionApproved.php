<?php

namespace App\Listeners;

use App\Events\CommissionApproved;
use App\Models\Commission;
use App\Services\LedgerPostingService;

class PostLedgerOnCommissionApproved
{
    public function __construct(protected LedgerPostingService $ledger) {}

    public function handle(CommissionApproved $event): void
    {
        $commission = $event->commission;

        // 'from_partner' is Service Partner Model A: the partner already collected payment
        // directly from the customer and owes this commission to us, so it's a debit
        // (increases what the partner owes) instead of the normal credit.
        $isOwedByPartner = $commission->direction === 'from_partner';

        $this->ledger->post(
            partner: $commission->partner,
            transactionType: 'commission',
            debit: $isOwedByPartner ? (float) $commission->commission_amount : 0,
            credit: $isOwedByPartner ? 0 : (float) $commission->commission_amount,
            description: 'Commission approved: '.$commission->transaction_reference,
            referenceType: Commission::class,
            referenceId: $commission->id,
        );
    }
}
