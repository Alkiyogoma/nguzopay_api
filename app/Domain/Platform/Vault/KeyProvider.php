<?php

namespace App\Domain\Platform\Vault;

/**
 * Supplies master keys (KEKs). Today they come from the environment;
 * swap in a KMS/HSM-backed provider without touching callers.
 */
interface KeyProvider
{
    public function activeVersion(): string;

    /** Raw 32-byte key for a version. */
    public function key(string $version): string;
}
