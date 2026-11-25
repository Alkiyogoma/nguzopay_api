<?php

namespace App\Domain\Collections\Billers;

/** CRDB standard-bills response body. HTTP is always 200; the outcome is in "status". */
final class StandardBillsResult
{
    public const SUCCESS = 200;

    public const INVALID_TOKEN = 201;

    public const INVALID_CHECKSUM = 202;

    public const ALREADY_PAID = 203;          // verification, FIXED amount

    public const INVALID_REFERENCE = 204;

    public const EXPIRED = 205;

    public const DUPLICATE = 206;             // same transaction again: return its receipt

    public const TRANSACTION_ALREADY_PAID = 207; // posting to a FIXED reference that is already paid

    private function __construct(public readonly int $status, public readonly string $description, public readonly ?array $data = null) {}

    public static function ok(array $data): self
    {
        return new self(self::SUCCESS, 'success', $data);
    }

    public static function error(int $status, string $description, ?array $data = null): self
    {
        return new self($status, $description, $data);
    }

    /** Shaped like CRDB's samples: numeric 200 on success, string codes on failure. */
    public function toArray(): array
    {
        return $this->status === self::SUCCESS
            ? ['status' => 200, 'statusDesc' => 'success', 'data' => $this->data]
            : array_filter(['status' => (string) $this->status, 'statusDesc' => $this->description, 'data' => $this->data]);
    }
}
