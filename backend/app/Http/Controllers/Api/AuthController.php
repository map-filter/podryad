<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'app_name' => 'Кабинет допуска подрядчика',
            'sign_mode' => config('permits.sign_mode') === 'ncalayer' ? 'ncalayer' : 'emulate',
            'mailpit_url' => config('permits.mailpit_url'),
            'telegram_configured' => (bool) config('permits.telegram.token'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $key = 'login:'.mb_strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['email' => 'Слишком много попыток входа, подождите минуту']);
        }
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль']);
        }
        RateLimiter::clear($key);
        $token = $user->createToken('cabinet')->plainTextToken;

        return response()->json(['token' => $token, 'user' => Present::user($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => Present::user($user),
            'organization' => $user->organization ? Present::organization($user->organization) : null,
            'customer' => $user->customer ? Present::customer($user->customer, false) : null,
        ]);
    }
}
