<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('class_participant_id')->constrained('class_participants')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('required_amount', 12, 2);
            $table->enum('status', ['upcoming', 'pending', 'partially_paid', 'paid', 'overdue', 'cancelled'])->default('upcoming');
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['class_participant_id', 'period_start', 'period_end'], 'payment_schedules_participant_period_unique');
            $table->index('class_id');
            $table->index('class_participant_id');
            $table->index('due_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};
