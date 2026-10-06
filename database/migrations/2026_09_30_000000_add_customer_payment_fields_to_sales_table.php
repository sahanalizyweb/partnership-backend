<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the customer has paid for a sale. A sale's commission can only be approved (become
     * payable) once the customer has paid in full — see CommissionPaymentService.
     * payment_status NULL = not tracked (sales recorded before this existed, and referrals),
     * which keeps their existing approval flow unchanged.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->nullable()->after('status');
            $table->decimal('amount_paid', 14, 2)->nullable()->after('payment_status');
            $table->string('payment_method', 100)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'amount_paid', 'payment_method']);
        });
    }
};
