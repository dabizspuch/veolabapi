<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{

    public function login(Request $request)
    {

        $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
        ]);
       
        // Buscar usuario
        $user = User::where('name', $request->name)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Generar token si la autenticación es correcta
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['token' => $token]);
    }

}