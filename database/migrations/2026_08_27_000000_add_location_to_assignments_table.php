<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text location entered directly on the New Assignment form, replacing the
     * dropdown-driven location_id for day-to-day use. location_id is left in place (still
     * used by AssignmentMatchingService for location-based partner suggestions) but is no
     * longer set from the create-assignment forms.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->text('location')->nullable()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
