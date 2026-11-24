<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'school',
            'legal_name' => 'Genesis Schools Ltd',
            'trading_name' => 'Genesis',
            'registration_authority' => 'brela',
            'registration_number' => '149876',
            'tin' => '123-456-789',
            'email' => 'accounts@genesis.ac.tz',
            'phone' => '0754 000 111',
            'region' => 'Dar es Salaam',
            'physical_address' => 'Plot 5, Mbezi',
        ], $overrides);
    }

    public function test_registering_a_business_makes_you_its_owner_with_a_merchant_code(): void
    {
        $this->signIn();

        $first = $this->postJson('/api/v1/businesses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', '0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.my_role', 'owner')
            ->assertJsonPath('data.tin', '123456789')
            ->assertJsonPath('data.phone', '255754000111');

        $this->postJson('/api/v1/businesses', $this->payload(['legal_name' => 'Second Ltd']))
            ->assertJsonPath('data.code', '0002');

        $this->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/me')->assertJsonCount(2, 'data.businesses');
        $this->assertNotNull($first->json('data.id'));
        $this->assertSame(['merchant_code' => '0001'], AuditLog::where('action', 'business.registered')->first()->properties);
    }

    public function test_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/businesses', $this->payload(['type' => 'casino', 'tin' => '12', 'phone' => '0222']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'tin']);

        $this->postJson('/api/v1/businesses', $this->payload(['phone' => '0222111222']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_outsiders_cannot_see_or_edit_a_business(): void
    {
        $business = $this->businessWith(User::factory()->create());
        $this->signIn();

        $this->getJson("/api/v1/businesses/{$business->id}")->assertForbidden();
        $this->patchJson("/api/v1/businesses/{$business->id}", ['trading_name' => 'Hacked'])->assertForbidden();
    }

    public function test_viewers_can_read_but_not_edit(): void
    {
        $viewer = $this->signIn();
        $business = $this->businessWith($viewer, StaffRole::Viewer);

        $this->getJson("/api/v1/businesses/{$business->id}")->assertOk()->assertJsonPath('data.my_role', 'viewer');
        $this->patchJson("/api/v1/businesses/{$business->id}", ['trading_name' => 'New'])->assertForbidden();
    }

    public function test_owner_updates_profile_and_logo(): void
    {
        Storage::fake('public');
        $owner = $this->signIn();
        $business = $this->businessWith($owner);

        $this->patchJson("/api/v1/businesses/{$business->id}", ['trading_name' => 'Genesis Academy', 'district' => 'Kinondoni'])
            ->assertOk()->assertJsonPath('data.display_name', 'Genesis Academy');

        $this->post("/api/v1/businesses/{$business->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.logo_url', fn ($url) => str_contains($url, "logos/{$business->id}/"));
    }

    public function test_legal_fields_are_locked_once_active(): void
    {
        $owner = $this->signIn();
        $business = $this->businessWith($owner);
        $business->forceFill(['status' => BusinessStatus::Active])->save();

        $this->patchJson("/api/v1/businesses/{$business->id}", ['tin' => '999999999'])
            ->assertUnprocessable()->assertJsonValidationErrors('tin');

        $this->patchJson("/api/v1/businesses/{$business->id}", ['website' => 'https://genesis.ac.tz'])->assertOk();
    }

    public function test_no_edits_while_under_review(): void
    {
        $owner = $this->signIn();
        $business = $this->businessWith($owner);
        $business->forceFill(['status' => BusinessStatus::Submitted])->save();

        $this->patchJson("/api/v1/businesses/{$business->id}", ['district' => 'Ilala'])->assertUnprocessable();
    }

    public function test_sender_id_request_rules(): void
    {
        $owner = $this->signIn();
        $business = $this->businessWith($owner);
        $other = Business::factory()->create();
        $other->senderIds()->create(['sender_id' => 'TAKEN']);

        $this->postJson("/api/v1/businesses/{$business->id}/sender-id", ['sender_id' => 'genesis'])
            ->assertCreated()->assertJsonPath('data.sender_id', 'GENESIS')->assertJsonPath('data.status', 'requested');

        $this->postJson("/api/v1/businesses/{$business->id}/sender-id", ['sender_id' => 'GENESISISCHOOL'])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$business->id}/sender-id", ['sender_id' => 'taken'])->assertUnprocessable();
        $this->postJson("/api/v1/businesses/{$business->id}/sender-id", ['sender_id' => 'NGUZOPAY'])->assertUnprocessable();

        // A new request replaces the one not yet sent to DarSMS.
        $this->postJson("/api/v1/businesses/{$business->id}/sender-id", ['sender_id' => 'GENESIS-SC'])->assertCreated();
        $this->assertSame(['GENESIS-SC'], $business->senderIds()->pluck('sender_id')->all());
    }

    public function test_meta_lists_form_options(): void
    {
        $this->getJson('/api/v1/meta')->assertOk()
            ->assertJsonFragment(['value' => 'school'])
            ->assertJsonFragment(['value' => 'bank_letter', 'label' => 'Settlement bank account letter', 'required' => true]);
    }
}
