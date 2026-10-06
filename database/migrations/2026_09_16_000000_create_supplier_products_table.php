<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed catalog of products/materials a Supplier Partner supplies to us — the
     * Supplier -> Our Company counterpart of the `products` catalog used by the Sales/
     * Affiliate/Referral flow. `standard_price` is an optional default to speed up logging a
     * Supply (see `supplies.unit_price`), not a fixed rate — actual purchase price can vary
     * per delivery/invoice.
     */
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('details')->nullable();
            $table->decimal('standard_price', 14, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
