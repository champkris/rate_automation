<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'email_id',
        'filename',
        'mime_type',
        'file_path',
        'file_size',
        'extraction_status',
        'extraction_error',
        'extracted_at',
    ];

    protected $casts = [
        'extracted_at' => 'datetime',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function isExcel(): bool
    {
        return in_array($this->mime_type, [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'text/csv',
        ]);
    }

    public function markAsProcessing(): void
    {
        $this->update(['extraction_status' => 'processing']);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'extraction_status' => 'completed',
            'extracted_at' => now(),
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'extraction_status' => 'failed',
            'extraction_error' => $error,
        ]);
    }
}
