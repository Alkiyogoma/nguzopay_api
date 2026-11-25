<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use App\Domain\Platform\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * NGUZO's own team: who has back-office access. Granting or removing access
 * needs two-step verification, is audited, and never leaves the platform with no admin.
 */
class StaffController extends Controller
{
    public function index(): JsonResponse
    {
        $staff = User::where('is_platform_admin', true)->with('socialAccounts', 'memberships')->orderBy('name')->get();

        // What each person has done in the back-office recently (KYC decisions, suspensions, pricing…).
        $activity = AuditLog::query()
            ->whereIn('user_id', $staff->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('action', 'like', 'business.%')->orWhere('action', 'like', 'kyc.%')
                ->orWhere('action', 'like', 'document.%')->orWhere('action', 'like', 'sender_id.%')
                ->orWhere('action', 'like', 'platform_admin.%')->orWhere('action', 'like', 'admin.%'))
            ->selectRaw('user_id, count(*) as actions, max(created_at) as last_action_at')
            ->groupBy('user_id')->get()->keyBy('user_id');

        return response()->json([
            'data' => $staff->map(fn (User $u) => (new UserResource($u))->resolve() + [
                'actions_30d' => (int) ($activity[$u->id]->actions ?? 0),
                'last_action_at' => isset($activity[$u->id]) ? CarbonImmutable::parse($activity[$u->id]->last_action_at)->toIso8601String() : null,
            ])->values(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::where('email', mb_strtolower($data['email']))->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'No NguzoPay account uses this email. Ask the person to sign up first.']);
        }
        if ($user->is_platform_admin) {
            throw ValidationException::withMessages(['email' => 'This person already has back-office access.']);
        }
        if (! $user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'This person has not verified their email yet.']);
        }

        $user->forceFill(['is_platform_admin' => true])->save();
        $audit->record('platform_admin.granted', $user, ['reason' => $data['reason']]);

        return response()->json(['data' => new UserResource($user->load('socialAccounts', 'memberships'))], 201);
    }

    public function destroy(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot remove your own back-office access.']);
        }

        DB::transaction(function () use ($user) {
            $admins = User::where('is_platform_admin', true)->lockForUpdate()->pluck('id');
            if (! $admins->contains($user->id)) {
                throw ValidationException::withMessages(['user' => 'This person does not have back-office access.']);
            }
            if ($admins->count() <= 1) {
                throw ValidationException::withMessages(['user' => 'NguzoPay must keep at least one admin.']);
            }
            $user->forceFill(['is_platform_admin' => false])->save();
        });

        $audit->record('platform_admin.revoked', $user, ['reason' => $data['reason']]);

        return response()->json(['data' => new UserResource($user->fresh()->load('socialAccounts', 'memberships'))]);
    }
}
