<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('tenant:id,name');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json($users);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['Administrator', 'Sales', 'Support', 'SuperAdmin'])],
        ]);

        $user->update($data);

        return response()->json($user->fresh()->load('tenant:id,name'));
    }

    public function destroy(User $user)
    {
        // Prevent deleting the last SuperAdmin
        if ($user->role === 'SuperAdmin' && User::where('role', 'SuperAdmin')->count() <= 1) {
            return response()->json(['message' => 'Cannot remove the last SuperAdmin.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User removed.']);
    }
}
