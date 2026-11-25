<?php

namespace App\Domain\Developers\Models;

use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant's secret key for the NguzoPay API (D-028). Only its SHA-256 hash is stored. */
class ApiKey extends Model
{
    use HasUlids;

    /** What a key may do. Keys never manage staff, payouts or other keys. */
    public const SCOPES = [
        'balance:read' => 'Read the balance',
        'payments:read' => 'Read payments and payment requests',
        'payments:write' => 'Send payment requests',
        'customers:read' => 'Read customers',
        'customers:write' => 'Create customers',
        'invoices:read' => 'Read invoices',
    ];

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
