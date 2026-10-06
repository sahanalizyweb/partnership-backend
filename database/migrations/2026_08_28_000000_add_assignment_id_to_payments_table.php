<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lets a payment optionally be tied to the specific assignment it was earned/paid for. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('assignment_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_id');
        });
    }
};
