<?php

namespace App\Services\Payment;

use App\Models\ClassModel;
use App\Models\ClassPaymentSetting;
use App\Models\PaymentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentScheduleService
{
    /**
     * Default generation horizon: the current period plus this many upcoming periods.
     * Schedules are not generated for a class's entire lifetime up front, since an
     * open-ended class (no end_date) would otherwise generate an unbounded number of rows.
     */
    public const DEFAULT_HORIZON_PERIODS = 3;

    /**
     * Generate any missing payment schedules for a class's active student participants.
     *
     * Safe to call repeatedly: existing (class_participant_id, period_start, period_end)
     * rows are left untouched (idempotent, backed by a database unique constraint).
     *
     * @return Collection<int, PaymentSchedule> newly created schedules only
     */
    public function generateForClass(ClassModel $class, ?int $horizonPeriods = null): Collection
    {
        $setting = $class->paymentSetting;

        if (! $setting || $class->status !== 'active') {
            return collect();
        }

        $horizonPeriods ??= self::DEFAULT_HORIZON_PERIODS;
        $periods = $this->calculatePeriods($class, $setting, $horizonPeriods);

        if ($periods === []) {
            return collect();
        }

        $participants = $class->participants()
            ->where('participant_type', 'student')
            ->where('status', 'active')
            ->get();

        return DB::transaction(function () use ($class, $setting, $participants, $periods) {
            $created = collect();

            foreach ($participants as $participant) {
                foreach ($periods as [$periodStart, $periodEnd]) {
                    $schedule = PaymentSchedule::query()->firstOrCreate(
                        [
                            'class_participant_id' => $participant->id,
                            'period_start' => $periodStart->toDateString(),
                            'period_end' => $periodEnd->toDateString(),
                        ],
                        [
                            'class_id' => $class->id,
                            'due_date' => $this->calculateDueDate($periodStart, $periodEnd),
                            'required_amount' => $setting->required_amount,
                            'status' => 'upcoming',
                            'generated_at' => now(),
                        ]
                    );

                    if ($schedule->wasRecentlyCreated) {
                        $created->push($schedule);
                    }
                }
            }

            return $created;
        });
    }

    /**
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function calculatePeriods(ClassModel $class, ClassPaymentSetting $setting, int $horizonPeriods): array
    {
        $anchor = $class->start_date ? Carbon::parse($class->start_date)->startOfDay() : Carbon::today();
        $today = Carbon::today();

        if ($anchor->lt($today)) {
            $anchor = $this->periodStartContaining($anchor, $today, $setting->payment_frequency);
        }

        $endDate = $class->end_date ? Carbon::parse($class->end_date)->endOfDay() : null;

        $periods = [];
        $cursor = $anchor->copy();

        for ($i = 0; $i < $horizonPeriods; $i++) {
            if ($endDate && $cursor->gt($endDate)) {
                break;
            }

            $periodEnd = $this->periodEnd($cursor, $setting->payment_frequency);
            $periods[] = [$cursor->copy(), $periodEnd->copy()];

            $cursor = $periodEnd->copy()->addDay();
        }

        return $periods;
    }

    /**
     * Fast-forward the anchor date to the start of the period that currently contains
     * $today, so generation never has to walk through the class's entire past history.
     */
    private function periodStartContaining(Carbon $start, Carbon $today, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $start->copy()->addWeeks((int) floor($start->diffInDays($today) / 7)),
            'fortnightly' => $start->copy()->addWeeks(2 * (int) floor($start->diffInDays($today) / 14)),
            'monthly' => $start->copy()->addMonthsNoOverflow($start->diffInMonths($today)),
            default => $start->copy(),
        };
    }

    private function periodEnd(Carbon $periodStart, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $periodStart->copy()->addDays(6),
            'fortnightly' => $periodStart->copy()->addDays(13),
            'monthly' => $periodStart->copy()->addMonthNoOverflow()->subDay(),
            default => $periodStart->copy()->addDays(6),
        };
    }

    /**
     * Centralized due-date rule: no per-class due-day configuration exists yet, so the
     * payment falls due on the first day of its period. See docs/payment/payment-schedule-engine.md.
     */
    private function calculateDueDate(Carbon $periodStart, Carbon $periodEnd): string
    {
        return $periodStart->toDateString();
    }
}
