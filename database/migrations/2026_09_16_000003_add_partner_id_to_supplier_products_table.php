<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a Supplier Partner add their own catalog entries from the portal, scoped to
     * themselves — nullable because Admin can still add general/shared entries (visible to
     * every supplier) with no partner_id, same as before this migration.
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('business_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
