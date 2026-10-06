<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerCategory;
use Illuminate\Http\Request;

class PartnerCategoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PartnerCategory::with(['partnerType', 'parent'])->where(function ($q) use ($user) {
            $q->whereNull('business_id');
            if ($user->business_id) {
                $q->orWhere('business_id', $user->business_id);
            }
        });

        if ($request->filled('partner_type_id')) {
            $query->where('partner_type_id', $request->integer('partner_type_id'));
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'business_id' => 'nullable|exists:businesses,id',
            'partner_type_id' => 'required|exists:partner_types,id',
            'parent_id' => 'nullable|exists:partner_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rules' => 'nullable|array',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(PartnerCategory::create($data), 201);
    }

    public function show(PartnerCategory $partnerCategory)
    {
        return $partnerCategory->load(['partnerType', 'parent', 'children']);
    }

    public function update(Request $request, PartnerCategory $partnerCategory)
    {
        $data = $request->validate([
            'partner_type_id' => 'sometimes|required|exists:partner_types,id',
            'parent_id' => 'nullable|exists:partner_categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'rules' => 'nullable|array',
            'status' => 'in:active,inactive',
        ]);

        $partnerCategory->update($data);

        return $partnerCategory;
    }

    public function destroy(PartnerCategory $partnerCategory)
    {
        $partnerCategory->delete();

        return response()->json(null, 204);
    }
}
