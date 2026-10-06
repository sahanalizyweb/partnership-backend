<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product sales — the primary commission-driving activity: a partner sold one of our
     * products (from Shopify, tracked manually for now). Each sale generates a Commission
     * (see commissions.sale_id) that goes through the existing approve/reject + ledger flow.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('product_name');
            $table->decimal('unit_price', 14, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('sale_amount', 14, 2); // unit_price * quantity, stored for flexibility
            $table->date('sale_date');
            $table->string('reference_number')->nullable(); // future Shopify order number/handle
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'sale_date']);
            $table->index(['partner_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
