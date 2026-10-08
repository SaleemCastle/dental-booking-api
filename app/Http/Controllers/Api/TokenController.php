<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'token_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return ApiResponse::success([
            'token_type' => 'Bearer',
            'access_token' => $user->createToken($validated['token_name'] ?? 'swagger')->plainTextToken,
        ], 'Token created.', 201);
    }

    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(message: 'Token revoked.', request: $request);
    }
}
