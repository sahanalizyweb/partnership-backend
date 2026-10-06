<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->enum('payment_type', ['commission', 'advance', 'refund', 'adjustment', 'settlement']);
            $table->enum('direction', ['to_partner', 'from_partner']);
            $table->decimal('amount', 14, 2);
            $table->string('reference_number')->nullable();
            $table->date('payment_date');
            $table->string('payment_method')->nullable(); // bank_transfer/upi/cheque/cash/...
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->foreignId('related_settlement_id')->nullable()->constrained('settlements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index(['business_id', 'payment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
