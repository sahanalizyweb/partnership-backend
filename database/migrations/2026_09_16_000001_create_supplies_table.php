<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks the Supplier -> Our Company flow: one row per delivery/receipt of goods from a
     * Supplier Partner. Admin-logged (the supplier doesn't self-report, unlike Sales — they
     * just view their own read-only history in the portal). `amount_paid`/`payment_status`
     * are kept in sync by SupplierPaymentController whenever a SupplierPayment is recorded
     * against this row (see supplier_payments.supply_id).
     */
    public function up(): void
    {
        Schema::create('supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete(); // the supplier
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); // snapshot, in case the catalog entry changes later
            $table->text('product_details')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->decimal('amount', 14, 2); // purchase/received amount for this delivery
            $table->date('supply_date'); // supply/received date
            $table->string('invoice_number')->nullable(); // supplier's invoice/reference number
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'supply_date']);
            $table->index(['partner_id', 'supply_date']);
            $table->index(['partner_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplies');
    }
};
