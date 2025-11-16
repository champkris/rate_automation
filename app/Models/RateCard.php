<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateCard extends Model
{
    protected $fillable = [
        'email_id',
        'carrier',
        'service_type',
        'origin_country',
        'origin_city',
        'origin_port',
        'destination_country',
        'destination_city',
        'destination_port',
        'rate',
        'currency',
        'container_type',
        'effective_date',
        'expiry_date',
        'remarks',
        'additional_charges',
        'raw_data',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'additional_charges' => 'array',
        'raw_data' => 'array',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
