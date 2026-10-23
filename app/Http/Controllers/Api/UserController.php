<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'users' => User::forCurrentTenant()->get(),
        ], 'Users retrieved.');
    }

    public function store(Request $request, UserWorkflow $workflow)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = $workflow->create($validated, $request->user());

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

    public function update(Request $request, User $user, UserWorkflow $workflow)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ]);

        $user = $workflow->update($user, $validated, $request->user());

        return ApiResponse::success([
            'user' => $user->refresh(),
        ], 'User successfully updated.');
    }

    public function destroy(Request $request, User $user, UserWorkflow $workflow)
    {
        $workflow->delete($user, $request->user());

        return ApiResponse::success(message: 'User successfully deleted.');
    }
}
