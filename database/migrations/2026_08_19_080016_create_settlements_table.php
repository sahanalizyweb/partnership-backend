<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('settlement_number');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_commission', 14, 2)->default(0);
            $table->decimal('total_advances', 14, 2)->default(0);
            $table->decimal('total_refunds', 14, 2)->default(0);
            $table->decimal('total_adjustments', 14, 2)->default(0);
            $table->decimal('net_payable', 14, 2)->default(0); // positive = payable to partner, negative = receivable
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'paid', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'settlement_number']);
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
