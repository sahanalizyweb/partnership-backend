<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Partner;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = LedgerEntry::with('partner');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->integer('partner_id'));
        }
        if ($request->filled('from')) {
            $query->where('entry_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('entry_date', '<=', $request->date('to'));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 30));
    }

    /** Partner statement: full running-balance history plus current balance. */
    public function statement(Request $request, Partner $partner)
    {
        $entries = $partner->ledgerEntries()->orderBy('entry_date')->orderBy('id')->get();

        return response()->json([
            'partner' => $partner->only('id', 'name', 'partner_code'),
            'current_balance' => (float) ($entries->last()->running_balance ?? 0),
            'entries' => $entries,
        ]);
    }
}
