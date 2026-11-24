<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\SocialProvider;
use App\Domain\Identity\Services\SessionIssuer;
use App\Domain\Identity\Services\SocialLoginFailed;
use App\Domain\Identity\Services\SocialLoginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialLoginService $social,
        private readonly SessionIssuer $sessions,
    ) {}

    /** Browser navigates here; we bounce it to Google/GitHub. */
    public function redirect(string $provider): RedirectResponse
    {
        return redirect()->away($this->social->redirectUrl(SocialProvider::from($provider)));
    }

    /** Signed-in user links another provider. Returns the URL for the browser to open. */
    public function linkUrl(Request $request, string $provider): JsonResponse
    {
        return response()->json([
            'url' => $this->social->redirectUrl(SocialProvider::from($provider), 'link', $request->user()),
        ]);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $frontend = config('nguzopay.frontend_url');

        try {
            $result = $this->social->resolve(SocialProvider::from($provider), $request->query('state'));
        } catch (SocialLoginFailed $e) {
            return redirect()->away("{$frontend}/login?error=".$e->getMessage());
        }

        if ($result['intent'] === 'link') {
            return redirect()->away("{$frontend}/settings/profile?linked={$provider}");
        }

        $code = $this->social->createExchangeCode($result['user']);

        return redirect()->away("{$frontend}/auth/callback?provider={$provider}&code={$code}");
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $this->social->exchange($data['code']);

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'This sign-in link has expired. Try again.']);
        }

        return $this->sessionResponse($this->sessions->begin($user, 'oauth', $data['device_name'] ?? null, $request->ip()));
    }
}
