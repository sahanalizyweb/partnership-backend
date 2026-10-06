<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storefront affiliate tracking:
     * - affiliate_clicks: one row per visitor per link per day, so a refresh or repeat visit
     *   never counts twice (the unique index is the dedupe).
     * - sales.external_order_id: the shop's order id, so a re-sent order webhook can never
     *   create a second Sale / conversion / commission for the same order.
     */
    public function up(): void
    {
        // A first run could fail after creating the table (MySQL DDL isn't transactional).
        Schema::dropIfExists('affiliate_clicks');

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_link_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->date('click_date');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique(['affiliate_link_id', 'visitor_hash', 'click_date'], 'affiliate_clicks_visitor_day_unique');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('external_order_id')->nullable()->unique()->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['external_order_id']);
            $table->dropColumn('external_order_id');
        });
        Schema::dropIfExists('affiliate_clicks');
    }
};
