<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): UserResource
    {
        return new UserResource($request->user()->load('socialAccounts', 'memberships'));
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $request->merge(array_filter(['email' => $request->has('email') ? Str::lower((string) $request->input('email')) : null]));

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            if ($data['email'] === null && $user->signInMethodCount() <= 1 && $user->hasPassword()) {
                throw ValidationException::withMessages(['email' => 'Add another way to sign in before removing your email.']);
            }
            $user->email_verified_at = null;
        }

        $user->fill($data)->save();

        if ($user->wasChanged('email') && $user->email) {
            $user->sendEmailVerificationNotification();
        }

        $this->audit->record('profile.updated', $user, ['fields' => array_keys($user->getChanges())]);

        return $this->show($request);
    }

    public function password(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => [$user->hasPassword() ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        if ($user->hasPassword() && ! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        if (! $user->email) {
            throw ValidationException::withMessages(['password' => 'Add an email address first: you sign in with email and password.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Sign out every other device.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current?->id, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        $this->audit->record('profile.password_changed', $user);

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    public function requestPhone(Request $request): JsonResponse
    {
        $user = $request->user();
        $phone = $this->phone($request);

        if (User::where('phone', $phone)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['phone' => 'This number is already used by another account.']);
        }

        $this->otp->send($phone, OtpPurpose::VerifyPhone, $user, $request->ip());

        return response()->json(['message' => 'Code sent.', 'phone_hint' => PhoneNumber::mask($phone)]);
    }

    public function verifyPhone(Request $request): UserResource
    {
        $user = $request->user();
        $phone = $this->phone($request);
        $request->validate(['code' => ['required', 'string']]);

        $otp = $this->otp->verify($phone, OtpPurpose::VerifyPhone, $request->input('code'));

        if ($otp->user_id !== $user->id) {
            throw ValidationException::withMessages(['code' => 'This code was not issued to you.']);
        }

        if (User::where('phone', $phone)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['phone' => 'This number is already used by another account.']);
        }

        $user->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
        $this->audit->record('profile.phone_verified', $user);

        return $this->show($request);
    }

    public function twoFactor(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'password' => [$user->hasPassword() ? 'required' : 'nullable', 'string'],
        ]);

        if ($user->hasPassword() && ! Hash::check((string) $data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is incorrect.']);
        }

        if (! $data['enabled'] && $this->twoFactorRequired($user)) {
            throw ValidationException::withMessages(['enabled' => 'Your role requires two-step verification, so it cannot be turned off. Contact NguzoPay support if you lost your phone.']);
        }

        if ($data['enabled'] && ! $user->hasVerifiedPhone()) {
            throw ValidationException::withMessages(['enabled' => 'Verify a phone number first. Codes are sent there by SMS.']);
        }

        if ($data['enabled'] && ! $user->hasPassword() && $user->socialAccounts()->doesntExist()) {
            throw ValidationException::withMessages(['enabled' => 'Set a password or link Google/GitHub first, so SMS is your second step, not your only one.']);
        }

        $user->forceFill(['two_factor_enabled' => $data['enabled']])->save();
        $this->audit->record($data['enabled'] ? 'profile.two_factor_enabled' : 'profile.two_factor_disabled', $user);

        return $this->show($request);
    }

    /** Owners, admins, finance staff (any active business) and platform admins must keep 2FA on. */
    private function twoFactorRequired(User $user): bool
    {
        return $user->is_platform_admin || $user->memberships()
            ->where('status', MemberStatus::Active)
            ->get()
            ->contains(fn ($m) => $m->role->requiresTwoFactor());
    }

    public function unlink(Request $request, string $provider): UserResource
    {
        $user = $request->user();
        $provider = SocialProvider::from($provider);

        $account = $user->socialAccounts()->where('provider', $provider)->first();

        if (! $account) {
            throw ValidationException::withMessages(['provider' => "{$provider->label()} is not linked."]);
        }

        if ($user->signInMethodCount() <= 1) {
            throw ValidationException::withMessages(['provider' => 'This is your only way to sign in. Add a password or phone first.']);
        }

        $account->delete();
        $this->audit->record('auth.social_unlinked', $user, ['provider' => $provider->value]);

        return $this->show($request);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return response()->json(['data' => $request->user()->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('last_used_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'current' => $current && $t->id === $current->id,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
            ])]);
    }

    public function revokeSession(Request $request, int $token): JsonResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return response()->json(['message' => 'Session revoked.']);
    }
}
