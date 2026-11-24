<?php

namespace App\Domain\Business\Models;

use App\Domain\Business\Enums\DocumentStatus;
use App\Domain\Business\Enums\DocumentType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessDocument extends Model
{
    use HasUlids;

    protected $fillable = ['type', 'disk', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected $attributes = ['status' => 'pending'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
