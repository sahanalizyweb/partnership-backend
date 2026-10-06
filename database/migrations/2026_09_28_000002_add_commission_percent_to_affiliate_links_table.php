<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The commission % verified by admin when the link is created (pre-loaded from the
     * product / affiliate commission rule). Sales attributed through the link use it.
     */
    public function up(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
