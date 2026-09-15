<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\ClassSchedule;
use App\Models\Notification;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\SponsorStudent;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds a complete, realistic dataset covering every status/scenario in the
 * system, with volumes large enough to exercise pagination (per_page = 20)
 * on all list endpoints. Safe to re-run: root accounts are firstOrCreate'd
 * and everything else is created fresh each run, so use `migrate:fresh`.
 */
class DemoDataSeeder extends Seeder
{
    private const ORGANIZATION_COUNT = 25;

    private const CLASSES_PER_MAIN_ORG = 30;

    private const STUDENTS_PER_CLASS = 12;

    public function run(): void
    {
        $this->seedRootAccounts();

        $organizations = $this->seedOrganizations();

        $organizations->each(fn (Organization $organization) => $this->seedOrganization($organization));

        $this->seedNotificationTemplates();
        $this->seedAuditLogs();
    }

    private function seedRootAccounts(): void
    {
        $superAdmin = User::query()->firstOrCreate(
            ['phone' => '+60123456789'],
            [
                'name' => 'Demo Admin',
                'email' => 'admin@example.com',
                'password' => 'password',
                'user_type' => 'admin',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $superAdmin->syncRoleFromUserType();

        $student = User::query()->firstOrCreate(
            ['phone' => '+60198765432'],
            [
                'name' => 'Demo Student',
                'email' => 'student@example.com',
                'password' => 'password',
                'user_type' => 'student',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $student->syncRoleFromUserType();

        $sponsor = User::query()->firstOrCreate(
            ['phone' => '+60187654321'],
            [
                'name' => 'Demo Sponsor',
                'email' => 'sponsor@example.com',
                'password' => 'password',
                'user_type' => 'sponsor',
                'status' => 'active',
                'phone_verified_at' => now(),
            ]
        );
        $sponsor->syncRoleFromUserType();

        UserDevice::factory()->for($superAdmin)->create(['platform' => 'android']);
        UserDevice::factory()->for($student)->create(['platform' => 'ios']);
        UserDevice::factory()->for($sponsor)->create(['platform' => 'android']);
    }

    /**
     * @return Collection<int, Organization>
     */
    private function seedOrganizations(): Collection
    {
        $main = Organization::factory()->create([
            'name' => 'Al-Huda Education',
            'code' => 'ORG-ALHUDA',
        ]);

        $others = Organization::factory(self::ORGANIZATION_COUNT - 2)->create();
        $inactive = Organization::factory()->create(['status' => 'inactive']);

        return collect([$main, $inactive, ...$others]);
    }

    private function seedOrganization(Organization $organization): void
    {
        $isMain = $organization->code === 'ORG-ALHUDA';

        $admins = $this->seedAdmins($organization, $isMain);
        $students = User::factory($isMain ? 60 : 8)->create(['user_type' => 'student']);
        $sponsors = User::factory($isMain ? 25 : 3)->create(['user_type' => 'sponsor']);

        $this->seedUserEdgeCases();
        $this->seedSponsorLinks($sponsors, $students, $isMain);

        $classCount = $isMain ? self::CLASSES_PER_MAIN_ORG : fake()->numberBetween(1, 4);

        ClassModel::factory($classCount)
            ->for($organization)
            ->create(['created_by' => $admins->first()->id])
            ->each(fn (ClassModel $class, int $index) => $this->seedClass(
                $class,
                $index,
                $admins,
                $students,
                $sponsors,
                $isMain,
            ));
    }

    /**
     * @return Collection<int, User>
     */
    private function seedAdmins(Organization $organization, bool $isMain): Collection
    {
        $admins = User::factory($isMain ? 3 : 1)->create(['user_type' => 'admin']);

        $admins->each(fn (User $admin, int $index) => OrganizationAdmin::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => $index === 0 ? 'active' : fake()->randomElement(['active', 'active', 'inactive']),
        ]));

        return $admins;
    }

    private function seedUserEdgeCases(): void
    {
        User::factory()->create([
            'user_type' => 'student',
            'status' => 'suspended',
        ]);
        User::factory()->unverified()->create(['user_type' => 'student']);
        User::factory()->withoutPassword()->create(['user_type' => 'sponsor']);
        User::factory()->create(['user_type' => 'admin', 'status' => 'inactive']);
    }

    /**
     * @param  Collection<int, User>  $sponsors
     * @param  Collection<int, User>  $students
     */
    private function seedSponsorLinks(Collection $sponsors, Collection $students, bool $isMain): void
    {
        $sponsors->each(function (User $sponsor, int $index) use ($students, $isMain): void {
            $linked = $isMain && $index === 0 ? 3 : fake()->numberBetween(1, 2);

            $students->random(min($linked, $students->count()))->each(
                fn (User $student) => SponsorStudent::factory()->create([
                    'sponsor_id' => $sponsor->id,
                    'student_id' => $student->id,
                    'status' => fake()->randomElement(['active', 'active', 'active', 'inactive']),
                ])
            );
        });
    }

    /**
     * @param  Collection<int, User>  $admins
     * @param  Collection<int, User>  $students
     * @param  Collection<int, User>  $sponsors
     */
    private function seedClass(
        ClassModel $class,
        int $index,
        Collection $admins,
        Collection $students,
        Collection $sponsors,
        bool $isMain,
    ): void {
        // Cover every class status; the main org gets a realistic distribution.
        $status = match (true) {
            $isMain => fake()->randomElement([
                'active', 'active', 'active', 'active', 'active', 'active',
                'draft', 'draft', 'inactive', 'completed',
            ]),
            default => ['draft', 'active', 'inactive', 'completed'][$index % 4],
        };
        $class->forceFill(['status' => $status])->save();

        $this->seedSchedules($class, $index);
        $this->seedPaymentSetting($class, $index);

        $participants = $this->seedParticipants($class, $students, $sponsors, $isMain);

        if ($status !== 'draft') {
            $this->seedPayments($class, $participants, $admins, $isMain);
        }
    }

    private function seedSchedules(ClassModel $class, int $index): void
    {
        // Primary schedule: rotate through days and all recurrence types.
        ClassSchedule::factory()->create([
            'class_id' => $class->id,
            'day_of_week' => $index % 7,
            'recurrence_type' => ['weekly', 'fortnightly', 'monthly'][$index % 3],
            'effective_from' => now()->subMonths(2)->toDateString(),
            'effective_until' => null,
        ]);

        // Every fourth class has a second weekly session on another day.
        if ($index % 4 === 0) {
            ClassSchedule::factory()->create([
                'class_id' => $class->id,
                'day_of_week' => ($index + 3) % 7,
                'recurrence_type' => 'weekly',
                'start_time' => '20:00',
                'effective_from' => now()->subMonths(2)->toDateString(),
            ]);
        }

        // An ended schedule (superseded by the current one).
        if ($index % 5 === 0) {
            ClassSchedule::factory()->create([
                'class_id' => $class->id,
                'day_of_week' => ($index + 1) % 7,
                'recurrence_type' => 'weekly',
                'effective_from' => now()->subMonths(6)->toDateString(),
                'effective_until' => now()->subMonths(2)->toDateString(),
            ]);
        }
    }

    private function seedPaymentSetting(ClassModel $class, int $index): void
    {
        $frequency = ['weekly', 'fortnightly', 'monthly'][$index % 3];

        ClassPaymentSetting::factory()->create([
            'class_id' => $class->id,
            'required_amount' => fake()->randomElement([30, 50, 80, 100, 150]),
            'payment_frequency' => $frequency,
            // Rotate payment-channel configurations: bank only, QR only,
            // merchant URL only, all channels, none (cash only).
            ...match ($index % 5) {
                0 => ['qr_code_path' => null, 'merchant_payment_url' => null],
                1 => ['bank_name' => null, 'bank_account_name' => null, 'bank_account_number' => null,
                    'qr_code_path' => 'qr-codes/class-'.$class->id.'.png', 'merchant_payment_url' => null],
                2 => ['bank_name' => null, 'bank_account_name' => null, 'bank_account_number' => null,
                    'qr_code_path' => null, 'merchant_payment_url' => 'https://pay.example.com/class-'.$class->id],
                3 => ['qr_code_path' => 'qr-codes/class-'.$class->id.'.png',
                    'merchant_payment_url' => 'https://pay.example.com/class-'.$class->id],
                default => ['bank_name' => null, 'bank_account_name' => null, 'bank_account_number' => null,
                    'qr_code_path' => null, 'merchant_payment_url' => null],
            },
            'allow_additional_infaq' => $index % 2 === 0,
            'reminder_enabled' => $index % 3 !== 0,
        ]);
    }

    /**
     * @param  Collection<int, User>  $students
     * @param  Collection<int, User>  $sponsors
     * @return Collection<int, ClassParticipant>
     */
    private function seedParticipants(
        ClassModel $class,
        Collection $students,
        Collection $sponsors,
        bool $isMain,
    ): Collection {
        $participantCount = $isMain ? fake()->numberBetween(8, self::STUDENTS_PER_CLASS + 20) : 5;

        $participants = $students
            ->random(min($participantCount, $students->count()))
            ->map(fn (User $student) => ClassParticipant::factory()->create([
                'class_id' => $class->id,
                'user_id' => $student->id,
                'participant_type' => 'student',
                'status' => fake()->randomElement(['active', 'active', 'active', 'active', 'inactive', 'removed']),
                'left_at' => fake()->optional(0.15)->dateTimeBetween('-1 month', 'now'),
            ]));

        // Sponsor observers on some classes.
        $sponsors->random(min(2, $sponsors->count()))->each(
            fn (User $sponsor) => ClassParticipant::factory()->create([
                'class_id' => $class->id,
                'user_id' => $sponsor->id,
                'participant_type' => 'sponsor',
            ])
        );

        return $participants->where('status', 'active')->values();
    }

    /**
     * Creates payment schedules in every status, with payments in every status,
     * proofs in every review state, and gateway transactions. Notification
     * volume here (reminders per schedule) is what exercises pagination on
     * the notification list endpoints.
     *
     * @param  Collection<int, ClassParticipant>  $participants
     * @param  Collection<int, User>  $admins
     */
    private function seedPayments(
        ClassModel $class,
        Collection $participants,
        Collection $admins,
        bool $isMain,
    ): void {
        $participants->each(function (ClassParticipant $participant) use ($class, $admins, $isMain): void {
            $scheduleCount = $isMain ? fake()->numberBetween(2, 6) : 2;

            for ($i = 0; $i < $scheduleCount; $i++) {
                $this->seedPaymentSchedule($class, $participant, $i, $admins);
            }
        });
    }

    /**
     * @param  Collection<int, User>  $admins
     */
    private function seedPaymentSchedule(
        ClassModel $class,
        ClassParticipant $participant,
        int $sequence,
        Collection $admins,
    ): void {
        $periodStart = Carbon::now()->subMonths(3)->addWeeks($sequence * 2);
        $dueDate = (clone $periodStart)->addWeek();
        $isPastDue = $dueDate->isPast();

        // Rotate through every schedule status.
        $status = match ($sequence % 6) {
            0 => 'paid',
            1 => 'pending',
            2 => 'overdue',
            3 => 'partially_paid',
            4 => 'upcoming',
            default => 'cancelled',
        };
        if (in_array($status, ['pending', 'partially_paid'], true) && ! $isPastDue) {
            $status = 'upcoming';
        }

        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->addDays(6),
            'due_date' => $dueDate,
            'required_amount' => $class->paymentSetting?->required_amount ?? 50,
            'status' => $status,
            'generated_at' => (clone $periodStart)->subDays(3),
        ]);

        $this->seedSchedulePayments($schedule, $participant, $admins);
        $this->seedScheduleNotifications($participant, $schedule, $status);
    }

    /**
     * @param  Collection<int, User>  $admins
     */
    private function seedSchedulePayments(
        PaymentSchedule $schedule,
        ClassParticipant $participant,
        Collection $admins,
    ): void {
        $required = (float) $schedule->required_amount;
        $verifier = $admins->random();

        $payment = match ($schedule->status) {
            'paid' => Payment::factory()->create([
                'payment_schedule_id' => $schedule->id,
                'payer_id' => $participant->user_id,
                'required_amount' => $required,
                'additional_infaq' => fake()->randomElement([0, 0, 5, 10]),
                'total_amount' => $required,
                'status' => 'paid',
                'payment_method' => fake()->randomElement(['qr', 'merchant', 'bank_transfer', 'manual']),
                'paid_at' => $schedule->due_date->copy()->subDay(),
                'verified_at' => $schedule->due_date,
                'verified_by' => $verifier->id,
            ]),
            'partially_paid' => Payment::factory()->create([
                'payment_schedule_id' => $schedule->id,
                'payer_id' => $participant->user_id,
                'required_amount' => $required,
                'total_amount' => $required / 2,
                'status' => 'pending',
                'payment_method' => 'bank_transfer',
            ]),
            'pending' => Payment::factory()->create([
                'payment_schedule_id' => $schedule->id,
                'payer_id' => $participant->user_id,
                'required_amount' => $required,
                'total_amount' => $required,
                'status' => fake()->randomElement(['initiated', 'pending', 'processing']),
            ]),
            'overdue' => Payment::factory()->create([
                'payment_schedule_id' => $schedule->id,
                'payer_id' => $participant->user_id,
                'required_amount' => $required,
                'total_amount' => $required,
                'status' => fake()->randomElement(['failed', 'rejected']),
            ]),
            'cancelled' => Payment::factory()->create([
                'payment_schedule_id' => $schedule->id,
                'payer_id' => $participant->user_id,
                'required_amount' => $required,
                'total_amount' => $required,
                'status' => fake()->randomElement(['cancelled', 'refunded']),
            ]),
            default => null, // upcoming: no payment attempted yet
        };

        if (! $payment instanceof Payment) {
            return;
        }

        // Payment proofs in every review state.
        match ($payment->status) {
            'paid' => PaymentProof::factory()->create([
                'payment_id' => $payment->id,
                'status' => 'approved',
                'reviewed_at' => $payment->verified_at,
                'reviewed_by' => $payment->verified_by,
            ]),
            'rejected' => PaymentProof::factory()->create([
                'payment_id' => $payment->id,
                'status' => 'rejected',
                'reviewed_at' => now()->subDays(2),
                'reviewed_by' => $verifier->id,
                'rejection_reason' => 'Receipt amount does not match the required amount.',
            ]),
            'pending', 'processing' => PaymentProof::factory()->create([
                'payment_id' => $payment->id,
                'status' => 'pending',
            ]),
            default => null,
        };

        // Gateway transactions for non-manual payments: success + a failed retry.
        if ($payment->payment_method !== 'manual') {
            PaymentTransaction::factory()->create([
                'payment_id' => $payment->id,
                'request_amount' => $payment->total_amount,
                'response_status' => $payment->status === 'paid' ? 'success' : 'pending',
                'completed_at' => $payment->status === 'paid' ? $payment->paid_at : null,
            ]);

            if (in_array($payment->status, ['paid', 'failed'], true)) {
                PaymentTransaction::factory()->create([
                    'payment_id' => $payment->id,
                    'request_amount' => $payment->total_amount,
                    'response_status' => 'failed',
                    'response_code' => 'DECLINED',
                    'response_message' => 'Insufficient funds.',
                    'initiated_at' => $payment->created_at->copy()->subHour(),
                    'completed_at' => $payment->created_at->copy()->subMinutes(55),
                ]);
            }
        }
    }

    private function seedScheduleNotifications(
        ClassParticipant $participant,
        PaymentSchedule $schedule,
        string $status,
    ): void {
        if (! in_array($status, ['pending', 'partially_paid', 'overdue'], true)) {
            return;
        }

        $notification = Notification::factory()->create([
            'user_id' => $participant->user_id,
            'type' => $status === 'overdue' ? 'payment.overdue' : 'payment.reminder',
            'title' => $status === 'overdue' ? 'Payment overdue' : 'Payment reminder',
            'message' => "Your payment of RM {$schedule->required_amount} is "
                .($status === 'overdue' ? 'overdue.' : 'due soon.'),
            'data' => ['payment_schedule_id' => $schedule->id],
            'related_type' => PaymentSchedule::class,
            'related_id' => $schedule->id,
            'read_at' => fake()->optional(0.4)->dateTimeBetween('-1 week', 'now'),
            'sent_at' => now()->subDays(fake()->numberBetween(1, 10)),
        ]);

        NotificationLog::factory()->create([
            'notification_id' => $notification->id,
            'channel' => 'push',
            'status' => 'delivered',
            'provider_message_id' => fake()->uuid(),
            'sent_at' => $notification->sent_at,
            'delivered_at' => $notification->sent_at->copy()->addMinute(),
        ]);

        if (fake()->boolean(30)) {
            NotificationLog::factory()->create([
                'notification_id' => $notification->id,
                'channel' => 'email',
                'status' => fake()->randomElement(['sent', 'failed']),
                'error_message' => fake()->boolean(20) ? 'Mailbox unavailable.' : null,
                'sent_at' => $notification->sent_at,
            ]);
        }
    }

    private function seedNotificationTemplates(): void
    {
        $types = ['payment.reminder', 'payment.overdue', 'payment.verified', 'payment.rejected'];
        $channels = ['push', 'email', 'telegram'];

        foreach ($types as $index => $type) {
            NotificationTemplate::factory()->create([
                'name' => $type.' '.$channels[$index % 3],
                'notification_type' => $type,
                'channel' => $channels[$index % 3],
                'status' => 'active',
            ]);
        }

        NotificationTemplate::factory()->create([
            'name' => 'payment.reminder telegram (disabled)',
            'notification_type' => 'payment.reminder',
            'channel' => 'telegram',
            'status' => 'inactive',
        ]);
    }

    private function seedAuditLogs(): void
    {
        $users = User::query()->inRandomOrder()->limit(50)->get();
        $entities = [
            Organization::class,
            ClassModel::class,
            Payment::class,
            ClassParticipant::class,
        ];
        $actions = ['RECORD_CREATED', 'RECORD_UPDATED', 'RECORD_DELETED', 'PAYMENT_VERIFIED', 'LOGIN'];

        for ($i = 0; $i < 60; $i++) {
            AuditLog::factory()->create([
                'user_id' => $users->random()->id,
                'action' => fake()->randomElement($actions),
                'entity_type' => fake()->randomElement($entities),
                'old_values' => fake()->boolean(40) ? ['status' => 'draft'] : null,
                'new_values' => fake()->boolean(60) ? ['status' => 'active'] : null,
                'created_at' => now()->subMinutes(fake()->numberBetween(1, 60 * 24 * 30)),
            ]);
        }
    }
}
