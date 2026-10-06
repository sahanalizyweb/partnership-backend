<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment a Reseller Partner made to us against a specific reseller_purchase/invoice —
     * the mirror of supplier_payments, kept just as self-contained so it can't interfere with
     * the unrelated Sales/Commission/Payment flow used by every other partner type.
     */
    public function up(): void
    {
        Schema::create('reseller_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reseller_purchase_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->string('reference_number')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'payment_date']);
            $table->index(['partner_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payments');
    }
};
