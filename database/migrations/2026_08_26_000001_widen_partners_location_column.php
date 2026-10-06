<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen partners.location from varchar(255) to TEXT so it can hold a full postal
     * address (building, street, area, city, state, PIN) rather than just a place name.
     * Raw SQL is used because doctrine/dbal (needed by Schema::table(...)->change()) isn't
     * installed in this project.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE partners MODIFY location TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partners MODIFY location VARCHAR(255) NULL');
    }
};
