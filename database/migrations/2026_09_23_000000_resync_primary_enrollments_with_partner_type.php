<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Changing a partner's type used to leave the primary enrollment on the old type, so the
 * Partner Portal kept showing the previous type's menus. PartnerController now keeps them in
 * sync on every update — this repairs rows that drifted before that fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('partner_type_enrollments as e')
            ->join('partners as p', 'p.id', '=', 'e.partner_id')
            ->where('e.is_primary', true)
            ->where(fn ($q) => $q->whereColumn('e.partner_type_id', '!=', 'p.partner_type_id')
                ->orWhereRaw('NOT (e.partner_category_id <=> p.partner_category_id)'))
            ->select('e.id', 'e.partner_id', 'e.partner_type_id as old_type_id', 'p.partner_type_id', 'p.partner_category_id', 'p.partner_code')
            ->get();

        foreach ($rows as $row) {
            $update = ['partner_category_id' => $row->partner_category_id, 'updated_at' => now()];

            if ((int) $row->old_type_id !== (int) $row->partner_type_id) {
                // Same type already held as a secondary role — fold it into the primary.
                DB::table('partner_type_enrollments')
                    ->where('partner_id', $row->partner_id)
                    ->where('partner_type_id', $row->partner_type_id)
                    ->where('id', '!=', $row->id)
                    ->delete();

                $code = DB::table('partner_types')->where('id', $row->partner_type_id)->value('code') ?? 'primary';
                $update['partner_type_id'] = $row->partner_type_id;
                $update['enrollment_code'] = $row->partner_code.'-'.strtoupper($code);
            }

            DB::table('partner_type_enrollments')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // Data repair only — nothing to reverse.
    }
};
