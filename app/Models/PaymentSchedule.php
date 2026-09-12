<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_id',
        'class_participant_id',
        'period_start',
        'period_end',
        'due_date',
        'required_amount',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'required_amount' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassModel, $this>
     */
    public function classModel(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    /**
     * @return BelongsTo<ClassParticipant, $this>
     */
    public function classParticipant(): BelongsTo
    {
        return $this->belongsTo(ClassParticipant::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'upcoming');
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'overdue');
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopeForParticipant(Builder $query, int $classParticipantId): Builder
    {
        return $query->where('class_participant_id', $classParticipantId);
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopeForClass(Builder $query, int $classId): Builder
    {
        return $query->where('class_id', $classId);
    }

    /**
     * @param  Builder<PaymentSchedule>  $query
     * @return Builder<PaymentSchedule>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->whereHas('classModel', fn (Builder $q) => $q->where('organization_id', $organizationId));
    }
}
