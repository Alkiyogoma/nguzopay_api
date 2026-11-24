<?php

namespace App\Domain\Platform\Services;

use App\Domain\Business\Models\Business;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Never written to the audit log, whatever the caller passes. */
    private const REDACT = ['password', 'password_confirmation', 'token', 'code', 'otp', 'secret', 'api_key'];

    public function record(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        ?User $actor = null,
        ?Business $business = null,
    ): AuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= $request?->user();

        if ($business === null && $subject instanceof Business) {
            $business = $subject;
        } elseif ($business === null && $subject && isset($subject->business_id)) {
            $business = Business::find($subject->business_id);
        }

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'business_id' => $business?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'properties' => $properties ? Arr::except($properties, self::REDACT) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 250, ''),
        ]);
    }
}
