<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment we made to a Supplier Partner against a specific Supply/invoice — kept
     * separate from the existing `payments` table (which drives the Commission/ledger flow
     * for Sales/Affiliate/Referral partners) so the Supplier flow stays self-contained and
     * can't interfere with that unrelated flow. `payment_method` is free text, same
     * convention as `payments.payment_method` — no new payment-method concept introduced.
     */
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'payment_date']);
            $table->index(['partner_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
