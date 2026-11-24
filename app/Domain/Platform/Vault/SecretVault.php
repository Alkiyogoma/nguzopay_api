<?php

namespace App\Domain\Platform\Vault;

/**
 * Envelope encryption for provider credentials and other secrets.
 *
 * Each secret gets its own random data key (DEK). The DEK encrypts the value;
 * the versioned master key (KEK) encrypts the DEK. Both use XChaCha20-Poly1305
 * with the caller's $context as associated data, so a ciphertext copied into
 * another row or column fails to decrypt.
 *
 * Stored format: "vault:v1:" + base64(json{k, wn, wk, n, c}).
 */
class SecretVault
{
    private const PREFIX = 'vault:v1:';

    public function __construct(private readonly KeyProvider $keys) {}

    public function encrypt(string $plaintext, string $context): string
    {
        $version = $this->keys->activeVersion();
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $dek);

        [$wrapNonce, $wrapped] = $this->wrap($dek, $version, $context);
        sodium_memzero($dek);

        return self::PREFIX.base64_encode(json_encode([
            'k' => $version,
            'wn' => base64_encode($wrapNonce),
            'wk' => base64_encode($wrapped),
            'n' => base64_encode($nonce),
            'c' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $payload, string $context): string
    {
        $data = $this->parse($payload);
        $dek = $this->unwrap($data, $context);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            base64_decode($data['c']), $context, base64_decode($data['n']), $dek,
        );
        sodium_memzero($dek);

        if ($plaintext === false) {
            throw new VaultException('Secret could not be decrypted (wrong context or tampered data).');
        }

        return $plaintext;
    }

    /** Re-encrypt only the data key under the active master key (key rotation). */
    public function rewrap(string $payload, string $context): string
    {
        $data = $this->parse($payload);
        $active = $this->keys->activeVersion();

        if ($data['k'] === $active) {
            return $payload;
        }

        $dek = $this->unwrap($data, $context);
        [$wrapNonce, $wrapped] = $this->wrap($dek, $active, $context);
        sodium_memzero($dek);

        $data['k'] = $active;
        $data['wn'] = base64_encode($wrapNonce);
        $data['wk'] = base64_encode($wrapped);

        return self::PREFIX.base64_encode(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function keyVersionOf(string $payload): string
    {
        return $this->parse($payload)['k'];
    }

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** @return array{0: string, 1: string} [nonce, wrapped key] */
    private function wrap(string $dek, string $version, string $context): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return [$nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, "kek:{$version}|{$context}", $nonce, $this->keys->key($version))];
    }

    private function unwrap(array $data, string $context): string
    {
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            base64_decode($data['wk']), "kek:{$data['k']}|{$context}", base64_decode($data['wn']), $this->keys->key($data['k']),
        );

        if ($dek === false) {
            throw new VaultException('Data key could not be unwrapped (wrong master key, context or tampered data).');
        }

        return $dek;
    }

    private function parse(string $payload): array
    {
        if (! self::isEncrypted($payload)) {
            throw new VaultException('Value is not a vault payload.');
        }

        $data = json_decode((string) base64_decode(substr($payload, strlen(self::PREFIX)), true), true);

        if (! is_array($data) || array_diff(['k', 'wn', 'wk', 'n', 'c'], array_keys($data))) {
            throw new VaultException('Vault payload is malformed.');
        }

        return $data;
    }
}
