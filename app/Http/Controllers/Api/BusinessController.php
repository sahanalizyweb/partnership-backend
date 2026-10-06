<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index()
    {
        return Business::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:businesses,code',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(Business::create($data), 201);
    }

    public function show(Business $business)
    {
        return $business;
    }

    public function update(Request $request, Business $business)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:businesses,code,'.$business->id,
            'status' => 'in:active,inactive',
        ]);

        $business->update($data);

        return $business;
    }

    public function destroy(Business $business)
    {
        $business->delete();

        return response()->json(null, 204);
    }
}
