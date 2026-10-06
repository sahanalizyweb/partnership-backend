<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a Sale/Referral to the admin-defined Product/Service catalog (nullable — older
     * free-text sales and sales for partner types that don't use the catalog keep working),
     * and tracks the Sale/Referral's own lifecycle status separately from its Commission's
     * approval status.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending')->after('sale_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('status');
        });
    }
};
