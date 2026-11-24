<?php

namespace App\Domain\Business\Services;

use App\Domain\Business\Enums\BusinessStatus;
use App\Domain\Business\Enums\DocumentStatus;
use App\Domain\Business\Enums\DocumentType;
use App\Domain\Business\Models\Business;
use App\Domain\Business\Models\BusinessDocument;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * KYC/KYB. The business uploads documents and submits; NGUZO reviews with
 * maker-checker: one platform admin recommends, a different one decides.
 */
class KycService
{
    /** Profile fields a reviewer needs before a submission makes sense. */
    public const REQUIRED_FIELDS = [
        'legal_name', 'type', 'registration_authority', 'registration_number', 'tin',
        'email', 'phone', 'region', 'physical_address',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function upload(Business $business, DocumentType $type, UploadedFile $file, User $actor): BusinessDocument
    {
        $this->assertEditable($business);

        $disk = config('nguzopay.kyc.disk');
        $path = $file->store("kyc/{$business->getKey()}", $disk);

        $document = $business->documents()->create([
            'type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'uploaded_by' => $actor->getKey(),
        ]);

        $this->audit->record('kyc.document_uploaded', $document, ['type' => $type->value], actor: $actor, business: $business);

        return $document;
    }

    public function delete(BusinessDocument $document, User $actor): void
    {
        $this->assertEditable($document->business);

        if ($document->status === DocumentStatus::Accepted) {
            throw ValidationException::withMessages(['document' => 'Accepted documents cannot be deleted.']);
        }

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        $this->audit->record('kyc.document_deleted', $document, ['type' => $document->type->value], actor: $actor, business: $document->business);
    }

    /** @return array{missing_fields: list<string>, missing_documents: list<string>} */
    public function checklist(Business $business): array
    {
        $missingFields = array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn ($field) => blank($business->{$field}),
        ));

        $present = $business->documents()
            ->where('status', '!=', DocumentStatus::Rejected)
            ->pluck('type')
            ->map(fn ($type) => $type instanceof DocumentType ? $type->value : $type)
            ->all();

        $missingDocuments = array_values(array_map(
            fn (DocumentType $type) => $type->value,
            array_filter(DocumentType::required(), fn (DocumentType $type) => ! in_array($type->value, $present, true)),
        ));

        return ['missing_fields' => $missingFields, 'missing_documents' => $missingDocuments];
    }

    /** Statuses that "hold" a TIN: at most one business per TIN may be in these. */
    public const LIVE_STATUSES = [BusinessStatus::Submitted, BusinessStatus::InReview, BusinessStatus::Active, BusinessStatus::Suspended];

    /**
     * Other businesses sharing this TIN or registration (authority + number), any status.
     * Shown to reviewers so duplicate or impersonating merchants are spotted.
     *
     * @return list<array{business: Business, matched_on: list<string>}>
     */
    public function duplicatesOf(Business $business): array
    {
        $hasTin = filled($business->tin);
        $hasReg = filled($business->registration_number);

        if (! $hasTin && ! $hasReg) {
            return [];
        }

        return Business::query()
            ->whereKeyNot($business->getKey())
            ->where(function ($q) use ($business, $hasTin, $hasReg) {
                if ($hasTin) {
                    $q->orWhere('tin', $business->tin);
                }
                if ($hasReg) {
                    $q->orWhere(fn ($q) => $q
                        ->where('registration_number', $business->registration_number)
                        ->where('registration_authority', $business->registration_authority?->value));
                }
            })
            ->limit(20)
            ->get()
            ->map(fn (Business $other) => [
                'business' => $other,
                'matched_on' => array_values(array_filter([
                    $hasTin && $other->tin === $business->tin ? 'tin' : null,
                    $hasReg && $other->registration_number === $business->registration_number
                        && $other->registration_authority === $business->registration_authority ? 'registration_number' : null,
                ])),
            ])
            ->all();
    }

    public function submit(Business $business, User $actor): Business
    {
        if (! in_array($business->status, [BusinessStatus::Draft, BusinessStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'Only a draft or rejected application can be submitted.']);
        }

        // Don't say which business holds it: that would leak another merchant's details.
        $tinTaken = filled($business->tin) && Business::query()
            ->whereKeyNot($business->getKey())
            ->where('tin', $business->tin)
            ->whereIn('status', self::LIVE_STATUSES)
            ->exists();

        if ($tinTaken) {
            throw ValidationException::withMessages([
                'tin' => 'Another business with this TIN is already verified or under review. If this is your business, contact NguzoPay support.',
            ]);
        }

        $checklist = $this->checklist($business);

        if ($checklist['missing_fields'] || $checklist['missing_documents']) {
            $messages = [];
            foreach ($checklist['missing_fields'] as $field) {
                $messages[$field] = 'Required before submitting for review.';
            }
            foreach ($checklist['missing_documents'] as $type) {
                $messages["documents.{$type}"] = 'Upload: '.DocumentType::from($type)->label().'.';
            }

            throw ValidationException::withMessages($messages);
        }

        $business->forceFill([
            'status' => BusinessStatus::Submitted,
            'submitted_at' => now(),
            'recommended_by' => null,
            'recommendation' => null,
            'recommendation_notes' => null,
            'recommended_at' => null,
            'decided_by' => null,
            'decided_at' => null,
            'rejection_reason' => null,
        ])->save();

        $this->audit->record('kyc.submitted', $business, actor: $actor);

        return $business;
    }

    public function reviewDocument(BusinessDocument $document, DocumentStatus $status, ?string $notes, User $admin): BusinessDocument
    {
        $document->forceFill([
            'status' => $status,
            'review_notes' => $notes,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->record('kyc.document_reviewed', $document, ['status' => $status->value], actor: $admin, business: $document->business);

        return $document;
    }

    /** Maker step. */
    public function recommend(Business $business, string $decision, ?string $notes, User $admin): Business
    {
        if ($business->status !== BusinessStatus::Submitted) {
            throw ValidationException::withMessages(['status' => 'Only a submitted application can be recommended.']);
        }

        $business->forceFill([
            'status' => BusinessStatus::InReview,
            'recommended_by' => $admin->getKey(),
            'recommendation' => $decision,
            'recommendation_notes' => $notes,
            'recommended_at' => now(),
        ])->save();

        $this->audit->record('kyc.recommended', $business, ['decision' => $decision], actor: $admin);

        return $business;
    }

    /** Checker step. Must be a different admin from the one who recommended. */
    public function decide(Business $business, string $decision, ?string $reason, User $admin): Business
    {
        if ($business->status !== BusinessStatus::InReview) {
            throw ValidationException::withMessages(['status' => 'This application is not waiting for a decision.']);
        }

        if ($business->recommended_by === $admin->getKey()) {
            throw ValidationException::withMessages(['decision' => 'A second admin must make the decision (maker-checker).']);
        }

        if ($decision === 'reject' && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Give the business a reason for the rejection.']);
        }

        DB::transaction(function () use ($business, $decision, $reason, $admin) {
            $business->forceFill([
                'status' => $decision === 'approve' ? BusinessStatus::Active : BusinessStatus::Rejected,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
                'rejection_reason' => $decision === 'reject' ? $reason : null,
            ])->save();

            if ($decision === 'approve') {
                $business->documents()->where('status', DocumentStatus::Pending)->update([
                    'status' => DocumentStatus::Accepted,
                    'reviewed_by' => $admin->getKey(),
                    'reviewed_at' => now(),
                ]);
            }
        });

        $this->audit->record('kyc.decided', $business, ['decision' => $decision, 'reason' => $reason], actor: $admin);

        return $business;
    }

    public function setSuspended(Business $business, bool $suspended, ?string $reason, User $admin): Business
    {
        $expected = $suspended ? BusinessStatus::Active : BusinessStatus::Suspended;

        if ($business->status !== $expected) {
            throw ValidationException::withMessages(['status' => $suspended
                ? 'Only an active business can be suspended.'
                : 'This business is not suspended.']);
        }

        $business->forceFill(['status' => $suspended ? BusinessStatus::Suspended : BusinessStatus::Active])->save();
        $this->audit->record($suspended ? 'business.suspended' : 'business.reinstated', $business, ['reason' => $reason], actor: $admin);

        return $business;
    }

    private function assertEditable(Business $business): void
    {
        if (! $business->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Documents cannot change while the application is under review.']);
        }
    }
}
