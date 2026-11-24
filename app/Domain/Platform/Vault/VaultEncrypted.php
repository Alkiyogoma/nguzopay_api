<?php

namespace App\Domain\Platform\Vault;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast: `'credentials' => VaultEncrypted::class.':json'` or `VaultEncrypted::class`.
 * The context binds the ciphertext to table + primary key + column.
 * The model must have its primary key before saving (ULIDs or explicit ids).
 */
class VaultEncrypted implements CastsAttributes
{
    public function __construct(private readonly string $format = 'string') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $plain = app(SecretVault::class)->decrypt($value, $this->context($model, $key));

        return $this->format === 'json' ? json_decode($plain, true, flags: JSON_THROW_ON_ERROR) : $plain;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($model->getKey() === null && method_exists($model, 'newUniqueId')) {
            $model->setAttribute($model->getKeyName(), $model->newUniqueId());
        }

        if ($model->getKey() === null) {
            throw new VaultException('Vault-encrypted attributes need the model key before they are set.');
        }

        $plain = $this->format === 'json' ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;

        return app(SecretVault::class)->encrypt($plain, $this->context($model, $key));
    }

    private function context(Model $model, string $key): string
    {
        return $model->getTable().':'.$model->getKey().':'.$key;
    }
}
