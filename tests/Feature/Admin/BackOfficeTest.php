<?php

namespace Tests\Feature\Admin;

use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BackOfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_admins_reach_the_back_office(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonStructure(['businesses', 'kyc_queue', 'users']);
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_admin_searches_businesses_and_sets_sender_id_status(): void
    {
        $business = Business::factory()->create(['legal_name' => 'Hodari Christian School']);
        $sender = $business->senderIds()->create(['sender_id' => 'HODARI']);
        Business::factory()->create(['legal_name' => 'Other Ltd']);

        $this->signIn(User::factory()->admin()->withTwoFactor()->create());
        $this->getJson('/api/v1/admin/businesses?q=Hodari')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/businesses/{$business->id}")->assertOk()->assertJsonPath('business.legal_name', 'Hodari Christian School');

        $this->patchJson("/api/v1/admin/businesses/{$business->id}/sender-ids/{$sender->id}", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/v1/admin/businesses/{$business->id}/sender-ids/{$sender->id}", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_audit_log_is_append_only_and_redacts_secrets(): void
    {
        $log = app(AuditLogger::class)->record('test.action', properties: ['password' => 'x', 'kept' => 'y']);

        $this->assertSame(['kept' => 'y'], $log->properties);

        $this->expectException(LogicException::class);
        AuditLog::first()->update(['action' => 'changed']);
    }

    public function test_console_command_grants_admin(): void
    {
        $user = User::factory()->create(['email' => 'ops@nguzo.co.tz']);

        $this->artisan('nguzopay:admin', ['email' => 'ops@nguzo.co.tz'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_admin);
    }
}
