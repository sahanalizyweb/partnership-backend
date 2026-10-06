<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerTypeEnrollment;
use App\Models\ResellerProductPrice;
use Illuminate\Http\Request;

/** Configurable per-reseller-enrollment product pricing (requirement #19) — admin-only. */
class ResellerProductPriceController extends Controller
{
    public function index(Request $request)
    {
        $query = ResellerProductPrice::with(['partnerTypeEnrollment.partner', 'product']);

        if ($request->filled('partner_type_enrollment_id')) {
            $query->where('partner_type_enrollment_id', $request->integer('partner_type_enrollment_id'));
        }

        return $query->orderByDesc('id')->paginate($request->integer('per_page', 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_type_enrollment_id' => 'required|exists:partner_type_enrollments,id',
            'product_id' => 'required|exists:products,id',
            'purchase_price' => 'required|numeric|min:0',
            'recommended_selling_price' => 'nullable|numeric|min:0',
            'status' => 'in:active,inactive',
        ]);

        $enrollment = PartnerTypeEnrollment::with('partnerType')->findOrFail($data['partner_type_enrollment_id']);
        abort_unless($enrollment->partnerType?->code === 'reseller', 422, 'Pricing can only be set for a Reseller enrollment.');

        $price = ResellerProductPrice::updateOrCreate(
            ['partner_type_enrollment_id' => $data['partner_type_enrollment_id'], 'product_id' => $data['product_id']],
            ['purchase_price' => $data['purchase_price'], 'recommended_selling_price' => $data['recommended_selling_price'] ?? null, 'status' => $data['status'] ?? 'active']
        );

        return response()->json($price->load('product'), 201);
    }

    public function update(Request $request, ResellerProductPrice $resellerProductPrice)
    {
        $data = $request->validate([
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'recommended_selling_price' => 'nullable|numeric|min:0',
            'status' => 'in:active,inactive',
        ]);

        $resellerProductPrice->update($data);

        return $resellerProductPrice->fresh(['product']);
    }

    public function destroy(ResellerProductPrice $resellerProductPrice)
    {
        $resellerProductPrice->delete();

        return response()->json(null, 204);
    }
}
