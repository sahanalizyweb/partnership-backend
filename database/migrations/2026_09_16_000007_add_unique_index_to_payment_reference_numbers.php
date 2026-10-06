<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both payments and supplier_payments now get a backend-generated reference_number when
     * the client doesn't supply one (PaymentController::store, SupplierPaymentController::
     * store) — unique per table, existing NULLs unaffected.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('reference_number');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->unique('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
        });
    }
};
