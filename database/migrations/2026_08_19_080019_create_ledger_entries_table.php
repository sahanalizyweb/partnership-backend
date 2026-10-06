<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger. Rows are written only via LedgerPostingService so that
        // running_balance always reflects the correct cumulative balance per partner.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->enum('transaction_type', [
                'opening_balance', 'commission', 'advance', 'payment', 'refund', 'adjustment', 'settlement',
            ]);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('running_balance', 14, 2);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
