<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'teacher_name',
        'day_of_week',
        'start_time',
        'frequency',
        'payment_amount',
        'status',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ClassSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'class_id');
    }

    /**
     * @return HasOne<ClassPaymentSetting, $this>
     */
    public function paymentSetting(): HasOne
    {
        return $this->hasOne(ClassPaymentSetting::class, 'class_id');
    }

    /**
     * @return HasMany<ClassParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ClassParticipant::class, 'class_id');
    }

    /**
     * @return HasMany<PaymentSchedule, $this>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class, 'class_id');
    }
}
