<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    protected $fillable = [
        'message_id',
        'subject',
        'from',
        'to',
        'cc',
        'body_html',
        'body_text',
        'has_attachments',
        'received_at',
        'processed_at',
        'status',
    ];

    protected $casts = [
        'has_attachments' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(ProcessingLog::class);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
}
