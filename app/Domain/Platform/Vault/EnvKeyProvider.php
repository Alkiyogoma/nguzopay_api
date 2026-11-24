<?php

namespace App\Domain\Platform\Vault;

class EnvKeyProvider implements KeyProvider
{
    /** @param array<string, string> $keys version => base64 key */
    public function __construct(private readonly string $active, private readonly array $keys) {}

    public function activeVersion(): string
    {
        return $this->active;
    }

    public function key(string $version): string
    {
        $encoded = $this->keys[$version] ?? null;

        if (! $encoded) {
            throw new VaultException("Vault key '{$version}' is not configured (set VAULT_KEY_".strtoupper($version).').');
        }

        $raw = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new VaultException("Vault key '{$version}' must be 32 bytes, base64-encoded. Run `php artisan vault:key`.");
        }

        return $raw;
    }
}
