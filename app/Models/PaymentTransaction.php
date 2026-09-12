<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'gateway_name',
        'transaction_reference',
        'gateway_reference',
        'request_amount',
        'response_status',
        'response_code',
        'response_message',
        'request_payload',
        'response_payload',
        'initiated_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_amount' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
