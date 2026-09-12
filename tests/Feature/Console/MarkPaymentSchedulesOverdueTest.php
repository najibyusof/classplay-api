<?php

namespace Tests\Feature\Console;

use App\Models\PaymentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkPaymentSchedulesOverdueTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_past_due_pending_schedules_as_overdue(): void
    {
        $overdue = PaymentSchedule::factory()->create([
            'due_date' => now()->subDay(),
            'status' => 'pending',
        ]);
        $notYetDue = PaymentSchedule::factory()->create([
            'due_date' => now()->addDay(),
            'status' => 'pending',
        ]);

        $this->artisan('payment-schedules:mark-overdue')->assertSuccessful();

        $this->assertSame('overdue', $overdue->fresh()->status);
        $this->assertSame('pending', $notYetDue->fresh()->status);
    }
}
