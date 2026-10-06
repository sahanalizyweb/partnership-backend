<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text location entered directly on the Partner Details page, replacing the
     * dropdown-driven primary_location_id for day-to-day editing. primary_location_id is
     * left in place (still used by AssignmentMatchingService for location-based matching)
     * but is no longer set from the partner edit forms.
     */
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('location')->nullable()->after('primary_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
