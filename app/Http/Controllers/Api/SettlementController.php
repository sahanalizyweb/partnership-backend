<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\Settlement;
use App\Services\SettlementService;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = Settlement::with('partner');
        $query = $this->scopeBusiness($query, $request);
        $query = $this->scopePartner($query, $request);

        foreach (['status', 'partner_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request, SettlementService $settlementService)
    {
        $data = $request->validate([
            'partner_id' => 'required|exists:partners,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $partner = Partner::findOrFail($data['partner_id']);

        $settlement = $settlementService->run($partner, $data['period_start'], $data['period_end']);

        return response()->json($settlement->load('items', 'partner'), 201);
    }

    public function show(Settlement $settlement)
    {
        return $settlement->load('partner', 'items', 'payments', 'approvedBy');
    }

    public function approve(Request $request, Settlement $settlement, SettlementService $settlementService)
    {
        abort_unless(in_array($settlement->status, ['draft', 'pending_approval'], true), 422, 'Settlement cannot be approved from its current status.');

        return $settlementService->approve($settlement, $request->user()->id)->load('partner', 'payments');
    }

    public function submitForApproval(Settlement $settlement)
    {
        abort_unless($settlement->status === 'draft', 422, 'Only draft settlements can be submitted.');

        $settlement->update(['status' => 'pending_approval']);

        return $settlement;
    }

    public function reject(Request $request, Settlement $settlement)
    {
        $settlement->update(['status' => 'rejected']);

        return $settlement;
    }
}
