<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\Enums\MemberStatus;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\StaffInvitation;
use App\Domain\Business\Services\StaffService;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        $this->authorize('viewStaff', $business);

        return MemberResource::collection($business->members()->with('user')->orderBy('created_at')->get());
    }

    public function update(Request $request, Business $business, BusinessMember $member): MemberResource
    {
        $this->authorize('manageStaff', $business);

        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(StaffRole::class)],
            'status' => ['sometimes', Rule::enum(MemberStatus::class)],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return new MemberResource($this->staff->update($member, $request->user(), $data)->load('user'));
    }

    public function destroy(Request $request, Business $business, BusinessMember $member): JsonResponse
    {
        $this->authorize('manageStaff', $business);
        $this->staff->remove($member, $request->user());

        return response()->json(['message' => 'Removed from the team.']);
    }

    public function invitations(Business $business): AnonymousResourceCollection
    {
        $this->authorize('manageStaff', $business);

        return InvitationResource::collection(
            $business->invitations()->with('inviter')->whereNull('accepted_at')->latest()->limit(100)->get()
        );
    }

    public function invite(Request $request, Business $business): JsonResponse
    {
        $this->authorize('manageStaff', $business);

        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'job_title' => ['nullable', 'string', 'max:100'],
        ]);
        $phone = $this->phone($request, 'phone', required: false);

        if (empty($data['email']) && ! $phone) {
            throw ValidationException::withMessages(['email' => 'Enter an email address or a phone number.']);
        }

        $result = $this->staff->invite($business, $request->user(), StaffRole::from($data['role']), $data['email'] ?? null, $phone, $data['job_title'] ?? null);

        return (new InvitationResource($result['invitation']->load('inviter')))->response()->setStatusCode(201);
    }

    public function revoke(Request $request, Business $business, StaffInvitation $invitation): JsonResponse
    {
        $this->authorize('manageStaff', $business);
        $this->staff->revoke($invitation, $request->user());

        return response()->json(['message' => 'Invitation revoked.']);
    }

    /** Public preview, so the invite page can show who is inviting before sign-in. */
    public function preview(string $token): InvitationResource
    {
        $invitation = $this->staff->findPendingByToken($token);
        abort_unless($invitation, 404, 'This invitation is invalid, expired or already used.');

        return new InvitationResource($invitation->load('business', 'inviter'));
    }

    public function accept(Request $request, string $token): MemberResource
    {
        return new MemberResource($this->staff->accept($token, $request->user())->load('user'));
    }
}
