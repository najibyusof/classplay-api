<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassPaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'required_amount',
        'currency',
        'payment_frequency',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'qr_code_path',
        'merchant_payment_url',
        'allow_additional_infaq',
        'minimum_infaq',
        'maximum_infaq',
        'reminder_enabled',
        'reminder_days_before',
        'reminder_days_after',
    ];

    protected function casts(): array
    {
        return [
            'required_amount' => 'decimal:2',
            'allow_additional_infaq' => 'boolean',
            'minimum_infaq' => 'decimal:2',
            'maximum_infaq' => 'decimal:2',
            'reminder_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ClassModel, $this>
     */
    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
