<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products / Services catalog: `type` tells a Product (Lizy Mart shop item) from a Service
     * (a Lizyweb service page). Existing rows stay 'product'. `page_url` is an optional full page
     * URL that overrides the built one (see AffiliateUrlBuilder).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 20)->default('product')->after('name');
            $table->string('page_url', 2048)->nullable()->after('slug');
            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'type']);
            $table->dropColumn(['type', 'page_url']);
        });
    }
};
