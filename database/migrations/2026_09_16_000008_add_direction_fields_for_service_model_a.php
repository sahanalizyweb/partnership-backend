<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service Partner Model A ("customer pays partner, partner owes us the commission") needs
     * a commission to represent a liability instead of a payable, and a settlement to net that
     * liability against what's normally owed to the partner. Both changes are additive:
     * `direction` defaults to 'to_partner' (today's only behaviour) so every existing
     * commission keeps its current meaning, and `total_commission_owed_by_partner` defaults to
     * 0 so every existing settlement's `net_payable` is unaffected.
     */
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->enum('direction', ['to_partner', 'from_partner'])->default('to_partner')->after('commission_amount');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('total_commission_owed_by_partner', 14, 2)->default(0)->after('total_commission');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn('direction');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('total_commission_owed_by_partner');
        });
    }
};
