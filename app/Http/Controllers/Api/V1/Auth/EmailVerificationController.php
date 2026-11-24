<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Signed link from the email. Redirects the browser to the portal. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);
        $frontend = config('nguzopay.frontend_url');

        if (! $user || ! $user->email || ! hash_equals(sha1($user->email), $hash)) {
            return redirect()->away("{$frontend}/verify-email?status=invalid");
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            event(new Verified($user));
            $this->audit->record('auth.email_verified', $user, actor: $user);
        }

        return redirect()->away("{$frontend}/verify-email?status=verified");
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->email || $user->email_verified_at) {
            return response()->json(['message' => 'Nothing to verify.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}
