<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `reference_number` moves from "optional free text" to "backend auto-generated when the
     * client didn't supply one" (see SaleController::store). Existing NULLs are unaffected —
     * MySQL allows any number of NULLs under a unique index.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unique('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
        });
    }
};
