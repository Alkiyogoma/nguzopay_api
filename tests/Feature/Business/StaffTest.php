<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\StaffInvitation;
use App\Domain\Business\Notifications\StaffInvited;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private function inviteToken(): ?string
    {
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, StaffInvited::class, function (StaffInvited $n) use (&$token) {
            $token = basename($n->url);

            return true;
        });

        return $token;
    }

    public function test_invite_by_email_and_accept(): void
    {
        Notification::fake();
        $owner = $this->signIn();
        $business = $this->businessWith($owner);

        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'Bursar@School.tz', 'role' => 'finance', 'job_title' => 'Bursar'])
            ->assertCreated()->assertJsonPath('data.email', 'bursar@school.tz')->assertJsonPath('data.status', 'pending');

        $token = $this->inviteToken();
        $this->assertSame(1, StaffInvitation::count());
        $this->assertNotSame($token, StaffInvitation::first()->token_hash, 'only the hash is stored');

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.role', 'finance');

        // Someone else cannot accept it.
        Sanctum::actingAs(User::factory()->create(['email' => 'stranger@school.tz']));
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertUnprocessable();

        $bursar = User::factory()->create(['email' => 'bursar@school.tz']);
        Sanctum::actingAs($bursar);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertCreated()->assertJsonPath('data.role', 'finance');

        $this->assertSame(StaffRole::Finance, $business->roleOf($bursar));
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertUnprocessable();
        $this->getJson("/api/v1/invitations/{$token}")->assertNotFound();
    }

    public function test_invite_by_phone_sends_sms(): void
    {
        $owner = $this->signIn();
        $business = $this->businessWith($owner);

        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['phone' => '0713 222 333', 'role' => 'support'])->assertCreated();
        $sms = app(SmsSender::class)->lastTo('255713222333');
        $this->assertStringContainsString('/invitations/', $sms);

        $token = basename(trim(substr($sms, strpos($sms, 'http'))));
        Sanctum::actingAs(User::factory()->withPhone('255713222333')->create());
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();
    }

    public function test_unverified_email_cannot_accept(): void
    {
        Notification::fake();
        $owner = $this->signIn();
        $business = $this->businessWith($owner);
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'x@school.tz', 'role' => 'viewer']);

        Sanctum::actingAs(User::factory()->unverified()->create(['email' => 'x@school.tz']));
        $this->postJson("/api/v1/invitations/{$this->inviteToken()}/accept")->assertUnprocessable();
    }

    public function test_role_hierarchy_is_enforced(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $business = $this->businessWith($owner);
        $business->members()->create(['user_id' => $admin->id, 'role' => StaffRole::Admin]);
        $otherAdmin = User::factory()->create();
        $otherAdminMember = $business->members()->create(['user_id' => $otherAdmin->id, 'role' => StaffRole::Admin]);
        $ownerMember = $business->owner;

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'a@b.tz', 'role' => 'admin'])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'a@b.tz', 'role' => 'owner'])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'a@b.tz', 'role' => 'developer'])->assertCreated();
        $this->patchJson("/api/v1/businesses/{$business->id}/staff/{$otherAdminMember->id}", ['role' => 'viewer'])->assertUnprocessable();
        $this->deleteJson("/api/v1/businesses/{$business->id}/staff/{$ownerMember->id}")->assertUnprocessable();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/businesses/{$business->id}/staff/{$otherAdminMember->id}", ['role' => 'finance'])
            ->assertOk()->assertJsonPath('data.role', 'finance');
        $this->patchJson("/api/v1/businesses/{$business->id}/staff/{$ownerMember->id}", ['role' => 'viewer'])->assertUnprocessable();
    }

    public function test_finance_cannot_manage_staff(): void
    {
        $finance = $this->signIn();
        $business = $this->businessWith(User::factory()->create());
        $business->members()->create(['user_id' => $finance->id, 'role' => StaffRole::Finance]);

        $this->getJson("/api/v1/businesses/{$business->id}/staff")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['email' => 'a@b.tz', 'role' => 'viewer'])->assertForbidden();
        $this->getJson("/api/v1/businesses/{$business->id}/audit-logs")->assertOk();
    }

    public function test_suspended_staff_lose_access(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessWith($owner);
        $staff = User::factory()->create();
        $staff->createToken('portal');
        $member = $business->members()->create(['user_id' => $staff->id, 'role' => StaffRole::Support]);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/businesses/{$business->id}/staff/{$member->id}", ['status' => 'suspended'])->assertOk();
        $this->assertSame(0, $staff->tokens()->count());

        Sanctum::actingAs($staff);
        $this->getJson("/api/v1/businesses/{$business->id}")->assertForbidden();
    }

    public function test_members_of_one_business_cannot_touch_another(): void
    {
        $owner = $this->signIn();
        $mine = $this->businessWith($owner);
        $theirs = $this->businessWith(User::factory()->create());
        $theirMember = $theirs->members()->first();

        // Scoped bindings: their member id under my business route is a 404.
        $this->deleteJson("/api/v1/businesses/{$mine->id}/staff/{$theirMember->id}")->assertNotFound();
    }
}
