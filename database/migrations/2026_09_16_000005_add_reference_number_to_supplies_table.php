<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Our own internal system reference for a Supply (e.g. SUPPLY-000001), separate from
     * `invoice_number` which stays the supplier's own document number. Nullable + unique so
     * existing rows (which have none) stay valid — only new rows get one generated.
     */
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('supplier_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropColumn('reference_number');
        });
    }
};
