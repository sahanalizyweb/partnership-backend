<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed catalog of Products/Services a partner (Affiliate/Referral/Sales
     * Partner, or any other type) can sell — each one carries the standard sale amount
     * and the fixed standard commission % that applies whenever a Sale is recorded
     * against it (see CommissionCalculationService::generateForSale). This replaces
     * free-text product entry + rule lookup with an amount+rate the Admin sets upfront.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
