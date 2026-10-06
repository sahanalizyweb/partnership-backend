<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fields needed to give Service and Delivery Partner assignments the detail their
     * dashboards need, without splitting them into separate tables — Assignment already
     * carries the lifecycle (status/history) both need. All nullable, so every existing
     * assignment row (lead/order/task, and existing service assignments) is unaffected.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('id');
            $table->string('customer_name')->nullable()->after('description');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->text('delivery_address')->nullable()->after('customer_phone');
            // Rate used for a Service assignment's commission split (see
            // CommissionCalculationService::generateForAssignment) — independent of the
            // generic CommissionRule lookup so Model A/B math is explicit per assignment.
            $table->decimal('commission_percent', 5, 2)->nullable()->after('transaction_amount');
            $table->enum('payment_direction', ['customer_pays_partner', 'customer_pays_lizyweb'])
                ->nullable()->after('commission_percent');
            $table->text('failure_reason')->nullable()->after('payment_direction');
            $table->string('delivery_proof')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn([
                'reference_number', 'customer_name', 'customer_phone', 'delivery_address',
                'commission_percent', 'payment_direction', 'failure_reason', 'delivery_proof',
            ]);
        });
    }
};
