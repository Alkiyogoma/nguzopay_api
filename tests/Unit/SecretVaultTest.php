<?php

namespace Tests\Unit;

use App\Domain\Platform\Vault\EnvKeyProvider;
use App\Domain\Platform\Vault\SecretVault;
use App\Domain\Platform\Vault\VaultException;
use PHPUnit\Framework\TestCase;

class SecretVaultTest extends TestCase
{
    private function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function test_round_trip(): void
    {
        $vault = new SecretVault(new EnvKeyProvider('v1', ['v1' => $this->key()]));
        $payload = $vault->encrypt('client-secret-123', 'provider_accounts:01H:credentials');

        $this->assertStringStartsWith('vault:v1:', $payload);
        $this->assertStringNotContainsString('client-secret-123', $payload);
        $this->assertSame('client-secret-123', $vault->decrypt($payload, 'provider_accounts:01H:credentials'));
    }

    public function test_ciphertext_moved_to_another_row_does_not_decrypt(): void
    {
        $vault = new SecretVault(new EnvKeyProvider('v1', ['v1' => $this->key()]));
        $payload = $vault->encrypt('secret', 'provider_accounts:A:credentials');

        $this->expectException(VaultException::class);
        $vault->decrypt($payload, 'provider_accounts:B:credentials');
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $vault = new SecretVault(new EnvKeyProvider('v1', ['v1' => $this->key()]));
        $data = json_decode(base64_decode(substr($vault->encrypt('secret', 'ctx'), 9)), true);
        $data['c'] = base64_encode(strrev(base64_decode($data['c'])));

        $this->expectException(VaultException::class);
        $vault->decrypt('vault:v1:'.base64_encode(json_encode($data)), 'ctx');
    }

    public function test_key_rotation_rewraps_without_changing_the_secret(): void
    {
        $v1 = $this->key();
        $old = new SecretVault(new EnvKeyProvider('v1', ['v1' => $v1]));
        $payload = $old->encrypt('secret', 'ctx');

        $new = new SecretVault(new EnvKeyProvider('v2', ['v1' => $v1, 'v2' => $this->key()]));
        $this->assertSame('secret', $new->decrypt($payload, 'ctx'), 'old key still decrypts');

        $rewrapped = $new->rewrap($payload, 'ctx');
        $this->assertSame('v2', $new->keyVersionOf($rewrapped));
        $this->assertSame('secret', $new->decrypt($rewrapped, 'ctx'));
    }

    public function test_missing_key_is_a_clear_error(): void
    {
        $vault = new SecretVault(new EnvKeyProvider('v1', []));

        $this->expectExceptionMessage('VAULT_KEY_V1');
        $vault->encrypt('secret', 'ctx');
    }
}
