<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ledger_entries. Keeps running_balance correct by always posting
 * relative to the partner's last entry inside a transaction (locked to avoid races
 * between concurrent postings for the same partner).
 */
class LedgerPostingService
{
    public function post(
        Partner $partner,
        string $transactionType,
        float $debit,
        float $credit,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $entryDate = null,
    ): LedgerEntry {
        return DB::transaction(function () use (
            $partner, $transactionType, $debit, $credit, $description, $referenceType, $referenceId, $entryDate
        ) {
            $lastBalance = LedgerEntry::where('partner_id', $partner->id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('running_balance') ?? 0;

            $runningBalance = $lastBalance + $credit - $debit;

            return LedgerEntry::create([
                'business_id' => $partner->business_id,
                'partner_id' => $partner->id,
                'entry_date' => $entryDate ?? now()->toDateString(),
                'transaction_type' => $transactionType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $runningBalance,
                'description' => $description,
            ]);
        });
    }

    public function openingBalance(Partner $partner, float $amount, ?string $description = null): LedgerEntry
    {
        return $this->post($partner, 'opening_balance', 0, $amount, $description ?? 'Opening balance');
    }
}
