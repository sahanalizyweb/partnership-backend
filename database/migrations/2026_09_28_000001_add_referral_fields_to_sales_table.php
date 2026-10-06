<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referrals share the sales table but carry no product/qty — a Referral Partner only
     * refers the customer; the actual order is handled by Lizy Mart. A referral records the
     * customer, its own commission %, and the final sale amount once completed (its commission
     * is only generated then — see SaleController).
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('sale_type', ['sale', 'referral'])->default('sale')->after('attribution_method');
            $table->string('customer_name')->nullable()->after('product_name');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('sale_amount');
            $table->string('product_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['sale_type', 'customer_name', 'customer_phone', 'commission_percent']);
            $table->string('product_name')->nullable(false)->change();
        });
    }
};
