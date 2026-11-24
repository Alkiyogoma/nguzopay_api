<?php

namespace Tests\Unit;

use App\Domain\Notifications\Sms\DarSmsSender;
use App\Domain\Notifications\Sms\SmsDeliveryFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DarSmsSenderTest extends TestCase
{
    private const BASE = 'https://dev.darsms.co.tz/api/v1';

    public function test_request_matches_the_darsms_spec(): void
    {
        Http::fake(['dev.darsms.co.tz/*' => Http::response(['success' => true])]);

        (new DarSmsSender(self::BASE, 'darsms_test_key'))->send('DARSMS', ['255712345678', '255754321987'], 'Dear James, your payment is due on 2026-05-05.');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://dev.darsms.co.tz/api/v1/integrations/sms/send'
            && $r->header('X-API-Key') === ['darsms_test_key']
            && ! $r->hasHeader('Authorization')
            && $r->header('Content-Type') === ['application/json']
            && $r->header('Accept') === ['application/json']
            && $r->data() === [
                'senderId' => 'DARSMS',
                'to' => ['255712345678', '255754321987'],
                'message' => 'Dear James, your payment is due on 2026-05-05.',
            ]);
    }

    public function test_bearer_auth_mode(): void
    {
        Http::fake(['dev.darsms.co.tz/*' => Http::response(['success' => true])]);

        (new DarSmsSender(self::BASE, 'darsms_test_key', auth: 'bearer'))->send('DARSMS', ['255712345678'], 'Hi');

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer darsms_test_key'] && ! $r->hasHeader('X-API-Key'));
    }

    public function test_client_errors_are_final_and_carry_darsms_reason_without_the_key(): void
    {
        Http::fake(['dev.darsms.co.tz/*' => Http::response(['message' => 'Sender ID NGUZOPAY is not approved for key darsms_test_key'], 422)]);

        try {
            (new DarSmsSender(self::BASE, 'darsms_test_key'))->send('NGUZOPAY', ['255712345678'], 'Hi');
            $this->fail('Expected SmsDeliveryFailed');
        } catch (SmsDeliveryFailed $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('not approved', $e->getMessage());
            $this->assertStringNotContainsString('darsms_test_key', $e->getMessage());
        }
    }

    public function test_server_errors_and_rate_limits_are_retryable(): void
    {
        foreach ([500, 503, 429] as $status) {
            Http::fake(['dev.darsms.co.tz/*' => Http::response([], $status)]);

            try {
                (new DarSmsSender(self::BASE, 'k'))->send('DARSMS', ['255712345678'], 'Hi');
                $this->fail("Expected failure for {$status}");
            } catch (SmsDeliveryFailed $e) {
                $this->assertTrue($e->retryable, "HTTP {$status} should be retryable");
            }
        }
    }

    public function test_missing_key_fails_without_calling_darsms(): void
    {
        Http::fake();

        $this->expectExceptionMessage('DARSMS_API_KEY');
        (new DarSmsSender(self::BASE, null))->send('DARSMS', ['255712345678'], 'Hi');
        Http::assertNothingSent();
    }

    public function test_sms_test_command_uses_the_configured_driver(): void
    {
        config(['nguzopay.sms.driver' => 'log']);

        $this->artisan('nguzopay:sms-test', ['phone' => '0712 345 678'])
            ->expectsOutputToContain('to: 255712345678')
            ->assertSuccessful();

        $this->artisan('nguzopay:sms-test', ['phone' => '0222'])->assertFailed();
    }
}
