<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Identity\Services\SessionIssuer;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Passwordless sign-in with an SMS code. The first sign-in creates the account. */
class OtpAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SessionIssuer $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function request(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $user = User::where('phone', $phone)->first();

        // With 2FA on, the phone is the second factor, so it cannot also be the only one.
        if (! ($user?->two_factor_enabled)) {
            $this->otp->send($phone, OtpPurpose::Login, $user, $request->ip());
        }

        return response()->json([
            'message' => 'If this number can sign in with SMS, a code is on its way.',
            'phone_hint' => PhoneNumber::mask($phone),
            'expires_in' => (int) config('nguzopay.otp.ttl_minutes') * 60,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'size:'.config('nguzopay.otp.length')],
            'name' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('phone', $phone)->first();

        if ($user?->two_factor_enabled) {
            throw ValidationException::withMessages(['phone' => 'This account uses two-step verification. Sign in with your password, Google or GitHub.']);
        }

        $this->otp->verify($phone, OtpPurpose::Login, $data['code']);

        $created = false;
        if (! $user) {
            $user = new User(['name' => $data['name'] ?? null, 'phone' => $phone]);
            $created = true;
        }
        $user->phone_verified_at ??= now();
        $user->save();

        if ($created) {
            $this->audit->record('auth.registered', $user, ['method' => 'sms'], actor: $user);
        }

        return $this->sessionResponse($this->sessions->issue($user, 'sms', $data['device_name'] ?? null), $created ? 201 : 200);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.config('nguzopay.otp.length')],
        ]);

        return $this->sessionResponse($this->sessions->completeTwoFactor($data['challenge'], $data['code']));
    }
}
