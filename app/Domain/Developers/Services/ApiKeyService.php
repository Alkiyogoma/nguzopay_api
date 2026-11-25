<?php

namespace App\Domain\Developers\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Developers\Models\ApiKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Secret keys are "np_sk_" + 40 random characters, returned once and stored only as a SHA-256 hash. */
class ApiKeyService
{
    public const PREFIX = 'np_sk_';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{0: ApiKey, 1: string} the key and its secret (shown once) */
    public function create(Business $business, string $name, array $scopes, ?CarbonInterface $expiresAt, User $actor): array
    {
        $secret = self::PREFIX.Str::random(40);

        $key = $business->apiKeys()->create([
            'name' => $name,
            'prefix' => substr($secret, 0, 14),
            'token_hash' => ApiKey::hash($secret),
            'scopes' => array_values(array_unique($scopes)),
            'expires_at' => $expiresAt,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('api_key.created', $key, ['name' => $name, 'prefix' => $key->prefix, 'scopes' => $key->scopes], actor: $actor, business: $business);

        return [$key, $secret];
    }

    public function revoke(ApiKey $key, User $actor): ApiKey
    {
        if (! $key->revoked_at) {
            $key->forceFill(['revoked_at' => now()])->save();
            $this->audit->record('api_key.revoked', $key, ['name' => $key->name, 'prefix' => $key->prefix], actor: $actor, business: $key->business);
        }

        return $key;
    }

    public function revokeAll(Business $business, User $actor): int
    {
        $count = $business->apiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->record('api_key.revoked_all', $business, ['count' => $count], actor: $actor);

        return $count;
    }

    /** The key for a secret, if it may be used now. */
    public function resolve(string $secret): ?ApiKey
    {
        if (! str_starts_with($secret, self::PREFIX)) {
            return null;
        }
        $key = ApiKey::where('token_hash', ApiKey::hash($secret))->with('business')->first();

        return $key && $key->isUsable() ? $key : null;
    }
}
