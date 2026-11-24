<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VaultKeyCommand extends Command
{
    protected $signature = 'vault:key';

    protected $description = 'Generate a new 32-byte master key for the secrets vault (VAULT_KEY_Vn)';

    public function handle(): int
    {
        $this->line('base64:'.base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()));
        $this->comment('Store it in your secret manager as VAULT_KEY_Vn. Never commit it.');

        return self::SUCCESS;
    }
}
