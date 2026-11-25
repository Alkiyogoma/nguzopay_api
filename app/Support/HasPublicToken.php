<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * An unguessable token that lets anyone holding a printed document's QR code
 * see that one document, without signing in. 32 random characters (~190 bits).
 */
trait HasPublicToken
{
    protected static function bootHasPublicToken(): void
    {
        static::creating(function ($model) {
            $model->public_token ??= Str::random(32);
        });
    }

    /** Ensure an existing row has a token (rows created before tokens existed). */
    public function ensurePublicToken(): string
    {
        if (! $this->public_token) {
            $this->forceFill(['public_token' => Str::random(32)])->saveQuietly();
        }

        return $this->public_token;
    }

    protected function publicLink(string $prefix): string
    {
        return config('nguzopay.frontend_url')."/{$prefix}/".$this->ensurePublicToken();
    }
}
