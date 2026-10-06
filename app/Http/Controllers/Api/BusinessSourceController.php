<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSource;
use Illuminate\Http\Request;

/** Configurable earning-source lookup (LizyMart, LizyNet, Service, Referral, ...) — mirrors PartnerTypeController exactly. */
class BusinessSourceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = BusinessSource::query()->where(function ($q) use ($user) {
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
            'code' => 'required|string|max:50',
            'description' => 'nullable|string',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(BusinessSource::create($data), 201);
    }

    public function show(BusinessSource $businessSource)
    {
        return $businessSource;
    }

    public function update(Request $request, BusinessSource $businessSource)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50',
            'description' => 'nullable|string',
            'status' => 'in:active,inactive',
        ]);

        $businessSource->update($data);

        return $businessSource;
    }

    public function destroy(BusinessSource $businessSource)
    {
        $businessSource->delete();

        return response()->json(null, 204);
    }
}
