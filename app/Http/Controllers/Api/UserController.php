<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'users' => User::all(),
        ], 'Users retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create($validated);

        return ApiResponse::success([
            'user' => $user,
        ], 'User successfully created.', 201);
    }

    public function show(User $user)
    {
        return ApiResponse::success([
            'user' => $user,
        ], 'User retrieved.');
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ]);

        $user->update($validated);

        return ApiResponse::success([
            'user' => $user->refresh(),
        ], 'User successfully updated.');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return ApiResponse::success(message: 'User successfully deleted.');
    }
}
