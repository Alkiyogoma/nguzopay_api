<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\DocumentType;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Notifications\StaffInvited;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\QueuedResetPassword;
use App\Domain\Identity\Notifications\QueuedVerifyEmail;
use App\Domain\Notifications\Jobs\SendSms;
use App\Domain\Notifications\Sms\DarSmsSender;
use App\Domain\Notifications\Sms\SmsDeliveryFailed;
use App\Domain\Notifications\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The three fixes from the post-P0 review: queued delivery, enforced 2FA, unique TINs. */
class MustFixTest extends TestCase
{
    use RefreshDatabase;

    /* ── 1. SMS and email go through the queue ─────────────────────── */

    public function test_otp_and_invitation_sms_are_queued_on_the_sms_queue(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertOk();
        Queue::assertPushedOn('sms', SendSms::class, fn (SendSms $job) => $job->to === ['255712345678'] && $job->purpose === 'otp.login');

        $owner = $this->signIn();
        $business = $this->businessWith($owner);
        $this->postJson("/api/v1/businesses/{$business->id}/invitations", ['phone' => '0713222333', 'role' => 'viewer'])->assertCreated();
        Queue::assertPushedOn('sms', SendSms::class, fn (SendSms $job) => $job->purpose === 'staff.invitation');
    }

    public function test_darsms_outage_does_not_break_the_request(): void
    {
        config([
            'queue.default' => 'database',
            'nguzopay.sms.driver' => 'darsms',
            'nguzopay.sms.darsms.api_key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0712345678'])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'sms')->count());
    }

    public function test_job_fails_at_once_on_a_rejection(): void
    {
        $this->app->instance(SmsSender::class, new DarSmsSender('https://dev.darsms.co.tz/api/v1', 'k'));

        Http::fake(['*' => Http::response(['message' => 'Sender not approved'], 422)]);
        $job = (new SendSms('NGUZOPAY', ['255712345678'], 'Hi'))->withFakeQueueInteractions();
        $job->handle(app(SmsSender::class));
        $job->assertFailed();
    }

    public function test_job_rethrows_transient_failures_so_the_queue_retries(): void
    {
        $this->app->instance(SmsSender::class, new DarSmsSender('https://dev.darsms.co.tz/api/v1', 'k'));
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(SmsDeliveryFailed::class);
        (new SendSms('NGUZOPAY', ['255712345678'], 'Hi'))->handle(app(SmsSender::class));
    }

    public function test_emails_are_queued(): void
    {
        foreach ([
            QueuedVerifyEmail::class,
            QueuedResetPassword::class,
            StaffInvited::class,
        ] as $class) {
            $this->assertContains(ShouldQueue::class, class_implements($class), "{$class} must be queued");
        }
    }

    /* ── 2. Two-step verification is enforced ──────────────────────── */

    public function test_submitting_for_review_requires_two_factor(): void
    {
        $owner = $this->signIn();
        $business = $this->businessWith($owner);

        $this->postJson("/api/v1/businesses/{$business->id}/kyc/submit")
            ->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    }

    public function test_admin_decisions_require_two_factor(): void
    {
        $business = Business::factory()->create(['status' => BusinessStatus::Submitted]);
        $this->signIn(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/businesses/{$business->id}")->assertOk();
        $this->postJson("/api/v1/admin/businesses/{$business->id}/recommend", ['decision' => 'approve'])
            ->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    }

    public function test_required_roles_cannot_turn_two_factor_off(): void
    {
        $owner = $this->signIn(User::factory()->withTwoFactor()->create());
        $this->businessWith($owner, StaffRole::Finance);

        $this->putJson('/api/v1/me/two-factor', ['enabled' => false, 'password' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');

        $viewer = $this->signIn(User::factory()->withTwoFactor()->create());
        $this->businessWith($viewer, StaffRole::Viewer);
        $this->putJson('/api/v1/me/two-factor', ['enabled' => false, 'password' => 'password123'])->assertOk();
    }

    /* ── 3. One live business per TIN ──────────────────────────────── */

    private function readyBusiness(User $owner, string $tin): Business
    {
        $business = $this->businessWith($owner, attributes: ['tin' => $tin]);
        foreach (DocumentType::required() as $type) {
            $business->documents()->create([
                'type' => $type, 'disk' => 'local', 'path' => "x/{$type->value}.pdf",
                'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
            ]);
        }

        return $business;
    }

    public function test_second_business_with_a_live_tin_cannot_submit(): void
    {
        $first = Business::factory()->create(['tin' => '123456789', 'status' => BusinessStatus::Active]);

        $owner = $this->signIn(User::factory()->withTwoFactor()->create());
        $copy = $this->readyBusiness($owner, '123456789');

        $this->postJson("/api/v1/businesses/{$copy->id}/kyc/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tin')
            ->assertJsonMissing(['legal_name' => $first->legal_name]);

        $other = $this->readyBusiness($owner, '987654321');
        $this->postJson("/api/v1/businesses/{$other->id}/kyc/submit")->assertOk();
    }

    public function test_database_blocks_two_live_businesses_with_one_tin(): void
    {
        Business::factory()->create(['tin' => '123456789', 'status' => BusinessStatus::Active]);
        $draft = Business::factory()->create(['tin' => '123456789']); // drafts may repeat

        $this->expectException(QueryException::class);
        $draft->forceFill(['status' => BusinessStatus::Submitted])->save();
    }

    public function test_reviewers_see_duplicates(): void
    {
        $original = Business::factory()->create(['tin' => '123456789', 'registration_number' => 'R-1', 'status' => BusinessStatus::Active]);
        $applicant = Business::factory()->create(['tin' => '555555555', 'registration_number' => 'R-1', 'status' => BusinessStatus::Submitted]);
        Business::factory()->create(['tin' => '999999999', 'registration_number' => 'R-9']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/businesses/{$applicant->id}")
            ->assertOk()
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonPath('duplicates.0.id', $original->id)
            ->assertJsonPath('duplicates.0.matched_on', ['registration_number']);
    }
}
