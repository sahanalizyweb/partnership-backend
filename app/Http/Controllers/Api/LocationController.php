<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $query = Location::query();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->integer('parent_id'));
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => 'nullable|exists:locations,id',
            'type' => 'required|in:country,state,district,city,area',
            'name' => 'required|string|max:255',
            'status' => 'in:active,inactive',
        ]);

        return response()->json(Location::create($data), 201);
    }

    public function show(Location $location)
    {
        return $location->load('children');
    }

    public function update(Request $request, Location $location)
    {
        $data = $request->validate([
            'parent_id' => 'nullable|exists:locations,id',
            'type' => 'sometimes|required|in:country,state,district,city,area',
            'name' => 'sometimes|required|string|max:255',
            'status' => 'in:active,inactive',
        ]);

        $location->update($data);

        return $location;
    }

    public function destroy(Location $location)
    {
        $location->delete();

        return response()->json(null, 204);
    }
}
