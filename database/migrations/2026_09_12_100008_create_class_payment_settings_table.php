<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->unique()->constrained('classes')->cascadeOnDelete();
            $table->decimal('required_amount', 12, 2);
            $table->char('currency', 3)->default('MYR');
            $table->enum('payment_frequency', ['weekly', 'fortnightly', 'monthly']);
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('qr_code_path', 500)->nullable();
            $table->text('merchant_payment_url')->nullable();
            $table->boolean('allow_additional_infaq')->default(false);
            $table->decimal('minimum_infaq', 12, 2)->nullable();
            $table->decimal('maximum_infaq', 12, 2)->nullable();
            $table->boolean('reminder_enabled')->default(false);
            $table->integer('reminder_days_before')->nullable();
            $table->integer('reminder_days_after')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_payment_settings');
    }
};
