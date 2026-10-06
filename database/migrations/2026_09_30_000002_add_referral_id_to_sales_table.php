<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a Sale that admin records to the Referral it came from (both live in `sales`:
     * a referral is a row with sale_type = 'referral'). Unique — one referral leads to at most
     * one sale, so it can never be commissioned twice.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('referral_id')->nullable()->unique()->after('sale_type')
                ->constrained('sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referral_id');
        });
    }
};
