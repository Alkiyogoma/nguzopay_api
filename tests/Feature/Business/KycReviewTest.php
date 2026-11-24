<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\DocumentType;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KycReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = $this->signIn(User::factory()->withTwoFactor()->create());
        $this->business = $this->businessWith($this->owner);
    }

    private function uploadAll(): void
    {
        foreach (DocumentType::required() as $type) {
            $this->post("/api/v1/businesses/{$this->business->id}/kyc/documents", [
                'type' => $type->value,
                'file' => UploadedFile::fake()->create("{$type->value}.pdf", 200, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();
        }
    }

    public function test_submission_needs_every_required_document(): void
    {
        $this->postJson("/api/v1/businesses/{$this->business->id}/kyc/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.registration_certificate', 'documents.bank_letter']);

        $this->getJson("/api/v1/businesses/{$this->business->id}/kyc")
            ->assertOk()->assertJsonCount(5, 'checklist.missing_documents');
    }

    public function test_documents_are_private_and_validated(): void
    {
        $this->post("/api/v1/businesses/{$this->business->id}/kyc/documents", [
            'type' => 'tin_certificate', 'file' => UploadedFile::fake()->create('x.exe', 10),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->uploadAll();
        $doc = $this->business->documents()->first();
        Storage::disk('local')->assertExists($doc->path);

        $this->get("/api/v1/businesses/{$this->business->id}/kyc/documents/{$doc->id}")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/businesses/{$this->business->id}/kyc/documents/{$doc->id}")->assertForbidden();
    }

    public function test_full_maker_checker_approval(): void
    {
        $this->uploadAll();
        $this->postJson("/api/v1/businesses/{$this->business->id}/kyc/submit")->assertOk()->assertJsonPath('data.status', 'submitted');

        // Documents are frozen while under review.
        $doc = $this->business->documents()->first();
        $this->deleteJson("/api/v1/businesses/{$this->business->id}/kyc/documents/{$doc->id}")->assertUnprocessable();

        // A business owner is not a platform admin.
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/recommend", ['decision' => 'approve'])->assertForbidden();

        $maker = User::factory()->admin()->withTwoFactor()->create();
        $checker = User::factory()->admin()->withTwoFactor()->create();

        Sanctum::actingAs($maker);
        $this->getJson('/api/v1/admin/businesses?status=submitted')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/recommend", ['decision' => 'approve', 'notes' => 'All good'])
            ->assertOk()->assertJsonPath('data.status', 'in_review');

        // The maker cannot also be the checker.
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/decide", ['decision' => 'approve'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');

        Sanctum::actingAs($checker);
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/decide", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame(0, $this->business->documents()->where('status', 'pending')->count());
        $this->assertEqualsCanonicalizing(
            ['kyc.submitted', 'kyc.recommended', 'kyc.decided'],
            AuditLog::where('business_id', $this->business->id)->where('action', 'like', 'kyc.%')
                ->where('action', '!=', 'kyc.document_uploaded')->pluck('action')->all(),
        );
    }

    public function test_rejection_needs_a_reason_and_lets_the_business_resubmit(): void
    {
        $this->uploadAll();
        $this->postJson("/api/v1/businesses/{$this->business->id}/kyc/submit")->assertOk();

        Sanctum::actingAs(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/recommend", ['decision' => 'reject']);

        Sanctum::actingAs(User::factory()->admin()->withTwoFactor()->create());
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/decide", ['decision' => 'reject'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/decide", ['decision' => 'reject', 'reason' => 'TIN certificate unreadable'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/businesses/{$this->business->id}")->assertJsonPath('data.rejection_reason', 'TIN certificate unreadable');
        $this->postJson("/api/v1/businesses/{$this->business->id}/kyc/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_admin_can_suspend_and_reinstate(): void
    {
        $this->business->forceFill(['status' => BusinessStatus::Active])->save();
        Sanctum::actingAs(User::factory()->admin()->withTwoFactor()->create());

        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/suspend")->assertUnprocessable();
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/suspend", ['reason' => 'Chargebacks'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/admin/businesses/{$this->business->id}/reinstate")->assertOk()->assertJsonPath('data.status', 'active');
    }
}
