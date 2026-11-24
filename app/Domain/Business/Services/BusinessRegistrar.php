<?php

namespace App\Domain\Business\Services;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\StaffRole;
use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessRegistrar
{
    /** Legal identity fields. Locked once the business is submitted, so what was reviewed stays true. */
    public const LEGAL_FIELDS = ['type', 'legal_name', 'registration_authority', 'registration_number', 'tin'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function register(User $owner, array $data): Business
    {
        $business = $this->withFreshCode(function (string $code) use ($owner, $data) {
            return DB::transaction(function () use ($owner, $data, $code) {
                $business = new Business($data);
                $business->code = $code;
                $business->save();

                $business->members()->create([
                    'user_id' => $owner->getKey(),
                    'role' => StaffRole::Owner,
                ]);

                return $business;
            });
        });

        $this->audit->record('business.registered', $business, ['merchant_code' => $business->code], actor: $owner);

        return $business;
    }

    public function update(Business $business, array $data, User $actor): Business
    {
        if (! $business->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'This business is under review and cannot be edited until the review is finished.',
            ]);
        }

        if ($business->status === BusinessStatus::Active) {
            $locked = array_intersect(array_keys($data), self::LEGAL_FIELDS);
            $changed = array_filter($locked, fn ($field) => (string) ($data[$field] ?? '') !== (string) ($business->{$field}?->value ?? $business->{$field}));

            if ($changed) {
                throw ValidationException::withMessages(array_fill_keys(
                    array_values($changed),
                    'This field was verified during KYC. Contact NguzoPay support to change it.',
                ));
            }
        }

        $business->fill($data);
        $dirty = array_keys($business->getDirty());

        // Editing after a rejection starts a fresh application.
        if ($dirty && $business->status === BusinessStatus::Rejected) {
            $business->status = BusinessStatus::Draft;
        }

        $business->save();

        if ($dirty) {
            $this->audit->record('business.updated', $business, ['fields' => $dirty], actor: $actor);
        }

        return $business;
    }

    /** 4-digit merchant code, unique and sequential (used in control numbers, DEVELOPMENT_PLAN §2.5). */
    private function withFreshCode(callable $create): Business
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = Business::withTrashed()->max('code');
            $next = str_pad((string) (((int) $last) + 1), 4, '0', STR_PAD_LEFT);

            if (strlen($next) > 4) {
                throw new \OverflowException('Merchant codes are exhausted (9999). Widen the code format.');
            }

            try {
                return $create($next);
            } catch (QueryException $e) {
                // Another registration took this code at the same moment; try the next one.
                if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate a merchant code.');
    }
}
