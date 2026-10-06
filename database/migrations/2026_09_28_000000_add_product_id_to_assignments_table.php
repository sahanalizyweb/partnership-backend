<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a (delivery) assignment to one of our listed Products — chosen from a Product
     * dropdown on the New Assignment form, instead of misusing the partner Category field.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('partner_category_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
