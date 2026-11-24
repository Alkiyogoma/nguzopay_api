<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\SessionIssuer;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordAuthController extends Controller
{
    public function __construct(
        private readonly SessionIssuer $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        event(new Registered($user)); // sends the verification email
        $this->audit->record('auth.registered', $user, ['method' => 'password'], actor: $user);

        return $this->sessionResponse($this->sessions->issue($user, 'password', $data['device_name'] ?? null), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', Str::lower($data['email']))->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            $this->audit->record('auth.login_failed', $user, ['method' => 'password', 'email' => $data['email']], actor: $user);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return $this->sessionResponse($this->sessions->begin($user, 'password', $data['device_name'] ?? null, $request->ip()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        $this->audit->record('auth.logout_all', $request->user());

        return response()->json(['message' => 'Signed out on every device.']);
    }
}
