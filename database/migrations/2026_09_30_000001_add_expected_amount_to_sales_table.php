<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The amount a Referral Partner expects the referred customer's order to be — an estimate
     * only. The final sale_amount (which the commission is based on) is still entered by admin
     * when completing the referral.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('expected_amount', 14, 2)->nullable()->after('sale_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('expected_amount');
        });
    }
};
