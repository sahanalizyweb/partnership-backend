<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe, backend-only reference number generator shared by every module that
 * needs one (Partner, Sale, Supply, Payment, Settlement, Assignment, Reseller...). Each
 * counter scope gets its own row in `reference_counters`; incrementing it under a row
 * lock inside a transaction means two simultaneous requests can never be handed the same
 * number, without needing an app-wide lock or a database-specific sequence object.
 */
class ReferenceNumberService
{
    /**
     * "PREFIX-000123" numbers are stored in columns that are unique across ALL businesses
     * (e.g. supplies.reference_number), so they draw from one global sequence per prefix.
     * A per-business sequence handed the second business "SUPPLY-000001" again, which the
     * unique index rejected. $businessId is kept in the signature for existing callers.
     */
    public function next(string $prefix, ?int $businessId = null, int $pad = 6): string
    {
        $number = $this->increment($prefix.':global', function () use ($prefix) {
            // First use of the global sequence: continue after the highest number any
            // business's old per-business counter already issued, so no past number repeats.
            return (int) DB::table('reference_counters')
                ->where('scope_key', 'like', $prefix.':%')
                ->max('next_number');
        });

        return $prefix.'-'.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * The raw incrementing integer, per business, for callers that build their own visible
     * format that already includes the business (e.g. Partner codes "ABC-XY-0001").
     */
    public function nextNumber(string $scopePrefix, ?int $businessId = null): int
    {
        return $this->increment($scopePrefix.':'.($businessId ?? 0), fn () => 0);
    }

    private function increment(string $scopeKey, callable $startFrom): int
    {
        return DB::transaction(function () use ($scopeKey, $startFrom) {
            if (! DB::table('reference_counters')->where('scope_key', $scopeKey)->exists()) {
                DB::table('reference_counters')->insertOrIgnore([
                    'scope_key' => $scopeKey,
                    'next_number' => $startFrom(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $row = DB::table('reference_counters')->where('scope_key', $scopeKey)->lockForUpdate()->first();

            DB::table('reference_counters')->where('id', $row->id)->update([
                'next_number' => $row->next_number + 1,
                'updated_at' => now(),
            ]);

            return $row->next_number + 1;
        });
    }
}
