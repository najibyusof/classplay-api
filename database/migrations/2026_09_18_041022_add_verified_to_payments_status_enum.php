<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_STATUSES = ['initiated', 'pending', 'processing', 'paid', 'failed', 'rejected', 'refunded', 'cancelled'];

    private const NEW_STATUSES = ['initiated', 'pending', 'processing', 'paid', 'verified', 'failed', 'rejected', 'refunded', 'cancelled'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', self::NEW_STATUSES)->default('initiated')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payments')->where('status', 'verified')->update(['status' => 'paid']);

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', self::OLD_STATUSES)->default('initiated')->change();
        });
    }
};
