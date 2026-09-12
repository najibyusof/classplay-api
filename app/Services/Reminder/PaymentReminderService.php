<?php

namespace App\Services\Reminder;

use App\Jobs\SendNotificationJob;
use App\Models\ClassPaymentSetting;
use App\Models\Notification;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Services\Notification\NotificationType;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

class PaymentReminderService
{
    /**
     * Process all upcoming and overdue payment reminders for a given as-of date.
     */
    public function processAllReminders(?DateTimeInterface $asOfDate = null): int
    {
        $sentUpcoming = $this->processUpcomingReminders($asOfDate);
        $sentOverdue = $this->processOverdueReminders($asOfDate);

        return $sentUpcoming + $sentOverdue;
    }

    /**
     * Process upcoming reminders based on class payment settings.
     */
    public function processUpcomingReminders(?DateTimeInterface $asOfDate = null): int
    {
        $today = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : now('Asia/Kuala_Lumpur')->startOfDay();

        $settings = ClassPaymentSetting::query()
            ->where('reminder_enabled', true)
            ->where('reminder_days_before', '>', 0)
            ->get();

        $sent = 0;

        foreach ($settings as $setting) {
            $targetDueDate = $today->copy()->addDays((int) $setting->reminder_days_before)->toDateString();

            $schedules = PaymentSchedule::query()
                ->where('class_id', $setting->class_id)
                ->whereDate('due_date', $targetDueDate)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->with(['classModel', 'classParticipant'])
                ->get();

            foreach ($schedules as $schedule) {
                if (! $this->isEligibleForReminder($schedule, 'upcoming', $today)) {
                    continue;
                }

                $outstanding = $this->calculateOutstandingBalance($schedule);

                if ($outstanding <= 0) {
                    continue;
                }

                $userId = $schedule->classParticipant->user_id;
                $className = $schedule->classModel?->name ?? 'class';
                $formattedDueDate = $schedule->due_date ? $schedule->due_date->format('j F Y') : $targetDueDate;
                $amountStr = number_format($outstanding, 2, '.', '');

                $type = NotificationType::PAYMENT_REMINDER;
                $title = 'Payment Reminder';
                $message = "Your payment of RM{$amountStr} for {$className} is due on {$formattedDueDate}.";

                $notification = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => [
                        'type' => $type,
                        'payment_schedule_id' => $schedule->id,
                        'class_id' => $schedule->class_id,
                        'reminder_type' => 'upcoming',
                        'reminder_date' => $today->toDateString(),
                        'outstanding_amount' => $amountStr,
                        'action' => 'open_payment',
                        'route' => "/payment-schedules/{$schedule->id}",
                    ],
                    'related_type' => PaymentSchedule::class,
                    'related_id' => $schedule->id,
                    'sent_at' => now(),
                ]);

                SendNotificationJob::dispatch($notification);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Process overdue reminders based on class payment settings.
     */
    public function processOverdueReminders(?DateTimeInterface $asOfDate = null): int
    {
        $today = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : now('Asia/Kuala_Lumpur')->startOfDay();

        $settings = ClassPaymentSetting::query()
            ->where('reminder_enabled', true)
            ->where('reminder_days_after', '>', 0)
            ->get();

        $sent = 0;

        foreach ($settings as $setting) {
            $targetDueDate = $today->copy()->subDays((int) $setting->reminder_days_after)->toDateString();

            $schedules = PaymentSchedule::query()
                ->where('class_id', $setting->class_id)
                ->whereDate('due_date', $targetDueDate)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->with(['classModel', 'classParticipant'])
                ->get();

            foreach ($schedules as $schedule) {
                if (in_array($schedule->status, ['pending', 'upcoming'], true) && $schedule->due_date && $schedule->due_date->lt($today)) {
                    $schedule->update(['status' => 'overdue']);
                }

                if (! $this->isEligibleForReminder($schedule, 'overdue', $today)) {
                    continue;
                }

                $outstanding = $this->calculateOutstandingBalance($schedule);

                if ($outstanding <= 0) {
                    continue;
                }

                $userId = $schedule->classParticipant->user_id;
                $className = $schedule->classModel?->name ?? 'class';
                $formattedDueDate = $schedule->due_date ? $schedule->due_date->format('j F Y') : $targetDueDate;
                $amountStr = number_format($outstanding, 2, '.', '');

                $type = NotificationType::PAYMENT_OVERDUE;
                $title = 'Payment Overdue';
                $message = "Your payment of RM{$amountStr} for {$className} was due on {$formattedDueDate}.";

                $notification = Notification::query()->create([
                    'user_id' => $userId,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => [
                        'type' => $type,
                        'payment_schedule_id' => $schedule->id,
                        'class_id' => $schedule->class_id,
                        'reminder_type' => 'overdue',
                        'reminder_date' => $today->toDateString(),
                        'outstanding_amount' => $amountStr,
                        'action' => 'open_payment',
                        'route' => "/payment-schedules/{$schedule->id}",
                    ],
                    'related_type' => PaymentSchedule::class,
                    'related_id' => $schedule->id,
                    'sent_at' => now(),
                ]);

                SendNotificationJob::dispatch($notification);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Check if a payment schedule is eligible for a given reminder type and date.
     */
    public function isEligibleForReminder(PaymentSchedule $schedule, string $reminderType, DateTimeInterface $asOfDate): bool
    {
        if (in_array($schedule->status, ['paid', 'cancelled'], true)) {
            return false;
        }

        $participant = $schedule->classParticipant;

        if (! $participant || ! $participant->user_id || ($participant->status ?? null) === 'inactive') {
            return false;
        }

        $class = $schedule->classModel;

        if (! $class || ($class->status ?? null) !== 'active') {
            return false;
        }

        $setting = $class->paymentSetting;

        if (! $setting || ! $setting->reminder_enabled) {
            return false;
        }

        if ($this->calculateOutstandingBalance($schedule) <= 0) {
            return false;
        }

        return ! $this->isReminderAlreadySent($participant->user_id, $schedule->id, $reminderType, $asOfDate);
    }

    /**
     * Deduplication check to prevent sending multiple identical notifications on the same date.
     */
    public function isReminderAlreadySent(int $userId, int $scheduleId, string $reminderType, DateTimeInterface $asOfDate): bool
    {
        $dateStr = Carbon::parse($asOfDate)->toDateString();

        return Notification::query()
            ->where('user_id', $userId)
            ->where('related_type', PaymentSchedule::class)
            ->where('related_id', $scheduleId)
            ->where(function ($query) use ($reminderType) {
                if ($reminderType === 'upcoming') {
                    $query->whereIn('type', [NotificationType::PAYMENT_REMINDER, 'payment.upcoming_reminder'])
                        ->orWhere('data->reminder_type', 'upcoming');
                } else {
                    $query->whereIn('type', [NotificationType::PAYMENT_OVERDUE, 'payment.overdue_reminder'])
                        ->orWhere('data->reminder_type', 'overdue');
                }
            })
            ->where(function ($query) use ($dateStr) {
                $query->whereDate('created_at', $dateStr)
                    ->orWhere('data->reminder_date', $dateStr);
            })
            ->exists();
    }

    /**
     * Server-side calculation of remaining outstanding balance.
     */
    public function calculateOutstandingBalance(PaymentSchedule $schedule): float
    {
        $totalRequired = (float) $schedule->required_amount;
        $totalPaid = (float) $schedule->payments()->where('status', 'paid')->sum('total_amount');

        return max(0.0, $totalRequired - $totalPaid);
    }

    /**
     * Admin manual reminder trigger.
     */
    public function sendManualReminder(PaymentSchedule $schedule, User $actor): Notification
    {
        $schedule->loadMissing(['classModel', 'classParticipant']);

        if (in_array($schedule->status, ['paid', 'cancelled'], true)) {
            throw new RuntimeException("Cannot send reminder for a {$schedule->status} payment schedule.");
        }

        $userId = $schedule->classParticipant?->user_id;

        if (! $userId) {
            throw new RuntimeException('Payment schedule participant user not found.');
        }

        $outstanding = $this->calculateOutstandingBalance($schedule);

        if ($outstanding <= 0) {
            throw new RuntimeException('This payment schedule is already fully paid.');
        }

        $isOverdue = $schedule->status === 'overdue' || ($schedule->due_date && $schedule->due_date->lt(now()->startOfDay()));
        $type = $isOverdue ? NotificationType::PAYMENT_OVERDUE : NotificationType::PAYMENT_REMINDER;
        $title = $isOverdue ? 'Payment Overdue' : 'Payment Reminder';

        $className = $schedule->classModel?->name ?? 'class';
        $formattedDueDate = $schedule->due_date ? $schedule->due_date->format('j F Y') : 'soon';
        $amountStr = number_format($outstanding, 2, '.', '');

        $message = $isOverdue
            ? "Your payment of RM{$amountStr} for {$className} was due on {$formattedDueDate}."
            : "Your payment of RM{$amountStr} for {$className} is due on {$formattedDueDate}.";

        $notification = Notification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => [
                'type' => $type,
                'payment_schedule_id' => $schedule->id,
                'class_id' => $schedule->class_id,
                'reminder_type' => $isOverdue ? 'overdue' : 'upcoming',
                'reminder_date' => now()->toDateString(),
                'outstanding_amount' => $amountStr,
                'manual_sent_by' => $actor->id,
                'action' => 'open_payment',
                'route' => "/payment-schedules/{$schedule->id}",
            ],
            'related_type' => PaymentSchedule::class,
            'related_id' => $schedule->id,
            'sent_at' => now(),
        ]);

        SendNotificationJob::dispatch($notification);

        return $notification;
    }

    /**
     * Preview eligible reminders for an as-of date without creating notifications.
     *
     * @return array<string, mixed>
     */
    public function previewEligibleReminders(?DateTimeInterface $asOfDate = null): array
    {
        $today = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : now('Asia/Kuala_Lumpur')->startOfDay();

        $settings = ClassPaymentSetting::query()->where('reminder_enabled', true)->get();
        $reminders = [];

        foreach ($settings as $setting) {
            // Upcoming
            if ($setting->reminder_days_before > 0) {
                $targetDueDate = $today->copy()->addDays((int) $setting->reminder_days_before)->toDateString();

                $schedules = PaymentSchedule::query()
                    ->where('class_id', $setting->class_id)
                    ->whereDate('due_date', $targetDueDate)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->with(['classModel', 'classParticipant.user'])
                    ->get();

                foreach ($schedules as $schedule) {
                    if ($this->isEligibleForReminder($schedule, 'upcoming', $today)) {
                        $totalPaid = (float) $schedule->payments()->where('status', 'paid')->sum('total_amount');
                        $outstanding = max(0.0, (float) $schedule->required_amount - $totalPaid);

                        $reminders[] = [
                            'payment_schedule_id' => $schedule->id,
                            'user_id' => $schedule->classParticipant?->user_id,
                            'user_name' => $schedule->classParticipant?->user?->name,
                            'class_name' => $schedule->classModel?->name,
                            'due_date' => $schedule->due_date?->toDateString(),
                            'reminder_type' => 'upcoming',
                            'reminder_date' => $today->toDateString(),
                            'required_amount' => (float) $schedule->required_amount,
                            'paid_amount' => $totalPaid,
                            'outstanding_amount' => $outstanding,
                        ];
                    }
                }
            }

            // Overdue
            if ($setting->reminder_days_after > 0) {
                $targetDueDate = $today->copy()->subDays((int) $setting->reminder_days_after)->toDateString();

                $schedules = PaymentSchedule::query()
                    ->where('class_id', $setting->class_id)
                    ->whereDate('due_date', $targetDueDate)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->with(['classModel', 'classParticipant.user'])
                    ->get();

                foreach ($schedules as $schedule) {
                    if ($this->isEligibleForReminder($schedule, 'overdue', $today)) {
                        $totalPaid = (float) $schedule->payments()->where('status', 'paid')->sum('total_amount');
                        $outstanding = max(0.0, (float) $schedule->required_amount - $totalPaid);

                        $reminders[] = [
                            'payment_schedule_id' => $schedule->id,
                            'user_id' => $schedule->classParticipant?->user_id,
                            'user_name' => $schedule->classParticipant?->user?->name,
                            'class_name' => $schedule->classModel?->name,
                            'due_date' => $schedule->due_date?->toDateString(),
                            'reminder_type' => 'overdue',
                            'reminder_date' => $today->toDateString(),
                            'required_amount' => (float) $schedule->required_amount,
                            'paid_amount' => $totalPaid,
                            'outstanding_amount' => $outstanding,
                        ];
                    }
                }
            }
        }

        return [
            'as_of_date' => $today->toDateString(),
            'eligible_count' => count($reminders),
            'reminders' => $reminders,
        ];
    }
}
