<?php

namespace App\Domain\Business\Services;

use App\Domain\Business\Enums\SenderIdStatus;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\SmsSenderId;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/** Branded SMS sender IDs (DEVELOPMENT_PLAN §4 DarSMS): max 11 chars, letters, digits and '-'. */
class SenderIdService
{
    public const PATTERN = '/^[A-Z0-9-]{3,11}$/';

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(Business $business, string $senderId, User $actor): SmsSenderId
    {
        $senderId = strtoupper(trim($senderId));

        if (! preg_match(self::PATTERN, $senderId) || ! preg_match('/[A-Z]/', $senderId)) {
            throw ValidationException::withMessages(['sender_id' => 'Use 3–11 letters, digits or "-", with at least one letter. No spaces.']);
        }

        if ($senderId === strtoupper(config('nguzopay.sms.default_sender_id'))) {
            throw ValidationException::withMessages(['sender_id' => 'This sender ID is reserved.']);
        }

        $taken = SmsSenderId::query()
            ->where('sender_id', $senderId)
            ->where('business_id', '!=', $business->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['sender_id' => 'Another business already uses this sender ID.']);
        }

        if ($business->senderIds()->whereIn('status', [SenderIdStatus::Submitted, SenderIdStatus::Approved])->exists()) {
            throw ValidationException::withMessages(['sender_id' => 'Your sender ID is already with DarSMS or approved. Contact support to change it.']);
        }

        // Replace an earlier request that has not gone to DarSMS yet (or was rejected).
        $business->senderIds()->whereIn('status', [SenderIdStatus::Requested, SenderIdStatus::Rejected])->delete();

        $record = $business->senderIds()->create(['sender_id' => $senderId]);
        $this->audit->record('sender_id.requested', $record, ['sender_id' => $senderId], actor: $actor, business: $business);

        return $record;
    }

    public function setStatus(SmsSenderId $record, SenderIdStatus $status, ?string $reason, User $admin): SmsSenderId
    {
        $record->forceFill([
            'status' => $status,
            'rejection_reason' => $status === SenderIdStatus::Rejected ? $reason : null,
            'approved_at' => $status === SenderIdStatus::Approved ? now() : null,
        ])->save();

        $this->audit->record('sender_id.status_changed', $record, ['status' => $status->value, 'reason' => $reason], actor: $admin, business: $record->business);

        return $record;
    }
}
