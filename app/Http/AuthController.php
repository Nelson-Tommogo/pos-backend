<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => ['required', 'string', 'regex:/\\A[^\\x00]{1,72}\\z/']]);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! Hash::check($data['password'], $user?->password ?? '$2y$12$GMxmZYsWIMmOWUzC7f8CHuCAine6ZDgObGtiAdy/vjdYcwsKb.aMe') || ! $user) {
            throw ValidationException::withMessages(['email' => 'The credentials are invalid.']);
        }

        return ['user' => $user, 'token' => $user->createToken('pos')->plainTextToken];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink(['email' => strtolower($data['email'])]);

        return response()->json(['message' => 'If the account exists, a reset link will be sent.'], 202);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'token' => 'required|string|max:255',
            'password' => ['required', 'string', 'min:12', 'regex:/\\A[^\\x00]{1,72}\\z/', 'confirmed'],
        ]);
        $data['email'] = strtolower($data['email']);
        $result = DB::transaction(function () use ($data) {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password): void {
                $user->update(['password' => $password]);
                $user->tokens()->delete();
            });
        });
        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'The reset link is invalid or expired.']);
        }

        return ['message' => 'Password updated.'];
    }
}
