<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_type_enrollment_id')->constrained('partner_type_enrollments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('purchase_price', 14, 2);
            $table->decimal('recommended_selling_price', 14, 2)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['partner_type_enrollment_id', 'product_id'], 'reseller_product_prices_enrollment_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_product_prices');
    }
};
