<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Lizyweb -> Reseller -> Customer flow: a Reseller Partner buys from our own
     * `products` catalog to resell — the mirror of `supplies` (Supplier -> Us), except here
     * the Reseller owes us (see resellerDashboard/ResellerPurchaseController), not the other
     * way round. `amount_paid`/`payment_status` are kept in sync by ResellerPaymentController
     * whenever a ResellerPayment is recorded (see reseller_payments.reseller_purchase_id).
     * The optional resale_* columns cover requirement #12's "Customer/Resale Tracking" on the
     * same row, rather than a separate CRM-style table.
     */
    public function up(): void
    {
        Schema::create('reseller_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete(); // the reseller
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_number')->nullable()->unique();
            $table->string('product_name'); // snapshot, in case the catalog entry changes later
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('amount', 14, 2); // quantity * unit_price
            $table->date('purchase_date');
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->string('resale_customer_name')->nullable();
            $table->decimal('resale_amount', 14, 2)->nullable();
            $table->date('resale_date')->nullable();
            $table->enum('resale_status', ['not_resold', 'resold'])->default('not_resold');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'purchase_date']);
            $table->index(['partner_id', 'purchase_date']);
            $table->index(['partner_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_purchases');
    }
};
