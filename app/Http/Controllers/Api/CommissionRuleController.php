<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToRequester;
use App\Http\Controllers\Controller;
use App\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionRuleController extends Controller
{
    use ScopesToRequester;

    public function index(Request $request)
    {
        $query = CommissionRule::with(['partner', 'partnerCategory', 'partnerType', 'slabs']);
        $query = $this->scopeBusiness($query, $request);

        return $query->orderByDesc('id')->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'business_id' => $user->business_id ? 'nullable' : 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'partner_id' => 'nullable|exists:partners,id',
            'partner_category_id' => 'nullable|exists:partner_categories,id',
            'partner_type_id' => 'nullable|exists:partner_types,id',
            'rule_type' => 'required|in:percentage,fixed,product,category,slab',
            'value' => 'nullable|numeric|min:0',
            'product_ref' => 'nullable|string|max:255',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'slabs' => 'required_if:rule_type,slab|array',
            'slabs.*.min_value' => 'required_with:slabs|numeric|min:0',
            'slabs.*.max_value' => 'nullable|numeric|min:0',
            'slabs.*.rate' => 'required_with:slabs|numeric|min:0',
        ]);

        abort_unless($data['partner_id'] ?? $data['partner_category_id'] ?? $data['partner_type_id'] ?? null, 422, 'Rule must target a partner, category, or type.');

        return DB::transaction(function () use ($data, $user) {
            $slabs = $data['slabs'] ?? [];
            unset($data['slabs']);

            $rule = CommissionRule::create($data + [
                'business_id' => $user->business_id ?: $data['business_id'],
                'status' => 'active',
            ]);

            foreach ($slabs as $slab) {
                $rule->slabs()->create($slab);
            }

            return response()->json($rule->load('slabs'), 201);
        });
    }

    public function show(CommissionRule $commissionRule)
    {
        return $commissionRule->load('partner', 'partnerCategory', 'partnerType', 'slabs');
    }

    public function update(Request $request, CommissionRule $commissionRule)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'effective_from' => 'sometimes|required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'status' => 'in:active,inactive',
        ]);

        $commissionRule->update($data);

        return $commissionRule;
    }

    public function destroy(CommissionRule $commissionRule)
    {
        $commissionRule->delete();

        return response()->json(null, 204);
    }
}
