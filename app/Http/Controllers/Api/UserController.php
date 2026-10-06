<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('roles', 'business')->whereNull('partner_id');

        $user = $request->user();
        if ($user->business_id) {
            $query->where(fn ($q) => $q->where('business_id', $user->business_id)->orWhereNull('business_id'));
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $requester = $request->user();

        $data = $request->validate([
            'business_id' => $requester->business_id ? 'nullable' : 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'business_id' => $requester->business_id ?: ($data['business_id'] ?? null),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        $user->assignRole($data['role']);

        return response()->json($user->load('roles'), 201);
    }

    public function show(User $user)
    {
        return $user->load('roles', 'business');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,'.$user->id,
            'status' => 'in:active,inactive',
            'role' => 'nullable|string|exists:roles,name',
        ]);

        $role = $data['role'] ?? null;
        unset($data['role']);

        $user->update($data);

        if ($role) {
            $user->syncRoles([$role]);
        }

        return $user->fresh('roles');
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => 'required|string|min:8']);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated']);
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(null, 204);
    }
}
