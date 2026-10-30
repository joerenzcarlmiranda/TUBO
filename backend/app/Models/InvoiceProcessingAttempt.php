<?php

namespace App\Models;

use App\Enums\InvoiceProcessingAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceProcessingAttempt extends Model
{
    protected $fillable = [
        'attempt_number',
        'status',
        'started_at',
        'finished_at',
        'http_status_code',
        'response_body',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceProcessingAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function canBeRetried(): bool
    {
        return $this->status === InvoiceProcessingAttemptStatus::Failed
            && ($this->http_status_code === null
                || $this->http_status_code === 408
                || $this->http_status_code === 429
                || ($this->http_status_code >= 500 && $this->http_status_code < 600));
    }
}
