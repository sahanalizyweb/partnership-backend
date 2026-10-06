<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->unsignedInteger('enquiries_count')->default(0)->after('clicks_count');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_links', function (Blueprint $table) {
            $table->dropColumn('enquiries_count');
        });
    }
};
