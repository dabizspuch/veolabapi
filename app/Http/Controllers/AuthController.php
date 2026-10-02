<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string',
            'password' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Datos no válidos', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('name', $request->input('name'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Credenciales no válidas'], 401);
        }

        return response()->json($this->issueToken($user));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    public function refresh(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->json($this->issueToken($user));
    }

    /** Emite un token; expires_at es null si los tokens no caducan. */
    private function issueToken(User $user): array
    {
        $minutes = config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;

        return [
            'token'      => $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
