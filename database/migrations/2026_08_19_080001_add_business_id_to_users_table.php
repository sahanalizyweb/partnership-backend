<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null business_id = global user (e.g. Super Administrator) who can see all businesses
            $table->foreignId('business_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('status')->default('active')->after('password'); // active/inactive
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn('status');
        });
    }
};
