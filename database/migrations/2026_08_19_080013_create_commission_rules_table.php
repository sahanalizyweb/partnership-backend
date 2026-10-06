<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // scope: exactly one of these should be set. partner-level rules take precedence
            // over category-level, which take precedence over type-level (see
            // CommissionCalculationService).
            $table->foreignId('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();
            $table->foreignId('partner_category_id')->nullable()->constrained('partner_categories')->cascadeOnDelete();
            $table->foreignId('partner_type_id')->nullable()->constrained('partner_types')->cascadeOnDelete();
            $table->enum('rule_type', ['percentage', 'fixed', 'product', 'category', 'slab']);
            $table->decimal('value', 14, 4)->nullable(); // percentage or fixed amount, null for slab
            $table->string('product_ref')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
