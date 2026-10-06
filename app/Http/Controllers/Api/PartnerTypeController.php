<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerType;
use Illuminate\Http\Request;

class PartnerTypeController extends Controller
{
    public function index(Request $request)
    {
        // Global types (business_id null) plus this user's business-specific types.
        $user = $request->user();

        $query = PartnerType::query()->where(function ($q) use ($user) {
            $q->whereNull('business_id');
            if ($user->business_id) {
                $q->orWhere('business_id', $user->business_id);
            }
        });

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'business_id' => 'nullable|exists:businesses,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(PartnerType::create($data), 201);
    }

    public function show(PartnerType $partnerType)
    {
        return $partnerType;
    }

    public function update(Request $request, PartnerType $partnerType)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'status' => 'in:active,inactive',
        ]);

        $partnerType->update($data);

        return $partnerType;
    }

    public function destroy(PartnerType $partnerType)
    {
        $partnerType->delete();

        return response()->json(null, 204);
    }
}
