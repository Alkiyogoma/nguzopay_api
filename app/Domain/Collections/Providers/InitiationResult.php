<?php

namespace App\Domain\Collections\Providers;

use App\Domain\Collections\Enums\IntentStatus;

final class InitiationResult
{
    private function __construct(
        public readonly IntentStatus $status,
        public readonly ?string $providerReference,
        public readonly ?string $errorKind,
        public readonly ?string $message,
        public readonly array $response = [],
    ) {}

    public static function sent(?string $providerReference, array $response = [], ?string $message = null): self
    {
        return new self(IntentStatus::Processing, $providerReference, null, $message, $response);
    }

    /** No clear answer (timeout, 5xx): the push may or may not have gone out. */
    public static function ambiguous(string $message, array $response = []): self
    {
        return new self(IntentStatus::Processing, null, 'ambiguous', $message, $response);
    }

    public static function failed(string $message, array $response = []): self
    {
        return new self(IntentStatus::Failed, null, 'definite', $message, $response);
    }
}
