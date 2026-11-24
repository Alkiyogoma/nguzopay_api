<?php

namespace App\Domain\Business\Enums;

/**
 * draft ─submit→ submitted ─recommend→ in_review ─decide→ active | rejected
 * rejected ─(edit)→ draft;  active ⇄ suspended (platform admin)
 */
enum BusinessStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Active = 'active';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /** The business can still edit its profile and documents. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Active], true);
    }
}
