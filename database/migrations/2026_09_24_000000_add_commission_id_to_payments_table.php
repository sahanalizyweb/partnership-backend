<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A commission is now paid (or collected) directly: one Payment row per commission, linked
 * here, instead of going through a separate Settlement batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('commission_id')->nullable()->after('assignment_id')
                ->constrained('commissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_id');
        });
    }
};
