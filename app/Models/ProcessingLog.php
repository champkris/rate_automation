<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingLog extends Model
{
    protected $fillable = [
        'email_id',
        'type',
        'status',
        'message',
        'context',
        'error_trace',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public static function logSuccess(string $type, string $message, ?int $emailId = null, ?array $context = null): void
    {
        self::create([
            'email_id' => $emailId,
            'type' => $type,
            'status' => 'success',
            'message' => $message,
            'context' => $context,
        ]);
    }

    public static function logFailure(string $type, string $message, ?\Throwable $exception = null, ?int $emailId = null, ?array $context = null): void
    {
        self::create([
            'email_id' => $emailId,
            'type' => $type,
            'status' => 'failed',
            'message' => $message,
            'context' => $context,
            'error_trace' => $exception ? $exception->getTraceAsString() : null,
        ]);
    }
}
