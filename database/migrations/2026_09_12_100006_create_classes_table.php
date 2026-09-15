<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('teacher_name', 150);
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->enum('frequency', ['weekly', 'fortnightly', 'monthly'])->default('weekly');
            $table->decimal('payment_amount', 12, 2);
            $table->enum('status', ['draft', 'active', 'inactive', 'completed'])->default('draft');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
